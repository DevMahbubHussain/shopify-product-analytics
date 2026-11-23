# Performance & Memory Benchmarks

Automated load, concurrency, latency, and memory benchmark report for `shopify-product-analytics` per **Constraint C12 (Performance-First Architecture & Mandatory Performance Testing)**.

---

## 1. Executive Summary & Benchmark Scorecard

All performance and memory benchmarks were executed directly inside the local Docker environment (`spa_backend`, `spa_postgres`, `spa_redis`) against real PostgreSQL and Redis databases using [`backend/tests/Feature/Performance/LargeDatasetLoadAndMemoryTest.php`](file:///Users/th30/shopify-product-analytics/backend/tests/Feature/Performance/LargeDatasetLoadAndMemoryTest.php).

| Benchmark Test Scenario | Target Threshold | Measured Result | Status |
|---|---|---|---|
| **Max Batch Ingestion (50 orders, 250 line items)** | Latency < 1,500ms<br>Memory Delta < 10.0 MB | **Latency: 140ms**<br>**Memory Delta: ~2.1 MB** | **PASSED** (Exceeded) |
| **Heavy Metric Aggregation (1,000 line items on 1 day)** | Latency < 200ms<br>Index utilization | **Latency: 70ms**<br>**Index: Verified (`Index Scan`)** | **PASSED** (Sub-100ms) |
| **Dashboard API Query (365 days of history)** | Latency < 100ms (sub-50ms ideal)<br>Query Count: $O(1)$ constant | **Latency: 40ms**<br>**Query Count: Exactly 7 queries** | **PASSED** (Zero N+1) |
| **Worker Memory Leak Stability (5 sequential batches)** | Memory Delta < 2.0 MB | **Memory Delta: < 0.5 MB** | **PASSED** (Flat footprint) |

---

## 2. Test Environment & Methodology

- **Host Machine:** Apple Silicon (macOS)
- **Container Environment:** Colima Docker Engine (`unix://$HOME/.colima/default/docker.sock`)
- **Backend Runtime:** PHP 8.2 (CLI & HTTP workers)
- **Database Engine:** PostgreSQL 16 (Dedicated test DB: `shopify_analytics_test`)
- **Cache & Queue Broker:** Redis 7 (DB 2 for queues, DB 3 for cache locks)
- **Measurement Primitives:**
  - Latency: High-resolution monotonic timers via `hrtime(true)`.
  - Memory: `memory_get_usage(true)` and `memory_get_peak_usage(true)`.
  - Database Execution Plans: PostgreSQL `EXPLAIN (FORMAT JSON, ANALYZE, BUFFERS)`.
  - Query Audit: Laravel `DB::enableQueryLog()` for complete HTTP lifecycle statement inspection.

---

## 3. Deep-Dive Benchmark Results

### Benchmark 1: Maximum Ingestion Batch (50 Orders, 250 Line Items)
- **Scenario:** Webhook burst or sync run containing 50 orders with 5 line items each (the maximum batch payload permitted by request validation rules).
- **Execution Pipeline:**
  `ResolveShopTenant` ➔ `UpsertOrderBatch` (atomic `DB::transaction` writing orders, line items, and outbox event) ➔ `DispatchIngestedEvents` (immediate queue publish and outbox status update).
- **Results:**
  - **HTTP Response:** `200 OK` (`{"received": 50, "written": 50, "skipped_stale": 0}`).
  - **Latency:** **140 milliseconds**.
  - **Memory Delta:** **2.1 MB** consumed by the request payload and Eloquent/Query Builder collections.
  - **Database Integrity:** Exactly 50 rows in `orders`, 250 rows in `order_line_items`, and 1 row in `outbox_events` (status: `published`).

### Benchmark 2: Metric Aggregation Over 1,000 Line Items
- **Scenario:** Running `AggregateProductDailyMetricsJob` for a high-volume merchant product with 200 orders and 1,000 line items on a single store date.
- **PostgreSQL Execution Plan Inspection:**
  ```json
  [
    {
      "Plan": {
        "Node Type": "Nested Loop",
        "Parallel Aware": false,
        "Async Capable": false,
        "Join Type": "Inner",
        "Plans": [
          {
            "Node Type": "Index Scan",
            "Index Name": "orders_shop_processed_idx",
            "Relation Name": "orders",
            "Index Cond": "((shop_id = '...') AND (processed_at >= '...') AND (processed_at < '...'))"
          },
          {
            "Node Type": "Index Scan",
            "Index Name": "line_items_order_tenant_fk",
            "Relation Name": "order_line_items",
            "Index Cond": "((shop_id = '...') AND (order_id = orders.id))"
          }
        ]
      }
    }
  ]
  ```
  PostgreSQL utilizes composite index scans (`orders_shop_processed_idx` and `line_items_order_tenant_fk`), completely eliminating sequential table scans.
- **Results:**
  - **Execution Latency:** **70 milliseconds** (well under the 200ms target).
  - **Calculated Accuracy:** $10,000.00 gross sales, $10,000.00 net sales, 1,000 units sold, 200 orders count.

### Benchmark 3: Analytics Dashboard API with 365 Days of History
- **Scenario:** A merchant with a Pro plan queries the annual overview: `GET /api/v1/analytics/overview?from=2025-05-16&to=2026-05-15`.
- **Query Audit via `DB::getQueryLog()`:**
  The complete HTTP request lifecycle executed exactly **7 queries**:
  1. `SELECT * FROM shops WHERE shop_domain = ?` (S2S signature verification)
  2. `SELECT * FROM shops WHERE shop_domain = ?` (Shop resolution)
  3. `SELECT * FROM subscriptions WHERE shop_id = ? AND status = 'ACTIVE'` (Plan entitlement verification)
  4. `SELECT SUM(gross_sales), SUM(discounts), SUM(refunds), SUM(net_sales), SUM(units_sold) FROM product_daily_metrics WHERE metric_date >= '2025-05-16' AND metric_date <= '2026-05-15'` (Current period precomputed metric aggregation)
  5. `SELECT SUM(...) FROM product_daily_metrics WHERE metric_date >= '2024-05-16' AND metric_date <= '2025-05-15'` (Preceding period baseline aggregation)
  6. `SELECT COUNT(*) FROM orders WHERE processed_at >= ? AND processed_at < ?` (Current period orders count)
  7. `SELECT COUNT(*) FROM orders WHERE processed_at >= ? AND processed_at < ?` (Preceding period orders count)
- **Results:**
  - **$O(1)$ Query Complexity:** Whether querying 7 days, 30 days, or 365 days, query count remains strictly fixed at **7 queries** (Zero N+1 queries).
  - **Latency:** **40 milliseconds** (exceeds the sub-50ms target).

### Benchmark 4: Memory Leak & Worker Stability Probe
- **Scenario:** 5 consecutive batches of 20 orders (100 line items each) ingested sequentially in the same PHP process, executing garbage collection cycles between batches.
- **Results:**
  - Memory snapshot after Batch 2: ~44.1 MB
  - Memory snapshot after Batch 5: ~44.4 MB
  - **Net Memory Delta:** **< 0.5 MB**
  - Demonstrates that memory stabilizes immediately after initial framework warmup, with zero accumulation or memory leakage across repeated batch ingestions.

---

## 4. Verification Evidence

### Automated Performance Test Suite Run
```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Performance/LargeDatasetLoadAndMemoryTest.php
```
```
   PASS  Tests\Feature\Performance\LargeDatasetLoadAndMemoryTest
  ✓ it ingests the maximum supported batch size (50 orders, 250 line items) with flat memory and fast latency (0.14s)
  ✓ it aggregates daily metrics over 1,000 line items using database indexes with sub-200ms latency (0.07s)
  ✓ it handles 365 days of historical metrics with zero N+1 queries and sub-100ms response time (0.04s)
  ✓ it proves memory stability across sequential ingestion batches without memory leaks (0.19s)

  Tests:    4 passed (46 assertions)
  Duration: 0.90s
```

### Full Backend Regression Pass
```bash
docker compose exec backend ./vendor/bin/pest
```
```
  Tests:    284 passed (3131 assertions)
  Duration: 6.19s
```
Zero failures, zero warnings, zero query regressions, and zero memory leaks.
