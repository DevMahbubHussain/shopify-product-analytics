# Laravel MCP Server: Operations, Audit & Lifecycle Architecture

This document details the operational resilience, asynchronous audit logging, emergency kill switches, debounced token tracking, and tenant lifecycle purging for the Model Context Protocol (MCP) server.

---

## 1. Architectural Overview & Design Principles

The MCP interface exposes operational endpoints to external AI agent runtimes. To ensure operational stability, audit compliance, and multi-tenant security, the architecture implements the following pillars:

```
+───────────────────────────────────────────────────────────────────────────────────+
|                            Incoming Client MCP Request                            |
|                            (POST /mcp or GET /mcp/sse)                            |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                         Emergency Kill Switch Check                               |
|                  config('mcp.enabled') == false ──► 503 Disabled                  |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                        Authentication & Tenant Resolution                         |
|             AuthenticateMcp ──► ResolveMcpTenant ──► EnsureMcpConsent             |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                        Debounced Redis Touch (300s TTL)
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                         TouchMcpTokenLastUsed Job (Async)                         |
|                    Updates mcp_tokens.last_used_at timestamp                      |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                        Entitlement & Server Execution                             |
|                 EnforceMcpEntitlement ──► AnalyticsServer                         |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                       Terminable Audit Logging Middleware                         |
|                     Captures duration_ms, status, target                          |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                           Dispatches Async Queue Job
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                        RecordMcpAuditLogJob (Persistent)                          |
|                     Inserts immutable row in mcp_audit_logs                       |
+───────────────────────────────────────────────────────────────────────────────────+
```

### Core Tenets:
1. **Zero Customer PII in Audit Logs:** Audit records capture execution metadata exclusively: `shop_id`, `connection_type`, `method`, `target_name`, `status`, `duration_ms`, `client_name`, `protocol_version`, and `correlation_id`. No order line items, customer names, or merchant sales data are ever recorded in audit trails.
2. **Zero Request Latency Impact:** Database insertions for `mcp_audit_logs` and `last_used_at` updates execute strictly out-of-band via asynchronous queued jobs (`RecordMcpAuditLogJob` and `TouchMcpTokenLastUsed`).
3. **Emergency Circuit Breaker:** The system provides an instant kill switch via `config('mcp.enabled')` (environment variable `MCP_ENABLED=false`) returning `503 Service Unavailable` before any database queries execute.
4. **Tenant Isolation & Cascade Lifecycle Purging:** When a tenant is deactivated or purged (`TenantPurgeRequested`), cached Redis credentials and principal states are purged, while database cascades (`cascadeOnDelete`) remove all tokens, consents, and audit logs.

---

## 2. Background Queue Jobs

### 2.1. `RecordMcpAuditLogJob`
- **Class:** `App\Jobs\RecordMcpAuditLogJob`
- **Queue Interface:** Implements `ShouldQueue` with `Queueable`.
- **Schema Target:** `mcp_audit_logs` table.
- **Fields:**
  - `shop_id`: UUID of the authenticated store tenant.
  - `connection_type`: Authentication type (`token` or `oauth`).
  - `connection_id`: UUID of the `mcp_tokens` record (or null).
  - `method`: Protocol method invoked (e.g. `tools/call`, `resources/read`, `prompts/get`).
  - `target_name`: Target tool, resource URI, or prompt identifier.
  - `status`: `success`, `tool_error` (tool result `isError`), `protocol_error` (JSON-RPC `error`), `rate_limited` (429), `rejected` (401/403), or `error` (other HTTP errors).
  - `duration_ms`: Execution duration in milliseconds.
  - `client_name`: Client user agent or declared client runtime name.
  - `protocol_version`: MCP protocol version string (e.g. `2026-07-28`).
  - `correlation_id`: Unique UUID assigned per request for distributed tracing.
  - `created_at`: Immutable timestamp of execution.

