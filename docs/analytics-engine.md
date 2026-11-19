# Analytics Engine & Queue Aggregation (Step 4)

How the Laravel backend transforms raw ingested orders and refunds into precomputed daily product metrics, running entirely asynchronously on Redis queue workers with atomic concurrency locks, arbitrary-precision financial mathematics, and store-timezone calendar bucketing.

---

## 1. What This Phase Provides

- **Asynchronous, Non-Blocking Aggregation:** Ingestion HTTP endpoints never calculate metrics or scan tables. They respond within 200ms, dispatching explicit domain events (`OrdersIngested`, `RefundsIngested`) that are processed by persistent Redis queue workers.
- **Precomputed Daily Metrics:** Orders and refunds are aggregated into `product_daily_metrics` indexed by `(shop_id, metric_date)`. Live merchant dashboard requests hit pre-aggregated rows in sub-50ms without on-the-fly table scans.
- **Arbitrary-Precision Financial Math:** All calculations use PHP's `bcmath` extension at scale 6 (`DECIMAL(20,6)`). Floating-point math is strictly forbidden. Negative net sales and negative units from heavy refunds are natively supported.
- **Store-Timezone Calendar Boundaries:** Order and refund timestamps stored in UTC are mapped to merchant store-local dates (`metric_date`) based on the store's IANA timezone, correctly handling midnight boundaries, leap years, and Daylight Saving Time (DST).
- **Concurrency & Deduplication:** Redis atomic locks guard both dispatch (`ShouldBeUnique` on `shop_id:product_id:metric_date`) and worker execution (`WithoutOverlapping`), preventing duplicate job buffers and race conditions during webhook bursts.
- **Period-over-Period Delta Engine:** Compares current periods to equal-length preceding periods, with zero-division safety and absolute-denominator percentage calculations for negative baselines.
- **Queue Resilience & Observability:** Jobs configure 5 tries with exponential backoff `[5, 15, 60]`, automatic deadlock retry `DB::transaction(..., 5)`, and log structured errors to PostgreSQL's `failed_jobs` table upon exhaustion.

---

## 2. Architecture & Request Flow

```
Shopify Webhook / Harvester
            │
            ▼
React Router App (S2S Signer)
            │  POST /internal/v1/ingestion/{orders,refunds} (< 200ms HTTP)
            ▼
Laravel Ingestion Pipeline
  ├── 1. VerifyHmacSignature & ResolveShopTenant
  ├── 2. Upsert raw rows in orders / order_line_items / refund_line_items
  ├── 3. Dispatch Domain Event: OrdersIngested / RefundsIngested
  └── 4. Return 200/201 HTTP Response
            │
            ▼ (Async Redis Queue)
Queued Event Listeners (ShouldQueue)
  ├── QueueMetricAggregationsFromOrders
  └── QueueMetricAggregationsFromRefunds
            │  Extracts distinct (shop_id, product_id, metric_date)
            ▼
AggregateProductDailyMetricsJob (Queue: default on redis)
  ├── ShouldBeUnique dispatch guard (uniqueFor: 300s)
  ├── WithoutOverlapping worker lock (expireAfter: 60s)
  ├── StoreTimezoneService (startOfDayUtc & endOfDayUtc)
  ├── DB::transaction(..., 5) deadlock retry
  ├── FinancialMath (BCMath scale 6)
  └── Upsert pre-aggregated row into product_daily_metrics
            ▲
            │ (Sub-50ms indexed read)
Merchant Dashboard (GET /api/v1/analytics/overview)
```

---

## 3. Financial & Precision Formulas

All monetary calculations are performed in `App\Analytics\Domain\FinancialMath` using `bcmath` with scale `6`:

