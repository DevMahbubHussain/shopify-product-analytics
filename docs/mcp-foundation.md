# MCP Foundation Milestone Explainer

This document outlines the foundation layer for the Model Context Protocol (MCP) server integration (`mcp-foundation`) built with Laravel's official package `laravel/mcp`.

---

## 1. What the Milestone Provides

The `mcp-foundation` milestone delivers the core infrastructure required to host a secure, platform-neutral, stateless Streamable HTTP MCP server:
1. **Package Installation:** `laravel/mcp` (v1.2.1) installed and auto-discovered.
2. **Database Migrations & Models:**
   - `mcp_consents`: Explicit merchant data-sharing consent storage (Shopify API Terms s.6.2(8)), secured by partial unique index `(shop_id) WHERE withdrawn_at IS NULL`.
   - `mcp_tokens`: SHA-256 hashed static bearer tokens (`spa_mcp_<base62>`).
   - `mcp_audit_logs`: Immutable metadata request audit trail (zero customer PII).
3. **Platform-Neutral Identity & Support Services:**
   - `McpPrincipal`: Readonly value object representing the store principal (`shopId`, `platform`, `staffRef`, `connectionId`, `connectionType`, `expiresAt`) with expiration detection (`isExpired()`).
   - `McpTokenService`: Cryptographic generation (256-bit entropy), hashing, validation, revocation, and Redis cache eviction.
4. **Security Middleware Pipeline & Gating:**
   - `AuthenticateMcp`: RFC 6750 case-insensitive Bearer token auth, fast-fail prefix rejection (`spa_mcp_`), Redis principal caching with TTL clamped to token `expires_at`, active `expires_at` validation on cache hits, queued debounced `TouchMcpTokenLastUsed` jobs, and failed-login IP throttling inside `unauthorized()` (preserving shared AI provider IPs for valid clients).
   - `ResolveMcpTenant`: Enforces store status `ShopStatus::Active` (rejects uninstalled/suspended stores with 403 `store_inactive`).
   - `EnsureMcpConsent`: Enforces data-sharing consent (rejects unconsented access with 403 `consent_required`).
   - `EnforceMcpEntitlement`: Strictly gates access to Pro plan (Free tier blocked with 403 `entitlement_denied` per PRD §7.7 and C7).
   - `throttle:mcp`: Redis-backed tier-specific rate limiting dynamically reading `$tier->mcpRateLimitPerMinute()` and `$tier->mcpRateLimitPerHour()`.
5. **Streamable HTTP Server & Routing:**
   - `AnalyticsServer`: Core server class extending `Laravel\Mcp\Server`.
   - `POST /mcp` registered in `routes/ai.php` adhering to MCP specification revision `2026-07-28`.
6. **Lifecycle & Domain Event Integration:**
   - `RevokeMcpConnectionsOnTenantDeactivated`: Auto-discovered listener on `TenantDeactivated` event that revokes all tokens, marks consent withdrawn, and clears Redis token caches upon store uninstall.
   - **Zero Duplicate Listener Discipline:** All listeners in `app/Listeners` rely exclusively on Laravel event auto-discovery (`@handle`). Manual `Event::listen` declarations in `AppServiceProvider` are strictly avoided to eliminate double job dispatching, verified by `tests/Feature/Infrastructure/EventListenerRegistrationTest.php`.
7. **Internal S2S APIs (`/internal/v1/mcp/*`):**
   - Endpoints for React Router / Shopify Admin: status check, concurrent-safe idempotent consent grant (with parent shop `lockForUpdate()` and unique constraint recovery), consent withdrawal, token creation (strictly Pro-tier gated), token listing, and token revocation (guarded with UUID route constraint).
   - All responses formatted via typed Laravel API Resources (`McpConsentResource`, `McpTokenResource`, `GeneratedMcpTokenResource`) per Constraint C14.
   - Standalone PSR-4 DTOs in `app/Data/Mcp/` (`GeneratedMcpToken`, `McpConsentData`, `StoreMcpTokenData`).

---

## 2. Request and Data Flows

