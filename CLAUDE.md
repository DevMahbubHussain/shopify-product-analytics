# CLAUDE.md

Binding engineering rules live in `AGENTS.md` and are imported verbatim below. They override every default. This file adds product context (from `PRD/PRD.md`), architecture context (from `docs/system-design.html`), and the verified current state of the repository.

@AGENTS.md

---

## 1. Source Documents

| File | Role | Read when |
|---|---|---|
| `AGENTS.md` | Binding constraints C1–C14, delivery roadmap Steps 1–7 | Always (imported above) |
| `PRD/PRD.md` | Product scope, metric definitions, data model, API surface, open decisions | Any feature, schema, or API work |
| `docs/system-design.html` | HLD topology, ingestion pipeline LLD, ERD, sequence flows | Architecture, ingestion, schema, queue work |
| `README.md` | Local setup (Colima + Docker), S2S handshake probe | Environment setup |

Precedence on conflict: `AGENTS.md` > `PRD/PRD.md` > `docs/system-design.html` > `README.md`. Surface any conflict to the user instead of silently picking one.

---

## 2. Product in One Paragraph

Embedded Shopify public app (lives inside Shopify Admin, installed and billed via Shopify) giving merchants product- and variant-level sales analytics: gross sales, discounts, refunds, net sales, units sold, orders, trends, rankings, period comparison, CSV export. Not a BI tool, not visitor/session tracking, not profit reporting, never mutates Shopify data. First goal: numbers merchants trust, fast reports, simple UX.

### MVP scope (PRD §5)
- **P0:** install/auth, initial sync, overview, product performance table, date filters, product detail, sync status.
- **P1:** product/variant filters, previous-period comparison, CSV export, refund-aware reporting, subscription plans, multi-currency display (store currency only, no cross-currency sums).
- **Non-goals:** sessions/pageviews/conversion rate, profit, modifying products/orders/inventory, other platforms, standalone dashboard outside Admin.

---

## 3. Architecture

```
Shopify Admin (App Bridge iframe + Polaris)
        │
        ▼
React Router app  (shopify-app/, Node 20, port 3000)
  - OAuth + session storage (tokens NEVER leave this container)
  - GraphQL Admin API harvester (cursor pagination first: 50, cost-throttled)
  - Webhook receiver (verify X-Shopify-Hmac-Sha256, 200 OK < 2s)
  - Billing mutations (appSubscriptionCreate)
  - S2S signer → Laravel
        │  HMAC-SHA256 signed, ±300s replay window
        ▼
Laravel REST API  (backend/, PHP 8.3, port 8000)
  - /internal/v1/*  S2S ingestion (signed)
  - /api/v1/*       analytics read endpoints
        │
        ├── PostgreSQL 16  (multi-tenant, product_daily_metrics aggregates)
        └── Redis 7        (queues, cache, atomic locks, rate limits)
                 │
                 ▼
        laravel-worker  (aggregation, exports, reconciliation)
```

Rule: Shopify GraphQL talks to Shopify. Laravel REST talks to our backend. React Router bridges both. Laravel never calls Shopify and never stores access tokens. Laravel workers never call Shopify with copied tokens.

### Flows (PRD §10.4, system-design §5)
- **Initial sync:** RR authenticates shop → harvests products, variants, orders (60-day window), refunds → normalizes → POSTs batches to `/internal/v1/ingestion/*` → Laravel validates, upserts by Shopify GID, dispatches aggregation, updates `sync_runs`.
- **Webhook:** Shopify → RR verifies HMAC → 200 OK → signed forward to Laravel → `webhook_events` dedupe → `DB::transaction(upsert, 5)` → dispatch `AggregateMetricsJob` → worker takes `WithoutOverlapping(shop:product:date)` lock → recompute → upsert `product_daily_metrics`.
- **Dashboard:** RR validates session → calls Laravel `/api/v1/*` → Laravel reads only `product_daily_metrics` via `(shop_id, metric_date)` index → JSON → Polaris UI.

### Ingestion pipeline (system-design §3)
`Illuminate\Pipeline\Pipeline` with discrete pipes:
1. `VerifyHmacSignature` (S2S HMAC, ±300s)
2. `ResolveShopTenant` (shop_domain → shop_id UUID)
3. `DeduplicatePayload` (webhook_id + order GID)
4. `UpsertNormalizedBatch` (`DB::transaction(fn() => ..., 5)`, `ON CONFLICT UPDATE`)
5. `DispatchMetricAggregation` (`ShouldBeUnique` + `WithoutOverlapping`)

---

## 4. Analytics Definitions (PRD §8)

| Metric | Definition |
|---|---|
| Gross sales | Σ(quantity × original_unit_price), before discounts and returns |
| Discounts | Discounts allocated to included line items |
| Refunds | Refunded line-item subtotal attributable to the product |
| Net sales | Gross − Discounts − Refunds (may be negative) |
| Units sold | Purchased units − refunded units |
| Orders | Distinct eligible orders with ≥1 included line item |
| Avg product revenue / order | Net sales ÷ distinct orders containing product |

