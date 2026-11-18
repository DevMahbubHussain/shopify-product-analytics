# Ingestion Pipeline — Feature Explainer

Step 2 of the roadmap. This document explains what the ingestion feature provides, how a request flows through it, and what every file does. The wire contract for callers (payload shapes, status codes) is in `docs/ingestion-api.md`.

---

## 1. What This Feature Provides

The Shopify integration layer (`shopify-app`) reads data from Shopify: shop details, products, orders, refunds. It cannot write that data to PostgreSQL itself, because only Laravel owns the database (constraint C2). This feature is the **door through which Shopify data enters Laravel**.

It gives `shopify-app` six private, signed HTTP endpoints:

| Endpoint | Plain-language purpose |
|---|---|
| `POST /internal/v1/ingestion/shop` | "This store installed the app" / "here are its updated details" |
| `POST /internal/v1/ingestion/products` | "Here is a page of products and their variants" |
| `POST /internal/v1/ingestion/orders` | "Here is a page of orders and their line items" |
| `POST /internal/v1/ingestion/refunds` | "Here are refunded line items" |
| `POST /internal/v1/ingestion/webhook-events` | "I received this webhook; have I seen it before?" |
| `POST /internal/v1/ingestion/sync-status` | "A sync started / progressed / finished / failed" |

Guarantees every endpoint gives:

1. **Safe to retry.** Sending the same batch twice never creates duplicate rows.
2. **Order-safe.** If an older version of a product or order arrives after a newer one, the older one is ignored.
3. **Tenant-safe.** Data is always written to the store named in the signed header; nothing in the body can redirect it to another store.
4. **Strict input.** Malformed data is rejected with `422` before anything is written: wrong GID types, money sent as numbers instead of strings, timestamps without a timezone, unknown status values.
5. **Fast.** Each request stays under the 200 ms budget (C9.6); heavy work (metric aggregation) is handed off through events.

What it does **not** do yet:
- Calculate metrics. It fires `OrdersIngested` / `RefundsIngested` events; the aggregation jobs that listen to them are Step 4.
- Handle product deletion, removed variants, or app uninstall. Those are webhook-driven and belong to Step 3.

---

## 2. Request Flow

```
shopify-app (signs request)
   │  POST /internal/v1/ingestion/orders   + X-App-* signature headers
   ▼
AssignCorrelationId middleware        tags the request with a correlation id
   ▼
VerifyS2SSignature middleware (s2s)   checks HMAC, timestamp, single-use nonce
   │                                  → sets request attribute shop_domain
   ▼
throttle:s2s-ingestion                per-shop rate limit in Redis
   ▼
IngestOrdersRequest (FormRequest)     validates the JSON → 422 on bad input
   │  ->toDto()
   ▼
OrderBatch DTO                        typed, immutable copy of the input
   ▼
IngestionController::orders()         builds IngestionContext, runs the pipeline
   ▼
Pipeline
   ├─ ResolveShopTenant               domain → Shop row (404 unknown, 403 inactive)
   ├─ UpsertOrderBatch                one transaction: upsert orders (stale skipped),
   │                                  look up product/variant ids, upsert line items
   └─ DispatchIngestedEvents          fires OrdersIngested after the transaction commits
   ▼
BatchResult → JSON {"received": 25, "written": 25, "skipped_stale": 0}
```

Every endpoint follows this shape; only the FormRequest, DTO, and middle pipe change.

---

## 3. File-by-File Breakdown

Files are grouped by layer, in the order a request touches them.

### Layer 1 — Wiring

#### `routes/api.php` (changed)
Registers the six `POST` routes inside the existing `/internal/v1` group. The group already applies `s2s`; this feature adds `throttle:s2s-ingestion` and points each route at an `IngestionController` method.

#### `bootstrap/app.php` (changed)
Two additions:
- `throttleWithRedis()` makes the `throttle` middleware count requests in Redis (atomic, shared across processes) as C9.4 requires.
- `prependToPriorityList(before: [ThrottleRequests…], prepend: VerifyS2SSignature)` forces signature checking to run **before** rate limiting. Without this, Laravel's default priority list ran the limiter first, when the shop was still unknown, so every store shared one quota. The rate-limit test caught this bug.