### 2.1 External AI Client Request Flow (`POST /mcp`)
```
External AI Client (Claude, ChatGPT, Cursor)
       │
       ▼
   POST /mcp (Header: Authorization: Bearer spa_mcp_..., MCP-Protocol-Version: 2026-07-28)
       │
       ├─► Laravel\Mcp Middleware: ValidateOrigin, ValidateMcpHeaders (-32020 on mismatch)
       │
       ├─► AuthenticateMcp: Case-insensitive Bearer check -> Fast-fail prefix check (spa_mcp_) -> 
       │   Redis lookup (re-checks expires_at) or mcp_tokens lookup -> Dispatches queued TouchMcpTokenLastUsed.
       │   On auth failure: records failed attempt against caller IP (returning 429 after 60 failed attempts).
       │
       ├─► ResolveMcpTenant: Verifies Shop status == active (403 store_inactive on uninstalled)
       │
       ├─► EnsureMcpConsent: Verifies active mcp_consents record exists (403 consent_required)
       │
       ├─► EnforceMcpEntitlement: Checks PlanTier::canUseMcp() (Free -> 403 entitlement_denied; Pro allowed)
       │
       ├─► throttle:mcp: Redis rate limiter using plan limits (60/min connection, 600/hr shop)
       │
       ▼
 AnalyticsServer::startServer() ──► Executes requested method (server/discover, tools/list, etc.)
```

### 2.2 S2S Consent & Token Management Flow
```
Shopify Admin (React Router)
       │
       ▼  Signed HMAC S2S (X-App-Signature, X-App-Shop-Domain)
 /internal/v1/mcp/*
       │
       ├─► POST consent: Serializes on Shop::lockForUpdate() -> Idempotent check -> Creates active mcp_consents record inside DB::transaction(..., 5)
       │
       ├─► POST tokens: Checks Pro plan tier -> Checks consent -> Generates spa_mcp_<base62> -> Saves SHA-256 -> Returns plaintext once
       │
       ├─► GET connections: Lists tokens returning prefix only (spa_mcp_xxxx) without secrets
       │
       ├─► DELETE connections/{id}: Validates UUID -> Revokes specific token (scoped by shop_id) -> Clears Redis cache
       │
       └─► DELETE consent: Atomically marks consent withdrawn, revokes all tokens for the shop, and evicts all Redis caches
```

### 2.3 Store Uninstall Lifecycle Flow
```
Webhook Ingestion: app/uninstalled
       │
       ▼
 Ingestion Pipeline -> MarkShopUninstalled -> Dispatches TenantDeactivated event
       │
       ▼
 RevokeMcpConnectionsOnTenantDeactivated Listener (Auto-Discovered)
       │
       ├─► Marks all active mcp_tokens for shop as revoked (revoked_at = deactivated_at)
       ├─► Marks all active mcp_consents for shop as withdrawn (withdrawn_at = deactivated_at)
       └─► Flushes all Redis token cache keys (mcp:principal:{token_hash})
```

---

## 3. File-by-File Breakdown

