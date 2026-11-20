# Embedded UI with Shopify Polaris (Step 6)

How the embedded Shopify application delivers the merchant-facing analytics dashboard inside Shopify Admin, featuring App Bridge navigation, Polaris components, real-time sync banners, date preset controls, KPI metric cards with period-over-period comparison badges, sales trend visualizers, and variant performance tables.

---

## 1. What This Phase Provides

- **Shopify App Bridge & Polaris Embedded Shell:** Integrates natively into Shopify Admin using `@shopify/shopify-app-react-router` and official Polaris components, matching Shopify's design system and design guidelines.
- **Overview Dashboard (`/app`):**
  - **Live Synchronization Alerts:** Real-time polling and status banners for background imports (`syncing`, `completed`, `failed`, `stalled`, `backend_error`) with manual retry capability.
  - **Date Range Selector Toolbar:** Quick preset buttons (`Today`, `Yesterday`, `7D`, `30D`, `90D`, `This month`, `Last month`) and formatted store-timezone date boundaries.
  - **KPI Performance Grid:** 6 summary metric cards (Gross Sales, Net Sales, Discounts, Refunds, Units Sold, Orders & Average Order Revenue) with period-over-period comparison badges (▲ / ▼ / flat).
  - **Interactive Sales Trend Visualizer:** Visualizes net sales across daily, weekly, or monthly time-series buckets with responsive SVG charts and tooltips.
  - **Product Performance Table:** Server-paginated table with product thumbnails, title links to drill-down detail views, vendor labels, sortable headers (`net_sales`, `gross_sales`, `units_sold`, `orders`), and title search.
- **Product Detail View (`/app/products/:id`):**
  - **Breadcrumb Navigation:** Context-preserving back link returning to the main dashboard with active date presets.
  - **Product Metadata Header:** Displays thumbnail image, product title, publishing status badge (`ACTIVE`, `DRAFT`, `ARCHIVED`), vendor, and handle.
  - **Product KPI Summary:** Single-product gross sales, net sales, refunds, units sold, and average order revenue compared to the preceding period.
  - **Variant Performance Table:** Granular variant-level table showing Variant Title, SKU, Price, Gross Sales, Discounts, Refunds, Net Sales, Units Sold, and Orders, sorted by net sales descending.
- **Store-Timezone Date Presets:** Calculates date range boundaries based on the merchant's store timezone, handling month transitions, leap years, and calendar year rollovers without external date library overhead.
- **S2S Query Parameter Signature Normalization:** Normalizes query strings in `BackendClient` to ensure HMAC-SHA256 signatures match Laravel's canonical `$request->path()`.
- **Fault-Tolerant Data Loaders:** Parallelized S2S data fetching with `Promise.all` and graceful degradation, preventing 500 crashes if backend services experience transient errors.

---

## 2. Architecture & Data Flow