- Never mix tax, shipping, or duties into product revenue.
- Comparison: preceding period of equal length; calendar presets (last month) compare to previous calendar month. Zero previous → show "New" / "No previous data", never divide by zero.
- Date presets: Today, Yesterday, 7D, 30D, 90D, This month, Last month, Custom. Boundaries in store timezone; timestamps stored UTC; `metric_date` is store-local. Reject ranges outside imported history.
- Currency: store reporting currency for MVP. Every amount carries ISO 4217 code. Never sum across currencies.

---

## 5. Data Model (PRD §11, system-design §4)

All PKs UUID. All timestamps `TIMESTAMPTZ`. Money `DECIMAL(20,6)`. Every tenant table has `shop_id`.

| Table | Key columns | Constraints / indexes |
|---|---|---|
| `shops` | shop_domain, shopify_shop_gid, currency_code CHAR(3), timezone, status (active/uninstalled/suspended), installed_at, uninstalled_at | UNIQUE shop_domain |
| `products` | shopify_product_gid, title, handle, vendor, product_type, status, image_url, published_at, shopify_updated_at | UNIQUE (shop_id, shopify_product_gid) |
| `product_variants` | product_id, shopify_variant_gid, title, sku, price | UNIQUE (shop_id, shopify_variant_gid) |
| `orders` | shopify_order_gid, order_name, financial_status, fulfillment_status, currency_code, processed_at, cancelled_at, shopify_updated_at | UNIQUE (shop_id, shopify_order_gid); INDEX (shop_id, processed_at) |
| `order_line_items` | order_id, product_id NULL, variant_id NULL, shopify_line_item_gid, title_snapshot, sku_snapshot, quantity, original_unit_price, discount_amount, currency_code | INDEX (shop_id, product_id) |
| `refund_line_items` | order_id, line_item_id NULL, shopify_refund_gid, refunded_quantity, refunded_subtotal, currency_code, processed_at | |
| `product_daily_metrics` | product_id, metric_date DATE, currency_code, gross_sales, discounts, refunds, net_sales, units_sold, order_count, calculated_at | UNIQUE (shop_id, product_id, metric_date, currency_code); INDEX (shop_id, metric_date) |
| `sync_runs` | sync_type, status, started_at, completed_at, cursor_metadata, records_received, records_processed, error_code, error_summary | No tokens |
| `webhook_events` | webhook_id, topic, payload_hash, status, received_at, processed_at, error_summary | UNIQUE (shop_id, webhook_id) |
| `subscriptions` | shopify_subscription_gid, plan_key, status, trial_ends_at, current_period_end | Entitlement snapshot; Shopify is source of truth |

No customer PII (names, emails, phones, addresses) anywhere. Shopify session storage belongs to React Router only; no `encrypted_access_token` table in Laravel.

---

## 6. API Surface (PRD §12)

**Analytics (`/api/v1`)**
- `GET /analytics/overview?from&to`
- `GET /analytics/sales-trend?from&to&interval=day|week|month`
- `GET /products?search&sort&direction&vendor&product_type&status&page`
- `GET /products/{id}/analytics?from&to`
- `GET /products/{id}/variants/analytics?from&to`
- `GET /sync/status`, `POST /sync/retry` (asks RR to resync; Laravel never fetches Shopify)
- `POST /exports/products`, `GET /exports/{id}`

**Private S2S ingestion (`/internal/v1/ingestion`)**: `products`, `orders`, `refunds`, `webhook-events`, `sync-status`.

Security: signed requests, timestamp + unique request id, replay protection, shop identity from verified header context only, FormRequest → readonly DTO, API Resources for output, consistent error format, rate limits, pagination, `X-Correlation-ID` propagated through RR → Laravel → jobs.

### Webhook topics (confirm each against pinned API version per C1)
`orders/create`, `orders/updated`, `orders/cancelled`, `refunds/create`, `products/create`, `products/update`, `products/delete`, `app/uninstalled`, plus mandatory privacy: `customers/data_request`, `customers/redact`, `shop/redact`.

### Billing (illustrative, not final)
| Plan | Price | Limits |
|---|---:|---|
| Free | $0 | Basic overview, limited history |
| Starter | $9 | Product reports, 30-day history, CSV export |
| Growth | $19 | 90-day history, comparisons, variant analytics |
| Advanced | $39 | Extended reporting, scheduled reports |

Entitlements enforced server-side in Laravel; frontend flags never unlock features. Handle trial, active, pending, declined, cancelled, expired, upgraded.

### Scopes
`read_products`, `read_orders` only. No customer scopes. Default order history 60 days; `read_all_orders` requires Shopify approval. UI must state the available window.

---

## 7. Current Repository State (verified 2026-11-18)

Step 1 (Foundation & S2S) complete. Nothing from Step 2+ exists yet.

