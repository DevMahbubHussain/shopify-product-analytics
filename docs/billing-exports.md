# Billing, Exports & Production Hardening (Step 7)

How the application delivers subscription management, plan feature gating, asynchronous CSV data exports, and mandatory Shopify privacy & compliance data erasure within the strict architectural trust boundary.

---

## 1. What This Phase Provides

- **Shopify Billing Integration (API 2026-10):**
  - Typed GraphQL billing client implementing the verified `appSubscriptionCreate` mutation.
  - Subscription options: **Free Tier** ($0/month) vs **Pro Plan** ($19.00/month recurring with a 7-day free trial).
  - Webhook processing for `app_subscriptions/update` syncing subscription status changes (`ACTIVE`, `CANCELLED`, `EXPIRED`, `FROZEN`) to the multi-tenant database.
- **Embedded Polaris Pricing Interface (`/app/pricing`):**
  - In-app pricing plan comparison card interface inside Shopify Admin.
  - Real-time active plan detection from Shopify GraphQL (`appInstallation.activeSubscriptions`).
  - Safe top-level redirect (`{ target: "_top" }`) to Shopify's billing confirmation URL.
- **Plan Feature Gating & Entitlements:**
  - Automated tier enforcement via `PlanEntitlementService` and `PlanTier` backed enum.
  - **Free Tier:** Analytics history restricted to a maximum 30-day window. Attempts to query beyond 30 days are rejected with `403 Forbidden` (`plan_limit_exceeded`). CSV exports are disabled.
  - **Pro Tier:** Full 365-day historical analytics window and unlimited CSV data exports.
  - Graceful downgrade: Cancelled or expired subscriptions automatically revert to Free tier limits.
- **Asynchronous CSV Export Pipeline:**
  - Dedicated PostgreSQL `exports` table tracking lifecycle states (`PENDING`, `PROCESSING`, `COMPLETED`, `FAILED`).
  - Redis queue worker job `GenerateProductCsvExportJob` using cursor-based lazy iteration (`cursor()`) over PostgreSQL aggregates, ensuring a flat memory footprint regardless of product count.
  - Standard RFC 4180 CSV generation with escaped headers (`"Product Title",Handle,Vendor,"Product Type","Net Sales","Gross Sales",Discounts,Refunds,"Units Sold",Orders`) and 2-decimal monetary formatting.
  - S2S REST API endpoints:
    - `POST /api/v1/exports/products` (dispatches export job; returns `202 Accepted`).
    - `GET /api/v1/exports/{id}` (progress polling; returns status, row counts, and metadata).
    - `GET /api/v1/exports/{id}/download` (streams binary file with `Content-Type: text/csv` and attachment disposition).
- **Mandatory Shopify Privacy & Compliance Webhooks:**
  - `customers/data_request`: Acknowledges receipt with `200 OK` (the app strictly stores zero Protected Customer Data).
  - `customers/redact`: Acknowledges receipt with `200 OK`.
  - `shop/redact`: Triggers full tenant erasure after app uninstall.
- **Tenant Data Purge (`POST /internal/v1/ingestion/shop-redact`):**
  - Cascading database wipe respecting foreign key constraints across `orders`, `order_line_items`, `refund_line_items`, `products`, `product_variants`, `product_daily_metrics`, `sync_runs`, `subscriptions`, `exports`, and `shops`.
  - Disk cleanup removing all tenant export files and directories (`Storage::disk('local')->deleteDirectory(...)`).
  - Complete multi-tenant isolation and idempotency.

---

## 2. Architecture & Data Flow

### 2.1 Subscription & Billing Flow