```
Merchant Browser (Shopify Admin iframe)
             │
             ▼
App Bridge Embedded Shell (<AppProvider>)
             │
    ┌────────┴───────────────────────────┐
    ▼                                    ▼
Overview Route (`/app`)          Product Route (`/app/products/:id`)
    │                                    │
    ▼                                    ▼
overviewLoader.server.ts         productDetailLoader.server.ts
  - parseDateRange(searchParams)   - parseDateRange(searchParams)
  - getSyncStatus()                - fetchProductDetail()
  - fetchOverview()                - fetchVariantAnalytics()
  - fetchSalesTrend()                    │ (Parallel S2S GET)
  - fetchProductList()                   ▼
    │ (Parallel S2S GET)         BackendClient (HMAC-SHA256)
    ▼                                    │
BackendClient (HMAC-SHA256)              │
    │                                    │
    └──────────────────┬─────────────────┘
                       │ S2S GET with headers:
                       │  - X-App-Shop-Domain
                       │  - X-App-Timestamp
                       │  - X-App-Nonce
                       │  - X-App-Signature (signs PATH without query)
                       ▼
            Laravel REST API (`/api/v1/*`)
              ├── VerifyS2SSignature middleware
              ├── OverviewAnalyticsController
              ├── SalesTrendController
              ├── ProductPerformanceController
              └── ProductDetailAnalyticsController
                       │
                       ▼
         Indexed PostgreSQL Reads (`product_daily_metrics`)
```

---

## 3. File-by-File Breakdown

### 3.1 S2S Client Infrastructure
- **`shopify-app/app/lib/backend/backendClient.server.ts`**:
  - Adds `QueryParams` dictionary support to `getInternal`.
  - Splits incoming paths into `pathOnly` and `inlineQuery`.
  - Computes HMAC-SHA256 signature strictly on the normalized pathname (`normalizePath(pathOnly)`), aligning with Laravel's `$request->path()` canonical requirement.
  - Builds URL search parameters and appends them to the outgoing fetch request.
- **`shopify-app/app/lib/backend/backendClient.server.test.ts`**:
  - Unit tests verifying that query parameters in `getInternal` are preserved on the HTTP request URL while excluded from the canonical signature payload.
- **`shopify-app/app/lib/backend/analyticsClient.server.ts`**:
  - Defines immutable TypeScript interfaces for all Laravel API Resource envelopes:
    - `OverviewAnalyticsData`, `OverviewSummary`, `OverviewComparison`, `MetricComparison`
    - `SalesTrendData`, `SalesTrendPoint`
    - `ProductListResponse`, `ProductPerformanceItem`, `ProductListParams`, `PaginationMeta`
    - `ProductDetailData`, `VariantAnalyticsData`, `VariantAnalyticsItem`
  - Implements typed API functions:
    - `fetchOverview(backend, shopDomain, { from, to })`
    - `fetchSalesTrend(backend, shopDomain, { from, to, interval })`
    - `fetchProductList(backend, shopDomain, params)`
    - `fetchProductDetail(backend, shopDomain, productId, { from, to })`
    - `fetchVariantAnalytics(backend, shopDomain, productId, { from, to })`
- **`shopify-app/app/lib/backend/analyticsClient.server.test.ts`**:
  - Unit tests verifying typed client invocations, query parameter serialization, and response unpacking.

### 3.2 Date Presets & Timezone Utilities
- **`shopify-app/app/features/analytics/datePresets.ts`**:
  - `getStoreToday(timezone, now)`: Uses `Intl.DateTimeFormat("en-CA", { timeZone })` to format calendar dates in the store's IANA timezone.
  - `DATE_PRESETS`: Defines standard presets (`today`, `yesterday`, `7d`, `30d`, `90d`, `this_month`, `last_month`, `custom`).
  - `calculateDateRange(preset, referenceToday)`: Deterministically calculates start/end dates using UTC calendar math across month boundaries, leap years, and year rollovers.
  - `parseDateRange(searchParams, referenceToday)`: Validates `from` and `to` against `YYYY-MM-DD`, checks `from <= to`, and safely defaults to `30d` if parameters are invalid.
- **`shopify-app/app/features/analytics/datePresets.test.ts`**:
  - Comprehensive unit tests covering all preset ranges, timezone adjustments, leap year calculations, month transitions, and malformed query fallbacks.

### 3.3 Server Data Loaders
- **`shopify-app/app/features/analytics/overviewLoader.server.ts`**:
  - Implements `loadOverviewDashboard({ request, shopDomain, backend, syncStatus, referenceToday })`.
  - Parses date range, search queries, vendor filters, sort parameters, and pagination from URL search params.
  - Executes `fetchOverview`, `fetchSalesTrend`, and `fetchProductList` concurrently via `Promise.all`.
  - Short-circuits with empty state if `syncStatus.state === "backend_error"`.
  - Catches transient backend errors and provides non-fatal `analyticsError` strings.
- **`shopify-app/app/features/analytics/overviewLoader.server.test.ts`**:
  - Unit tests verifying concurrent fetching, query param extraction, graceful error recovery, and sync status integration.
- **`shopify-app/app/features/analytics/productDetailLoader.server.ts`**:
  - Implements `loadProductDetail({ request, shopDomain, backend, productId, referenceToday })`.
  - Concurrently fetches product-level metrics and variant breakdowns.
  - Identifies 404 responses (`BackendError.status === 404`) and maps them to `notFound: true`.
- **`shopify-app/app/features/analytics/productDetailLoader.server.test.ts`**:
  - Unit tests verifying product detail loading, 404 detection, empty ID validation, and server error handling.

### 3.4 Shimmer Loading Skeletons
- **`shopify-app/app/features/analytics/AnalyticsSkeleton.tsx`**:
  - Implements lightweight, GPU-accelerated CSS shimmer skeletons:
    - `<KpiCardsSkeleton />`: 6 placeholder cards for metrics, numbers, and comparison badges.
    - `<TrendChartSkeleton />`: Shimmering bar chart placeholders with staggered height bars.
    - `<ProductTableSkeleton />`: 5 shimmering rows with thumbnail, title, and metric column placeholders.
    - `<ProductDetailSkeleton />`: Product header, KPI cards, and variant table skeleton placeholders.
    - `<ShimmerStyle />`: Injects keyframe CSS rules for the shimmer sweep animation (`spaShimmer`).

### 3.5 Polaris Embedded UI Routes
- **`shopify-app/app/routes/app._index.tsx`**:
  - Subscribes to `useNavigation()` to detect loading transitions.
  - Replaces nested `<Link><s-button>` with direct button `onClick` handlers invoking `navigate(url, { preventScrollReset: true })` to prevent scroll jumping and double events.
  - Smoothly renders shimmering skeletons during data fetching transitions.
  - Main embedded dashboard with sync banners, date preset buttons, KPI metric cards with delta badges, interactive trend visualizer, and product ranking table.
- **`shopify-app/app/routes/app.products.$id.tsx`**:
  - Subscribes to `useNavigation()` and renders `<ProductDetailSkeleton />` during transition loads.
  - Product drill-down detail view with breadcrumbs, product header, KPI summary cards, and variant performance table.

---

## 4. URL State & Query Management

All dashboard filters, presets, sorting, and pagination are synchronized with the URL query string, enabling browser bookmarking, link sharing, and back/forward browser navigation:

| Parameter | Type | Default | Description |
|---|---|---|---|
| `preset` | `string` | `30d` | Active preset (`today`, `yesterday`, `7d`, `30d`, `90d`, `this_month`, `last_month`, `custom`) |
| `from` | `string` | Calculated | Start date (`YYYY-MM-DD`) |
| `to` | `string` | Calculated | End date (`YYYY-MM-DD`) |
| `interval` | `string` | `day` | Time-series bucket interval (`day`, `week`, `month`) |
| `search` | `string` | None | Product title search substring |
| `sort_by` | `string` | `net_sales` | Sort column (`net_sales`, `gross_sales`, `units_sold`, `orders`, `title`) |
| `sort_direction` | `string` | `desc` | Sort direction (`asc`, `desc`) |
| `page` | `number` | `1` | Current page number |
| `per_page` | `number` | `10` | Products per page |

When selecting a product from the table, links to `/app/products/:id?preset=${currentPreset}` pass the selected date range to the detail view automatically.

---

## 5. Edge Cases Handled

1. **S2S Query Parameter Signature Mismatch:**
   - Laravel's `VerifyS2SSignature` signs `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHOP_DOMAIN\nsha256(BODY)` where `PATH` is `/` + `$request->path()`.
   - In PHP, `$request->path()` excludes the query string.
   - `BackendClient` separates `path` into pathname and query string, ensuring the HMAC-SHA256 signature is strictly computed against the canonical pathname, while query parameters are appended to the outgoing HTTP fetch request.
2. **Leap Years & Month Transitions:**
   - `calculateDateRange("last_month")` evaluates February 28 on standard years (e.g. 2026) and February 29 on leap years (e.g. 2024).
   - In January, `last_month` rolls back to December 1 through December 31 of the previous calendar year.
3. **Inverted or Malformed Date Ranges:**
   - If a user passes `from > to` or invalid date formats in URL search params, `parseDateRange` detects the condition and falls back safely to the default `30d` range.
4. **Tenant Product 404 Resolution:**
   - When viewing `/app/products/:id`, if the product belongs to another shop or was deleted, Laravel returns `404 Not Found`.
   - `productDetailLoader` catches `BackendError` with status 404 and returns `notFound: true`, displaying a friendly Polaris banner and a return button without throwing an unhandled exception.
5. **Backend Outages & Degradation:**
   - If the Laravel backend is temporarily unreachable, `loadOverviewDashboard` catches the error and populates `analyticsError`. The dashboard displays a warning banner while maintaining the page layout and navigation.

---

## 6. Verification Evidence

### 6.1 Backend Test Suite (Pest / PostgreSQL in Docker)
Executed via `docker compose exec backend ./vendor/bin/pest`:
- **Result:** **215 passed (1009 assertions)**
- **Coverage:** Unit tests for financial math, period comparisons, timezone services; Feature tests for S2S signature verification, tenant isolation, overview analytics, sales trends, product rankings, and product detail endpoints.

### 6.2 Shopify App Test Suite (Node.js Built-in Runner)
Executed via `npm test` in `shopify-app`:
- **Result:** **147 passed (0 failures)**
- **Coverage:**
  - `backendClient.server.test.ts`: S2S signing, GET query parameter canonicalization, replay prevention.
  - `analyticsClient.server.test.ts`: Typed REST API requests for overview, trend, products, detail, and variants.
  - `datePresets.test.ts`: Store timezone calculations, leap year handling, preset ranges, URL parameter parsing.
  - `overviewLoader.server.test.ts`: Parallel loader fetching, search/sort/page mapping, error recovery.
  - `productDetailLoader.server.test.ts`: Product detail and variant analytics loading, 404 detection, ID validation.

### 6.3 TypeScript Strict Typecheck & Linter
Executed via `npm run typecheck && npm run lint` in `shopify-app`:
- **Result:** **0 errors, 0 warnings**
- Prohibits `any` types, validates all Polaris web component properties, and ensures 100% typed data flows between React Router loaders and components.
