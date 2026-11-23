# Transactional Outbox Pattern & Queue Delivery Reliability

Specification and architecture of the Transactional Outbox reliability engine for `shopify-product-analytics`: ensuring zero dropped domain events, guaranteed at-least-once metric aggregation dispatch, non-blocking multi-worker recovery locking, automated console scheduling, event schema versioning, retention pruning, deterministic lock ordering, and complete resilience against process crashes or Redis broker downtime during webhook and sync ingestion.

---

## 1. What This Feature Provides

- **Dual-Write Hazard Elimination:** Resolves the distributed systems dual-write gap between PostgreSQL database commits and Redis queue dispatches.
- **Atomic In-Transaction Recording:** Records pending domain events (`OrdersIngested`, `RefundsIngested`) in an `outbox_events` table inside the **exact same database transaction** that persists orders, line items, and refund records. If PostgreSQL commits, the event record is guaranteed to be saved.
- **Fast-Path Immediate Publishing:** Normal HTTP requests publish domain events to Redis and immediately mark the specific outbox record as `published` (< 5ms) by UUID, adding zero perceivable latency to ingestion response times.
- **Automated Task Scheduling (`routes/console.php`):** Configures `outbox:publish --age=30 --limit=100` to execute every minute and `outbox:prune --days=14` to execute daily via Laravel's native Console Scheduler, guarded with `withoutOverlapping()`.
- **Deterministic Lock-Order Sorting:** Enforces strict alphabetical sorting by `shopify_order_gid` and `shopify_line_item_gid` before executing PostgreSQL batch upserts, eliminating lock-order inversion deadlocks (`40P01 deadlocks detected`) under concurrent webhook bursts.
- **Multi-Worker Concurrency Locking (`FOR UPDATE SKIP LOCKED`):** The `php artisan outbox:publish` sweeper command claims pending records inside an atomic database transaction using PostgreSQL `FOR UPDATE SKIP LOCKED`. Concurrent sweeper instances process disjoint batches of stranded records without blocking, waiting, or re-publishing rows claimed by another worker.
- **Event Schema Drift Versioning (`event_version`):** Adds an explicit `event_version` integer (default `1`) to `outbox_events` to safeguard event reconstitution contracts and maintain backward/forward compatibility as domain event schemas evolve.
- **Automated Retention Management (`outbox:prune`):** Provides `php artisan outbox:prune --days=14 --chunk=500` to clean up old `published` records in high-performance chunks using PostgreSQL `ctid` physical tuple locator scans, while strictly preserving `pending` records and diagnostic history.
- **Automated Stranded Event Recovery:** Detects events that remained `pending` for longer than 30 seconds (caused by process crashes, Redis network drops, or container restarts), reconstructs the strongly-typed domain event, and pushes it to Redis.
- **Transactional Rollback Integrity:** If an ingestion batch fails validation or triggers a database deadlock/rollback, the outbox record is rolled back automatically, preventing phantom event dispatches.

---

## 2. The Dual-Write Problem & Failure Analysis

In a standard two-tier architecture where an ACID database (PostgreSQL) and an asynchronous queue broker (Redis) are written sequentially:

```
1. Begin DB Transaction
2. Write orders and line items
3. Commit DB Transaction  <─── Database state is committed!
      │
      ├── [CRASH GAP: Process killed by OOM, SIGKILL, server restart, or Redis network drop]
      │
4. Push event to Redis   <─── Never reached if process dies!
```

### The Production Failure Scenarios
1. **PHP-FPM Worker SIGKILL / OOM:** If a worker process exceeds memory limits or receives a container restart signal after Step 3, the committed orders exist in PostgreSQL, but the in-memory event array is destroyed.
2. **Transient Redis Broker Outage:** If Redis is restarting or experiences a brief network timeout, the HTTP request throws an exception after Step 3. The data is in PostgreSQL, but Redis never receives the aggregation job.
3. **Webhook Idempotency Trap:** If Shopify retries the webhook because the HTTP process crashed, the application's webhook idempotency table (`webhook_events`) recognizes the duplicate delivery and skips processing, cementing the uncalculated metrics in `product_daily_metrics`.

---

## 3. Technical Architecture & Lifecycle Flow

