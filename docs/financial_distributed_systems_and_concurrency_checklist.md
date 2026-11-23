# Engineering Checklist: Financial Integrity, Distributed Systems, and Concurrency

This document defines the mandatory engineering constraints, architectural patterns, and pre-merge verification checklist for `shopify-product-analytics`. Any code touching financial data, background messaging pipelines, or database concurrency MUST comply with these protocols.

---

## 1. Financial Integrity & Mathematical Precision

Financial calculations must be mathematically exact, deterministic, and auditable. Minor rounding discrepancies or floating-point errors compound across multi-tenant aggregations and destroy merchant trust.

### 1.1 Strict Decimal Representation (Zero Floating-Point Operations)
- **Database Storage:** All monetary amounts MUST be stored in PostgreSQL as `DECIMAL(20,6)` or integer minor units (cents). Never use `FLOAT`, `DOUBLE PRECISION`, or `REAL`.
- **PHP Arithmetic:** Native PHP floating-point arithmetic (`+`, `-`, `*`, `/`) is strictly prohibited for money. All calculations MUST use the `bcmath` extension via `App\Analytics\Domain\FinancialMath` with 6 decimal places of precision (`bcscale(6)`).
- **String Handling:** Treat all monetary quantities as immutable numeric strings in PHP (e.g. `'100.500000'`).
- **Rounding Rules:** Intermediate calculations must preserve full 6-decimal precision. Rounding occurs exclusively at final presentation boundaries using standard half-up rounding (`ROUND_HALF_UP`).

### 1.2 ISO 4217 Currency Isolation
- **No Cross-Currency Blending:** Every monetary figure in PostgreSQL (`orders`, `order_line_items`, `refund_line_items`, `product_daily_metrics`) must be stored with its explicit 3-letter ISO 4217 `currency_code` (e.g., `'USD'`, `'EUR'`, `'GBP'`).
- **Strict Grouping:** Database aggregations and API responses MUST always partition and group by `currency_code`. Summing monetary values across different currencies is strictly forbidden:
  ```sql
  -- CORRECT: Currency-isolated aggregation
  SELECT currency_code, SUM(net_sales) AS total_net_sales
  FROM product_daily_metrics
  WHERE shop_id = :shopId AND metric_date >= :startDate AND metric_date < :endDate
  GROUP BY currency_code;

  -- FORBIDDEN: Blending foreign currency totals
  SELECT SUM(net_sales) FROM product_daily_metrics WHERE shop_id = :shopId;
  ```

### 1.3 Multi-Currency Bucket Staleness Elimination
- **The Problem:** When an order or refund is edited, cancelled, or has its transaction currency altered, recalculation jobs that only aggregate active orders will ignore the previous currency, leaving ghost figures in `product_daily_metrics`.
- **The Protocol:** When recalculating metrics for an affected `(shop_id, product_id, metric_date)` bucket:
  1. Query all historical currencies currently recorded in `product_daily_metrics` for that bucket.
  2. If an existing currency no longer has any matching transactions in the updated raw data, the stale currency row MUST be explicitly zeroed out (`gross_sales = '0.000000'`, `net_sales = '0.000000'`, `order_count = 0`, `units_sold = 0`).

### 1.4 Negative Net Sales & Baseline Edge Cases
- **Negative Net Sales:** Net sales is defined as $\text{Gross Sales} - \text{Discounts} - \text{Refunds}$. When refunds on a given day exceed sales (common after marketing campaigns or holiday rushes), net sales must legitimately represent negative values (e.g., `'-45.000000'`). Never clamp negative net sales to zero.
- **Division by Zero Safety:** Period-over-period percentage comparisons must safely handle zero and negative baselines:
  - Baseline $= 0$ and current $> 0$: Return `null` or define a distinct indicator (`+100%` or positive delta); never trigger a division-by-zero fatal error.
  - Baseline $< 0$: Use the absolute value of the denominator ($|\text{previous}|$) to ensure correct growth directional sign:
    $$\text{Change \%} = \frac{\text{current} - \text{previous}}{|\text{previous}|} \times 100$$

---

## 2. Distributed Systems & At-Least-Once Delivery

