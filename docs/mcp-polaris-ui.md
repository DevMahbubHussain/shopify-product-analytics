# MCP Polaris UI Milestone Delivery

This document summarizes the architecture, implementation, request flows, file-by-file breakdown, edge cases handled, and verification evidence for the **`mcp-polaris-ui`** feature stream.

---

## 1. What the Feature Stream Provides

The `mcp-polaris-ui` milestone delivers the merchant-facing administrative and governance interface for Model Context Protocol (MCP) integrations inside Shopify Admin using Shopify Polaris and App Bridge. It provides:

1. **Merchant Consent Management:** A dedicated interface enabling store owners to grant or withdraw explicit data-sharing authorization for external AI agent connections.
2. **Token Management & One-Time Reveal:** Generation of scoped API tokens with prefix `spa_mcp_`, one-time plaintext reveal with instant clipboard copy, listing of active tokens with last-used timestamps, and immediate revocation.
3. **Client Configuration Generator:** A copyable remote MCP client block (`url` + bearer `Authorization` header) pointing at the public endpoint from `MCP_PUBLIC_URL`. The page also covers clients that need `"type": "http"` and connector-form clients (endpoint + `Authorization` request header, public https on 443 required).
4. **Audit Trail Explorer:** Real-time visibility into incoming operations (protocol methods, tool invocations, client runtimes, duration in ms, and status badges) with zero customer PII.
5. **Subscription Gating & Navigation:** App Bridge `<s-app-nav>` integration with real-time entitlement checking (Pro vs Free tier upgrade CTAs).

---

## 2. Request & Execution Flows

### 2.1. Page Load Flow (`/app/mcp`)
```
Merchant opens Shopify Admin -> App Navigation -> "AI & MCP"
  └── React Router Route Loader (app.mcp.tsx)
        ├── Authenticates admin session via App Bridge
        ├── parseAuditPage(?page): digits only, safe integer >= 1, else 1
        ├── resolveMcpPublicUrl(): MCP_PUBLIC_URL required, https (http only for localhost); throws otherwise
        └── Dispatches parallel S2S requests to Laravel API (each failure recorded in loadErrors):
              ├── GET /internal/v1/mcp/status (consent state, plan tier, limits)
              ├── GET /internal/v1/mcp/connections (active tokens list)
              └── GET /internal/v1/mcp/audit-logs (paginated execution trail)
  └── Renders Polaris components with state and configurations
```

### 2.2. Token Creation & Revocation Flow
```
Merchant creates token -> Submits form with "create_token" intent
  └── Route action: authenticate.admin() -> sessionToken.sub (verified session token)
  └── Action Handler (app.mcp.server.ts)
        ├── staff_ref = sessionToken.sub (form-supplied staff_ref is ignored; missing sub -> refused)
        └── POST /internal/v1/mcp/tokens (S2S signed)
              └── Laravel creates 256-bit token & returns plaintext once
  └── Action returns generatedToken
  └── UI displays prominent one-time copy banner

Merchant revokes token -> clicks Revoke -> confirms -> "revoke_token" intent
  └── Action Handler (app.mcp.server.ts)
        ├── revokeMcpToken(): token id must be a UUID, URL-encoded; otherwise no backend call
        └── DELETE /internal/v1/mcp/connections/{id} (S2S signed)
              └── Laravel marks revoked_at and evicts Redis cache
  └── Action triggers revalidation; token immediately disappears from table

Merchant grants consent -> "grant_consent" intent
  └── staff_ref = sessionToken.sub, policy_version = MCP_CONSENT_POLICY_VERSION (server constant)

Merchant withdraws consent -> clicks Withdraw -> confirms -> "withdraw_consent" intent
  └── DELETE /internal/v1/mcp/consent (revokes every active token)
```

### 2.3. Action Error Handling
| Backend outcome | Message shown to merchant |
|---|---|
| 4xx with JSON `error_description` or `message` | That text |
| 4xx with non-JSON body (proxy page) | Generic "request was rejected" |
| 5xx or network failure | Generic "service is unavailable" |
| Invalid token id | "Invalid token id." |