| File | Purpose |
|---|---|
| `backend/composer.json` | Requires `laravel/mcp: ^1.2` |
| `docs/laravel-mcp/01_foundation_architecture.md` | In-depth technical architecture guide on `laravel/mcp` and spec `2026-07-28` |
| `backend/app/Data/Mcp/GeneratedMcpToken.php` | Standalone typed DTO for token generation results |
| `backend/app/Data/Mcp/McpConsentData.php` | Standalone typed DTO for consent requests |
| `backend/app/Data/Mcp/StoreMcpTokenData.php` | Standalone typed DTO for token creation requests |
| `backend/app/Enums/PlanTier.php` | Defines `canUseMcp()` (Free: false, Pro: true), `mcpRateLimitPerMinute()`, `mcpRateLimitPerHour()` |
| `backend/database/migrations/2026_11_24_000019_create_mcp_consents_table.php` | Schema for merchant data-sharing authorization with partial unique index |
| `backend/database/migrations/2026_11_24_000020_create_mcp_tokens_table.php` | Schema for hashed static bearer tokens |
| `backend/database/migrations/2026_11_24_000021_create_mcp_audit_logs_table.php` | Schema for request metadata audit logs |
| `backend/app/Models/McpConsent.php` | Eloquent model for consent records with `Platform` enum casting |
| `backend/app/Models/McpToken.php` | Eloquent model for static tokens with validation helpers |
| `backend/app/Models/McpAuditLog.php` | Eloquent model for immutable audit logging |
| `backend/app/Mcp/Support/McpPrincipal.php` | Platform-neutral value object representing the store principal with expiration checking |
| `backend/app/Mcp/Support/McpTokenService.php` | Cryptographic token generation, SHA-256 hashing, resolution, revocation, and Redis cache eviction |
| `backend/app/Http/Middleware/AuthenticateMcp.php` | Authenticates bearer tokens, enforces prefix check, caches principals in Redis with expiration-clamped TTL, checks expiration on cache hits, dispatches queued last_used job, and throttles failed logins by IP |
| `backend/app/Jobs/TouchMcpTokenLastUsed.php` | Queued job performing debounced asynchronous updates to `last_used_at` |
| `backend/app/Http/Middleware/ResolveMcpTenant.php` | Validates store existence and `ShopStatus::Active` |
| `backend/app/Http/Middleware/EnsureMcpConsent.php` | Enforces active data-sharing authorization |
| `backend/app/Http/Middleware/EnforceMcpEntitlement.php` | Strictly gates requests based on `PlanTier` (Free -> 403, Pro -> allowed) |
| `backend/app/Listeners/RevokeMcpConnectionsOnTenantDeactivated.php` | Auto-discovered listener permanently revoking all tokens, consent, and cache on uninstall |
| `backend/app/Http/Resources/Mcp/McpConsentResource.php` | API Resource formatting consent records |
| `backend/app/Http/Resources/Mcp/McpTokenResource.php` | API Resource formatting token list records |
| `backend/app/Http/Resources/Mcp/GeneratedMcpTokenResource.php` | API Resource formatting newly generated token response |
| `backend/app/Http/Controllers/Internal/Concerns/ResolvesTenantShop.php` | Shared trait providing uniform shop tenant resolution across S2S controllers |
| `backend/app/Mcp/Servers/AnalyticsServer.php` | Root MCP server class extending `Laravel\Mcp\Server` |
| `backend/routes/ai.php` | Registers `POST /mcp` with middleware pipeline |
| `backend/bootstrap/app.php` | Registers `routes/ai.php`, middleware aliases, priority ordering, and JSON exception handling |
| `backend/app/Providers/AppServiceProvider.php` | Configures dynamic plan rate limiter `RateLimiter::for('mcp')` |
| `backend/app/Http/Requests/Internal/StoreMcpTokenRequest.php` | FormRequest mapping into `StoreMcpTokenData` DTO |
| `backend/app/Http/Requests/Internal/McpConsentRequest.php` | FormRequest mapping into `McpConsentData` DTO |
| `backend/app/Http/Controllers/Internal/McpConsentController.php` | S2S controller for status, concurrent-safe idempotent grant, and consent withdrawal |
| `backend/app/Http/Controllers/Internal/McpTokenController.php` | S2S controller for token listing, Pro-gated creation, and UUID-constrained revocation |

---

## 4. Deferred to the OAuth Phase (`mcp-oauth-evals`)

The foundation supports static bearer tokens only. OAuth discovery is intentionally absent so clients are not pointed at an authorization server that does not exist yet. When Passport and `Mcp::oauthRoutes()` land (MCP PRD v2.1 Journey 2, §11.1), restore discovery:

| Item | Current state | Required in the OAuth phase |
|---|---|---|
| `WWW-Authenticate` on `401` (`AuthenticateMcp::unauthorized()`) | `Bearer realm="mcp", error="invalid_token"` | Add `resource_metadata="https://mcp.<app-domain>/.well-known/oauth-protected-resource"` so clients can start OAuth discovery (RFC 9728) |
| `GET /.well-known/oauth-protected-resource` | Not registered (an earlier version returned an empty `authorization_servers` list and invented scopes; removed) | Register with `resource` = the MCP resource URI on the MCP host, `authorization_servers` = the real Passport issuer, `bearer_methods_supported` = `["header"]`, `scopes_supported` = `["mcp:use"]` (the only scope `laravel/mcp` supports) |
| Authorization server metadata | Not registered | `GET /.well-known/oauth-authorization-server` advertising `code_challenge_methods_supported: ["S256"]` (RFC 8414) |
| Token audience | Static tokens only | Reject access tokens whose audience is not the MCP resource URI (RFC 8707) |

Verification for that phase: an unauthenticated `POST /mcp` returns `401` with `resource_metadata`; following it reaches valid protected-resource and authorization-server metadata; ChatGPT and Claude connectors complete OAuth end to end.