In distributed e-commerce systems, network timeouts, broker crashes, and out-of-order webhook deliveries are inevitable. The system must guarantee zero data loss and idempotent processing under failure conditions.

### 2.1 The Transactional Outbox Pattern
- **Atomic Persistence:** Ingestion pipelines (`POST /internal/v1/ingestion/*`) must persist domain events (`OrdersIngested`, `RefundsIngested`) into the `outbox_events` table inside the exact same database transaction (`DB::transaction(fn() => ..., 5)`) as the business entities.
- **Dual-Path Delivery:**
  1. *Fast-Path:* The HTTP ingestion pipeline dispatches the event immediately to Redis and calls `OutboxService::markPublished($outboxId)`.
  2. *Recovery Sweeper:* A scheduled background sweeper (`php artisan outbox:publish --age=30 --limit=100`) queries pending outbox records older than 30 seconds and re-dispatches them to Redis.

### 2.2 Dual-Dispatch Crash Replay Idempotency
- **The Reality of At-Least-Once Delivery:** If an ingestion process terminates or crashes between Redis dispatch and database commit, the outbox record remains `pending`, causing the recovery sweeper to redeliver the event.
- **Pure Function Aggregation:** Downstream consumers (e.g., `AggregateProductDailyMetricsJob`) must compute aggregations directly from immutable raw database rows (`orders`, `order_line_items`, `refund_line_items`) and upsert the precalculated totals. Duplicate dispatches must converge to identical totals without double-counting.

### 2.3 Physical Broker Delivery Verification (Testing Protocol)
- **Zero Mocking of the Transport Layer:** Never rely solely on in-memory counters (`Event::listen()`) or mock queue assertions (`Queue::fake()`) when verifying outbox reliability or crash recovery.
- **Redis Queue Inspection:** Test suites must clear the test Redis list (`$redis->del('queues:default')`), trigger the pipeline, and assert directly against the real Redis connection:
  ```php
  $queueLen = $redis->llen('queues:default');
  expect($queueLen)->toBeGreaterThan(0);

  $rawJob = $redis->lindex('queues:default', 0);
  $payload = json_decode((string) $rawJob, true);
  expect($payload['displayName'])->toBe(QueueMetricAggregationsFromOrders::class);
  ```

### 2.4 Truncated Exponential Backoff & Poison Pill Isolation
- **Transient Broker Failures:** When Redis or downstream workers fail transiently, the recovery sweeper must apply truncated exponential retry backoff:
  $$\text{Delay} = 2^{\min(\text{attempts}, 6)} \times 5\text{ seconds}$$
  Records must set `retry_after = now() + delay` and be filtered out of query windows until the backoff expires.
- **Poison Pill Isolation:** If an outbox event encounters a fatal, unrecoverable payload exception (`\InvalidArgumentException`, corrupted JSON, non-existent tenant), it must immediately transition to `FAILED` with diagnostics and 0 retry delay to prevent blocking the sweeper pipeline.

### 2.5 Out-of-Order Webhook Freshness Guards & Monotonicity
- **Monotonic Sequence Enforcement:** Webhook handlers must track `shopify_updated_at` (falling back monotonically to `processed_at`).
- **Conditional Database Updates:** Child records (`order_line_items`, `refund_line_items`) must use conditional SQL upserts:
  ```sql
  ON CONFLICT (shop_id, shopify_line_item_gid)
  DO UPDATE SET ...
  WHERE excluded.shopify_updated_at >= order_line_items.shopify_updated_at;
  ```
- **Stale Suppression:** Ingestion must detect out-of-order deliveries (`written = 0, skipped_stale = 1`), suppress domain event dispatch, and skip recalculation queues.

---

## 3. Multi-Tenant Database Concurrency & Deadlock Prevention

Concurrent webhook deliveries, backfill syncs, and background workers operate on shared tables. Without disciplined concurrency control, deadlocks and race conditions will occur.

### 3.1 Strict Tenant Isolation
- **Tenant Scope Everywhere:** Every SQL query, Redis lock key, queue payload, and cache entry MUST include `shop_id`.
- **Compound Keys:** All tables child to tenant MUST define compound unique constraints `(shop_id, shopify_*_gid)` and compound foreign keys `(shop_id, order_id)`.

