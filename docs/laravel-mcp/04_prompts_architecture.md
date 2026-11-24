# Laravel MCP Server: Prompts Architecture & Workflow Templates

This document details the architecture, design principles, protocol contracts, argument schemas, and execution workflows for the Model Context Protocol (MCP) prompt templates implemented with **`laravel/mcp`** under `app/Mcp/Prompts/`.

---

## 1. Architectural Philosophy & Design Principles

In the Model Context Protocol (revision `2026-07-28`), **Prompts** provide pre-engineered, user-controlled workflow templates that AI clients (such as Claude Desktop, ChatGPT, or Cursor) can discover and invoke to execute complex multi-step analytical tasks.

While **Tools** execute concrete operations against the database and **Resources** expose passive context streams, **Prompts** guide the AI client's reasoning process by specifying exact sequences of tool calls, temporal parameters, and synthesis guidelines.

```
+-----------------------------------------------------------------------------------+
|                            Incoming MCP Client Request                            |
|              (POST /mcp with prompts/list or prompts/get JSON-RPC)                |
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
|                Dispatches prompt template via ResolvesPrompts                     |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                               Prompt Execution                                    |
|   FormatsToolMetadata ──> Store Timezone Calculation ──> Template Composition     |
+───────────────────────────────────────────────────────────────────────────────────+
                                         │
                                         ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                            Response::text(...)                                    |
|            Emits JSON-RPC result.messages with user role and instructions         |
+───────────────────────────────────────────────────────────────────────────────────+
```

### Core Tenets:
1. **User-Controlled Workflow Templates:** Prompts are initiated explicitly by users selecting a workflow template in their AI interface.
2. **Template Independence (No Premature Data Embedding):** Per PRD §7.5, prompts are prompt templates only; they never embed live store metrics at `prompts/get` time. Instead, they provide structured instructions directing the model on which tools to call.
3. **Store Timezone Grounding:** Prompts automatically calculate store-local date parameters (e.g., store yesterday, rolling 7-day or 30-day windows) using `shops.timezone`.
4. **Data Completeness & Integrity Guardrails:** Prompt templates instruct the model to inspect `get_sync_status` and `data_complete` flags, ensuring AI responses prominently state caveats if data sync is pending or partial.
5. **Platform Neutrality:** Prompt text uses platform-neutral concepts and terms, avoiding proprietary platform identifiers or raw GIDs (`gid://shopify/...`).

---

## 2. Prompt Base Class & Protocol Contract

In `laravel/mcp`, all prompts extend `Laravel\Mcp\Server\Prompt` and declare metadata via PHP 8 attributes:
- `#[Name('...')]`: Unique prompt identifier invoked via `prompts/get`.
- `#[Title('...')]`: Human-readable title displayed in client UI selectors.
- `#[Description('...')]`: High-level summary of the prompt's analysis scope.

### Defining Arguments:
Arguments are declared by overriding `arguments(): array`:
```php
public function arguments(): array
{
    return [
        new Argument(
            name: 'end_date',
            description: 'End date for the weekly brief (YYYY-MM-DD, inclusive, defaults to store yesterday).',
            required: false,
        ),
    ];
}
```

### Execution Lifecycle:
When a client sends a `prompts/get` request:
1. `GetPrompt` resolves the prompt class from `AnalyticsServer::$prompts`.
2. Resolves parameters via `Container::call([$prompt, 'handle'])`.
3. Returns `result.description` and `result.messages` array where each message has `role: user` and `content: { type: 'text', text: '...' }`.

---

## 3. The 3 Official MCP Prompts Specification

| Prompt Name | Class | Arguments | Primary Analytical Scope |
|---|---|---|---|
| `weekly_executive_brief` | `WeeklyExecutiveBriefPrompt` | `end_date` (optional) | 7-day revenue overview, preceding period growth deltas, top/bottom product rankings, and data completeness warnings. |
| `refund_anomaly_audit` | `RefundAnomalyAuditPrompt` | `days` (optional, 30), `min_refund_rate_pct` (optional, 5) | Detects high refund items via `find_high_refund_products` and drills into variant-level return rates. |
| `product_deep_dive` | `ProductDeepDivePrompt` | `product_query` (required), `days` (optional, 30) | Searches for a specific product and evaluates variant breakdown, revenue trajectory, and daily trends. |