```
Merchant UI (/app/pricing)
      │
      │ 1. Form POST: "Start 7-Day Free Trial"
      ▼
Action Handler (app.pricing.tsx)
      │
      │ 2. appSubscriptionCreate GraphQL mutation
      ▼
Shopify Admin API (2026-10) ──> Returns confirmationUrl
      │
      │ 3. Redirect { target: "_top" } to confirmationUrl
      ▼
Merchant approves recurring charge in Shopify Admin
      │
      │ 4. Shopify sends webhook: app_subscriptions/update
      ▼
shopify-app (/webhooks/app_subscriptions/update)
      │
      │ 5. Signed S2S POST: /internal/v1/ingestion/subscription
      ▼
Laravel Backend (IngestionController)
      │
      │ 6. Ingestion Pipeline: ResolveShopTenant -> UpsertSubscription
      ▼
PostgreSQL (`subscriptions` table updated with plan_key='pro', status='ACTIVE')
```

### 2.2 Asynchronous CSV Export Flow

```
React Router UI / S2S Client
      │
      │ 1. POST /api/v1/exports/products { from, to }
      ▼
Laravel Controller (ProductExportController::store)
      │
      ├──> PlanEntitlementService::enforceCsvExport() (403 if Free tier)
      ├──> PlanEntitlementService::enforceDateRangeLimit() (403 if > max days)
      │
      │ 2. Inserts Export record (status='PENDING')
      │ 3. Dispatches GenerateProductCsvExportJob to Redis
      ▼
Responds 202 Accepted { id, status: 'PENDING' }
      │
      ▼
Persistent Redis Worker (laravel-worker)
      │
      ├──> Sets status='PROCESSING'
      ├──> Runs cursor() over product_daily_metrics aggregates
      ├──> Streams rows into php://temp and writes to Storage (exports/{shop_id}/*.csv)
      └──> Updates Export record (status='COMPLETED', row_count, file_path)
      │
      ▼
Client polls GET /api/v1/exports/{id} ──> returns COMPLETED
Client streams GET /api/v1/exports/{id}/download ──> binary CSV download
```

### 2.3 Shop Redact Compliance Flow

```
Shopify Webhook Engine (48 hours post-uninstall)
      │
      │ 1. POST /webhooks/shop/redact
      ▼
shopify-app Route (webhooks.shop.redact.tsx)
      │
      ├──> verifyShopifyWebhook(request) [HMAC validation]
      ├──> Signed S2S POST /internal/v1/ingestion/shop-redact
      │        │
      │        ▼
      │    Laravel Backend (TenantPurgeService)
      │        ├── DB::transaction (deletes order_lines, orders, metrics, products, exports, shop)
      │        └── Storage::deleteDirectory("exports/{shop_id}")
      │
      └──> db.session.deleteMany({ where: { shop } }) [Prisma sessions wipe]
      │
      ▼
Responds 200 OK to Shopify
```

---

## 3. File-by-File Breakdown

### 3.1 Backend (Laravel 13)