| Metric | Formula | Edge Cases Handled |
|---|---|---|
| **Gross Sales** | $\sum (\text{Quantity} \times \text{Original Unit Price})$ | Evaluated per line item before discounts or returns; avoids IEEE 754 float drift. |
| **Discounts** | $\sum \text{discount\_amount}$ | Allocated line-item discounts. |
| **Refunds** | $\sum \text{refunded\_subtotal}$ | Attributed to the specific product on the date the refund was processed. |
| **Net Sales** | $\text{Gross Sales} - \text{Discounts} - \text{Refunds}$ | May evaluate to negative numbers when refunds exceed gross sales. |
| **Units Sold** | $\text{Purchased Units} - \text{Refunded Units}$ | Evaluates to negative units if return quantities exceed purchases in a day. |
| **Order Count** | $\text{COUNT}(\text{DISTINCT } \text{order\_id})$ | Distinct orders on that store date containing at least one item of the product. |
| **Average Order Revenue** | $\text{Net Sales} \div \text{Order Count}$ | Returns `0.000000` when $\text{Order Count} \le 0$ to prevent division by zero. |

### Period-over-Period Delta Formulas (`PeriodComparisonService`)

For any arbitrary date range $[t_{\text{from}}, t_{\text{to}}]$ of length $N$ days, the preceding period is shifted by $N$ days:
- $\text{Delta} = \text{Current} - \text{Previous}$
- $\text{Direction} = \text{increase} \mid \text{decrease} \mid \text{unchanged}$
- When $\text{Previous} = 0$:
  - If $\text{Current} > 0$: $\text{isNew} = \text{true}$, $\text{percentageChange} = \text{null}$.
  - If $\text{Current} = 0$: $\text{isNew} = \text{false}$, $\text{percentageChange} = \text{null}$.
- When $\text{Previous} \ne 0$ (including negative baselines):
  $$\text{Percentage Change} = \frac{\text{Current} - \text{Previous}}{|\text{Previous}|} \times 100$$

---

## 4. Concurrency, Queue Primitives & Idempotency

### Concurrency Guards
1. **Dispatch Guard (`ShouldBeUnique`):**
   ```php
   public int $uniqueFor = 300;

   public function uniqueId(): string
   {
       return "{$this->shopId}:{$this->productId}:{$this->metricDate}";
   }
   ```
   If 20 order webhooks arrive in the same minute affecting the same product on the same day, only the first job is queued in Redis; redundant jobs are discarded before consuming worker memory.
2. **Worker Execution Guard (`WithoutOverlapping`):**
   ```php
   public function middleware(): array
   {
       return [
           (new WithoutOverlapping("metrics:{$this->shopId}:{$this->productId}:{$this->metricDate}"))
               ->expireAfter(60),
       ];
   }
   ```
   Prevents concurrent workers from simultaneously calculating and updating the same daily record.
3. **Deadlock Recovery:**
   All queries and upserts are wrapped in `DB::transaction(fn() => ..., 5)` to automatically retry up to 5 times if PostgreSQL encounters lock contention.
4. **Retry & Backoff:**
   `$tries = 5`, exponential backoff `[5, 15, 60]`. Unrecoverable errors log structured context and are captured by `queue.failer` into PostgreSQL's `failed_jobs` table.

---

## 5. Store-Timezone Date Bucketing

All orders and refunds are saved with UTC timestamps (`TIMESTAMPTZ`). To determine which calendar day (`metric_date`) an event belongs to:
1. `StoreTimezoneService::toStoreDate($utcTimestamp, $shop->timezone)` converts the UTC instant into the merchant's IANA timezone.
2. Example boundary conversions:
   - Order processed at `2026-03-31T23:30:00Z`:
     - In `UTC`: March 31
     - In `Asia/Tokyo` (+09:00): April 1 (`2026-04-01`)
   - Order processed at `2026-01-01T02:00:00Z`:
     - In `UTC`: January 1
     - In `America/New_York` (-05:00): December 31 of prior year (`2025-12-31`)
3. `startOfDayUtc($storeDate, $timezone)` and `endOfDayUtc($storeDate, $timezone)` convert local store days back to UTC bounds for query execution against indexed timestamp columns.

---

## 6. File-by-File Breakdown

