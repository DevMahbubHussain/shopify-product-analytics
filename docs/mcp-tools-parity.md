# Milestone Delivery: MCP Tools Parity (`mcp-tools-parity`)

This document provides the technical delivery explainer for the **MCP Tools Parity** milestone, implementing the 6 official read-only analytics tools over revision **`2026-07-28`** of the Model Context Protocol using **`laravel/mcp`**.

---

## 1. Feature Overview

The `mcp-tools-parity` stream delivers autonomous, platform-neutral analytics query tools directly accessible to Model Context Protocol clients (e.g., Claude Desktop, ChatGPT, AI agents) with 100% exact cent-for-cent parity against the existing Laravel analytics REST endpoints.

### Key Architectural Guarantees:
- **Strict Read-Only & Idempotent Contract:** Every tool is declared as a `final class` and decorated with protocol-level hint annotations (`#[IsReadOnly]`, `#[IsIdempotent]`, `#[IsOpenWorld(false)]`), assuring AI clients that executions are non-destructive, repeatable, and safe.
- **Platform Neutrality (Zero Shopify GID Leakage):** External AI agents receive clean internal UUIDs, handles, and SKUs. Raw platform GIDs (`gid://shopify/...`) are strictly filtered out of tool responses.
- **Store-Isolated ISO 4217 Currency:** Monetary figures are computed in the merchant's store currency using arbitrary-precision arithmetic (`DECIMAL(20,6)`). All monetary calculations use exact decimal arithmetic (`bcmath`), while non-monetary percentage thresholds (such as `$minRefundRatePct`) are passed as numeric parameters; refund rates are computed directly in PostgreSQL as `NUMERIC` (`ROUND((refund_amount / gross_sales) * 100, 2)`) and cast as string (`(string) $row->refund_rate`), preserving PostgreSQL's 2-decimal precision.
- **Half-Open Day Boundary Precision:** Store-local calendar dates are mapped to UTC with microsecond precision using $[startOfDayUtc, startOfNextDayUtc)$ intervals.
- **Strict Input Validation & Actionable Remediation:** Every tool invokes `$request->validate([...])` at the start of execution. Invalid types, impossible calendar dates (`2026-02-30`), invalid UUIDs, out-of-range pagination limits (> 50), and invalid sort parameters throw `ValidationException`, which `laravel/mcp` converts into clean JSON-RPC tool responses (`isError: true`) with actionable remediation guidance rather than crashing or silently clamping values.
- **Dual-Representation Output:** Using `Response::structured(...)`, tools emit both machine-readable JSON objects (`result.structuredContent`) and text-based JSON fallback (`result.content[0].text`).

---

## 2. Request & Execution Flow

```
AI Client (POST /mcp)
       │
       ▼
[HTTP Middleware Pipeline]
  ├── AuthenticateMcp (Bearer Token with 60s Redis Principal Cache)
  ├── ResolveMcpTenant (Binds Shop context to Request Attributes)
  ├── EnsureMcpConsent (Verifies active Merchant Consent)
  └── EnforceMcpEntitlement (Verifies Pro Tier Subscription)
       │
       ▼
[AnalyticsServer Registry]
  ├── get_sync_status ─────────────> SyncRun state & freshness metadata
  ├── get_analytics_overview ──────> OverviewAnalyticsService (KPIs + Comparisons)
  ├── list_product_performance ────> ProductPerformanceService (Ranked table + Pagination)
  ├── get_product_analytics ───────> ProductDetailAnalyticsService (Drill-down + Variants + Trends)
  ├── get_sales_trend ─────────────> SalesTrendAnalyticsService (Daily/Weekly/Monthly time-series)
  └── find_high_refund_products ───> ProductPerformanceService::getHighRefundProducts()
       │
       ▼
[$request->validate(...)] (Strict Schema & Type Enforcement)
       │
       ▼
[FormatsToolMetadata]
  ├── Validates inverted date ranges (from > to)
  ├── Enforces plan date boundaries (diffInDays <= 365 on Pro)
  ├── Rejects future date ranges (from/to > store today)
  ├── Verifies completed initial sync (points to get_sync_status)
  └── Verifies date window against store's imported history
       │
       ▼
Response::structured(...) [200 OK with JSON-RPC Result]
```

---

## 3. The 6 Official MCP Tools