### 3.2 Deterministic Parent Row Locking
- **The Concurrency Problem:** When two concurrent webhooks update line items belonging to the same orders, locking orders in arbitrary order creates circular wait conditions resulting in database deadlocks (`SQLSTATE 40P01`).
- **Deterministic Order:** Ingestion pipelines writing or updating child records must acquire row locks on existing parent orders in ascending ID order within a retryable transaction:
  ```php
  DB::transaction(function () use ($shopId, $orderIds) {
      DB::table('orders')
          ->where('shop_id', $shopId)
          ->whereIn('id', $orderIds)
          ->orderBy('id', 'asc')
          ->lockForUpdate()
          ->get();

      // Proceed with child record upserts...
  }, 5);
  ```

### 3.3 Atomic Redis Distributed Locks
- **Aggregation Job Deduplication:** Background jobs recalculating metrics for a bucket must implement `ShouldBeUniqueUntilProcessing` using unique keys formatted as `(shop_id:product_id:metric_date)`.
- **Execution Overlap Protection:** Concurrent execution must be guarded with Redis atomic locks:
  ```php
  public function middleware(): array
  {
      return [
          (new WithoutOverlapping($this->uniqueId()))
              ->releaseAfter(10)
              ->expireAfter(120),
      ];
  }
  ```
  - **Lock Expiration (120s):** Must strictly exceed the queue worker timeout (90s) to prevent lock expiration while a worker is still writing.
  - **Release Interval (10s):** Provides exponential backoff under worker contention.
  - **Driver Constraint:** Distributed locks MUST run exclusively against the Redis cache driver (`SETNX` and Lua scripts). Never use database or file cache drivers for concurrency locks.

---

## 4. Timezone Boundaries & Microsecond Precision

E-commerce reporting requires sales to align with the merchant's store timezone, not the application server's UTC clock. Boundary errors near midnight cause day-shifting and duplicate reporting.

### 4.1 Half-Open Day Intervals ($[startOfDayUtc, startOfNextDayUtc)$)
- **Microsecond Precision:** PostgreSQL timestamps (`processed_at`, `refunded_at`) must use `timestamptz(6)`.
- **Half-Open Range Requirement:** Daily aggregations and dashboard queries MUST always query half-open intervals:
  $$\text{Interval} = [startOfDayUtc, startOfNextDayUtc)$$
  ```sql
  -- CORRECT: Half-open interval protects boundary microseconds
  WHERE processed_at >= :startOfDayUtc AND processed_at < :startOfNextDayUtc

  -- FORBIDDEN: Closed interval loses or duplicates microsecond transactions
  WHERE processed_at BETWEEN :startOfDay AND :endOfDay
  WHERE processed_at <= '2026-05-15 23:59:59'
  ```

### 4.2 Store-Local Date Projection
- **Projection Before Grouping:** Timestamps must be projected into the store's configured IANA timezone (e.g. `'America/New_York'`) using `App\Analytics\Services\StoreTimezoneService` before extracting the `metric_date` (`YYYY-MM-DD`).
- **Boundary Shifts:** A sale at `2026-05-15 02:00:00 UTC` belongs to `2026-05-14` in New York (EDT, UTC-4). Metrics must be aggregated under `2026-05-14`.

### 4.3 Dual-Bucket Recalculation on Updates
- **The Problem:** When an order's `processed_at` timestamp is updated in Shopify Admin (e.g. edited order or manual backdate), the order shifts from Date A to Date B.
- **The Protocol:** Ingestion pipelines must capture both the old date and the new date. Recalculation jobs must be dispatched for both `(product_id, Date A)` and `(product_id, Date B)` so Date A does not retain orphaned revenue.

---

## 5. Performance, Indexing, and Memory Benchmarks

High-volume stores generate millions of line items. Live dashboards cannot execute table scans over raw order rows.

### 5.1 Precomputed Aggregates vs Raw Tables
- **Precomputation Target:** Live dashboard and analytics requests (`/api/v1/analytics/*`) MUST query precomputed aggregates in `product_daily_metrics`. Live table scans across `order_line_items` during HTTP dashboard requests are prohibited.
- **Sub-50ms Dashboard Response:** Overview queries must execute sub-50ms with a constant query count ($O(1)$ database queries) across any historical date range (e.g., 30, 90, 365 days).