| Step | File Path | Type | Role |
|---|---|---|---|
| **4.1** | `backend/tests/Unit/Analytics/FinancialMathTest.php` | Test | Unit tests for arbitrary-precision BCMath financial math and division safety. |
| **4.2** | `backend/app/Analytics/Domain/FinancialMath.php` | Implementation | BCMath scale 6 calculations for gross sales, net sales, units, and order averages. |
| **4.3** | `backend/tests/Unit/Analytics/StoreTimezoneServiceTest.php` | Test | Unit tests for timezone boundary conversions, leap years, and DST transitions. |
| **4.4** | `backend/app/Analytics/Services/StoreTimezoneService.php` | Implementation | IANA timezone conversion service with UTC fallback. |
| **4.5** | `backend/tests/Feature/Analytics/AggregateProductDailyMetricsJobTest.php` | Test | Feature test for daily aggregation job, idempotency, negative net sales, and tenant isolation. |
| **4.6** | `backend/app/Jobs/AggregateProductDailyMetricsJob.php` | Implementation | Queued job on Redis with `ShouldBeUnique`, `WithoutOverlapping`, and deadlock transaction. |
| **4.7** | `backend/tests/Feature/Analytics/IngestionEventListenersTest.php` | Test | Feature test for `OrdersIngested` and `RefundsIngested` event listeners. |
| **4.8** | `backend/app/Listeners/QueueMetricAggregationsFromOrders.php` | Implementation | Queued listener extracting affected product dates from orders and dispatching jobs. |
| **4.9** | `backend/app/Listeners/QueueMetricAggregationsFromRefunds.php` | Implementation | Queued listener extracting affected product dates from refunds and dispatching jobs. |
| **4.10** | `backend/app/Providers/AppServiceProvider.php` | Edit | Registers explicit `Event::listen` bindings for `OrdersIngested` and `RefundsIngested`. |
| **4.11** | `backend/tests/Unit/Analytics/PeriodComparisonTest.php` | Test | Unit tests for preceding-period ranges, percentage changes, and zero baselines. |
| **4.12** | `backend/app/Analytics/Services/PeriodComparisonService.php` | Implementation | Period delta engine returning typed `MetricComparisonResult`. |
| **4.13** | `backend/tests/Feature/Analytics/QueueResilienceAndFailedJobsTest.php` | Test | Feature test verifying retry backoff, error logging, and `failed_jobs` table persistence. |
| **4.14** | `docs/analytics-engine.md` | Documentation | This document. |

---

## 7. Analytics REST API Endpoints & Contracts

All endpoints live under `/api/v1/*`, are authenticated via the HMAC-SHA256 `s2s` middleware, and enforce strict tenant isolation by resolving the shop domain exclusively from verified headers.

### 1. `GET /api/v1/analytics/overview`
- **Query Params:** `from` (YYYY-MM-DD), `to` (YYYY-MM-DD).
- **Function:** Returns high-level store totals (gross sales, discounts, refunds, net sales, units sold, orders, average order revenue) along with preceding-period comparisons ($\Delta$, $\%$, and direction).
- **Performance:** Sub-50ms; reads directly from precomputed `product_daily_metrics` and indexed `orders.processed_at`.

### 2. `GET /api/v1/analytics/sales-trend`
- **Query Params:** `from`, `to`, `interval` (`day` \| `week` \| `month`).
- **Function:** Returns continuous time-series chart points with automatic zero-gap filling for days without orders or refunds.

### 3. `GET /api/v1/products`
- **Query Params:** `from`, `to`, `search`, `vendor`, `product_type`, `status`, `sort` (`net_sales` \| `gross_sales` \| `units_sold` \| `orders` \| `title`), `direction` (`asc` \| `desc`), `page`, `per_page`.
- **Function:** Paginated product ranking table joining `products` with date-filtered aggregates.

### 4. `GET /api/v1/products/{id}/analytics`
- **Query Params:** `from`, `to`.
- **Function:** Single product performance drill-down with period comparison and trend summary.

### 5. `GET /api/v1/products/{id}/variants/analytics`
- **Query Params:** `from`, `to`.
- **Function:** Variant-level breakdown for a product, sorted by net sales descending.

---

## 8. Verification Evidence

- **Full Backend Test Suite:** **215 passed (1009 assertions)** in `4.63s`.
  ```bash
  docker compose exec backend ./vendor/bin/pest
  ```
- **Shopify App Test Suite:** **121 passed (0 failures)**.
  ```bash
  cd shopify-app && npm test
  ```
- **Static Analysis & Typechecks:** 0 PHP errors, strict types declared on every file, 0 TypeScript errors (`npm run typecheck && npm run lint`).