| Tool Name | Class | Primary Purpose | REST Parity Target |
|---|---|---|---|
| `get_sync_status` | `GetSyncStatusTool` | Check background sync lifecycle, completeness, and freshness. | `GET /internal/v1/sync/status` |
| `get_analytics_overview` | `GetAnalyticsOverviewTool` | High-level KPI summary (gross, net, discounts, returns, AOV) with preceding period comparison. | `GET /api/v1/analytics/overview` |
| `list_product_performance` | `ListProductPerformanceTool` | Tabular product ranking sorted by sales, volume, or refund metrics with pagination (limit 1–50). | `GET /api/v1/products` |
| `get_product_analytics` | `GetProductAnalyticsTool` | In-depth single product performance breakdown by UUID or handle with variants and daily trend. | `GET /api/v1/products/{id}/analytics` |
| `get_sales_trend` | `GetSalesTrendTool` | Time-series data points grouped by day, week, or month for trend analysis. | `GET /api/v1/analytics/sales-trend` |
| `find_high_refund_products` | `FindHighRefundProductsTool` | Identification of refund anomalies, elevated return rates, and return amounts. | `GET /api/v1/products?sort=refund_rate` |

---

## 4. File-by-File Implementation Breakdown

### Domain Services & Validation Requests (`backend/app/Analytics/Services/`, `backend/app/Http/Requests/`)
1. **`ProductPerformanceService.php`:**
   - Implements `getHighRefundProducts(Shop $shop, string $from, string $to, string $sortBy = 'refund_rate', string $direction = 'desc', float|int|string|null $minRefundRatePct = null, ?string $minRefundAmount = null, int $limit = 20): array`. Executes bounded SQL aggregation over `product_daily_metrics` joined with `products`, excluding non-refunded or zero-sales products (`gross_sales > 0`, `refund_amount > 0`), computing return rate as PostgreSQL `NUMERIC` (`ROUND((refund_amount / gross_sales) * 100, 2)`), and casting `refund_rate` directly as a string (`(string) $row->refund_rate`) to preserve PostgreSQL's 2-decimal rounded representation.
   - Updates `getProducts()` to support sorting by `refund_rate` and `refunds`.
   - Escapes SQL wildcard characters (`%`, `_`, `\`) in search title queries.
2. **`ProductListRequest.php`:**
   - Adds `refund_rate` and `refunds` to `sort` validation rules.
   - Preserves `per_page` at max 100 for REST requests (PRD §7.3) while MCP tool enforces 1–50.

### Core Tool Implementations (`backend/app/Mcp/Tools/`)
3. **`Concerns/FormatsToolMetadata.php`:** Shared trait providing `resolveCurrentShop()`, `buildMetadata()`, and `validateDateRange()`. Enforces:
   - Inverted range rejection (`from > to`).
   - Plan tier span checking aligned with REST using inclusive calendar days (`(int) $fromDate->diffInDays($toDate) + 1 > $maxDays`).
   - Future date rejection against store timezone today (`from > today || to > today`).
   - Initial sync completion verification (directing agents to `get_sync_status`).
   - History coverage verification using `cursor_metadata.window_start` (or `installed_at - 60 days`) alongside historical metric dates (`min($candidates)`). Dates within the sync window prior to the first sale return 0 sales without error, while ranges partly predating history flag `data_complete = false`.
4. **`GetSyncStatusTool.php`:** `final class`. Queries latest sync lifecycle state, data completeness boolean, store currency, timezone, and ISO 8601 timestamps.
5. **`GetAnalyticsOverviewTool.php`:** `final class`. Validates `from`/`to` parameters and delegates to `OverviewAnalyticsService::getOverview()`, returning financial summary metrics and preceding period percentage deltas.
6. **`ListProductPerformanceTool.php`:** `final class`. Validates pagination limits (1–50) and sort fields, delegating to `ProductPerformanceService::getProducts()`, providing sorting, pagination, and keyword search while stripping raw Shopify GIDs.
7. **`GetProductAnalyticsTool.php`:** `final class`. Accepts either `product_id` (UUID) or `handle` (slug) with mutual exclusion (`prohibits:handle` on `product_id` with single custom error message). Supports `include_variants` (default true), `include_daily_trend` (default true), and `compare_previous` (default true). Returns identical `'Product not found in this store.'` error for non-existent identifiers.
8. **`GetSalesTrendTool.php`:** `final class`. Validates `from`, `to`, and `interval` (`in:day,week,month`). Delegates to `SalesTrendAnalyticsService::getSalesTrend()`, generating continuous time-series points with zero-filled gaps for missing days.
9. **`FindHighRefundProductsTool.php`:** `final class`. Validates `min_refund_rate` (0–100, max 2 decimals), `min_refund_amount` (`regex:/^\d+(\.\d{1,6})?$/` protecting `bcmath` operations from scientific notation or spaces), and `limit` (1–50). Delegates to `ProductPerformanceService::getHighRefundProducts()`.

### Server Registration (`backend/app/Mcp/Servers/`)
10. **`AnalyticsServer.php`:** Exposes all 6 tools in `protected array $tools = [...]`.

### Documentation (`docs/`)
11. **`docs/laravel-mcp/02_tools_architecture.md`:** Comprehensive architectural and technical guide for the Tools layer.
12. **`docs/mcp-tools-parity.md`:** This milestone delivery document.

### Feature Test Suites (`backend/tests/Feature/Mcp/Tools/`)
13. **`GetSyncStatusToolTest.php`:** Verifies empty sync state, completed sync state, metadata, and annotations.
14. **`GetAnalyticsOverviewToolTest.php`:** Verifies overview financial calculations, comparisons, date validations, impossible calendar dates, future date rejection, unfinished sync detection, history coverage, and plan limits.
15. **`ListProductPerformanceToolTest.php`:** Verifies product ranking, sorting, pagination limit enforcement (1–50), sort validation, wildcard escaping in search, and platform neutrality.
16. **`GetProductAnalyticsToolTest.php`:** Verifies single-product drill-down by UUID or handle, UUID format validation, mutual exclusion of identifiers, uniform non-existence error, daily trends, variant breakdowns, zero GID leaks, and cross-shop isolation.
17. **`GetSalesTrendToolTest.php`:** Verifies daily continuous time-series filling, interval validation (`day`, `week`, `month`), impossible date rejection, and store boundary isolation.
18. **`FindHighRefundProductsToolTest.php`:** Verifies return rate calculations, decimal string formatting, threshold filters, limit cap (1–50), numeric amount validation, sort validation, and cross-tenant isolation.
19. **`McpNumericParityAndIsolationTest.php`:** End-to-end parity test suite asserting cent-for-cent equality between REST endpoints and MCP tools on identical database states across overview, product listing, single product analytics, time-series trends, and high refund products.

---

## 5. Edge Cases Handled

1. **Continuous Time-Series Gaps:** `SalesTrendAnalyticsService` automatically zero-fills calendar days within the requested interval where no sales occurred, preventing broken trend charts in AI clients.
2. **Actionable LLM Error Guidance:** When date formats are invalid, impossible (`2026-02-30`), or inverted (`from > to`), tools return clear remediation instructions (e.g., `Invalid date format. Expected YYYY-MM-DD for both from and to dates.`) instead of generic 500/400 errors or SQLSTATE exceptions.
3. **No Silent Clamping:** Requests specifying invalid limits (`limit: 500`), invalid sorts (`sort: bogus`), or invalid intervals (`interval: yearly`) fail validation with explicit error messages rather than silently substituting default values.
4. **Boundary & Future Date Protection:** Inquiries for future dates (`from/to > today` in store timezone) are rejected with clear explanation that analytics are strictly retrospective.
5. **Sync Lifecycle & History Visibility:** When querying stores with unfinished initial sync or requesting dates prior to available imported history, tools return explicit guidance directing agents to `get_sync_status`.
6. **No Existence Oracle:** Non-existent product lookups (whether by UUID or handle) return an identical `'Product not found in this store.'` error, preventing cross-tenant existence enumeration.
7. **Division-by-Zero Safety:** Average order revenue and refund rate calculations handle zero-order or zero-sales baselines without exceptions. Products with `gross_sales <= 0` or `refund_amount <= 0` are excluded from high refund rankings.
8. **Multi-Tenant Boundary Isolation:** Every database query and aggregation is strictly filtered by `shop_id`. Responses for one store contain zero records from any neighboring store.

---

## 6. Verification Evidence

### 1. Code Style Inspection (Pint)
```bash
./vendor/bin/pint app/Mcp/Tools app/Analytics/Services/ProductPerformanceService.php app/Http/Requests/Analytics/ProductListRequest.php tests/Feature/Mcp/Tools
PASS: All modified files formatted to PSR-12 and Laravel standards with 0 style issues.
```

### 2. MCP Tools Test Suite (53 tests)
```bash
PASS  Tests\Feature\Mcp\Tools\FindHighRefundProductsToolTest (9 passed)
PASS  Tests\Feature\Mcp\Tools\GetAnalyticsOverviewToolTest (12 passed)
PASS  Tests\Feature\Mcp\Tools\GetProductAnalyticsToolTest (7 passed)
PASS  Tests\Feature\Mcp\Tools\GetSalesTrendToolTest (6 passed)
PASS  Tests\Feature\Mcp\Tools\GetSyncStatusToolTest (3 passed)
PASS  Tests\Feature\Mcp\Tools\ListProductPerformanceToolTest (9 passed)
PASS  Tests\Feature\Mcp\Tools\McpNumericParityAndIsolationTest (7 passed)
Tests: 53 passed (433 assertions)
Duration: 1.89s
```

### 3. Full Backend Test Suite (454 tests)
```bash
Tests:    454 passed (4475 assertions)
Duration: 13.89s
```
Zero regressions across all existing unit, feature, security, performance, ingestion, and sync tests.
