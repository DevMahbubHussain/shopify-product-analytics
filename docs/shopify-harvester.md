# Shopify Harvester (Step 3, Group F)

Specification and architectural explainer of the historical synchronization harvester. This subsystem connects the Shopify Admin GraphQL pagination client with the domain normalizers and streams data securely into Laravel's `/internal/v1/ingestion/*` endpoints.

---

## 1. What This Feature Provides

The harvester orchestrates the end-to-end extraction and ingestion of a merchant's commerce data:

1. **`sync_runs` Lifecycle Management:**
   * Registers a sync run with Laravel (`POST /internal/v1/ingestion/sync-status`) upon starting.
   * Periodically updates cumulative records received and processed as pages complete.
   * Marks the run as `completed` on success or `failed` with error details upon failure.
2. **Strict Dependency Order Ingestion:**
   * **Stage 1 — Products:** Catalogue products and variants are harvested and upserted first (`/internal/v1/ingestion/products`).
   * **Stage 2 — Orders:** 60-day historical orders and line items are ingested second (`/internal/v1/ingestion/orders`).
   * **Stage 3 — Refunds:** Attributed return lines are ingested third (`/internal/v1/ingestion/refunds`).
   * *Rationale:* Laravel enforces relational integrity at write time. If a refund line item arrives before its parent order, Laravel rejects the batch with `409 Conflict ("Referenced orders are not ingested yet.")`.
3. **Compound Batching & Budget Guards:**
   * Products: Batched in chunks of $\le 100$ (`INGESTION_LIMITS.productsPerBatch`).
   * Orders: Batched in chunks of $\le 50$ orders AND $\le 250$ total line items (`INGESTION_LIMITS.lineItemsPerOrderBatch`). Orders containing large item lists are automatically split across requests.
   * Refunds: Batched in chunks of $\le 250$ lines (`INGESTION_LIMITS.refundLinesPerBatch`).
4. **Historical Window Scoping & Metadata Persistence:**
   * Automatically scopes order queries to the default 60-day window (`updated_at:>='{now - 60d}'`).
   * Saves the ISO 8601 `window_start` timestamp in `cursor_metadata` on the `sync_runs` record so downstream analytics reporting can reject queries requesting date ranges outside the harvested historical boundary.
   * *Important Distinction:* The GraphQL crawler filters by `updated_at` (capturing all orders created or updated within the last 60 days, including recently refunded older orders), whereas the analytics engine aggregates daily sales metrics grouped by `processed_at` (order placement date).
5. **Shopify Cost & Rate Pacing:**
   * Uses `paginate<TData, TNode>` with cost-aware leaky bucket pacing so high-volume catalogs never exceed Shopify GraphQL API rate limits.

---

## 2. Execution Architecture & Data Flow

```
[Shopify Admin GraphQL API]
           │
           │ (cost-paced cursor pagination)
           ▼
 [paginateGraphql Engine]
           │
           ├──► [Stage 1: harvestProducts]
           │         │
           │         ├─► completeProductVariants()  (only if variants truncated)
           │         ├─► normalizeProduct()
           │         └─► POST /internal/v1/ingestion/products (max 100)
           │
           └──► [Stage 2: harvestOrders]
                     │
                     ├─► completeOrderDetails()  (only if lines / refunds / refund lines truncated)
                     ├─► normalizeOrder()
                     ├─► chunkOrders() (max 50 orders / 250 line items)
                     ├─► POST /internal/v1/ingestion/orders
                     │
                     └─► [Stage 3: Refunds]
                           │
                           ├─► normalizeRefunds()
                           └─► POST /internal/v1/ingestion/refunds (max 250)
                                      ▲
                                      │ (Only after orders commit)
```

---

## 3. File Breakdown

