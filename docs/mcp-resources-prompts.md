# MCP Resources, Prompts & Operations Milestone Delivery

This document summarizes the architecture, protocol implementations, request flows, file-by-file breakdown, edge cases handled, and verification evidence for the **`mcp-resources-prompts`** feature stream.

---

## 1. What the Feature Stream Provides

The `mcp-resources-prompts` milestone completes the full Model Context Protocol (MCP) server implementation for the Shopify Product Analytics platform using **`laravel/mcp`** under protocol revision `2026-07-28`. It provides three major subsystems:

1. **Passive Context Resources (`analytics://...`):**
   - `analytics://shop/context`: Shop metadata (currency, timezone, domain, install date) without leaking Shopify GIDs or access tokens.
   - `analytics://shop/sync-status`: Real-time ingestion state, data completeness flags, window boundaries, and history notice.
   - `analytics://docs/metric-definitions`: Authoritative mathematical formulas, rounding rules, half-open interval boundaries, and metric definitions.
2. **Orchestrated Analytical Prompts:**
   - `weekly_executive_brief`: 7-day revenue comparison, top/bottom product rankings, and data completeness warnings.
   - `refund_anomaly_audit`: Spike detection and variant-level root-cause analysis for elevated refund rates.
   - `product_deep_dive`: Product search, SKU-level breakdown, and sales velocity analysis.
3. **Operational Resilience, Audit Logging & Lifecycle Management:**
   - **Emergency Kill Switch:** Instant circuit-breaker via `config('mcp.enabled')` returning `503 Service Unavailable`.
   - **Asynchronous Audit Logging:** Terminable middleware (`AuditMcpRequest`) capturing execution duration, client identity, correlation IDs, and protocol methods out-of-band via `RecordMcpAuditLogJob` into `mcp_audit_logs`. Zero customer PII.
   - **Debounced Token Tracking:** Asynchronous `TouchMcpTokenLastUsed` updating `mcp_tokens.last_used_at` with Redis atomic debounce (300s TTL).
   - **Tenant Lifecycle Purging:** `PurgeMcpCacheOnTenantPurge` listening to `TenantPurgeRequested`, clearing all Redis credential caches and debounce locks, supported by database foreign key cascading (`cascadeOnDelete()`).

---

## 2. Request & Execution Flows

### 2.1. MCP Resource Read Flow (`resources/read`)
```
Client Request (POST /mcp with resources/read)
  └── AuditMcpRequest (checks config('mcp.enabled'), starts microsecond timer)
        └── AuthenticateMcp (validates spa_mcp_* token, debounces last_used_at)
              └── ResolveMcpTenant (binds Shop model to request)
                    └── EnsureMcpConsent (verifies active consent)
                          └── EnforceMcpEntitlement (verifies Pro subscription)
                                └── AnalyticsServer -> Resource::handle()
                                      └── Returns JSON-RPC result with MIME type & contents
  [Post-Response]: AuditMcpRequest::terminate() dispatches RecordMcpAuditLogJob
```

### 2.2. MCP Prompt Generation Flow (`prompts/get`)
```
Client Request (POST /mcp with prompts/get)
  └── Middleware Pipeline (Kill switch -> Auth -> Tenant -> Consent -> Entitlement)
        └── AnalyticsServer -> Prompt::handle()
              ├── Store Timezone Grounding (CarbonImmutable store yesterday)
              ├── Argument Clamping & Defaults (e.g. days 1-365, threshold 0-100)
              └── Template Formulation (Guides client tool sequence)
                    └── Returns JSON-RPC result.messages with user role instructions
  [Post-Response]: AuditMcpRequest::terminate() dispatches RecordMcpAuditLogJob
```

### 2.3. Tenant Lifecycle Purge Flow
```
Store Uninstallation / Redaction Webhook
  └── TenantPurgeService::purgeByDomain()
        └── DB::transaction()
              ├── Dispatches TenantPurgeRequested(shop_id)
              │     └── PurgeMcpCacheOnTenantPurge
              │           ├── Clears Redis mcp:principal:{tokenHash}
              │           └── Clears Redis mcp:last_used:{tokenId}
              └── Deletes shops row
                    ├── Foreign Key Cascade: deletes mcp_tokens
                    ├── Foreign Key Cascade: deletes mcp_consents
                    └── Foreign Key Cascade: deletes mcp_audit_logs
```

---

## 3. File-by-File Breakdown

### 3.1. Resources (`app/Mcp/Resources/`)
| File | Role |
|---|---|
| `ShopContextResource.php` | Store context under `analytics://shop/context`: currency, timezone, plan, history window, freshness. No platform identifiers (no shop domain, no GIDs). |
| `SyncStatusResource.php` | Sync status, counters, completeness and gateway `history_notice` under `analytics://shop/sync-status`. |
| `MetricDefinitionsResource.php` | Formulas and rules under `analytics://docs/metric-definitions`, including Monday-start weekly buckets, partial edge buckets, refund-rate exclusions, and which tools carry a comparison. |