### 2.2. `TouchMcpTokenLastUsed`
- **Class:** `App\Jobs\TouchMcpTokenLastUsed`
- **Queue Interface:** Implements `ShouldQueue` with `Queueable`.
- **Debounce Strategy:** Guarded by Redis atomic cache lock (`mcp:last_used:{tokenId}`) with a 300-second (5-minute) TTL to prevent write saturation on repeated agent calls.

---

## 3. Operational Middleware: `AuditMcpRequest`

- **Class:** `App\Http\Middleware\AuditMcpRequest`
- **Alias:** `mcp.audit`
- **Pipeline Position:** Placed at the very front of the MCP middleware pipeline (`routes/ai.php`).

### 3.1. Emergency Kill Switch (`handle`)
Checks `config('mcp.enabled', true)` (backed by the `MCP_ENABLED` environment variable).
- When `false`: Immediately halts processing and responds with `503 Service Unavailable`:
  ```json
  {
    "error": "mcp_disabled",
    "error_description": "The MCP interface is currently disabled."
  }
  ```
- Fast rejection occurs before hitting database connections or Redis token resolvers.
- Initializes microsecond start timing (`mcp_start_time`) and takes the correlation id already validated by `AssignCorrelationId` (a UUID; a malformed client `X-Correlation-ID` is replaced, never stored). At terminate time client-supplied values are cut to their column sizes so the audit insert cannot fail.

### 3.2. Out-of-Band Audit Logging (`terminate`)
Implements Laravel's terminable middleware contract (`terminate(Request $request, Response $response): void`).
- Executes strictly after HTTP response packets are transmitted to the client, guaranteeing zero latency overhead.
- Resolves authenticated `McpPrincipal` (omits unauthenticated probe traffic to maintain strict database tenant foreign key constraints).
- Maps HTTP status codes to execution status:
  - `200-399`: `success`
  - `401, 403`: `rejected` (e.g. invalid tokens, missing consent, or entitlement barriers)
  - `400+`: `error`
- Dispatches `RecordMcpAuditLogJob` to persistent background queues.

---

## 4. Tenant Lifecycle & Cache Purging

### 4.1. Event Listeners
1. **`RevokeMcpConnectionsOnTenantDeactivated`:**
   - Listens to: `App\Events\TenantDeactivated`
   - Actions: Atomically marks all `mcp_tokens.revoked_at` and `mcp_consents.withdrawn_at` with `deactivatedAt`. Purges Redis principal cache (`mcp:principal:{tokenHash}`).
2. **`PurgeMcpCacheOnTenantPurge`:**
   - Listens to: `App\Events\TenantPurgeRequested`
   - Actions: When a merchant uninstalls the app or data redaction is requested (`TenantPurgeService`), this listener queries all existing token hashes and token IDs for the tenant and forgets their Redis principal cache (`mcp:principal:*`) and debounce tracking keys (`mcp:last_used:*`).

### 4.2. Database Cascade Guarantees
PostgreSQL foreign keys enforce strict tenant isolation and deletion cleanup:
- `mcp_tokens.shop_id` references `shops(id)` on delete cascade.
- `mcp_consents.shop_id` references `shops(id)` on delete cascade.
- `mcp_audit_logs.shop_id` references `shops(id)` on delete cascade.

When `TenantPurgeService` executes `DB::table('shops')->where('id', $shop->id)->delete()`, all relational MCP child records are purged atomically by the database engine without leaving orphaned tokens or audit entries.

---

## 5. Verification & TDD Protocol

The operations and audit subsystem is tested under `tests/Feature/Mcp/Operations/`:
1. **Emergency Kill Switch:** Asserts that disabling `config('mcp.enabled')` yields an immediate `503 Service Unavailable` with `mcp_disabled`.
2. **Audit Job Persistence:** Asserts that `RecordMcpAuditLogJob` correctly inserts immutable audit logs into PostgreSQL with all execution metrics.
3. **Out-of-Band Audit Dispatch:** Asserts that terminable middleware captures latency and dispatches `RecordMcpAuditLogJob` after the HTTP response completes.
4. **Cache & State Purging:** Asserts that `TenantPurgeRequested` forgets all Redis keys and cascaded database records are removed cleanly.
