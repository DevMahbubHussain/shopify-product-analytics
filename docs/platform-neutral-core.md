# Platform-Neutral Core (Level B Seams)

Additive changes that keep the Laravel core, and the MCP layer built on it, independent of Shopify-specific concepts. Shopify stays the only delivered platform; a later platform (e.g. WooCommerce) is added by building a new gateway, not by rewriting MCP, analytics, or tenant authorization.

Scope follows MCP PRD v2.1 §9.7: levels **A** (new MCP code is platform-neutral) and **B** (three seams in existing code, below). Level **C** (renaming `shopify_*_gid` columns, `ShopifyGid`, the S2S shop header, or `shops`) is out of scope.

---

## 1. What the Feature Provides

| Seam | Purpose | Consumer |
|---|---|---|
| `shops.platform` + `App\Enums\Platform` | One place to know which platform a tenant comes from (approval URL, consent text) | MCP `McpPrincipal`, `McpApprovalUrl` (later) |
| `TenantDeactivated` event | Signals "tenant stopped using the app" without exposing the Shopify `app/uninstalled` topic | MCP `RevokeMcpConnections` (later) |
| `TenantPurgeRequested` event | Signals "delete all tenant data" without exposing the Shopify `shop/redact` topic | MCP `PurgeMcpData` (later) |
| `sync_runs.history_notice` | Gateway states, in plain text, any limit on imported history; Laravel stores and returns it verbatim and never reasons about platform scopes such as `read_all_orders` | MCP `get_sync_status` (later) |

No listeners exist yet. The events are dispatched today so the MCP phase only adds listeners.

---

## 2. Design Rules

1. **Additive only.** No renames, no changed response shapes, no new required fields. Existing callers are unaffected.
2. **MCP never sees platform specifics.** MCP code reacts to the two domain events and reads `history_notice` as opaque text; it never references webhook topics, Shopify scopes, or GIDs.
3. **No speculative abstraction** (AGENTS C9.7, C10). `Platform` has one case. No `PlatformAdapter` interface or manager until a second platform exists.
4. **`platform` default is a deliberate exception** to migrations rule 8 ("no silent defaults for facts"). The value is our own classification, not data sourced from Shopify, and only one platform exists. When a second platform is added, its gateway must set it explicitly.

---

## 3. Data Flow

### 3.1 Uninstall → `TenantDeactivated`

```
Shopify app/uninstalled
  → React Router verifies HMAC
  → POST /internal/v1/ingestion/shop-uninstall (S2S)
  → MarkShopUninstalled pipe
       DB::transaction(..., 5):
         lock shop row → skip if already uninstalled or delivery predates install
         → status = uninstalled
       COMMIT
       if status actually changed: event(TenantDeactivated(shopId, uninstalledAt))
```

Dispatched **after commit**. A deadlock retry inside `DB::transaction(..., 5)` can never leak an event for a write that rolled back.

### 3.2 Redact → `TenantPurgeRequested`

```
Shopify shop/redact (48h after uninstall)
  → POST /internal/v1/ingestion/shop-redact (S2S)
  → PurgeShopTenant pipe → TenantPurgeService::purgeByDomain
       unknown shop → return (no event)
       DB::transaction(..., 5):
         event(TenantPurgeRequested(shopId))     ← listeners run here, shop row still exists
         delete tenant tables … delete shops row
       COMMIT
       delete exports/{shopId} directory
```

Dispatched **inside the transaction, before any delete**. Listeners run synchronously in the same transaction, so MCP cleanup and the core purge succeed or fail together.

### 3.3 Sync status → `history_notice`

```
runHistoricalSync (React Router)
  start call: { sync_type, status: running, cursor_metadata.window_start, history_notice }
  heartbeats / progress / completed: history_notice omitted
→ POST /internal/v1/ingestion/sync-status
  ReportSyncStatusRequest (nullable|string|max:255) → SyncStatusData → RecordSyncStatus
  statusFields(): array_filter drops null → missing notice never overwrites a stored one
→ sync_runs.history_notice

GET /internal/v1/sync/status → SyncRunResource → sync_run.history_notice (key always present, may be null)
```

---

## 4. File-by-File Breakdown

### Part 1: platform column

| File | Change |
|---|---|
| `backend/app/Enums/Platform.php` (new) | `enum Platform: string { case Shopify = 'shopify'; }` |
| `backend/database/migrations/2026_11_24_000017_add_platform_to_shops_table.php` (new) | `string('platform', 16)` NOT NULL default `'shopify'`; Postgres fills existing rows; `down()` drops it |
| `backend/app/Models/Shop.php` | `platform` cast to `Platform`; `$attributes` default `Platform::Shopify->value` so a model created without it is not `null` in memory before `fresh()`; not in `$fillable` |
| `backend/tests/Feature/Database/ShopPlatformTest.php` (new) | Column type/nullability/default, default on insert, NOT NULL rejection (SQLSTATE `23502`), enum cast |

### Part 2: lifecycle events

| File | Change |
|---|---|
| `backend/app/Events/TenantDeactivated.php` (new) | `final readonly`: `shopId`, `CarbonImmutable deactivatedAt` |
| `backend/app/Ingestion/Pipes/MarkShopUninstalled.php` | Transaction returns `[Shop, bool $deactivated]`; event dispatched after commit only when the status changed |
| `backend/app/Events/TenantPurgeRequested.php` (new) | `final readonly`: `shopId` |
| `backend/app/Services/TenantPurgeService.php` | `event(new TenantPurgeRequested($shop->id))` first statement inside the transaction |
| `backend/tests/Feature/Ingestion/ShopUninstallTest.php` | Event once with correct `shopId`/`deactivatedAt` (offset converted to UTC); none on repeat, stale delivery, unknown shop |
| `backend/tests/Feature/Ingestion/ShopRedactTest.php` | Event once while shop and product rows still exist; none for unknown/already purged shop; throwing listener rolls back DB purge and keeps export file |