| Step | File | Purpose |
|---|---|---|
| **F-1** | `app/features/catalog-sync/harvestProducts.server.test.ts` | Unit tests for catalogue streaming, batch partitioning, empty states, and failure propagation. |
| **F-2** | `app/features/catalog-sync/harvestProducts.server.ts` | Paginates through `PRODUCTS_QUERY`, normalizes items, and posts product batches to Laravel. |
| **F-3** | `app/features/order-sync/harvestOrders.server.test.ts` | Unit tests for order/refund streaming, dependency ordering, line item batch limits, and test order exclusion. |
| **F-4** | `app/features/order-sync/harvestOrders.server.ts` | Paginates through `ORDERS_QUERY`, normalizes orders and refunds, chunks orders within 250 line items, and posts orders then refunds. |
| **F-5** | `app/features/sync/syncOrchestrator.server.test.ts` | Integration tests for full synchronization lifecycle against Laravel sync-status tracking. |
| **F-6** | `app/features/sync/syncOrchestrator.server.ts` | Top-level coordinator managing sync run creation, sequential stage execution, and completion/failure reporting. |
| **R5-1** | `app/features/catalog-sync/productsQuery.server.ts`<br>`app/features/order-sync/ordersQuery.server.ts` | Field lists extracted to `VARIANT_FIELDS`, `LINE_ITEM_FIELDS`, `REFUND_FIELDS`, `REFUND_LINE_FIELDS` so follow-up queries select exactly what the page queries select. Generated page queries are unchanged (whitespace-normalized comparison). |
| **R5-2** | `app/features/catalog-sync/completeProductVariants.server.test.ts` | No-op for complete products; multi-page refetch; deleted product; stuck cursor. |
| **R5-3** | `app/features/catalog-sync/completeProductVariants.server.ts` | `PRODUCT_VARIANTS_QUERY` + `completeProductVariants`. |
| **R5-4** | `app/features/catalog-sync/harvestProducts.server.{test,}.ts` | Each product is completed before `normalizeProduct`; 101-variant product posts all 101. |
| **R5-5** | `app/features/order-sync/completeOrderDetails.server.test.ts` | No-op for complete and test orders; line items > 50; exactly 5 and 7 refunds; refund lines > 20, including on refetched refunds; deleted order / refund. |
| **R5-6** | `app/features/order-sync/completeOrderDetails.server.ts` | `ORDER_LINE_ITEMS_QUERY`, `ORDER_REFUNDS_QUERY`, `REFUND_LINE_ITEMS_QUERY` + `completeOrderDetails`. |
| **R5-7** | `app/features/order-sync/normalizeRefunds.server.{test,}.ts` | Refund-count guard removed (5+ refunds normalize); truncated refund-line guard kept as a harvester-bug safety net. |
| **R5-8** | `app/features/order-sync/harvestOrders.server.{test,}.ts` | Each order is completed before normalizing; a 60-line, 6-refund order is fully ingested. |
| **R5-9** | `app/features/order-sync/harvestOrders.server.{test,}.ts` | `chunkOrders` slices orders above 250 lines into single-order requests; a 600-line order goes as 250 / 250 / 100 and counts once. |
| **R5-10** | `backend/tests/Feature/Ingestion/OrderIngestionTest.php` | Pins the contract slicing relies on: three slices plus a resend leave one order with 600 unique line items. |

---

## 4. Key Engineering Details

### 4.1 Order Chunking Algorithm (`chunkOrders`)
Shopify orders may contain arbitrary quantities of line items. While Laravel accepts up to 50 orders per batch, it caps each ingestion request at 250 total line items to guarantee execution under 200ms.

`chunkOrders()` iterates through normalized orders and flushes a sub-batch whenever adding the next order would push the cumulative line count past 250 or the order count past 50.

**Oversized orders (> 250 line items) are sliced, not rejected.** The order is split into consecutive single-order requests of at most 250 line items, each repeating the same order header (`gid`, statuses, `shopify_updated_at`, ...):

```
600-line order  →  POST orders [#2: lines 1–250]     (counted)
                   POST orders [#2: lines 251–500]   (continuation, not counted)
                   POST orders [#2: lines 501–600]   (continuation, not counted)
```

This works because of two Laravel behaviours, both pinned by Pest tests:
* `UpsertOrderBatch` upserts line items by `(shop_id, shopify_line_item_gid)` and never deletes lines absent from a request, so slices accumulate.
* The `GuardedUpsert` freshness check is `incoming >= current`, so a repeated header with the same `shopify_updated_at` is written, not reported as stale.

Each slice stays at the normal ~60 ms validation cost. Raising Laravel's per-order cap instead was measured and rejected: validating one 2,000-line request took ~500 ms (684 ms end to end), breaking the 200 ms ingestion budget. Continuation slices travel alone and are excluded from `records_received` / `records_processed`, so one order counts once.

### 4.2 Sync Run Status Reporting
The sync run tracks progress directly in Laravel's PostgreSQL database:
* **Start:** `POST /internal/v1/ingestion/sync-status` with `sync_type: "initial"`, `status: "running"`.
* **Incremental Updates:** Updates cumulative counts (`records_received` and `records_processed`) after each stage.
* **Completion:** Marks `status: "completed"`.
* **Resilience:** Any unhandled error triggers an immediate `status: "failed"` update with `error_code: "HARVEST_FAILED"` and `error_summary` before rethrowing.

