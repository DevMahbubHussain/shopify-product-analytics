# Laravel MCP Server: Resources Architecture & Protocol Contracts

This document details the architecture, design principles, protocol contracts, schema specifications, and security guardrails for the Model Context Protocol (MCP) resources implemented with **`laravel/mcp`** under `app/Mcp/Resources/`.

---

## 1. Architectural Philosophy & Design Principles

In the Model Context Protocol (revision `2026-07-28`), **Resources** provide passive, application-controlled context directly into an AI client session without requiring the model to initiate an active tool call. While tools represent callable actions that return computed results over arbitrary parameters, resources represent known entity streams and reference documents identified by standard URIs.

```
+-----------------------------------------------------------------------------------+
|                            Incoming MCP Client Request                            |
|             (POST /mcp with resources/list or resources/read JSON-RPC)            |
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
|               Dispatches resource invocation via ResolvesResources                |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                              Resource Execution                                   |
|   FormatsToolMetadata ──> resolveCurrentShop() ──> Domain/Model Serialization     |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                        Response::json(...) / text(...)                            |
|             Emits JSON-RPC result.contents with URI and MIME type                 |
+───────────────────────────────────────────────────────────────────────────────────+
```

### Core Tenets:
1. **Application-Controlled Context:** Resources expose curated metadata and reference documentation directly to AI clients, grounding the model in store specifics before user prompts are processed.
2. **Platform-Neutral URI Scheme (`analytics://`):** All resources use the `analytics://` URI scheme rather than platform-specific schemes (such as `shopify-analytics://`), ensuring strict platform neutrality (PRD §7.4).
3. **Strict Zero Shopify GID Leakage:** Data payloads contain internal UUIDs, handles, currency codes, and timestamps. Raw Shopify platform GIDs (`gid://shopify/Shop/...`) are strictly filtered out.
4. **Deterministic & Cacheable:** Resource endpoints are idempotent and safe. Reference documentation is statically cached; operational context is computed from indexed database states.
5. **Tenant Scoping & Isolation:** Resource requests pass through the exact same authentication and tenant-scoping middleware chain (`AuthenticateMcp`, `ResolveMcpTenant`, `EnsureMcpConsent`, `EnforceMcpEntitlement`) as tool calls. Store A can never read Store B's context.

---

## 2. Resource Base Class & Protocol Contract

In `laravel/mcp`, all resources extend `Laravel\Mcp\Server\Resource` and utilize declarative PHP 8 attributes:
- `#[Name('...')]`: Internal machine identifier.
- `#[Title('...')]`: Human-readable title displayed in client UIs.
- `#[Uri('analytics://...')]`: Canonical URI identifier.
- `#[MimeType('application/json' | 'text/markdown')]`: Media type of the resource content.
- `#[Description('...')]`: Explanatory description helping AI clients understand when to load the resource.

### Protocol Methods Supported:
- **`resources/list`:** Returns the list of available resources registered on `AnalyticsServer::$resources`.
- **`resources/read`:** Invokes the resource's `handle(Request $request): Response` method and returns `result.contents[0]` with `uri`, `mimeType`, and `text`.

---

## 3. The 3 Official MCP Resources Specification

| Resource Name | Class | URI | MIME Type | Primary Domain Purpose |
|---|---|---|---|---|
| `shop_context` | `ShopContextResource` | `analytics://shop/context` | `application/json` | Exposes store domain, currency, timezone, active plan limits, and historical data boundaries. |
| `sync_status` | `SyncStatusResource` | `analytics://shop/sync-status` | `application/json` | Exposes background sync run lifecycle state, record processing counters, and data completeness. |
| `metric_definitions` | `MetricDefinitionsResource` | `analytics://docs/metric-definitions` | `text/markdown` | Static Markdown reference document explaining formulas, half-open date boundaries, and comparisons. |

---

## 4. Deep Dive: `ShopContextResource`

- **Class:** `App\Mcp\Resources\ShopContextResource`
- **URI:** `analytics://shop/context`
- **MIME:** `application/json`

### Payload Schema:
```json
{
  "currency_code": "USD",
  "timezone": "America/New_York",
  "plan": "pro",
  "max_history_days": 365,
  "history_start_date": "2026-03-01",
  "history_end_date": "2026-11-24",
  "data_complete": true,
  "last_synced_at": "2026-11-24T14:30:00Z"
}
```