| File | Purpose |
|---|---|
| `database/migrations/2026_11_20_000011_create_exports_table.php` | Creates the `exports` table with UUID key, foreign key to `shops`, file metadata, row count, status, date range, and compound index `(shop_id, status)`. |
| `app/Models/Subscription.php` | Eloquent model for `subscriptions` with `HasUuids`, immutable datetime casting, and `belongsTo(Shop::class)`. |
| `app/Models/Export.php` | Eloquent model for `exports` with `HasUuids`, integer and datetime casts, and `belongsTo(Shop::class)`. |
| `app/Models/Shop.php` | Added `subscriptions(): HasMany` and `activeSubscription(): HasOne` (ordered by `created_at desc` compatible with PostgreSQL UUID keys). |
| `app/Enums/PlanTier.php` | Backed string enum (`free`, `pro`) defining `maxAnalyticsDays()` and `canExportCsv()`. |
| `app/Exceptions/PlanLimitExceededException.php` | Custom exception with `render(): JsonResponse` returning `403 Forbidden` and `{ error: 'plan_limit_exceeded', plan, max_days }`. |
| `app/Services/PlanEntitlementService.php` | Resolves store's active plan tier and enforces maximum date ranges and CSV export permissions. |
| `app/Services/TenantPurgeService.php` | Deletes all database records for a tenant across 11 tables in foreign-key-safe dependency order, and clears export files from local disk. |
| `app/Data/Ingestion/SubscriptionData.php` | Readonly DTO mapping subscription ingestion payloads into strongly typed properties. |
| `app/Data/Ingestion/ShopRedactData.php` | Readonly DTO encapsulating store domain for tenant purge. |
| `app/Http/Requests/Internal/IngestSubscriptionRequest.php` | FormRequest validating subscription GID regex, plan keys, status, and dates. |
| `app/Http/Requests/Internal/ShopRedactRequest.php` | FormRequest validating target store domain for tenant purge. |
| `app/Http/Requests/Exports/CreateProductExportRequest.php` | FormRequest validating `from` and `to` date strings, integrating plan entitlement checks. |
| `app/Http/Resources/ExportResource.php` | API Resource formatting export metadata for REST consumers. |
| `app/Ingestion/Responses/SubscriptionResult.php` | Response contract returning `status: 'recorded'`, `plan_key`, and `subscription_status`. |
| `app/Ingestion/Responses/ShopRedactResult.php` | Response contract returning `status: 'purged'`. |
| `app/Ingestion/Pipes/UpsertSubscription.php` | Pipeline pipe executing `updateOrCreate` on `[shop_id, shopify_subscription_gid]` with transaction deadlock retry. |
| `app/Ingestion/Pipes/PurgeShopTenant.php` | Pipeline pipe invoking `TenantPurgeService::purgeByDomain`. |
| `app/Jobs/GenerateProductCsvExportJob.php` | Persistent Redis queue job streaming product metrics to temporary stream and uploading CSV artifact. |
| `app/Http/Controllers/Exports/ProductExportController.php` | REST API actions for `store`, `show`, and `download`. |
| `app/Http/Controllers/Internal/SubscriptionStatusController.php` | S2S controller for `GET /internal/v1/subscription` returning the shop's active plan tier and entitlement limits. |
| `app/Http/Controllers/Internal/IngestionController.php` | Registered S2S actions `subscription` and `shopRedact`. |
| `routes/api.php` | Registered routes for `/api/v1/exports/*`, `/internal/v1/subscription`, and `/internal/v1/ingestion/*`. |
| `tests/Feature/Internal/GetSubscriptionStatusTest.php` | 5 Pest tests covering S2S signature verification, active Pro plan reporting, free tier fallback, and tenant isolation. |
| `tests/Feature/Ingestion/SubscriptionIngestionTest.php` | 8 Pest tests covering subscription upsert, idempotency, validation, and isolation. |
| `tests/Feature/Analytics/PlanFeatureGatingTest.php` | 6 Pest tests covering 30-day Free limit, 365-day Pro allowance, cancellation fallback, and endpoint uniformity. |
| `tests/Feature/Exports/GenerateProductCsvExportJobTest.php` | 3 Pest tests covering queue parameters, memory-safe CSV generation, and tenant isolation. |
| `tests/Feature/Exports/ProductExportApiTest.php` | 7 Pest tests covering entitlement rejection, job dispatch, status polling, streaming download, and 409 guard. |
| `tests/Feature/Ingestion/ShopRedactTest.php` | 4 Pest tests covering complete cascading wipe, disk cleanup, tenant isolation, and idempotency. |

---

### 3.2 Shopify Integration Layer (React Router / Node)