Raw backend bodies are logged server-side only.

---

## 3. File-by-File Breakdown

### 3.1. Frontend (`shopify-app/app/`)
| File | Role |
|---|---|
| `routes/app.mcp.tsx` | Polaris web-component page (`s-section`, `s-table`, `s-text-field`, `s-banner`). Passes `sessionToken.sub` to the action; load-error banner; inline confirmation for withdraw/revoke; past-last-page recovery. |
| `routes/app.mcp.server.ts` | `loadMcpData` (parallel loads, `loadErrors`, no invented plan), `handleMcpAction` (session staff identity, server policy version, safe error mapping), `parseAuditPage`, `resolveMcpPublicUrl`. |
| `routes/app.mcp.test.ts` | Tests for data assembly, every intent, forged staff_ref/policy_version ignored, missing staff id, non-UUID revoke, 4xx/5xx/non-JSON error mapping, status load failure, page parsing, public URL rules. |
| `features/mcp/mcpClient.server.ts` | Typed S2S client functions, `MCP_CONSENT_POLICY_VERSION`, UUID guard (`InvalidMcpTokenIdError`) on revoke. |
| `features/mcp/mcpClient.server.test.ts` | Unit tests for all S2S MCP client helper functions. |
| `lib/backend/backendClient.server.ts` | Added `deleteInternal`; all verbs share one private `signedFetch` (S2S contract unchanged). |
| `lib/backend/backendClient.server.test.ts` | Added tests for `deleteInternal` request signing and error handling. |
| `routes/app.tsx` | Added `<s-link href="/app/mcp">AI & MCP</s-link>` to the App Bridge navigation menu. |

### 3.2. Backend (`backend/`)
| File | Role |
|---|---|
| `app/Http/Controllers/Internal/McpAuditLogController.php` | `GET /internal/v1/mcp/audit-logs`: tenant-scoped, ordered `created_at DESC, id DESC` for stable pages. |
| `app/Http/Requests/Internal/ListMcpAuditLogsRequest.php` | Validates `page` (integer >= 1) and `per_page` (integer 1–100); invalid values return 422. |
| `app/Data/Mcp/McpAuditLogQueryData.php` | Read-only DTO (`page`, `perPage`, default 25, max 100). |
| `app/Http/Resources/Mcp/McpAuditLogResource.php` | API resource formatting audit log records for the UI table. |
| `routes/api.php` | Registered `audit-logs` endpoint in the internal MCP route group. |
| `tests/Feature/Internal/McpAuditLogApiTest.php` | S2S auth, tenant isolation, ordering, pagination bounds (422 cases), page past end, tie ordering, unknown (404) and uninstalled (403) shops. |
| `database/migrations/2026_11_25_000022_alter_mcp_audit_logs_created_at_to_microsecond_precision.php` | `created_at` to `timestamptz(6)`; down reverts to `timestamptz(0)`. |
| `app/Models/McpAuditLog.php` | `$dateFormat` with microseconds and offset. |
| `app/Http/Controllers/Internal/McpTokenController.php` | Connections list filtered to active tokens. |
| `tests/Feature/Performance/McpAuditLogBenchmarkTest.php` | 41 shops / 42,000 rows: index use on first page, deep page and count; constant query count; p50/p95; heap delta. |

### 3.3. Documentation (`docs/`)
| File | Role |
|---|---|
| `docs/laravel-mcp/06_polaris_ui_architecture.md` | Exhaustive architecture and protocol guide for the Polaris UI subsystem. |
| `docs/mcp-polaris-ui.md` | Milestone delivery explainer and verification report. |

### 3.4. Configuration
| File | Role |
|---|---|
| `shopify-app/.env.example`, `.env.example` | `MCP_PUBLIC_URL` key (public MCP endpoint shown to merchants). |
| `docker-compose.yml` | Passes `MCP_PUBLIC_URL` to the `app` profile container; required (`${MCP_PUBLIC_URL:?}`), so every compose command fails fast until the root `.env` sets it. |

