# Laravel MCP Server: Shopify Polaris Embedded UI Architecture

This document details the embedded merchant user interface built with Shopify Polaris and App Bridge under `shopify-app/app/routes/app.mcp.tsx`, its underlying Service-to-Service (S2S) communication flows, token management lifecycle, merchant consent verification, and operational audit trail visualization.

---

## 1. Architectural Overview & Boundaries

The Model Context Protocol (MCP) interface requires administrative controls for Shopify merchants directly within Shopify Admin. In accordance with the application's strict trust boundaries (**Constraint C2**):
- The **Shopify Integration Layer (React Router)** exclusively authenticates merchant sessions via Shopify App Bridge, renders the Polaris UI, and executes signed S2S requests to Laravel.
- The **Data & Analytics Engine (Laravel REST API)** stores all tokens, consents, and audit logs in multi-tenant PostgreSQL tables (`mcp_tokens`, `mcp_consents`, `mcp_audit_logs`) and enforces Pro plan entitlement.
- **Shopify access tokens are NEVER shared with external AI clients.** Instead, merchants generate dedicated API bearer tokens with prefix `spa_mcp_` that grant scoped access to analytical tools and resources.

```
+───────────────────────────────────────────────────────────────────────────────────+
|                           Shopify Admin Embedded UI                               |
|               (React Router + Polaris Web Components at /app/mcp)                 |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                   Authenticated App Bridge Admin Session
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                           React Router Route Loaders                              |
|                   Parallel S2S requests via BackendClient                         |
+───────────────────────────────────────────────────────────────────────────────────+
                                          │
                  HMAC-SHA256 Signed S2S Requests (/internal/v1/mcp/*)
                                          │
                                          ▼
+───────────────────────────────────────────────────────────────────────────────────+
|                              Laravel Backend API                                  |
|        VerifyS2SSignature ──► ResolveTenantShop ──► Internal Controllers          |
+───────────────────────────────────────────────────────────────────────────────────+
      │                                    │                                  │
      ▼                                    ▼                                  ▼
McpConsentController              McpTokenController                McpAuditLogController
(status, grant, withdraw)      (connections, tokens, destroy)           (audit-logs)
```

---

## 2. Component Design & Embedded UI Layout

The embedded page (`app.mcp.tsx`) integrates into the Shopify Admin App Bridge navigation via `<s-app-nav>` and renders standard Polaris elements:

### 2.1. Plan Section
- Reads `status.plan.can_use_mcp`, `rate_limit_per_minute`, `history_days`.
- Pro: success badge plus rate limit and history window.
- Not entitled: explanation plus a `View plans` button to `/app/pricing`. No price is hard-coded in this page.
- Status unavailable (`status === null`): "Plan status unavailable"; a page-level critical banner names the failed sections and actions stay disabled.

### 2.2. Data-Sharing Authorization Section
- Reads `status.has_consent` and `status.consent`.
- Grant: submits `grant_consent`. The action sends `staff_ref = sessionToken.sub` (from `authenticate.admin()`, verified session token) and `policy_version = MCP_CONSENT_POLICY_VERSION`. Form fields named `staff_ref` or `policy_version` are ignored. No `sub` means the action is refused before any backend call.
- Withdraw: first click shows an inline warning; the second, explicit button submits `withdraw_consent`, which revokes all tokens and evicts Redis principal caches.

### 2.3. Connection Tokens Section
- `s-table` listing name, prefix, created, last used.
- Generate form: `s-text-field` (max 100 chars, matching `StoreMcpTokenRequest`) mirrored into a hidden `name` input so the value is part of the native form submission.
- One-time plaintext reveal banner with copy button; the token lives only in action data and is gone on the next navigation.
- Revoke: inline confirm per row; submits `revoke_token`. `revokeMcpToken()` accepts only UUIDs (`InvalidMcpTokenIdError` otherwise) and URL-encodes the id.

### 2.4. Client Connection Setup
- Endpoint comes from `MCP_PUBLIC_URL` via `resolveMcpPublicUrl()`: required, absolute, https (http only for `localhost`/`127.0.0.1`), trailing slash trimmed. The internal `BACKEND_API_URL` is never shown.
- Copyable remote MCP client block:
  ```json
  {
    "mcpServers": {
      "commerce-analytics": {
        "url": "https://<public-mcp-host>/mcp",
        "headers": {
          "Authorization": "Bearer <YOUR_MCP_TOKEN>"
        }
      }
    }
  }
  ```