### 5.2 Natural Cost-Based Index Evaluation
- **Zero Planner Hints:** Never benchmark PostgreSQL queries using artificial planner flags (`SET enable_seqscan = OFF`).
- **Realistic Data Distributions:** Benchmark fixtures must seed realistic data distributions (e.g., 50 pending outbox events among 2,000 published events) so PostgreSQL's cost-based query planner naturally selects composite index scans (`outbox_events_pending_age_idx`, `orders_shop_processed_idx`).

### 5.3 Memory Leak Resistance
- **Chunking & Lazy Iteration:** Bulk ingestion pipelines and data aggregators must process records using `chunk()` or `lazy()` to maintain a flat memory footprint.
- **True Engine Allocation Measurement:** Memory benchmarks must measure true PHP Zend Engine allocations using `memory_get_usage(false)` to avoid libc OS page allocator noise (`memory_get_usage(true)`), verifying heap delta remains $< 0.5\text{MB}$ across 50 sequential batches.

---

## 6. Pre-Merge Verification Checklist

Before opening a PR or merging any financial, distributed messaging, or concurrency change, verify each item on this checklist:

### Financial & Arithmetic Checks
- [ ] Are all monetary columns defined as `DECIMAL(20,6)` in migrations?
- [ ] Are all financial formulas using `FinancialMath` (`bcmath`) with zero native float operators?
- [ ] Are all metric aggregations grouped strictly by `currency_code`?
- [ ] Does the recalculation logic explicitly zero out stale foreign currency rows if transactions are removed?
- [ ] Are negative net sales handled correctly without clamping to zero?
- [ ] Are period comparison baselines safe against zero and negative values?

### Distributed Systems & Outbox Checks
- [ ] Are domain events persisted to `outbox_events` inside the exact same `DB::transaction()` as the raw records?
- [ ] Does the fast-path try/catch block allow HTTP ingestion to succeed if Redis dispatch fails?
- [ ] Does the test suite assert physical queue delivery against Redis (`Redis::connection()->llen('queues:default')`)?
- [ ] Are downstream aggregation consumers pure functions that converge to identical totals upon duplicate delivery?
- [ ] Does transient broker failure trigger truncated exponential backoff ($2^{\min(\text{attempts}, 6)} \times 5\text{s}$) with `retry_after`?
- [ ] Do fatal payload exceptions (`\InvalidArgumentException`) transition immediately to `FAILED` without retries?
- [ ] Are out-of-order webhook updates rejected using monotonic timestamp guards (`shopify_updated_at >= ...`)?

### Concurrency & Multi-Tenancy Checks
- [ ] Is every database query, queue job, and Redis lock key strictly scoped by `shop_id`?
- [ ] Do parent order locks execute in deterministic ascending ID order (`orderBy('id', 'asc')->lockForUpdate()`)?
- [ ] Are aggregation jobs protected by Redis `WithoutOverlapping` locks with `expireAfter(120)` exceeding worker timeout (90s)?
- [ ] Are batch database upserts wrapped in `DB::transaction(fn() => ..., 5)` for automatic deadlock recovery?

### Timezone & Precision Checks
- [ ] Are all day intervals half-open: `WHERE processed_at >= :startOfDayUtc AND processed_at < :startOfNextDayUtc`?
- [ ] Are timestamps stored with microsecond precision (`timestamptz(6)`)?
- [ ] Are timestamps projected to store-local time before extracting `metric_date`?
- [ ] Does an order date update trigger recalculation jobs for both the old date bucket and the new date bucket?

### Performance & Benchmark Checks
- [ ] Do live analytics endpoints query precomputed aggregates in `product_daily_metrics` (zero raw line item scans)?
- [ ] Does a 365-day historical query execute with constant $O(1)$ query count and sub-50ms latency?
- [ ] Does PostgreSQL's query planner naturally choose index scans without planner hint overrides?
- [ ] Does sequential batch ingestion pass memory benchmarks with engine heap delta $< 0.5\text{MB}$?