| File | Purpose |
|---|---|
| `app/lib/billing/billingClient.server.ts` | GraphQL client for `appSubscriptionCreate` mutation targeting Shopify API `2026-10`. |
| `app/lib/billing/billingClient.server.test.ts` | Unit tests for billing client mutation variables, confirmation URL extraction, and GraphQL user errors. |
| `app/features/billing/getSubscription.server.ts` | S2S helper function querying Laravel's `GET /internal/v1/subscription` to check active plan entitlements. |
| `app/features/billing/getSubscription.server.test.ts` | Unit tests for S2S subscription queries, header forwarding, and graceful error fallback. |
| `app/features/billing/subscriptionWebhook.server.ts` | Processes `app_subscriptions/update` payload and forwards updates to Laravel S2S. |
| `app/features/billing/subscriptionWebhook.server.test.ts` | Unit tests for subscription webhook parsing, error handling, and retry response codes. |
| `app/routes/webhooks.app_subscriptions.update.tsx` | Webhook route receiving Shopify billing updates. |
| `app/routes/app.pricing.tsx` | Embedded Polaris page with plan tier comparison cards, active subscription badges, and upgrade form action. |
| `app/features/privacy/shopRedact.server.ts` | Purges backend data via S2S and wipes Prisma session storage. |
| `app/features/privacy/shopRedact.server.test.ts` | Unit tests for shop redact handler and retry failure propagation. |
| `app/routes/webhooks.shop.redact.tsx` | Mandatory webhook route for `shop/redact`. |
| `app/routes/webhooks.customers.data_request.tsx` | Mandatory webhook route for `customers/data_request` (acknowledges 200 OK; no customer data stored). |
| `app/routes/webhooks.customers.redact.tsx` | Mandatory webhook route for `customers/redact` (acknowledges 200 OK). |

---

## 4. Edge Cases Handled

1. **PostgreSQL UUID Incompatibility with `latestOfMany()`:**
   - In PostgreSQL, executing `MAX(id)` on a `UUID` primary key throws an SQL error (`function max(uuid) does not exist`).
   - Resolved by defining `activeSubscription(): HasOne` with explicit `orderByDesc('created_at')`, eliminating subquery aggregation and ensuring fast, indexed single-row resolution.
2. **Flat Memory Footprint on Large CSV Exports:**
   - Instead of loading large collections of products and metric records into PHP memory, `GenerateProductCsvExportJob` streams database rows one-by-one using `cursor()`.
   - Rows are written directly to a temporary file stream (`php://temp`) before persisting to local storage, preventing worker OOM (Out Of Memory) crashes.
3. **Database Cascade Dependency Order in Tenant Purge:**
   - Foreign key constraints require child tables (`order_line_items`, `refund_line_items`, `product_daily_metrics`, `product_variants`) to be purged before parent tables (`orders`, `products`, `shops`).
   - `TenantPurgeService` executes deletions in strict topological dependency order inside a deadlock-resilient transaction (`DB::transaction(..., 5)`).
4. **Idempotent Subscription & Purge Ingestion:**
   - Repeated webhook deliveries for the same subscription or shop redact event never produce duplicate records or throw unhandled exceptions. If the shop is already purged, the endpoint returns `200 OK` immediately.
5. **Replay & Tampering Protection:**
   - S2S endpoints enforce HMAC-SHA256 signatures, 300-second timestamp drift tolerances, and atomic Redis nonce verification (`add('s2s:nonce:{uuid}', ...)`).

---

## 5. Verification Evidence

### 5.1 Backend Test Suite (Pest)

```bash
docker compose exec backend ./vendor/bin/pest
```

Output:
```
Tests:    248 passed (1158 assertions)
Duration: 5.14s
```

All 248 backend unit, feature, ingestion, security, and contract tests pass with zero warnings and zero query errors.

### 5.2 Shopify App Test Suite (Node Test Runner)

```bash
cd shopify-app && npm test
```

Output:
```
ℹ tests 157
ℹ suites 4
ℹ pass 157
ℹ fail 0
ℹ duration_ms 1014.684292
```

### 5.3 TypeScript Strict Typecheck & ESLint

```bash
cd shopify-app && npm run typecheck && npm run lint
```

Output:
```
> typecheck
> react-router typegen && tsc --noEmit

> lint
> eslint --ignore-path .gitignore --cache --cache-location ./node_modules/.cache/eslint .

(0 errors, 0 warnings)
```