| Area | Actual state |
|---|---|
| Laravel | `laravel/framework ^13.17`, PHP 8.3 CLI image running `php artisan serve`, Pest ^4.7 |
| Routes | `routes/api.php`: `GET /api/v1/health`, `POST /internal/v1/ping` (`s2s` middleware). `apiPrefix: ''` in `bootstrap/app.php` |
| S2S middleware | `backend/app/Http/Middleware/VerifyS2SSignature.php`, aliased `s2s`, sets `shop_domain` request attribute |
| Correlation | `backend/app/Http/Middleware/AssignCorrelationId.php`, prepended globally; `Context::add('correlation_id')` flows into logs and queued jobs |
| S2S client | `shopify-app/app/services/backendClient.ts` (`backendClient().postInternal`), lazy singleton, throws without env |
| Tests | `backend/tests/Feature/Security/{VerifyS2SSignatureTest,CorrelationIdTest}.php`, `shopify-app/tests/s2sHandshake.test.ts` |
| shopify-app | Plain `tsx` server (`app/index.ts`); no React Router routes, `shopify.server.ts`, or Polaris UI yet |
| Migrations | Only Laravel defaults (users, cache, jobs) |
| strict_types | Present in every PHP file under `backend/` (excluding `vendor/`) |

**Canonical S2S contract (both sides):**
```
Headers:   X-App-Shop-Domain, X-App-Timestamp, X-App-Nonce (UUID), X-App-Signature, X-Correlation-ID
Canonical: METHOD\nPATH\nTIMESTAMP\nNONCE\nSHOP_DOMAIN\nsha256_hex(BODY)
Signature: hex(HMAC_SHA256(S2S_SHARED_SECRET, canonical)), compared with hash_equals
Tolerance: abs(now - ts) <= config('services.s2s.tolerance', 300)
Nonce:     Cache::store('redis')->add('s2s:nonce:{uuid}', 1, tolerance * 2), claimed only after signature verifies
Errors:    401 {"error": "..."} for missing headers, malformed nonce, stale timestamp, bad signature, reused nonce
```

### Environment and secrets
- Secrets live only in gitignored root `.env` (compose interpolation, `${VAR:?}` fails fast) and `backend/.env`. Templates: `.env.example`, `backend/.env.example`. No secret values in tracked files.
- `php artisan serve` strips container env from HTTP workers (`ServeCommand::$passthroughVariables`), so the backend HTTP process reads `backend/.env` while queue worker and CLI read compose env. Keep both in sync.
- Docker on this machine runs via Colima: `DOCKER_HOST=unix://$HOME/.colima/default/docker.sock`.

### Known open issues
- Cache key prefix differs between HTTP process (`APP_NAME` from `backend/.env`) and worker/CLI (`APP_NAME` from compose). Must be aligned before Step 4 locks (`ShouldBeUnique`, `WithoutOverlapping`) or locks will not be shared.
- `phpunit.xml` uses SQLite in-memory, contradicting C13 (real PostgreSQL). Switch before Step 2 migrations.
- Pest S2S tests write nonce keys into the dev Redis cache DB (short TTL, harmless, but not isolated).
- Worker command uses `--tries=3`; jobs must still declare `$tries = 5` per C9.3 (job property wins).

## 8. Open Product Decisions (PRD §19)

Ask the user before assuming any of these:
1. 60-day history acceptable vs `read_all_orders`.
2. First target segment (small vs growing merchants).
3. Inventory context in MVP or sales only.
4. Refund semantics: net units everywhere vs separate gross/net units.
5. Plan limits to test.
6. CSV sufficient for launch.
7. Data retention after uninstall.
8. Attribution of deleted/archived/merged products in history.
9. Sync runner: RR process vs separate Node worker sharing session storage.
10. S2S scheme: HMAC-SHA256 + timestamp + Redis nonce implemented; confirm acceptable for production or move to mTLS/JWT.

---

## 9. Working Checklist Per Task

1. Confirm roadmap step and feature branch (C8). Never commit/push without explicit request.
2. Verify every Shopify field/topic/mutation on `shopify.dev` and every Laravel API in `vendor/` source (C1). Cite in summary, not code.
3. TDD: failing Pest test → minimal code → refactor (C13). Real Postgres, `RefreshDatabase`.
4. FormRequest → readonly DTO → service/pipeline → API Resource (C14).
5. Scope every query/job by `shop_id`; `DECIMAL(20,6)` money with currency code; no PII (C5).
6. Run edge checks: timezone day boundaries, leap years, negative net sales, zero previous period, partial cancellations, empty store, re-ingest same batch → zero dupes, free-tier → 403 (C6, C7).
7. Performance: query count, `EXPLAIN ANALYZE`, sub-50ms aggregate reads, chunked/lazy iteration (C12).
8. Inspect `psql` / `redis-cli` / container logs before declaring done (C4).
9. No AI/model mentions in commits, PRs, code comments, or docs (C3).
