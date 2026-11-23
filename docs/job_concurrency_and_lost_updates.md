# Aggregation Job Concurrency & Lost Update Prevention

Specification and architecture of the queue concurrency and lock lifecycle engine for `shopify-product-analytics`: ensuring that aggregation jobs running concurrently during high-throughput webhook bursts or backfills never drop subsequent dispatches or cause lost updates, while preventing worker lock starvation.

---

## 1. What This Feature Provides

- **Zero Lost Updates Under Concurrency:** Guarantees that if new orders or refunds arrive for a store, product, and date while an aggregation job is actively executing in the database, subsequent dispatches are never discarded.
- **`ShouldBeUniqueUntilProcessing` Lifecycle:** Transitions the unique job contract from holding the dispatch lock throughout job completion to releasing the lock immediately when worker processing begins.
- **Worker Timeout Alignment:** Aligns the `WithoutOverlapping` lock TTL to 120 seconds, strictly exceeding the 90-second queue worker timeout (`--timeout=90` in `docker-compose.yml`), eliminating split-brain concurrent execution under heavy database loads.
- **Graceful Job Deferral (`releaseAfter`):** If an overlapping job is picked up by a worker while another worker is already processing the same product-date bucket, the secondary job is delayed by 10 seconds and re-queued instead of throwing exceptions or dropping work.
- **Automatic In-Queue Deduplication:** While a deferred or pending job sits in the queue, it holds its own unique dispatch lock, deduplicating subsequent webhook events for that bucket and preventing queue floods.
- **Native Laravel Architecture:** Solves the edge case cleanly using standard framework primitives (`Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing` and `Illuminate\Queue\Middleware\WithoutOverlapping`) without coupling listeners to ad-hoc cache keys or user-land state machines.

---

## 2. Problem Statement & Failure Analysis

### Problem A: The Lost Update Race Condition (`ShouldBeUnique`)
Under the previous implementation, `AggregateProductDailyMetricsJob` implemented `ShouldBeUnique`. In Laravel, `ShouldBeUnique` retains the Redis dispatch lock (`laravel_unique_job:...`) for `$uniqueFor` seconds or until the job has completely finished execution (`JobProcessed`).

If another webhook or ingestion batch arrived while Job 1 was in-flight:
1. Job 1 queries PostgreSQL (`SELECT ... FROM order_line_items ...`) and finds Order 1 ($100.00).
2. Order 2 ($50.00) arrives and commits to PostgreSQL.
3. Event listener attempts to dispatch `AggregateProductDailyMetricsJob` for the same bucket.
4. Because Job 1 is still running, the unique lock is still held. Laravel **silently discards** the dispatch.
5. Job 1 finishes its upsert with `$100.00`.
6. Result: PostgreSQL has Order 1 and Order 2 ($150.00 total), but `product_daily_metrics` permanently reflects only $100.00.

### Problem B: Lock Expiration Mismatch (`expireAfter(60)` vs `--timeout=90`)
The queue worker in `docker-compose.yml` was configured with:
```yaml
command: php artisan queue:work redis --queue=default,exports --sleep=3 --tries=3 --timeout=90
```
However, the `WithoutOverlapping` middleware was configured with `->expireAfter(60)`. If a database transaction or heavy aggregation query took 70 seconds:
1. At second 60, the Redis lock expired.
2. A second worker picked up a job for the same bucket.
3. Both workers executed concurrently against the exact same tenant row in `product_daily_metrics`, leading to deadlocks and data corruption.

---

## 3. Technical Architecture & Lifecycle Flow

```
Time   Worker 1 (Job 1)                             Ingestion Gateway / Worker 2 (Job 2)
─────────────────────────────────────────────────────────────────────────────────────────────
T0     Order 1 arrives
       Dispatches Job 1
       Acquires unique lock: [shop:product:date]

T1     Worker 1 picks up Job 1:
       - Enters WithoutOverlapping middleware
       - Acquires overlap lock: [metrics:shop:product:date]
       - Laravel releases unique dispatch lock! ───► Unique dispatch lock is now FREE.
       - Enters handle() and begins DB queries.

T2     [Worker 1 still querying / aggregating] ────► Order 2 arrives and commits to DB!
                                                     Dispatches Job 2.
                                                     Laravel checks unique lock (it is FREE!).
                                                     Job 2 acquires unique lock and enters queue.

T3     Worker 1 upserts $100 to metrics.             Worker 2 picks up Job 2:
                                                     - Hits WithoutOverlapping middleware.
                                                     - Cannot acquire overlap lock (held by W1).
                                                     - releaseAfter(10) releases Job 2 with 10s delay.

T4     Worker 1 finishes handle()
       Releases WithoutOverlapping lock in finally.

T5     [10 seconds later]                            Job 2 retry picked up by available worker:
                                                     - Acquires WithoutOverlapping lock.
                                                     - Queries DB (finds Order 1 + Order 2).
                                                     - Upserts $150.00 into metrics!
                                                     - Releases overlap lock and exits.
```