### 4.3 Completing Truncated Nested Lists

Page queries keep small nested sizes so a page stays under Shopify's 1,000-point query cost. When a node comes back truncated, it is completed with follow-up queries before normalization instead of failing the sync:

| Nested list | Admin API 2026-10 shape | Truncation signal | Follow-up |
|---|---|---|---|
| `Product.variants` (page: 100) | Connection | `pageInfo.hasNextPage` | `product(id:)` → `variants(first: 250, after:)`, paged (single product at root allows up to 2,048 variants) |
| `Order.lineItems` (page: 50) | Connection | `pageInfo.hasNextPage` | `order(id:)` → `lineItems(first: 250, after:)`, paged |
| `Order.refunds` (page: 5) | Plain list `[Refund!]!`; `first` only truncates, no cursor | `length >= 5` (a full page may hide more) | `order(id:)` → `refunds` with no `first`, which returns every refund |
| `Refund.refundLineItems` (page: 20) | Connection | `pageInfo.hasNextPage` | `refund(id:)` → `refundLineItems(first: 250, after:)`, paged |

* Follow-ups refetch the whole list from the start (nested page queries expose no inner `endCursor`), page at the 250 maximum, and go through `paginate` / `shopifyQuery`, so throttling and cost pacing apply.
* Complete nodes and test orders make zero extra calls; only oversized nodes pay for follow-ups, sequentially.
* Root `product`, `order`, and `refund` queries return nullable types. A `null` (deleted mid-sync) throws `ProductVanishedError` / `OrderDetailsVanishedError`; the run is marked `failed`, and a retry no longer lists the deleted resource.
* `normalizeProduct` / `normalizeOrder` / `normalizeRefunds` keep their `hasNextPage` guards as a safety net: they only fire if completion is bypassed.

---

## 5. Edge Cases Handled

| Scenario | Handled By | Behavior |
|---|---|---|
| **Empty Catalogue / Zero Orders** | `harvestProducts` / `harvestOrders` | Bypasses backend ingestion calls; completes with 0 records without errors. |
| **Orders with > 250 Line Items in total** | `chunkOrders` | Dynamically slices orders into multiple sub-batches to respect the 250 line item ceiling. |
| **Test Orders (`test: true`)** | `normalizeOrder` filter | Automatically skipped; zero test records reach Laravel. `completeOrderDetails` also skips them, so no follow-up queries are spent. |
| **Product with > 100 Variants** | `completeProductVariants` | All variants refetched from the product root and posted (up to 2,048, the Laravel limit). |
| **Order with > 50 Line Items** | `completeOrderDetails` | All line items refetched and posted. |
| **Order with Exactly 5 Refunds** | `completeOrderDetails` | One extra `OrderRefunds` query confirms the list; no longer a false "truncated" failure. |
| **Order with > 5 Refunds / Refund with > 20 Lines** | `completeOrderDetails` | Full refund list refetched; each truncated refund's lines paged from the refund root, including refunds that only appear in the refetch. |
| **Product / Order / Refund Deleted Mid-Sync** | `ProductVanishedError` / `OrderDetailsVanishedError` | Run fails with a clear message; retry succeeds. |
| **Single Order with > 250 Line Items** | `chunkOrders` / `sliceOrder` | Sent as single-order slices of ≤ 250 lines with the same header; Laravel accumulates them. No upper limit. If the sync fails between slices the order is briefly partial; the retry resends every slice. |
| **Shopify API Throttling** | `paginate` leaky bucket | Waits for bucket restoration automatically and resumes without failing. |
| **Orders Ingested After Refunds in Source Data** | `harvestOrders` stage separation | Orders are always submitted in an HTTP request that finishes before the refund lines HTTP request begins. |
| **Network or Backend Outage** | `runHistoricalSync` catch block | Sets `sync_run.status = "failed"` with diagnostic summary, preventing orphaned "running" states. |

---

## 6. Verification Evidence

* **Automated Tests:** **119 passed (0 failures)** in `shopify-app` via `npm test` (after truncation completion).
* **Type Safety:** **Clean exit (0 errors)** via `npm run typecheck` (`react-router typegen && tsc --noEmit`).
* **Linting:** **Clean exit (0 errors, 0 warnings)** via `npm run lint`.
* **Backend:** 168 Laravel Pest tests green, including the slice-accumulation contract test. No Laravel code changed.
* **Live Shopify probe:** Not yet run. Follow-up queries are verified against the 2026-10 reference docs and the unit tests only; their live `requestedQueryCost` is unmeasured.