- Client formats (checked against each client's published MCP configuration docs):
  - JSON-file clients using `mcpServers` → server entry with `url` + `headers`, no `type` field: the block above works as is.
  - JSON-file clients whose current format requires `"type": "http"` in the entry (same `mcpServers`, `url`, `headers` shape): the page tells merchants to add it.
  - Connector-form clients (no JSON file): endpoint plus a fixed `Authorization: Bearer <token>` request header entered in the form. They connect from the vendor's cloud, so the endpoint must be public https on port 443; a `localhost` `MCP_PUBLIC_URL` only works for clients on the same machine.
- No `MCP-Protocol-Version` header is pinned: clients negotiate it. The installed `laravel/mcp` serves `2026-07-28` (`ProtocolVersion::serverSupported()`) and accepts `2025-11-25`, `2025-06-18`, `2025-03-26` through the initialize handshake (`ProtocolVersion::initializeSupported()`, `vendor/laravel/mcp/src/Enums/ProtocolVersion.php`).

### 2.5. Audit Trail
- `s-table` with timestamp, method, target, client, latency, status badge.
- Pagination via `?page=`; `parseAuditPage()` maps anything other than a positive safe integer to 1.
- Page past the end (empty data, `total > 0`): shows "Go to first page" instead of the empty state.

### 2.6. Action Error Mapping
| Backend outcome | Shown to merchant |
|---|---|
| 4xx JSON with `error_description` / `message` | That text |
| 4xx non-JSON | Generic "request was rejected" |
| 5xx / network | Generic "service is unavailable" |
| `InvalidMcpTokenIdError` | "Invalid token id." |

Raw bodies are logged with `console.error`, never rendered.

---

## 3. Service-to-Service (S2S) Endpoints Reference

The Polaris route communicates with the following private endpoints under `/internal/v1/mcp`:

| HTTP Verb | Path | Controller Action | Purpose |
|---|---|---|---|
| `GET` | `/internal/v1/mcp/status` | `McpConsentController@status` | Fetches consent status, plan tier, rate limits, and token counts. |
| `POST` | `/internal/v1/mcp/consent` | `McpConsentController@grant` | Records explicit merchant data-sharing consent. |
| `DELETE` | `/internal/v1/mcp/consent` | `McpConsentController@withdraw` | Withdraws consent and atomically revokes all active tokens. |
| `GET` | `/internal/v1/mcp/connections` | `McpTokenController@index` | Lists active connection tokens for the shop tenant. |
| `POST` | `/internal/v1/mcp/tokens` | `McpTokenController@store` | Generates a new 256-bit token; returns plaintext once. |
| `DELETE` | `/internal/v1/mcp/connections/{id}` | `McpTokenController@destroy` | Revokes an individual token and evicts its Redis cache. |
| `GET` | `/internal/v1/mcp/audit-logs` | `McpAuditLogController@index` | Paginated audit metadata. `ListMcpAuditLogsRequest` → `McpAuditLogQueryData`: `page` integer >= 1, `per_page` integer 1–100 (default 25), invalid → 422. Ordered `created_at DESC, id DESC`. Unknown shop 404, inactive shop 403. |

---

## 4. Verification & Testing

1. **Audit endpoint:** `backend/tests/Feature/Internal/McpAuditLogApiTest.php` (auth, isolation, ordering, 422 bounds, page past end, tie ordering, 404/403 shops).
2. **Audit benchmark:** `backend/tests/Feature/Performance/McpAuditLogBenchmarkTest.php` (index use on first page, deep page and count over 41 shops / 42,000 rows; constant query count; p50 < 30 ms, p95 < 60 ms; heap delta < 2 MB via `memory_get_usage(false)`).
3. **Gateway client:** `shopify-app/app/features/mcp/mcpClient.server.test.ts` (incl. UUID guard) and `shopify-app/app/lib/backend/backendClient.server.test.ts`.
4. **Gateway route:** `shopify-app/app/routes/app.mcp.test.ts` (identity source, policy version, error mapping, load failures, page parsing, public URL rules).
5. **Typecheck & lint:** `npm run typecheck`, `npm run lint` clean.