### Part 3: history notice

| File | Change |
|---|---|
| `backend/database/migrations/2026_11_24_000018_add_history_notice_to_sync_runs_table.php` (new) | `string('history_notice', 255)->nullable()` |
| `backend/app/Http/Requests/Internal/ReportSyncStatusRequest.php` | Rule `nullable, string, max:255` |
| `backend/app/Data/Ingestion/SyncStatusData.php` | `?string $historyNotice` (last constructor parameter) |
| `backend/app/Models/SyncRun.php` | `history_notice` in `$fillable`; `@property string\|null` |
| `backend/app/Ingestion/Pipes/RecordSyncStatus.php` | `history_notice` added to `statusFields()` (start and advance) |
| `backend/app/Http/Resources/SyncRunResource.php` | `history_notice` returned |
| `backend/tests/Feature/Ingestion/WebhookAndSyncIngestionTest.php` | Stored on start; kept when omitted; replaced when sent; null when never sent; 256 chars / non-string → 422 with no row |
| `backend/tests/Feature/Sync/GetSyncStatusTest.php` | Notice returned; key present and `null` when absent |

### Part 4: gateway

| File | Change |
|---|---|
| `shopify-app/app/features/sync/syncOrchestrator.server.ts` | `SyncStatusBody.history_notice`; exported `ORDER_HISTORY_NOTICE`; sent on the start call only |
| `shopify-app/app/features/sync/syncOrchestrator.server.test.ts` | Start call carries the notice; later calls omit it |

---

## 5. Edge Cases and Decisions

| Case | Behaviour |
|---|---|
| Repeat `app/uninstalled` delivery | No event; first `uninstalled_at` kept |
| Uninstall delivered after reinstall (`uninstalled_at < installed_at`) | No status change, no event |
| Uninstall / redact for unknown shop | 404 / OK respectively; no event |
| Deadlock retry during uninstall | Event fires once, after the successful commit |
| Deadlock retry during purge | Event fires once per attempt; listeners **must be idempotent** (delete by `shop_id`) |
| Purge listener throws | Whole purge rolls back; export files untouched; error propagates |
| Purge listeners and `ShouldQueue` | Must be **synchronous**. Deliberate exception to C9.5: a queued listener would run after the `shops` row is gone |
| `Shop::create()` without `platform` | `Platform::Shopify` in memory (model default) and in DB (column default) |
| Notice omitted on update | Stored notice kept (same rule as `cursor_metadata`, `error_*`) |
| Clearing a notice | Not possible on an existing run (`null` is ignored); a new sync run starts empty |
| Notice > 255 chars or non-string | 422, no row written |

---

## 6. Verification

### Automated tests

| Suite | Result |
|---|---|
| Backend Pest | 355 passed (3,727 assertions); 14 new |
| Gateway `npm test` | 170 passed; 1 new |
| Gateway `tsc --noEmit`, `eslint` on changed files | Clean |

### Reversibility (development database)

- `000017`: rollback removes `shops.platform`; re-migrate backfills the existing shop to `shopify`.
- `000018`: rollback removes `sync_runs.history_notice`; re-migrate leaves all 3 existing runs `NULL`.

### Direct inspection (`psql`)

| Column | Type | Nullable | Default |
|---|---|---|---|
| `shops.platform` | `character varying(16)` | NO | `'shopify'::character varying` |
| `sync_runs.history_notice` | `character varying(255)` | YES | none |

### Runtime probes

| Probe | Result |
|---|---|
| `MarkShopUninstalled` first delivery | Listener sees `transactionLevel = 0`, status `uninstalled`; 2 queries (lock + update), unchanged |
| `MarkShopUninstalled` repeat delivery | No event; 1 query |
| `TenantPurgeService` | Listener sees `transactionLevel = 1`, shop row present; shop removed after commit |
| `RecordSyncStatus` start / advance without notice / advance with notice | Notice `A` / `A` / `B`; 3 / 2 / 2 queries, unchanged |
| Signed `GET /internal/v1/sync/status` on running backend | `200`; `sync_run` includes `history_notice` |

All probes used temporary shops or rolled-back transactions; no development data was changed.

### Performance

Columns are additive and not filtered on; no new query plans. Query counts on the changed paths are identical to before (table above).

---

## 7. Known Issues

1. **Test rate-limit carry-over (fixed).** `throttleWithRedis()` stores S2S rate-limit counters on the default Redis connection (test DB 2), not the `array` cache store, so they leaked between tests and runs: one run used 336 of the 600/min budget for `acme.myshopify.com`, and a second run within 60 s returned `429`. `tests/TestCase.php` now flushes test DB 2 before every test, guarded to DB 2 and the `spa-test-database-` prefix. Verified with three back-to-back full runs, all green, and development Redis untouched. Production rate limiting is unchanged.
2. **Duplicated 60-day value.** `ORDER_HISTORY_NOTICE` text and the literal window in `harvestOrders.server.ts` must change together. Fix (separate change): export `ORDER_HISTORY_DAYS` and derive both.