#### `app/Providers/AppServiceProvider.php` (changed)
Defines the `s2s-ingestion` rate limiter: N requests per minute **per verified shop domain**, where N comes from config.

#### `config/services.php` (changed)
Adds `services.s2s.ingestion_per_minute` (env `S2S_INGESTION_PER_MINUTE`, default 600).

---

### Layer 2 — Input Validation (FormRequests)

Each endpoint has one FormRequest. It declares validation rules and offers `toDto()` to convert validated input into a typed object (C14). Laravel runs validation automatically and returns `422` with field errors if anything is wrong.

#### `app/Http/Requests/Internal/IngestionRequest.php`
Abstract base for all six requests. Provides `authorize(): true` (authentication already happened in the `s2s` middleware) and `shopDomain()`, which reads the **verified** domain set by the middleware, never a value from the body.

#### `IngestShopRequest.php`
Validates shop metadata. It copies the verified header domain into the input as `shop_domain`, overwriting any `shop_domain` in the body, and requires it to look like `name.myshopify.com`. Validates ISO currency (`USD`), IANA timezone (`America/New_York`), and an optional `Shop` GID.

#### `IngestProductsRequest.php`
1–100 products. Per product: `Product` GID (unique within the batch), title, handle, `status` from the Shopify `ProductStatus` enum, timestamps with timezone, and a `variants` list with `ProductVariant` GIDs and string prices.

#### `IngestOrdersRequest.php`
1–50 orders. Per order: `Order` GID, Shopify financial and fulfillment status enums, currency, timestamps, and line items (`LineItem` GID, optional product/variant GIDs, quantity ≥ 1, string unit price and discount). Its `after()` hook enforces **at most 250 line items per request in total**: validation costs about 0.3 ms per line, and 250 lines keeps the request near 125 ms.

#### `IngestRefundsRequest.php`
1–250 refund lines, each with `Refund`, `RefundLineItem` (required, unique), and `Order` GIDs, an optional `LineItem` GID, quantity ≥ 1, string subtotal, currency, and timestamp.

#### `RecordWebhookDeliveryRequest.php`
Webhook ID, topic in `resource/action` form (`orders/create`), SHA-256 hex hash of the raw body, and receipt time.

#### `ReportSyncStatusRequest.php`
Optional `sync_run_id` (omitted means "create a new run"), `sync_type` (required when creating), `status`, non-negative counters, optional cursor JSON, and an `error_code` that is required when status is `failed`.

---

### Layer 3 — Shared Rules and Value Objects

#### `app/Support/IngestionRules.php`
One place for the repeated validation rules so every request uses identical rules:
- `gid('Product')` → must match `gid://shopify/Product/<digits>`
- `amount()` → string decimal, max 14 integer and 6 fractional digits
- `timestamp()` → ISO 8601 with `Z` or `±HH:MM`
- `currency()` → three uppercase letters
- `toUtc()` → parses a timestamp and converts it to UTC

#### `app/Support/ShopifyGid.php`
Value object for a Shopify global ID. `ShopifyGid::parse($value, 'Order')` refuses anything that isn't an Order GID, so a product ID can never be passed where an order ID is expected. Converts back to the string form when stored.

#### `app/Support/DecimalAmount.php`
Value object for money. It holds the amount as a **string**, never a float, so `19.99` stays exactly `19.99` (C5). It rejects negatives and more than 6 decimal places.

---

### Layer 4 — DTOs (`app/Data/Ingestion/`)

Read-only typed objects built from validated input. Once built, they cannot change, and every field has a precise type (enum, value object, `CarbonImmutable` in UTC). The rest of the feature never touches raw request arrays (C14).

| File | Holds |
|---|---|
| `ShopData.php` | Verified domain, optional shop GID, currency, timezone |
| `ProductBatch.php` / `ProductData.php` / `VariantData.php` | A list of products, each with its fields and list of variants |
| `OrderBatch.php` / `OrderData.php` / `LineItemData.php` | A list of orders, each with status enums, UTC timestamps, and line items |
| `RefundBatch.php` / `RefundLineData.php` | A list of refund lines |
| `WebhookDeliveryData.php` | One webhook delivery |
| `SyncStatusData.php` | One sync status report |

Each has a `fromArray()` factory, the only way it is built.

---

### Layer 5 — Enums (`app/Enums/`)