```
Shopify Webhook / Sync Run
            │
            ▼
React Router Ingestion Gateway (S2S Signed Request)
            │
            ▼
Laravel Ingestion Pipeline:
  ├── 1. ResolveShopTenant
  │
  ├── 2. UpsertOrderBatch / UpsertRefundBatch
  │       │
  │       ├── Sort orders deterministically by shopify_order_gid
  │       ├── Sort line items deterministically by shopify_line_item_gid
  │       │
  │       ▼ [BEGIN DB::transaction]
  │       ├── Write orders / order_line_items / refund_line_items (GuardedUpsert with freshness check)
  │       ├── Prune removed line items (if incoming updated_at > existing updated_at)
  │       ├── Compute merged affected (product_id, metric_date) buckets
  │       ├── Insert pending outbox record:
  │       │     INSERT INTO outbox_events (id, shop_id, event_class, event_version, payload, status)
  │       │     VALUES (uuid7, shop_id, 'App\Events\OrdersIngested', 1, json, 'pending');
  │       ▼ [COMMIT DB::transaction] ──► Both Data AND Outbox Record are Persisted!
  │
  └── 3. DispatchIngestedEvents (Fast Path)
          ├── event($event) ──► QueueMetricAggregationsFromOrders ──► Pushes job to Redis queue
          └── UPDATE outbox_events SET status = 'published', dispatched_at = now() 
                WHERE id = :outbox_id AND status = 'pending';

─────────────────────────────────────────────────────────────────────────────────────────────
RECOVERY SAFETY-NET (Scheduled Console Sweeper with SKIP LOCKED)
Every Minute: `Schedule::command('outbox:publish --age=30 --limit=100')->everyMinute()->withoutOverlapping()`
  ├── [BEGIN DB::transaction]
  ├── SELECT * FROM outbox_events 
  │     WHERE status = 'pending' AND created_at <= (now - 30s)
  │     ORDER BY created_at ASC LIMIT 100 
  │     FOR UPDATE SKIP LOCKED; ──► Concurrent sweepers skip locked records without blocking!
  ├── Reconstitutes: $event = OutboxService::reconstitute($record); (validates event_version)
  ├── event($event) ──► Re-pushes to Redis queue
  ├── UPDATE outbox_events SET status = 'published', dispatched_at = now() 
  │     WHERE id = :record_id AND status = 'pending';
  └── [COMMIT DB::transaction]

─────────────────────────────────────────────────────────────────────────────────────────────
STORAGE RETENTION & CLEANUP (Daily Maintenance)
Daily: `Schedule::command('outbox:prune --days=14')->daily()->withoutOverlapping()`
  └── DELETE FROM outbox_events 
        WHERE ctid IN (
            SELECT ctid FROM outbox_events 
            WHERE status = 'published' AND created_at <= (now - 14d) 
            LIMIT 500
        ); (Chunked loop via PostgreSQL ctid locator; pending rows strictly preserved)
```

---

## 4. Database Schema: `outbox_events`

```
                                   Table "public.outbox_events"
    Column     |            Type             | Collation | Nullable |           Default            
---------------+-----------------------------+-----------+----------+------------------------------
 id            | uuid                        |           | not null | 
 shop_id       | uuid                        |           | not null | 
 event_class   | character varying(255)      |           | not null | 
 event_version | integer                     |           | not null | 1
 payload       | jsonb                       |           | not null | 
 status        | character varying(32)       |           | not null | 'pending'::character varying
 dispatched_at | timestamp(0) with time zone |           |          | 
 created_at    | timestamp(6) with time zone |           |          | 
 updated_at    | timestamp(6) with time zone |           |          | 
Indexes:
    "outbox_events_pkey" PRIMARY KEY, btree (id)
    "outbox_events_shop_status_idx" btree (shop_id, status)
    "outbox_events_status_created_idx" btree (status, created_at)
Foreign-key constraints:
    "outbox_events_shop_id_foreign" FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
```

- **UUIDv7 Primary Key:** Generates time-ordered UUIDs to ensure sequential B-tree clustering in PostgreSQL, preventing index page fragmentation.
- **Tenant Scoping:** Foreign key with cascading delete references `shops(id)`, guaranteeing complete multi-tenant isolation.
- **Event Versioning:** Explicit `event_version` column guarantees that evolving event structures are validated and parsed according to the exact version under which they were recorded.
- **JSONB Payload:** Stores structured event arguments (`shop_id`, `order_ids`, `affected_buckets`) for deterministic reconstitution.
- **Index Optimization:** Compound index on `(status, created_at)` enables index-only scans for background sweeper queries looking for stranded pending events.

---

## 5. File-by-File Breakdown

### 1. `backend/database/migrations/2026_11_23_000013_create_outbox_events_table.php`
- **Role:** Database migration establishing the `outbox_events` table.
- **Features:** Strict tenant scoping, JSONB storage, microsecond timestamps (`timestampsTz(6)`), and status indexing.

### 2. `backend/database/migrations/2026_11_23_000014_add_event_version_to_outbox_events_table.php`
- **Role:** Database migration introducing `event_version` integer column (default `1`).
- **Features:** Clean reversible schema evolution for forward-compatible payload contracts.

### 3. `backend/routes/console.php`
- **Role:** Laravel Console routes and task scheduler definition.
- **Features:** Registers `outbox:publish --age=30 --limit=100` (every minute) and `outbox:prune --days=14` (daily) with `withoutOverlapping()`.