---

## 4. Prompt Specifications

### 4.1. `WeeklyExecutiveBriefPrompt`

- **Class:** `App\Mcp\Prompts\WeeklyExecutiveBriefPrompt`
- **Name:** `weekly_executive_brief`
- **Arguments:**
  - `end_date` (string, optional): Inclusive end date in `YYYY-MM-DD` format. Defaults to store yesterday in `shops.timezone`.

#### Workflow Steps Orchestrated by the Template:
1. **Sync Check:** Calls `get_sync_status` to verify whether `data_complete: true`.
2. **Overview KPIs:** Calls `get_analytics_overview` for the 7-day period ending on `end_date` (the overview always includes the preceding-period comparison).
3. **Top Performers:** Calls `list_product_performance` for top 5 products sorted by `net_sales` descending.
4. **Underperformers:** Calls `list_product_performance` for bottom 5 products sorted by `net_sales` ascending.
5. **Report Synthesis:** Compiles Executive Summary, KPI table, product rankings, and strategic merchandising recommendations.

---

### 4.2. `RefundAnomalyAuditPrompt`

- **Class:** `App\Mcp\Prompts\RefundAnomalyAuditPrompt`
- **Name:** `refund_anomaly_audit`
- **Arguments:**
  - `days` (integer, optional): Number of historical days to audit (1-365, default: 30).
  - `min_refund_rate_pct` (number, optional): Minimum refund rate percentage threshold (0-100, default: 5).

#### Workflow Steps Orchestrated by the Template:
1. **Sync Check:** Calls `get_sync_status` to verify data completeness and ingestion freshness.
2. **Identify High Refund Products:** Calls `find_high_refund_products` with `min_refund_rate: {min_refund_rate_pct}`, `sort_by: "refund_rate"`, `direction: "desc"`, and `limit: 10` for the calculated window.
3. **Variant-Level Drill-Down:** For the top 3 products with the highest return rates or monetary return values, calls `get_product_analytics` with `include_variants: true` and `include_daily_trend: true` to isolate whether returns are concentrated in specific SKUs/variants or uniform.
4. **Report Synthesis:** Compiles Executive Summary, High-Risk Products Breakdown table, Variant Return Concentration analysis, and Prioritized Remediation Action Plan.

---

### 4.3. `ProductDeepDivePrompt`

- **Class:** `App\Mcp\Prompts\ProductDeepDivePrompt`
- **Name:** `product_deep_dive`
- **Arguments:**
  - `product_query` (string, required): Product title, keyword, or handle to search and analyze.
  - `days` (integer, optional): Number of historical days to analyze (1-365, default: 30).

#### Workflow Steps Orchestrated by the Template:
1. **Sync Check:** Calls `get_sync_status` to verify background sync completion and fresh data.
2. **Locate Product:** Calls `list_product_performance` with `search: "{product_query}"` and the calculated date range to resolve the matching `product_id`.
3. **Variant & Performance Drill-Down:** Calls `get_product_analytics` with `product_id: {product_id}`, `include_variants: true`, and `include_daily_trend: true` to inspect SKU-level unit volumes, revenue, and return distributions.
4. **Trajectory & Sales Velocity:** Uses the product-level `daily_trend` returned by `get_product_analytics` to analyze sales momentum and daily velocity. (`get_sales_trend` has no product filter and returns store-wide totals, so it is not used here.)
5. **Report Synthesis:** Compiles Product Overview, Variant Performance Breakdown table, Trajectory & Momentum analysis, Return & Risk Evaluation, and actionable Merchant Recommendations.

---

## 5. Verification & TDD Protocol

Prompts undergo feature testing under `tests/Feature/Mcp/Prompts/`:
1. **Metadata & Arguments:** Asserts names, descriptions, argument types, and required flags.
2. **JSON-RPC Listing (`prompts/list`):** Asserts that registered prompts appear in the client catalog.
3. **JSON-RPC Generation (`prompts/get`):** Verifies date window calculations, argument substitution, tool instructions, and zero platform GIDs.
4. **Subscription Entitlement:** Asserts that Free tier shops receive 403 Forbidden with `entitlement_denied`.