Fixed sets of allowed values (C11). Shopify-sourced ones were checked against the Admin API `2026-10` docs.

| File | Values | Source |
|---|---|---|
| `ProductStatus.php` | `ACTIVE`, `ARCHIVED`, `DRAFT`, `UNLISTED` | Shopify |
| `OrderFinancialStatus.php` | `AUTHORIZED` … `VOIDED` (8) | Shopify |
| `OrderFulfillmentStatus.php` | `FULFILLED` … `UNFULFILLED` (11) | Shopify |
| `ShopStatus.php` | `active`, `uninstalled`, `suspended` | App |
| `SyncType.php` | `initial`, `incremental`, `reconciliation` | App |
| `SyncRunStatus.php` | `pending`, `running`, `completed`, `failed`, plus `canTransitionTo()` and `isTerminal()` | App |
| `WebhookEventStatus.php` | `received`, `processed`, `failed` | App |

`SyncRunStatus::canTransitionTo()` is the single source of truth for allowed status changes: a finished run can never be reopened.

---

### Layer 6 — Controller

#### `app/Http/Controllers/Internal/IngestionController.php`
Six short methods, one per endpoint. Each takes its FormRequest, converts it to a DTO, and runs a list of pipes:

| Method | Pipes |
|---|---|
| `shop` | `UpsertShop` |
| `products` | `ResolveShopTenant` → `UpsertProductBatch` → `DispatchIngestedEvents` |
| `orders` | `ResolveShopTenant` → `UpsertOrderBatch` → `DispatchIngestedEvents` |
| `refunds` | `ResolveShopTenant` → `UpsertRefundBatch` → `DispatchIngestedEvents` |
| `webhookEvents` | `ResolveShopTenant` → `DeduplicateWebhookDelivery` |
| `syncStatus` | `ResolveShopTenant` → `RecordSyncStatus` |

The private `run()` method sends an `IngestionContext` through Laravel's `Pipeline` and turns the resulting response DTO into JSON. The controller contains no business logic (C9.1).

---

### Layer 7 — Pipeline Infrastructure (`app/Ingestion/`)

#### `IngestionContext.php`
The object passed from pipe to pipe. It carries:
- `shopDomain` and `payload` (the DTO), fixed at creation
- `shop`, filled in by `ResolveShopTenant`
- `events`, queued by upsert pipes and fired later
- `response`, set by the pipe that produces the result

Helper methods fail loudly if a pipe runs out of order, e.g. `shop()` throws if no tenant was resolved.

#### `IngestionResponse.php`
Interface every response DTO implements: JSON-serializable, plus an HTTP `status()`.

#### `Responses/BatchResult.php`
`{"received", "written", "skipped_stale"}` for products, orders, and refunds. `skipped_stale = received − written`.

#### `Responses/ShopResult.php`, `WebhookDeliveryResult.php`, `SyncStatusResult.php`
Response shapes for the other endpoints. `SyncStatusResult` returns `201` when a run was created and `200` when updated.

#### `GuardedUpsert.php`
The core write helper, used by every batch pipe. Given rows, it runs one PostgreSQL statement:

```sql
INSERT INTO orders (...) VALUES (...), (...), ...
ON CONFLICT (shop_id, shopify_order_gid) DO UPDATE SET ... = excluded....
  WHERE orders.shopify_updated_at IS NULL
     OR excluded.shopify_updated_at >= orders.shopify_updated_at   -- freshness guard
RETURNING id, shopify_order_gid
```