---

## 4. Edge Cases Handled

1. **One-Time Token Visibility:** Plaintext tokens are never stored in the database (only 64-char SHA-256 hashes are retained). The UI provides a dedicated banner to copy the token immediately upon creation, preventing subsequent exposure.
2. **Immediate Cache Eviction:** Revoking a token or withdrawing consent immediately purges Redis principal caches, terminating active agent connections within milliseconds.
3. **Plan Entitlement Protection:** Free tier stores cannot create connection tokens; the UI displays an informative upgrade card with a direct link to `/app/pricing`.
4. **Backend Load Failures:** A failed section is listed in `loadErrors`; the page shows a critical banner and disables actions. A failed status load returns `status: null` instead of pretending the store is on the Free plan.
5. **Zero Customer PII:** The audit trail strictly excludes personal customer information.
6. **Staff Identity:** Consent and token records carry the staff id from the verified session token (`sessionToken.sub`). Form fields cannot set it; a session without `sub` is refused.
7. **Consent Policy Version:** Fixed server-side (`MCP_CONSENT_POLICY_VERSION`); the form cannot claim another version.
8. **Public Endpoint URL:** Taken from `MCP_PUBLIC_URL`, never from the internal `BACKEND_API_URL`. Missing, malformed or plain-http (non-localhost) values throw.
9. **Token Id Tampering:** Revoke accepts UUIDs only and URL-encodes them, so values like `../consent` never reach the backend.
10. **Error Leakage:** 5xx and non-JSON bodies are replaced by generic messages.
11. **Pagination Bounds:** Gateway turns `NaN`, negative, fractional or unsafe `page` values into 1. Laravel rejects invalid `page`/`per_page` with 422 and caps `per_page` at 100. A page past the end shows a "Go to first page" action instead of the empty-state text.
12. **Stable Ordering:** `id DESC` breaks `created_at` ties, so rows are never repeated or skipped across pages.
14. **Audit Timestamp Precision:** `mcp_audit_logs.created_at` is `timestamptz(6)` (migration `2026_11_25_000022`) and `McpAuditLog::$dateFormat = 'Y-m-d H:i:s.uP'`, because Eloquent's default `Y-m-d H:i:s` drops microseconds on write. Calls made within the same second keep their real order.
15. **Active Tokens Only:** `GET /internal/v1/mcp/connections` applies `McpToken::active()`, so revoked and expired tokens leave the list and the sidebar count.
16. **Token Field Contract:** the creation response carries the secret as `plaintext_token`; the gateway type and UI read that field.
13. **Destructive Actions:** Withdraw and Revoke ask for inline confirmation before submitting.

---

## 5. Verification Evidence

- **Frontend Tests (`shopify-app`):** **196 passed (0 failed)** via `npm test`.
- **TypeScript Typecheck:** Clean (`npm run typecheck`).
- **Frontend Linter:** Clean (`npm run lint`).
- **Backend Tests (`backend`):** **544 passed (4,884 assertions)** via Pest.
- **Backend Pint:** Clean on all touched files.
- **Query plans (41 shops, 42,000 audit rows, natural planner):** first page `Index Scan Backward using mcp_audit_logs_shop_created_idx` + `Incremental Sort` (0.04 ms); deep page (offset 1900) `Bitmap Index Scan on mcp_audit_logs_shop_created_idx` (0.45 ms); count `Bitmap Index Scan` (0.17 ms). With only 5 shops (20% of rows per shop) the planner picks a sequential scan for deep pages, which is the cheaper plan at that selectivity.
- **Live S2S probe (dev backend):** default 200; `per_page=500`, `per_page=0`, `page=abc` → 422; `page=9999` → 200 with empty data and real meta; revoke of unknown UUID → 404. No errors in backend logs.
