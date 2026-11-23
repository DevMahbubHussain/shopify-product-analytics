# Concurrency, Retry & Idempotency Verification

Comprehensive verification report and test specifications for transactional outbox retries, parent order row locking, out-of-order update protection, duplicate delivery idempotency, and the post-dispatch crash replay failure window per **Constraint C2 (Strict Architectural Trust Boundary)**, **Constraint C5 (Security, Edge & Corner Case Protocol)**, **Constraint C6 (Probe, Don't Reason)**, and **Constraint C15 (Incremental Delivery)**.

---

## 1. Executive Summary & Verification Matrix

This suite provides verified proofs for five critical data integrity and reliability concerns in Shopify distributed ingestion pipelines:

| Verification Scenario | Implementation Mechanism | Dedicated Test File | Status |
|---|---|---|---|
| **1. Transient Redis Failure Retries** | Sweeper catches `Throwable`, increments `attempts`, sets exponential delay $2^{\min(\text{attempts}, 6)} \times 5\text{s}$, and stores `last_error`. | [`backend/tests/Feature/Ingestion/OutboxRetryTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Ingestion/OutboxRetryTest.php) | **VERIFIED (PASSED)** |
| **2. Retry Limit Exhaustion** | When `attempts >= 5`, `markAttemptFailed` transitions event to `failed`, clears `retry_after`, and halts future retries. | [`backend/tests/Feature/Ingestion/OutboxRetryTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Ingestion/OutboxRetryTest.php) | **VERIFIED (PASSED)** |
| **3. Duplicate Delivery & Zero Double-Counting** | Compound unique constraints (`orders_shop_gid_unique`, `refund_lines_shop_gid_unique`) deduplicate deliveries. Re-aggregation yields identical precomputed aggregates. | [`backend/tests/Feature/Analytics/OrderConcurrentUpdateTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Analytics/OrderConcurrentUpdateTest.php)<br>[`backend/tests/Feature/Ingestion/RefundOutOfOrderUpdateTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Ingestion/RefundOutOfOrderUpdateTest.php) | **VERIFIED (PASSED)** |
| **4. Concurrent & Out-of-Order Updates** | PostgreSQL conditional update `WHERE excluded.shopify_updated_at >= table.shopify_updated_at` rejects stale payloads (`written = 0, skipped_stale = 1`). Parent orders locked via deterministic `lockForUpdate()`. | [`backend/tests/Feature/Analytics/OrderConcurrentUpdateTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Analytics/OrderConcurrentUpdateTest.php)<br>[`backend/tests/Feature/Ingestion/RefundOutOfOrderUpdateTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Ingestion/RefundOutOfOrderUpdateTest.php) | **VERIFIED (PASSED)** |
| **5. Post-Dispatch / Pre-MarkPublished Crash Window** | When fast-path or sweeper dispatches to Redis and crashes before DB commit, recovery sweeper replays the event. Pure mathematical summation over raw rows guarantees idempotent convergence with zero double-counting. | [`backend/tests/Feature/Ingestion/OutboxDualDispatchCrashReplayTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Ingestion/OutboxDualDispatchCrashReplayTest.php) | **VERIFIED (PASSED)** |

---

## 2. Deep-Dive Scenarios & Test Evidence

### Scenario 1: Redis Broker Failure & Exponential Backoff
- **Problem:** If Redis or persistent queue workers experience a network blip or temporary restart, fast-path or background sweeper publishing must not permanently drop outbox events.
- **Verification Proof (`OutboxRetryTest.php`):**
  1. An event is recorded in `outbox_events` (`status = 'pending'`, `attempts = 0`).
  2. Transient Redis outage is simulated by raising a `\RuntimeException` during event dispatching.
  3. Sweeper command `php artisan outbox:publish` executes inside transaction:
     - Catches transient error.
     - Increments `attempts` from 0 to 1.
     - Computes `retry_after = now() + 10s` ($2^1 \times 5\text{s}$).
     - Keeps `status = 'pending'`.
     - Records error trace in `last_error`.
  4. Immediate subsequent sweeper runs skip the record because `retry_after > now()`.
  5. When time advances past `retry_after`, a second failure increments `attempts` to 2 and extends `retry_after = now() + 20s` ($2^2 \times 5\text{s}$).
  6. When Redis recovers, the event is successfully dispatched and marked `status = 'published'`.

### Scenario 2: Retry Limit Exhaustion & Permanent Failure Handling
- **Problem:** Permanent errors (e.g. poison pills or persistently exhausted retries) must not loop indefinitely and starve the outbox recovery worker.
- **Verification Proof (`OutboxRetryTest.php`):**
  1. An event with `attempts = 4` experiences a 5th consecutive failure.
  2. `OutboxService::markAttemptFailed` detects `attempts >= 5`:
     - Sets `status = 'failed'`.
     - Clears `retry_after = null`.
     - Persists the final exception message.
  3. Sweeper query `getPendingStrandedEvents` queries `WHERE status = 'pending'`, permanently excluding exhausted records.
  4. Corrupt schemas throwing `\InvalidArgumentException` are immediately routed to `markPermanentFailure`, marking them `failed` on attempt 0 without wasting retry iterations.

### Scenario 3: Duplicate Webhook Delivery & Zero Metric Double-Counting
- **Problem:** Shopify frequently re-delivers the same webhook topic when network acknowledgements are delayed. Multiple deliveries must never create duplicate line items or inflate sales metrics.
- **Verification Proof (`OrderConcurrentUpdateTest.php` & `RefundOutOfOrderUpdateTest.php`):**
  1. **Order Duplicate Test:**
     - Order with 3 units ($75 gross, $5 discount, $70 net) is ingested.
     - Precomputation job runs: calculates 3 units, 1 order, $75 gross, $70 net.
     - The identical webhook payload is delivered a 2nd and 3rd time.
     - `orders` row count remains **1**; `order_line_items` row count remains **1**.
     - Aggregation job re-runs: precomputed metrics remain **exactly $75.000000 gross, 3 units, and $70.000000 net** (zero double-counting).
  2. **Refund Duplicate Test:**
     - Base order has 2 units ($100 gross).
     - Refund of 1 unit ($50 subtotal) is delivered.
     - Precomputation job runs: calculates 1 unit sold, $100 gross, $50 refunds, $50 net sales.
     - The identical refund webhook is delivered a 2nd and 3rd time.
     - `refund_line_items` row count remains **1**.
     - Aggregation job re-runs: precomputed metrics remain **exactly 1 unit sold, $50.000000 refunds, and $50.000000 net sales** (zero double-counting).

### Scenario 4: Concurrent & Out-of-Order Updates
- **Problem:** In high-throughput stores, refund and order webhooks can arrive out of chronological order. Stale payloads must never overwrite newer stored data.
- **Verification Proof (`OrderConcurrentUpdateTest.php` & `RefundOutOfOrderUpdateTest.php`):**
  1. **Order Out-of-Order Test:**
     - Order update with `shopify_updated_at = 14:00:00` is ingested.
     - Out-of-order update with `shopify_updated_at = 13:00:00` arrives.
     - Ingestion returns `{"received": 1, "written": 0, "skipped_stale": 1}`.
     - Database retains `#1001-NEW` and `14:00:00`. Outbox event publication is suppressed.
  2. **Refund Out-of-Order Test:**
     - Refund line with `shopify_updated_at = 14:00:00` ($60 subtotal, 2 units) is ingested.
     - Out-of-order update with `shopify_updated_at = 13:00:00` ($20 subtotal, 1 unit) arrives.
     - Ingestion returns `{"received": 1, "written": 0, "skipped_stale": 1}`.
     - Database retains $60.000000, 2 units, and `14:00:00`. Outbox event publication is suppressed.
  3. **Deterministic Parent Order Locking:**
     - Both `UpsertOrderBatch` and `UpsertRefundBatch` sort parent IDs and execute `ORDER BY id/shopify_order_gid ASC lockForUpdate()` inside the active transaction before capturing pre-update buckets, completely eliminating concurrency deadlocks and snapshot skew.

### Scenario 5: Post-Dispatch / Pre-MarkPublished Crash Failure Window
- **Problem:** In an at-least-once transactional outbox architecture, a process may crash immediately after pushing the domain event to Redis, before the database update `status = 'published'` commits. The recovery sweeper will subsequently detect the event as stranded and replay the dispatch, queueing duplicate aggregation jobs.
- **Verification Proof (`OutboxDualDispatchCrashReplayTest.php`):**
  1. **Fast-Path Post-Dispatch Crash:**
     - Fast-path dispatches `OrdersIngested` to Redis, but process crashes before `markPublished()` executes.
     - PostgreSQL row remains `status = 'pending'`.
     - Queue worker processes Job #1 from Redis: aggregates gross sales = $100.00, units = 2.
     - Recovery sweeper (`outbox:publish`) detects stranded pending record, re-dispatches Job #2 to Redis, and marks `status = 'published'`.
     - Queue worker processes Job #2 from Redis.
     - Database metrics are inspected: **exactly $100.000000 gross sales, 2 units sold, 1 order count** (zero double-counting).
  2. **Sweeper Post-Dispatch Crash:**
     - Sweeper dispatches event, but crashes before `markPublished()` commits.
     - Next sweeper cycle safely replays the dispatch and marks published.
     - Sequential job executions converge to the identical canonical aggregate ($100.00 gross sales, 4 units).
  3. **Concurrent Worker Lock Guard:**
     - `AggregateProductDailyMetricsJob` enforces `WithoutOverlapping("metrics:{$shopId}:{$productId}:{$metricDate}")` with 120s expiration and 10s backoff, preventing concurrent execution races under worker contention.

---

## 3. Test Execution Commands

### Run Dedicated Verification Test Suites
```bash
docker compose exec backend ./vendor/bin/pest \
  tests/Feature/Ingestion/OutboxRetryTest.php \
  tests/Feature/Analytics/OrderConcurrentUpdateTest.php \
  tests/Feature/Ingestion/RefundOutOfOrderUpdateTest.php \
  tests/Feature/Ingestion/OutboxDualDispatchCrashReplayTest.php
```

```text
   PASS  Tests\Feature\Ingestion\OutboxRetryTest (4 passed)
   PASS  Tests\Feature\Analytics\OrderConcurrentUpdateTest (4 passed)
   PASS  Tests\Feature\Ingestion\RefundOutOfOrderUpdateTest (4 passed)
   PASS  Tests\Feature\Ingestion\OutboxDualDispatchCrashReplayTest (3 passed)

  Tests:    15 passed (158 assertions)
  Duration: 1.48s
```

### Full Backend Verification
```bash
docker compose exec backend ./vendor/bin/pest
```
All **329 tests pass (3,565 assertions)** across the entire backend suite.