What that achieves:
- **New row** → inserted, with a fresh time-ordered UUIDv7 `id`.
- **Existing row, newer data** → updated in place; `id` and `created_at` stay the same.
- **Existing row, older data** → untouched, and not returned, which is how `skipped_stale` is counted.
- One statement per chunk instead of one per row (chunked under PostgreSQL's 65,535-parameter limit).
- Timestamps are bound with an explicit UTC offset, so the database session timezone can't shift them.

#### `TenantIdLookup.php`
Converts Shopify GIDs into internal row IDs (`gid://shopify/Product/1` → `0199…uuid`), **always filtered by `shop_id`**, so one store's GIDs can never resolve to another store's rows. Uses the `(shop_id, gid)` unique indexes (0.12 ms for 50 GIDs against 50,000 products).

---

### Layer 8 — Pipes (`app/Ingestion/Pipes/`)

Each pipe does one job, then hands the context to the next pipe.

#### `ResolveShopTenant.php`
Looks up the shop by verified domain. Unknown → `404 Unknown shop.`; not active → `403 Shop is not active.`; otherwise stores the `Shop` on the context. Every tenant-scoped pipe depends on this.

#### `UpsertShop.php`
Used only by the `shop` endpoint (no tenant exists yet).
1. Tries `INSERT … ON CONFLICT DO NOTHING` for a new active shop. This is race-safe if two installs arrive at once.
2. If the shop already existed: locks the row, updates currency/timezone/GID, and if it was uninstalled, reactivates it (`installed_at = now`, `uninstalled_at = null`).
All inside `DB::transaction(…, 5)` (deadlock retry, C9.3).

#### `UpsertProductBatch.php`
In one transaction:
1. Guarded upsert of products on `(shop_id, shopify_product_gid)` with the `shopify_updated_at` freshness guard.
2. Upsert of variants on `(shop_id, shopify_variant_gid)`, **only for products that were written**. A stale product's variants are skipped with it.

#### `UpsertOrderBatch.php`
In one transaction:
1. Guarded upsert of orders on `(shop_id, shopify_order_gid)`.
2. For orders that were written: looks up product and variant IDs from the line items' GIDs (2 queries for the whole batch, not one per line).
3. Upserts line items on `(shop_id, shopify_line_item_gid)`. Each line takes its order's currency. An unknown or missing product GID is stored as `null` (custom items such as gift wrap have no product).
4. Queues an `OrdersIngested` event listing the written order IDs.

The test enforces at most 12 queries per request regardless of batch size.

#### `UpsertRefundBatch.php`
1. Looks up all referenced orders. If any is missing → `409` with `missing_order_gids`, and **nothing** is written. The caller ingests those orders and retries.
2. Looks up referenced line items (`null` if unknown).
3. In one transaction, upserts refund lines on `(shop_id, shopify_refund_line_item_gid)`.
4. Queues `RefundsIngested` with the affected order IDs.

#### `DeduplicateWebhookDelivery.php`
`INSERT … ON CONFLICT DO NOTHING` on `(shop_id, webhook_id)`. One row inserted → first delivery (`duplicate: false`); zero rows → a repeat (`duplicate: true`). The database's unique constraint makes this race-safe.

#### `RecordSyncStatus.php`
- **No `sync_run_id`:** creates a run; sets `started_at` when the status is past `pending`.
- **With `sync_run_id`:** locks the run (scoped to this shop, so another shop's run returns `404`), rejects illegal status changes (`409`) and counters that decrease (`422`), then updates. Sets `completed_at` when the run reaches `completed` or `failed`.

#### `DispatchIngestedEvents.php`
Fires the queued events. It runs as the **last** pipe, after the upsert transaction has committed, so a listener can never see half-written data.

---

### Layer 9 — Errors (`app/Ingestion/Exceptions/`)

#### `IngestionException.php`
Base class. Each subclass defines an HTTP status and optional extra JSON fields. Its `render()` produces `{"error": "...", ...details}`, which Laravel uses automatically.

| File | Status | Body |
|---|---|---|
| `UnknownShop.php` | 404 | `Unknown shop.` |
| `InactiveShop.php` | 403 | `Shop is not active.` |
| `MissingOrders.php` | 409 | `Referenced orders are not ingested yet.` + `missing_order_gids` |
| `UnknownSyncRun.php` | 404 | `Unknown sync run.` |
| `IllegalSyncTransition.php` | 409 | `Illegal sync status transition.` + `from`, `to` |

---

### Layer 10 — Events and Models

#### `app/Events/OrdersIngested.php`, `RefundsIngested.php`
Plain read-only messages: `shopId` plus the affected `orderIds`. They are explicit domain events (C9.5). In Step 4, queued listeners will use them to recalculate `product_daily_metrics` for the affected products and days.

#### `app/Models/Shop.php`
Eloquent model for `shops`, with UUIDv7 keys and `status` cast to `ShopStatus`. Used by `ResolveShopTenant` and `UpsertShop`.

#### `app/Models/SyncRun.php`
Eloquent model for `sync_runs`, with enum casts for type and status and a JSON cast for `cursor_metadata`. Used by `RecordSyncStatus`.

Batch tables (`products`, `orders`, etc.) are written through `GuardedUpsert`, which needs raw `ON CONFLICT … WHERE`, so they have no models yet. Models arrive with the analytics read layer (Step 5).

---

### Layer 11 — Tests

#### `tests/Pest.php` (changed)
Adds `s2sPost($path, $body, $shopDomain)`, which signs a request exactly as `shopify-app` does (headers, canonical string, HMAC, nonce). Every ingestion test goes through the real middleware stack.

#### `tests/Support/ingestion.php`
Payload builders (`productPayload()`, `orderPayload()`, `lineItemPayload()`, `refundLinePayload()`) and `installShop()`. Tests override only the fields they care about.

| Test file | Covers |
|---|---|
| `ShopIngestionTest.php` | Install, idempotent refresh, reactivation, invalid metadata, non-myshopify domain, unsigned request |
| `ProductIngestionTest.php` | Insert, retry with zero duplicates, stale skip, newer update, header-over-body tenancy, unknown/inactive shop, 6 malformed-input cases, duplicate GIDs, batch limits |
| `OrderIngestionTest.php` | Product/variant linking, UTC conversion, custom/unknown products, retry, stale skip including line items and no event, 6 malformed-input cases, duplicate line GIDs, 250-line cap, query count |
| `RefundIngestionTest.php` | Linking, retry, 409 with nothing written, cross-shop order isolation, 4 malformed-input cases |
| `WebhookAndSyncIngestionTest.php` | First vs duplicate delivery, per-shop webhook IDs, malformed deliveries, sync create/progress/complete, terminal-state 409, counter regression, failed requires error code, cross-shop sync run 404, per-shop rate limit |

---

## 4. Edge Cases Handled

| Situation | Behaviour |
|---|---|
| Same batch sent twice | Zero duplicate rows; same IDs kept |
| Older update arrives after newer | Skipped (`skipped_stale`); child rows untouched; no event |
| Body contains another shop's id | Ignored; tenant comes only from the signed header |
| Store never installed / uninstalled | `404` / `403`, nothing written |
| Money sent as JSON number (`19.99`) | `422` (float precision risk) |
| More than 6 decimal places | `422` |
| Timestamp without timezone | `422` |
| `-05:00` offset | Stored as UTC (`12:00-05:00` → `17:00Z`) |
| Line item for a custom item or unknown product | Stored with `null` product/variant reference |
| Refund before its order | `409` + list of missing orders; nothing written |
| Same GID twice in one batch | `422` (would otherwise break the single-statement upsert) |
| Webhook redelivered | `duplicate: true`, single row |
| Sync run reopened after completion | `409` |
| Sync counters go backwards | `422` |
| One shop floods requests | Only that shop is throttled (`429`) |
| Unsigned flood | Rejected by signature check before it touches any quota |

---

## 5. Verification (2026-11-18)

**Automated:** 137 Pest tests pass (571 assertions), including 58 ingestion tests, all written before the code (TDD).

**Live HTTP** (signed requests from the `shopify-app` container to the running backend, dev database):

| Request | Latency | Result |
|---|---|---|
| 100 products + 100 variants | 64 ms | 100 written |
| 25 orders / 250 line items | 125 ms | 25 written, 250 lines |
| Same orders retried | 94 ms | 25 written, still 250 lines |
| 20 refund lines (and retry) | 20 ms | 20 rows, no duplicates |
| 5 stale orders | 27 ms | 0 written, 5 skipped |
| Webhook first / repeat | 15 / 13 ms | `false` / `true` |

**Database inspection:** exact row counts after retries, every line item linked to its product, UTC conversion confirmed, GID lookups on the `(shop_id, gid)` unique index at 50,000 products, no errors in logs. Probe data was removed afterwards; the shop delete cascaded to 0 rows.

---

## 6. Suggested Reading Order

To learn the feature from the code, read in this order:

1. `routes/api.php`: the six endpoints
2. `IngestionController.php`: which pipes each endpoint runs
3. `IngestOrdersRequest.php` → `OrderData.php`: input to typed object
4. `ResolveShopTenant.php`: how the tenant is found
5. `UpsertOrderBatch.php` → `GuardedUpsert.php`: how data is written safely
6. `OrderIngestionTest.php`: the behaviour, expressed as tests
