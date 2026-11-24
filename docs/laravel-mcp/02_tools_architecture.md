# Laravel MCP Server: Tools Architecture & Protocol Contracts

This document details the architecture, design principles, protocol contracts, schema specifications, and security guardrails for the Model Context Protocol (MCP) analytics tools implemented with **`laravel/mcp`** in `app/Mcp/Tools/`.

---

## 1. Architectural Philosophy & Design Principles

The Model Context Protocol tool layer provides autonomous AI agents (such as Claude Desktop, ChatGPT, and custom LLM orchestrators) with direct access to precomputed multi-tenant product analytics.

```
+-----------------------------------------------------------------------------------+
|                            Incoming MCP Client Request                            |
|                       (POST /mcp with tools/call JSON-RPC)                        |
+-----------------------------------------------------------------------------------+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                           HTTP Middleware Pipeline                                |
|  AuthenticateMcp ──> ResolveMcpTenant ──> EnsureMcpConsent ──> EnforceEntitlement |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                       Tenant & Plan bound to request
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                               AnalyticsServer                                     |
|                   Dispatches tool invocation via ToolInvoker                      |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                               Tool Execution                                      |
|  FormatsToolMetadata ──> validateDateRange() ──> Domain Services (Sub-50ms)       |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                            Response::structured(...)                              |
|           Emits JSON-RPC structured content + JSON text fallback                  |
+───────────────────────────────────────────────────────────────────────────────────+
```

### Core Tenets:
1. **Strict Read-Only Guarantee:** Every analytics tool is strictly non-destructive. Calling a tool can never mutate business state, orders, line items, or customer records.
2. **Deterministic & Idempotent:** Repeated executions of any tool with identical arguments return identical results for a given database state.
3. **Closed-World Boundaries:** Tools operate exclusively over the authenticated store's localized data boundary without reaching out to external networks or unverified sources (`#[IsOpenWorld(false)]`).
4. **Platform Neutrality (No Shopify GID Leakage):** External AI agents only receive internal application UUIDs, handles, and SKU references. Raw Shopify GIDs (`gid://shopify/Product/...`) are never exposed.
5. **Exact REST Parity:** Tools delegate directly to domain service classes (`OverviewAnalyticsService`, `ProductPerformanceService`, `SalesTrendAnalyticsService`), guaranteeing exact cent-for-cent parity with the REST API.
6. **Zero Float Arithmetic:** Monetary values are handled as high-precision strings (`DECIMAL(20,6)`) or integer minor units, strictly isolated by the store's ISO 4217 currency code.

---

## 2. Tool Base Class & Protocol Contract

In `laravel/mcp`, all tools extend `Laravel\Mcp\Server\Tool` and implement three core methods:
- `schema(JsonSchema $schema): array`: Defines the JSON Schema contract for input arguments.
- `outputSchema(JsonSchema $schema): array`: Defines the expected JSON Schema structure of the tool's result.
- `handle(Request $request): ResponseFactory`: Executes the business logic and returns structured output.

```php
declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ExampleTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'example_tool';
    protected string $title = 'Example Tool';

    public function description(): string
    {
        return 'Concise description explaining what data the tool returns and when the LLM should invoke it.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date (YYYY-MM-DD, inclusive).'),
            'to' => $schema->string()->format('date')->description('End date (YYYY-MM-DD, inclusive).'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->object([...]),
            'metadata' => $schema->object([...]),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $shop = $this->resolveCurrentShop(request());
        // Domain logic execution...
        return Response::structured($payload);
    }
}
```

---

## 3. Protocol Annotations & LLM Guidance

The MCP standard (revision `2026-07-28`) provides hint annotations that LLM clients inspect to determine safety, execution policy, and caching behavior. Every tool in `shopify-product-analytics` applies three explicit annotations:

| Annotation | Protocol Key | Value | Semantic Meaning to AI Clients |
|---|---|---|---|
| `#[IsReadOnly]` | `readOnlyHint` | `true` | The tool never writes, mutates, or creates data. Safe to execute without user confirmation dialogs. |
| `#[IsIdempotent]` | `idempotentHint` | `true` | Calling the tool multiple times with the same parameters produces identical outcomes. Clients may retry on network drops. |
| `#[IsOpenWorld(false)]` | `openWorldHint` | `false` | The tool operates exclusively within a bounded, closed environment (the merchant's store tenant). It does not query external web APIs or arbitrary URLs. |

These annotations are mapped into the JSON-RPC response when clients query `tools/list`, allowing AI clients to construct execution graphs safely.

---

## 4. Tenant Context & Shared Metadata (`FormatsToolMetadata`)

Tools never accept a `shop_id` or tenant identifier from client arguments. Doing so would violate multi-tenant security boundaries. Tenant identity is resolved strictly from the authenticated session context established by `ResolveMcpTenant` middleware.

The shared trait `App\Mcp\Tools\Concerns\FormatsToolMetadata` provides standard capabilities across all 6 tools:

### 4.1 Tenant Resolution
```php
protected function resolveCurrentShop(mixed $request = null): Shop
{
    $shop = null;
    if ($request instanceof \Illuminate\Http\Request) {
        $shop = $request->attributes->get('mcp_shop');
    }
    $shop ??= request()->attributes->get('mcp_shop');

    if (! $shop instanceof Shop) {
        throw new \RuntimeException('MCP shop tenant is not resolved on the current request.');
    }

    return $shop;
}
```

### 4.2 Standard Metadata Payload
Every analytics tool embeds a standardized `metadata` object in its response, providing the LLM with context on store currency, timezone, data completeness, and plan tier:

```json
{
  "currency_code": "USD",
  "timezone": "America/New_York",
  "from": "2026-11-01",
  "to": "2026-11-07",
  "last_synced_at": "2026-11-20T12:00:00+00:00",
  "data_complete": true,
  "plan": "pro"
}
```

- **`data_complete`:** Informs the LLM if historical sync is finished. If `false`, the LLM is guided to advise the merchant that sync is in progress.
- **`last_synced_at`:** Freshness timestamp indicating the last completed sync run.
- **`currency_code`:** The ISO 4217 currency code. The LLM MUST cite this currency symbol and never assume default USD.

---

## 5. Input Validation & Actionable LLM Error Remediation

When an LLM supplies invalid arguments (e.g. inverted dates, invalid formats, or date ranges exceeding the merchant's plan), returning generic errors causes the model to hallucinate or enter retry loops.

The `validateDateRange` helper validates parameters and returns actionable remediation text directly formatted for LLM recovery:

```php
protected function validateDateRange(Shop $shop, string $from, string $to): array|Response
{
    if ($from > $to) {
        return Response::error("Invalid date range: 'from' date ({$from}) cannot be after 'to' date ({$to}).");
    }

    $tier = request()->attributes->get('mcp_plan_tier') ?? app(PlanEntitlementService::class)->resolvePlan($shop);
    $maxDays = $tier->maxAnalyticsDays();

    $fromDate = CarbonImmutable::parse($from);
    $toDate = CarbonImmutable::parse($to);
    $daysDiff = (int) $fromDate->diffInDays($toDate) + 1;

    if ($daysDiff > $maxDays) {
        return Response::error(
            "Requested date range ({$daysDiff} days) exceeds the {$tier->value} plan limit of {$maxDays} days. Please select a shorter date range or upgrade to Pro."
        );
    }

    $today = CarbonImmutable::now($shop->timezone ?? 'UTC')->toDateString();
    if ($from > $today || $to > $today) {
        return Response::error("The requested date range ({$from} to {$to}) includes future dates (store today is {$today}). Historical analytics are only available for past or current dates.");
    }

    $hasCompletedSync = SyncRun::query()
        ->where('shop_id', $shop->id)
        ->where('status', SyncRunStatus::Completed)
        ->exists();

    if (! $hasCompletedSync && ! DB::table('product_daily_metrics')->where('shop_id', $shop->id)->exists() && ! DB::table('orders')->where('shop_id', $shop->id)->exists()) {
        return Response::error("Data sync has not completed for this store. Historical analytics are unavailable until the initial sync finishes. Call the 'get_sync_status' tool to monitor progress.");
    }

    $historyStartDate = $this->resolveHistoryStartDate($shop);

    if ($historyStartDate !== null && $to < $historyStartDate) {
        return Response::error("The requested date range ({$from} to {$to}) is earlier than the store's imported history. Earliest available data is {$historyStartDate}. Call get_sync_status for details.");
    }

    return [$from, $to];
}
```

---

## 6. Dual-Representation Responses (`Response::structured`)

Under the MCP `2026-07-28` specification, tools return structured data objects. However, older clients or raw LLM consumers expect a text string.

Using `Response::structured($payload)` satisfies both:
1. **`result.structuredContent`:** Exposes the native JSON object for programmatic parsing by clients that support structured output.
2. **`result.content[0].text`:** Contains the JSON-encoded string representation of the payload as a fallback for standard text-based LLM interpreters.
3. **`result.isError`:** Set to `false` on success, or `true` when `Response::error(...)` is returned.

---

## 7. The 6 Core Analytics Tools Specification

| Tool Name | Class | Primary Domain Purpose | REST Parity Equivalent |
|---|---|---|---|
| `get_sync_status` | `GetSyncStatusTool` | Check background sync lifecycle, completeness, and freshness. | `GET /internal/v1/sync/status` |
| `get_analytics_overview` | `GetAnalyticsOverviewTool` | High-level KPI summary (gross, net, discounts, returns, AOV) with preceding period comparison. | `GET /api/v1/analytics/overview` |
| `list_product_performance` | `ListProductPerformanceTool` | Tabular product ranking sorted by sales, volume, or refund metrics with pagination (limit 1–50). | `GET /api/v1/products` |
| `get_product_analytics` | `GetProductAnalyticsTool` | In-depth single product performance breakdown by UUID or handle with variants and daily trend. | `GET /api/v1/products/{id}/analytics` |
| `get_sales_trend` | `GetSalesTrendTool` | Time-series data points grouped by day, week, or month for trend analysis. | `GET /api/v1/analytics/sales-trend` |
| `find_high_refund_products` | `FindHighRefundProductsTool` | Identification of refund anomalies, elevated return rates, and return amounts. | `GET /api/v1/products?sort=refund_rate` |

---

## 8. Verification & TDD Protocol

Every tool undergoes strict Test-Driven Development:
1. **Pest Feature Suite:** Each tool has a dedicated test file in `tests/Feature/Mcp/Tools/` testing annotations, input schemas, validation failures, plan barriers, and JSON-RPC execution via `POST /mcp`.
2. **Parity Inspection:** Monetary amounts returned by tools are asserted cent-for-cent against the underlying database rows and domain service calculations.
3. **Multi-Tenant Isolation:** Tests assert that data from neighboring shops in the test database is completely omitted from responses.