### 4. `backend/app/Ingestion/Services/OutboxService.php`
- **Role:** Core service managing outbox operations.
- **Methods:**
  - `record(string $shopId, object $event, int $eventVersion = 1): string`: Inserts pending outbox record with explicit versioning.
  - `markPublished(string $outboxId): void`: Marks a record as published by exact UUID with `WHERE status = 'pending'`.
  - `getPendingStrandedEvents(DateTimeInterface $cutoff, int $limit = 100, bool $lock = false): Collection`: Queries stranded records with optional `FOR UPDATE SKIP LOCKED`.
  - `reconstitute(object $outboxRecord): object`: Rebuilds strongly-typed domain events from JSON payloads, validating `event_version`.

### 5. `backend/app/Ingestion/Pipes/UpsertOrderBatch.php`
- **Role:** Pipeline pipe for order ingestion.
- **Changes:** Enforces deterministic alphabetical sorting by `shopify_order_gid` and `shopify_line_item_gid`, captures outbox UUIDs, and prunes removed line items when newer order updates arrive.

### 6. `backend/app/Ingestion/Pipes/UpsertRefundBatch.php`
- **Role:** Pipeline pipe for refund ingestion.
- **Changes:** Computes affected buckets, captures outbox UUIDs inside the atomic `DB::transaction(...)`, and appends them to `$context->outboxIds`.

### 7. `backend/app/Ingestion/Pipes/DispatchIngestedEvents.php`
- **Role:** Downstream pipeline pipe executing fast-path dispatch.
- **Changes:** Fires `event($event)` and immediately updates the specific outbox records to `published` using their exact UUIDs `$context->outboxIds`. Never uses broad shop-wide queries that could hijack concurrent pending events.

### 8. `backend/app/Console/Commands/PublishPendingOutboxEventsCommand.php`
- **Role:** Artisan console recovery command (`php artisan outbox:publish`).
- **Features:** Configurable `--age=30` threshold and `--limit=100` chunk size. Claims records inside an atomic `DB::transaction(...)` using `FOR UPDATE SKIP LOCKED`, preventing lock contention and duplicate worker execution.

### 9. `backend/app/Console/Commands/PrunePublishedOutboxEventsCommand.php`
- **Role:** Artisan maintenance cleanup command (`php artisan outbox:prune`).
- **Features:** Configurable `--days=14` retention window and `--chunk=500` chunk size. Safely cleans up expired `published` records using PostgreSQL `ctid` batch deletion while strictly preserving all `pending` and recent records.

### 10. Feature & Integration Test Suites
- `backend/tests/Feature/Console/OutboxScheduleTest.php`: Verifies console scheduler registrations, intervals, and overlapping guards.
- `backend/tests/Feature/Ingestion/OrderSnapshotConcurrencyTest.php`: Tests snapshot preservation, stale update rejection, and reversed batch ordering.
- `backend/tests/Feature/Ingestion/TransactionalOutboxEndToEndRecoveryTest.php`: End-to-end integration test verifying real queue dispatch, crash recovery, and duplicate replay idempotency.
- `backend/tests/Feature/Ingestion/OutboxWorkerSkipLockedConcurrencyTest.php`: Tests that concurrent worker transactions claim disjoint event sets without blocking.
- `backend/tests/Feature/Ingestion/OutboxEventVersioningTest.php`: Tests default and explicit version persistence and reconstitution validation.
- `backend/tests/Feature/Ingestion/PrunePublishedOutboxEventsCommandTest.php`: Tests retention thresholds, chunked cleanup, and status isolation.
- `backend/tests/Feature/Ingestion/OutboxConcurrencyTargetingTest.php`: Tests that fast-path publishing strictly targets the individual outbox UUID.
- `backend/tests/Feature/Ingestion/OutboxServiceTest.php`: Tests record, publish, query, and reconstitution contracts.
- `backend/tests/Feature/Ingestion/TransactionalOutboxOrderIngestionTest.php`: Tests order batch atomic outbox recording and rollback integrity.
- `backend/tests/Feature/Ingestion/TransactionalOutboxRefundIngestionTest.php`: Tests refund batch atomic outbox recording and rollback integrity.
- `backend/tests/Feature/Ingestion/PublishPendingOutboxEventsCommandTest.php`: Tests the CLI recovery workflow.

---

## 6. Edge Cases Handled

1. **Mid-Pipeline Process Crashes:**
   If a worker container crashes between the database transaction commit and event dispatching, the pending outbox record remains in PostgreSQL and is safely recovered by `outbox:publish`.
2. **Concurrent Request Race Protection (Single-ID Targeting):**
   Fast-path publishing marks records `published` strictly by their individual UUID (`WHERE id = :outboxId AND status = 'pending'`). Parallel requests for the same shop will never prematurely mark each other's pending outbox records as published.