### 3.2. Prompts (`app/Mcp/Prompts/`)
| File | Role |
|---|---|
| `WeeklyExecutiveBriefPrompt.php` | 7-day brief. `end_date` must be `YYYY-MM-DD` and not in the future (validation error otherwise). |
| `RefundAnomalyAuditPrompt.php` | Refund audit. `days` must be an integer 1–365 and `min_refund_rate_pct` a number 0–100 (validation error otherwise). |
| `ProductDeepDivePrompt.php` | Product deep dive. `product_query` (max 255 chars) is embedded as a JSON-quoted string so quotes/newlines cannot reshape the template; trend analysis uses `get_product_analytics.daily_trend` (product-level), not the store-level `get_sales_trend`. |

All prompts use `$request->validate()`; invalid arguments return JSON-RPC `-32602` with the validation message. No prompt embeds the shop domain or any platform identifier.

### 3.3. Operations (`app/Http/Middleware/`, `app/Jobs/`, `app/Models/`, `app/Listeners/`, `routes/console.php`)
| File | Role |
|---|---|
| `AuditMcpRequest.php` | Kill switch (`503 mcp_disabled`) and terminable audit dispatch. Correlation id comes from `AssignCorrelationId` (UUID-validated), never the raw client header. Client-supplied values are cut to column sizes (method 64, target 128, client 100, protocol 16). Status is derived from the response: `success`, `tool_error` (`result.isError`), `protocol_error` (JSON-RPC `error`), `rate_limited` (429), `rejected` (401/403), `error` (other). |
| `RecordMcpAuditLogJob.php` | Queued insert into `mcp_audit_logs`; `$tries = 5`, backoff `[5, 15, 60]`. |
| `McpAuditLog.php` | `MassPrunable`: rows older than 90 days (`RETENTION_DAYS`) are deleted. |
| `routes/console.php` | `model:prune --model=App\Models\McpAuditLog` scheduled daily. |
| `PurgeMcpCacheOnTenantPurge.php` | Synchronous listener clearing token principal caches on `TenantPurgeRequested`. |
| `config/mcp.php` | `mcp.enabled` (`MCP_ENABLED`). |
| `AnalyticsServer.php` | Registers 6 tools, 3 resources, 3 prompts. |

### 3.4. Tests (`tests/Feature/Mcp/`)
| File | Tests | Assertions |
|---|---|---|
| `Resources/ShopContextResourceTest.php` | 4 | 27 |
| `Resources/SyncStatusResourceTest.php` | 5 | 32 |
| `Resources/MetricDefinitionsResourceTest.php` | 5 | 27 |
| `Prompts/WeeklyExecutiveBriefPromptTest.php` | 11 | 38 |
| `Prompts/RefundAnomalyAuditPromptTest.php` | 12 | 33 |
| `Prompts/ProductDeepDivePromptTest.php` | 12 | 37 |
| `Operations/RecordMcpAuditLogJobTest.php` | 3 | 18 |
| `Operations/AuditMcpRequestTest.php` | 11 | 23 |
| `Operations/PurgeMcpCacheOnTenantPurgeTest.php` | 2 | 10 |
| `Operations/McpAuditLogPruneTest.php` | 2 | 5 |

---

## 4. Edge Cases Handled

1. **Zero customer PII** in audit rows, resources and prompts.
2. **Platform neutrality:** no GIDs and no shop domain in any resource or prompt (tests assert no `shopify` substring).
3. **Audit cannot be evaded:** a malformed `X-Correlation-ID` or oversized `Mcp-Name`/`Mcp-Method`/`MCP-Protocol-Version` still produces an audit row (previously the insert failed and the request went unrecorded).
4. **Accurate audit status:** tool errors (HTTP 200 with `isError`) and JSON-RPC errors (HTTP 400) are no longer recorded as `success`.
5. **Retention:** audit rows are purged after 90 days (PRD §10.1); `shop/redact` still removes everything for the shop via the `shops` cascade.
6. **Store timezone:** prompt date windows use `shops.timezone`.
7. **Entitlement barrier:** Free plan → `403 entitlement_denied` for tools, resources and prompts.
8. **Kill switch:** `mcp.enabled = false` → `503 mcp_disabled` before token lookup.
9. **No silent argument rewriting in prompts:** out-of-range `days`, non-numeric thresholds, natural-language or future `end_date` are rejected, not clamped.
10. **Template integrity:** a `product_query` containing quotes or blank lines stays inside one JSON string.
11. **Correct product trend:** the deep dive no longer asks for a `product_id` filter that `get_sales_trend` does not support (which returned store-wide data).
12. **Redis cleanup on purge:** `PurgeMcpCacheOnTenantPurge` clears principal and debounce keys inside the purge transaction.

---

## 5. Verification Evidence

- **Pest suite:** 522 passed (4,727 assertions).
- **Live probes** (real HTTP to `POST /mcp`, audit jobs processed by `laravel-worker`, temporary shop deleted afterwards): 8 of 8 requests audited, including the malformed-correlation-id and 300-char-name requests; statuses `success`, `tool_error`, `protocol_error` as expected; every `correlation_id` a valid UUID; target name stored at 128 chars; `failed_jobs` 0 before and after.
- **Resources/prompts live:** `shop_context` contains no shop domain; deep dive keeps the injected query inside a JSON string and no longer references `get_sales_trend`; `days: 9999` and `end_date: "+5 years"` return `-32602`.
- **Schedule:** `php artisan schedule:list` shows `model:prune --model='App\Models\McpAuditLog'` daily at `0 0 * * *`.