### Implementation Mechanics:
- Binds to the current tenant via `$this->resolveCurrentShop(request())`.
- Resolves the active subscription tier via `PlanEntitlementService::resolvePlan()`.
- Calculates available historical date boundaries via `resolveHistoryStartDate($shop)`:
  - Parses `cursor_metadata.window_start` from the latest completed initial sync.
  - Falls back to `installed_at − 60 days`.
  - Incorporates `MIN(metric_date)` from `product_daily_metrics` if older records exist.
- Formats payload using `Response::json(...)` without any platform GIDs.

---

## 4.2 Deep Dive: `SyncStatusResource`

- **Class:** `App\Mcp\Resources\SyncStatusResource`
- **URI:** `analytics://shop/sync-status`
- **MIME:** `application/json`

### Payload Schema:
```json
{
  "status": "COMPLETED",
  "sync_type": "initial",
  "records_received": 250,
  "records_processed": 250,
  "data_complete": true,
  "last_synced_at": "2026-11-24T14:30:00Z",
  "history_notice": "Initial 60-day historical order sync complete.",
  "currency_code": "USD",
  "timezone": "America/Chicago",
  "plan": "pro"
}
```

### Implementation Mechanics:
- Queries the latest `SyncRun` record for operational lifecycle status (`COMPLETED`, `RUNNING`, `FAILED`, or `NO_SYNC`).
- Exposes record processing progress counters (`records_received`, `records_processed`) and `sync_type`.
- Sets `data_complete: true` only if at least one sync run has completed successfully for the store.
- Evaluates `last_synced_at` from the most recent completed sync run timestamp.
- Formats structured JSON via `Response::json(...)` with zero platform GIDs.

---

## 4.3 Deep Dive: `MetricDefinitionsResource`

- **Class:** `App\Mcp\Resources\MetricDefinitionsResource`
- **URI:** `analytics://docs/metric-definitions`
- **MIME:** `text/markdown`

### Document Contents & Grounding Sections:
1. **Core Financial Formulas:**
   - Gross Sales: $\sum (\text{Quantity} \times \text{Original Unit Price})$ prior to discounts and returns.
   - Discounts: Aggregated coupon and line-level discount allocations in store currency.
   - Refunds: Attributed strictly to the refund's processing date (`processed_at`), preventing historical order day restatement.
   - Net Sales: $\text{Gross Sales} - \text{Discounts} - \text{Refunds}$ (with explicit negative Net Sales handling).
   - Units Sold: $\text{Gross Units} - \text{Refunded Units}$.
   - Orders Count: $\text{COUNT}(\text{DISTINCT } \text{Order ID})$.
   - Average Order Revenue: $\frac{\text{Net Sales}}{\text{Orders Count}}$ (with 0-order division safety).
   - Refund Rate: $\left(\frac{\text{Refunds}}{\text{Gross Sales}}\right) \times 100$.
2. **Temporal Precision & Day Boundaries:**
   - Half-Open Intervals: Enforces $[\text{startOfDayUtc}, \text{startOfNextDayUtc})$ in store local timezone.
   - Store Timezone Grounding: Local calendar days converted to UTC microseconds.
3. **Currency Isolation (ISO 4217):**
   - Monetary figures partitioned strictly by currency code; zero foreign currency cross-summing.
4. **Preceding Period Comparison Math:**
   - Evaluates $\frac{\text{Current} - \text{Preceding}}{|\text{Preceding}|} \times 100$ using absolute denominator to prevent growth inversion on negative baselines.

---

## 5. Verification & TDD Protocol

Resources undergo rigorous feature testing under `tests/Feature/Mcp/Resources/`:
1. **Metadata & Annotations:** Verifies URI, MIME type, name, and description.
2. **JSON-RPC Listing (`resources/list`):** Asserts that registered resources appear in the server catalog.
3. **JSON-RPC Read (`resources/read`):** Asserts that `uri` and `text` are returned with 200 OK.
4. **Tenant Isolation:** Asserts that Store B's credentials return only Store B's metadata with zero cross-tenant leakage.
5. **Platform Neutrality:** Asserts zero platform GIDs (`gid://shopify/...`) in the response text.