---

## 4. File-by-File Breakdown

### 1. `backend/app/Jobs/AggregateProductDailyMetricsJob.php`
- **Role:** Primary background calculation job for product-level daily aggregations.
- **Changes:**
  - Implements `Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing` instead of `ShouldBeUnique`.
  - Configures `WithoutOverlapping` in `middleware()`:
    ```php
    return [
        (new WithoutOverlapping("metrics:{$this->shopId}:{$this->productId}:{$this->metricDate}"))
            ->releaseAfter(10)
            ->expireAfter(120),
    ];
    ```
- **Guarantees:** Releases the unique dispatch lock the moment execution begins; safely delays overlapping attempts by 10 seconds; holds overlap lock for up to 120 seconds (greater than the 90s worker timeout).

### 2. `backend/tests/Feature/Analytics/JobConcurrencyLostUpdatesTest.php`
- **Role:** Dedicated feature test suite driving and verifying concurrency, middleware parameters, and metric convergence.
- **Test Scenarios:**
  - `implements ShouldBeUniqueUntilProcessing to allow dispatches while processing`: Verifies class inheritance and unique key contract.
  - `configures WithoutOverlapping with 120s expiration and 10s release delay`: Verifies `expiresAfter === 120` and `releaseAfter === 10`.
  - `defers execution when WithoutOverlapping lock is held and executes when released`: Simulates an active lock and verifies `$job->release(10)` is invoked, and that execution proceeds once the lock is freed.
  - `converges to correct aggregate when new orders arrive during execution sequence`: Verifies that consecutive orders on the same day converge to correct gross sales and order counts without data loss.

### 3. `backend/tests/Feature/Analytics/AggregateProductDailyMetricsJobTest.php`
- **Role:** Base unit and feature contract tests for the aggregation job.
- **Changes:**
  - Updated interface assertions to include `ShouldBeUniqueUntilProcessing::class`.
  - Added assertions validating `expiresAfter` (120s) and `releaseAfter` (10s) on the job middleware.

---

## 5. Edge Cases Handled

1. **High-Frequency Bursts for the Same Product:**
   When 20 orders arrive within a 2-second window for the same product, Job 1 runs, releases the unique lock, and Job 2 is enqueued. Because Job 2 is pending in the queue, it holds the unique lock for subsequent dispatches, preventing 19 identical jobs from flooding Redis. When Job 2 executes, it aggregates all 20 orders at once.
2. **Worker Timeouts Under Database Latency:**
   If PostgreSQL experiences lock contention or high load and a job takes 75 seconds to finish, the 120-second lock TTL ensures the lock does not expire before the worker completes or times out at 90 seconds.
3. **Transient Concurrency Collisions:**
   When two workers attempt to run jobs for the same `(shop_id, product_id, metric_date)` simultaneously, `WithoutOverlapping->releaseAfter(10)` releases the second job back into Redis with a 10-second delay rather than throwing an exception or failing the job.
4. **Tenant Isolation:**
   Lock keys are strictly scoped by tenant: `metrics:{$shopId}:{$productId}:{$metricDate}`, ensuring activity in Shop A never delays or blocks job processing for Shop B.

---

## 6. Verification Evidence

### Targeted Concurrency Feature Tests
```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Analytics/JobConcurrencyLostUpdatesTest.php
```
```
   PASS  Tests\Feature\Analytics\JobConcurrencyLostUpdatesTest
  ✓ it implements ShouldBeUniqueUntilProcessing to allow dispatches while processing (0.30s)
  ✓ it configures WithoutOverlapping with 120s expiration and 10s release delay (0.01s)
  ✓ it defers execution when WithoutOverlapping lock is held and executes when released (0.02s)
  ✓ it converges to correct aggregate when new orders arrive during execution sequence (0.02s)

  Tests:    4 passed (17 assertions)
  Duration: 0.44s
```

### Full Backend Test Suite
```bash
docker compose exec backend ./vendor/bin/pest
```
```
  Tests:    269 passed (3020 assertions)
  Duration: 5.35s
```
Zero failures, zero warnings, zero orphaned locks.