3. **Lock-Order Deadlock Elimination:**
   By sorting orders and line items deterministically prior to database insertion, concurrent transactions writing overlapping sets of orders acquire PostgreSQL row locks in identical sequence, eliminating lock-order inversion deadlocks (`40P01 deadlocks detected`).
4. **Multi-Worker Contention (SKIP LOCKED):**
   When multiple recovery worker containers or crons run simultaneously, `FOR UPDATE SKIP LOCKED` guarantees each worker acquires a disjoint set of records without waiting on locks or re-publishing rows claimed by another worker.
5. **Schema Evolution & Version Drift:**
   Future payload structure changes increment `event_version`. Old workers reject unknown future schemas fast rather than corrupting in-memory structures or calculations.
6. **Transient Redis Down Time:**
   If Redis connection drops during ingestion, the outbox record is already saved in PostgreSQL. Once Redis recovers, `outbox:publish` flushes all pending events without data loss.
7. **Database Rollbacks & Constraint Failures:**
   If an order or refund batch triggers a database constraint failure (e.g. invalid currency format, missing foreign key), the outbox write is rolled back with the transaction, preventing phantom event dispatch.
8. **Idempotent Queue Replay:**
   Because downstream queue jobs (`AggregateProductDailyMetricsJob`) implement `ShouldBeUniqueUntilProcessing` and idempotent PostgreSQL upserts (`ON CONFLICT (shop_id, product_id, metric_date, currency_code) DO UPDATE`), re-publishing an outbox event produces 100% deterministic, mathematically identical results with zero duplicate rows.
9. **Storage Retention & Safe Chunked Cleanup:**
   `outbox:prune` purges only expired `published` records in bounded chunks, preventing PostgreSQL table bloat while ensuring active or uncalculated events are never pruned.

---

## 7. Verification Evidence

### Targeted Outbox & Recovery Test Suites
```bash
docker compose exec backend ./vendor/bin/pest \
  tests/Feature/Console/OutboxScheduleTest.php \
  tests/Feature/Ingestion/OrderSnapshotConcurrencyTest.php \
  tests/Feature/Ingestion/TransactionalOutboxEndToEndRecoveryTest.php \
  tests/Feature/Ingestion/OutboxWorkerSkipLockedConcurrencyTest.php \
  tests/Feature/Ingestion/OutboxEventVersioningTest.php \
  tests/Feature/Ingestion/PrunePublishedOutboxEventsCommandTest.php \
  tests/Feature/Ingestion/OutboxConcurrencyTargetingTest.php \
  tests/Feature/Ingestion/OutboxServiceTest.php \
  tests/Feature/Ingestion/PublishPendingOutboxEventsCommandTest.php \
  tests/Feature/Ingestion/TransactionalOutboxOrderIngestionTest.php \
  tests/Feature/Ingestion/TransactionalOutboxRefundIngestionTest.php
```

```
   PASS  Tests\Feature\Console\OutboxScheduleTest (1 test, 9 assertions)
   PASS  Tests\Feature\Ingestion\OrderSnapshotConcurrencyTest (3 tests, 25 assertions)
   PASS  Tests\Feature\Ingestion\TransactionalOutboxEndToEndRecoveryTest (3 tests, 34 assertions)
   PASS  Tests\Feature\Ingestion\OutboxWorkerSkipLockedConcurrencyTest (2 tests, 8 assertions)
   PASS  Tests\Feature\Ingestion\OutboxEventVersioningTest (4 tests, 14 assertions)
   PASS  Tests\Feature\Ingestion\PrunePublishedOutboxEventsCommandTest (3 tests, 13 assertions)
   PASS  Tests\Feature\Ingestion\OutboxConcurrencyTargetingTest (1 test, 7 assertions)
   PASS  Tests\Feature\Ingestion\OutboxServiceTest (4 tests, 26 assertions)
   PASS  Tests\Feature\Ingestion\PublishPendingOutboxEventsCommandTest (3 tests, 13 assertions)
   PASS  Tests\Feature\Ingestion\TransactionalOutboxOrderIngestionTest (2 tests, 12 assertions)
   PASS  Tests\Feature\Ingestion\TransactionalOutboxRefundIngestionTest (2 tests, 14 assertions)

  Tests:    28 passed (175 assertions)
  Duration: 1.84s
```

### Full Backend Test Suite
```bash
docker compose exec backend ./vendor/bin/pest
```
```
  Tests:    306 passed (3285 assertions)
  Duration: 7.72s
```

### Shopify App Test Suite
```bash
cd shopify-app && npm test
```
```
ℹ tests 168
ℹ suites 9
ℹ pass 168
ℹ fail 0
ℹ duration_ms 872.87ms
```
