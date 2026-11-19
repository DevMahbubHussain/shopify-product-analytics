# Shopify Sync Trigger & Status (Step 3, Group G)

Specification and architectural explainer of the background synchronization trigger, per-shop concurrency locking, and status synchronization wiring into the embedded Shopify Admin UI.

---

## 1. What This Feature Provides

1. **Non-blocking Background Trigger (`triggerHistoricalSync`):**
   * Detaches historical sync execution from the web request cycle via `Promise.resolve().then(...)`.
   * Ensures the main route loader responds in $<50\text{ms}$ without blocking on large GraphQL page crawls.
   * Isolates background uncaught errors so node processes never crash from unhandled rejections.

2. **In-Process Concurrency Guard (`runningSyncs`):**
   * Employs an in-memory lock keyed by `shopDomain`.
   * If a merchant rapidly reloads the dashboard or opens multiple admin tabs, secondary trigger attempts return `{ started: false, reason: "already_running" }` without spawning duplicate background jobs.
   * Lock release is guaranteed via `finally` whether the runner succeeds, throws, or rejects.

3. **Service-to-Service Status Query (`GET /internal/v1/sync/status`):**
   * Enables the React Router integration layer to query Laravel for the latest `sync_runs` record of the authenticated shop.
   * Isolated outside the write-ingestion throttle group.
   * Enforces HMAC-SHA256 request signing, timestamp tolerance ($\le 300\text{s}$), and replay nonce checking.
   * Fully tenant-isolated by the verified `X-App-Shop-Domain` header.
   * Filters by `created_at >= shop.installed_at` so that if a store was uninstalled and subsequently reinstalled, obsolete runs from prior installs are discarded and a fresh sync is triggered.
   * Deterministically tie-breaks runs sharing the same whole second by `orderByDesc('created_at')->orderByDesc('id')`.
   * Output passes through `SyncRunResource`, so only the public contract below leaves Laravel.

   **Response contract (`200 OK`):**

   ```json
   {
     "sync_run": {
       "id": "01a1ee8d-56d4-711f-9e75-840f968bcfec",
       "sync_type": "initial",
       "status": "completed",
       "records_received": 27,
       "records_processed": 27,
       "started_at": "2026-11-18T12:06:42.000000Z",
       "completed_at": "2026-11-18T12:06:43.000000Z",
       "error_code": null,
       "last_activity_at": "2026-11-18T12:06:43.000000Z"
     }
   }
   ```

   | Field | Type | Notes |
   |---|---|---|
   | `id` | UUID | `sync_runs.id` |
   | `sync_type` | `initial` \| `incremental` \| `reconciliation` | Enum value |
   | `status` | `pending` \| `running` \| `completed` \| `failed` | Enum value |
   | `records_received`, `records_processed` | integer | Monotonic counters |
   | `started_at`, `completed_at` | ISO 8601 UTC or `null` | Microsecond precision, `Z` suffix |
   | `error_code` | string or `null` | Short machine code shown in the UI banner (e.g. `HARVEST_FAILED`) |
   | `last_activity_at` | ISO 8601 UTC | `sync_runs.updated_at`; bumped by every status/heartbeat update. Drives stale detection |

   `sync_run` is `null` when the shop has no runs. Never returned: `shop_id`, `cursor_metadata`, `error_summary`, `created_at` (`updated_at` is exposed only as `last_activity_at`). `error_summary` can carry raw upstream error text and stays server-side.

   | Condition | Status |
   |---|---|
   | Bad signature, stale timestamp, reused nonce | `401` |
   | Shop not active (uninstalled / suspended) | `403` |
   | Shop never registered | `404` |

4. **S2S Client GET Support (`BackendClient.getInternal`):**
   * Extends the Node.js S2S backend client to support signed `GET` requests using the SHA-256 hash of an empty body (`e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855`).

5. **Polaris Status Banner (`app._index.tsx`):**
   * Automatically launches the initial sync when a shop accesses the app for the first time (`state: "never_synced"`).
   * Renders dynamic Polaris banners:
     * `syncing` (`tone="info"`): Shows progress notice during background import.
     * `completed` (`tone="success"`): Confirms store data is synced.
     * `failed` (`tone="critical"`): Shows the `error_code` and a **Retry sync** button.
     * `stalled` (`tone="warning"`): The run is `running`/`pending` in Laravel, is not running in this process, and has had no activity for more than 10 minutes. Shows a **Retry sync** button.
   * The route `action` (POST from the Retry button via `useFetcher`) re-reads the status and starts a new sync only when the state is `failed` or `stalled`. Page loads never auto-retry a failed run, so a deterministic failure does not create a new run on every refresh.

6. **Progress Heartbeat (`runHistoricalSync`):**
   * During products and orders harvesting, posts `status: running` with the current counters at most once per `HEARTBEAT_MS` (60s).
   * Heartbeats are serialized on one promise chain and flushed before the products-stage, `completed`, and `failed` posts, so counters reach Laravel in order and the monotonic counter check never rejects them.
   * A failed heartbeat is logged and does not abort the harvest.
   * `HEARTBEAT_MS` (60s) is far below `STALE_AFTER_MS` (10 min), so a live sync is never reported as `stalled`.

---

## 2. Sequence Flow

```
[Merchant Browser]        [React Router: app._index.tsx]         [Laravel: /internal/v1/*]
         │                              │                                    │
         │─── GET /app ────────────────►│                                    │
         │                              │─── GET ingestion/sync-status ─────►│
         │                              │    (HMAC signed, empty body hash)  │
         │                              │◄── { sync_run: null } ─────────────│
         │                              │
         │                              │─── triggerHistoricalSync()
         │                              │    ├─ Check runningSyncs set
         │                              │    ├─ Add shop to runningSyncs
         │                              │    └─ Detach runHistoricalSync()
         │                              │
         │◄── Render (status: syncing) ─│                                    │
         │                              │                                    │
         ▼                              │                                    ▼
  [Polaris Banner:                      │                             [Background Sync]
   "Syncing your store data..."]        │                             Products -> Orders -> Refunds
                                        │                             └─ Record sync_run progress
```

---

## 3. File Breakdown

| Step | File | Purpose |
|---|---|---|
| **G-1** | `shopify-app/app/features/sync/syncTrigger.server.test.ts` | Unit tests for non-blocking trigger, per-shop concurrency locking, cross-shop isolation, and lock release on error. |
| **G-2** | `shopify-app/app/features/sync/syncTrigger.server.ts` | Implementation of `triggerHistoricalSync`, `isSyncRunning`, and `_resetRunningSyncsForTest`. |
| **G-3** | `backend/tests/Feature/Ingestion/GetSyncStatusTest.php` | Feature tests for `GET /internal/v1/ingestion/sync-status` verifying authentication, null states, latest run retrieval, and tenant isolation. |
| **G-4** | `backend/app/Http/Controllers/Internal/IngestionController.php`<br>`backend/routes/api.php` | Controller action and route registration returning the latest `SyncRun` for the verified shop tenant. |
| **G-5** | `shopify-app/app/lib/backend/backendClient.server.test.ts` | Unit tests verifying canonical HMAC signing for GET requests with empty body SHA-256. |
| **G-6** | `shopify-app/app/lib/backend/backendClient.server.ts` | Implementation of `getInternal<TResponse>()` on `BackendClient`. |
| **G-7** | `shopify-app/app/features/sync/getSyncStatus.server.test.ts` | Unit tests for status reader mapping never-synced, syncing, completed, and failed states. |
| **G-8** | `shopify-app/app/features/sync/getSyncStatus.server.ts` | Queries Laravel backend and combines remote database state with local in-memory lock into `ShopSyncStatus`. |
| **G-9** | `shopify-app/app/routes/app._index.tsx` | Route loader wiring automatic sync trigger on first load and rendering Polaris banners in the embedded UI. |
| **G-10**| `docs/shopify-sync-trigger.md` | Architectural specification and verification summary. |
| **R4-1** | `backend/tests/Feature/Ingestion/GetSyncStatusTest.php` | Exact-JSON assertion and "exposes only the public sync run contract" test (internal columns must be absent). |
| **R4-2** | `backend/app/Http/Resources/SyncRunResource.php` | Typed `JsonResource` mapping `SyncRun` to the 8 contract fields; enums as values, timestamps as ISO 8601. |
| **R4-3** | `backend/app/Http/Controllers/Internal/IngestionController.php` | `getSyncStatus` wraps the latest run in `SyncRunResource`; `null` when no run exists. |
| **R4-4** | `docs/shopify-sync-trigger.md` | Response contract and status codes. |
| **R3-1** | `shopify-app/app/features/sync/syncOrchestrator.server.test.ts` | Tests: 5,000-char message cut to 2,000 code points; emoji message cut without broken surrogates; rejected failure report logged and original error rethrown. |
| **R3-2** | `shopify-app/app/lib/backend/ingestionContract.server.ts` | `INGESTION_LIMITS.errorSummaryLength = 2000`, mirroring `ReportSyncStatusRequest`. |
| **R3-3** | `shopify-app/app/features/sync/syncOrchestrator.server.ts` | `errorSummary()` code-point truncation; `console.error` instead of a silent `.catch(() => {})`. |
| **R3-4** | `docs/shopify-sync-trigger.md` | Edge cases and verification for the above. |
| **R12-1** | `backend/tests/Feature/Ingestion/GetSyncStatusTest.php` | `last_activity_at` in the exact contract; reflects the latest status update. |
| **R12-2** | `backend/app/Http/Resources/SyncRunResource.php` | Adds `last_activity_at` (`updated_at`). |
| **R12-3** | `shopify-app/app/features/sync/getSyncStatus.server.test.ts` | `stalled` for running/pending past the threshold; `syncing` at the exact boundary, when local, or with no timestamp. |
| **R12-4** | `shopify-app/app/features/sync/getSyncStatus.server.ts` | `STALE_AFTER_MS`, `stalled` state, injectable `now`. |
| **R12-5** | `shopify-app/app/features/sync/syncOrchestrator.server.test.ts` | Heartbeats with monotonic counters, throttling, orders-stage failure counters. |
| **R12-6** | `shopify-app/app/features/sync/syncOrchestrator.server.ts` | `progressHeartbeat` (throttled, serialized, flushed before terminal posts); `HEARTBEAT_MS`. |
| **R12-7** | `shopify-app/app/routes/app._index.tsx` | Retry `action`, `stalled` banner, `RetryButton`, status label map. |
| **R12-8** | `docs/shopify-sync-trigger.md` | This section. |

---

## 4. Edge Cases Handled

* **Rapid Page Reloads:** Rapid clicking or concurrent page loads from the same shop encounter the `runningSyncs` lock. Secondary calls return `{ started: false, reason: "already_running" }` without firing duplicate requests to Shopify GraphQL or Laravel.
* **Background Worker Exceptions:** If the background promise fails (e.g. rate limit, network timeout, GraphQL schema error), the error is caught, passed to `onError` or logged, and the `finally` block cleans up the `runningSyncs` set so subsequent requests are not permanently locked out.
* **Empty Sync Run History:** When a shop accesses the app immediately after OAuth install before any job has executed, `GET /internal/v1/ingestion/sync-status` returns `{"sync_run": null}` with `200 OK` rather than a 404 error, allowing clean deterministic state detection.
* **Oversized Error Messages:** Laravel validates `error_summary` with `max:2000`, counted by `mb_strlen` (code points). The orchestrator cuts the message to `INGESTION_LIMITS.errorSummaryLength` code points with `Array.from(...)`, so a 5,000-character GraphQL error cannot turn the `failed` report into a `422` and leave the run `running`. Cutting by code point also never splits a surrogate pair (emoji), which would otherwise produce invalid UTF-8 and a rejected request.
* **Rejected Failure Report:** If the `failed` status post itself fails, the orchestrator logs `Failed to record sync failure for <shop> (sync_run <id>, status <http>)` with `console.error` and still rethrows the original harvest error. Previously the rejection was swallowed silently.
* **Process Crash / Restart Mid-Sync:** The run stays `running` in Laravel and the in-memory lock is gone. After 10 minutes without a heartbeat it is reported as `stalled` and the merchant can retry. The abandoned row stays `running`; the UI always reads the latest run.
* **Stale Threshold Boundary:** Exactly 10 minutes since `last_activity_at` is still `syncing`; stale requires strictly more. A missing or unparsable `last_activity_at` is never stale.
* **Duplicate Sync After Hot Reload:** A module reload clears the in-memory lock while the old sync keeps running. That sync still heartbeats every 60s, so its run never looks stalled and the Retry button is not offered.
* **Retry Guard:** The action ignores retry requests unless the state is `failed` or `stalled` (e.g. a stale browser tab posting after a sync already completed), and `triggerHistoricalSync` still rejects a second run in the same process.
* **Accurate Failure Counters:** Orders-stage progress now updates the running totals, so a `failed` report carries products + orders + refunds counts instead of products only.
* **No Internal Column Leakage:** The response is built from an explicit field list in `SyncRunResource`. New `sync_runs` columns stay private until added there; `error_summary` and `cursor_metadata` are never exposed.
* **Tenant Isolation:** Shop A's admin session can never view Shop B's sync runs. Laravel scopes queries strictly to the resolved `shop_id` corresponding to the verified HMAC domain header.
* **Database-Level Concurrency Guard:** In addition to the in-memory lock, Laravel's `RecordSyncStatus` pipe executes an atomic `lockForUpdate` check on `sync_runs` and rejects starting a second running or pending sync run with `409 Conflict` (`SyncRunAlreadyInProgress`), ensuring multi-instance and multi-worker safety across process restarts.
* **Offline Access Token Refresh:** `triggerHistoricalSync` accepts a dynamic `getAdmin` resolver which invokes `unauthenticated.admin(shopDomain)` inside the detached background runner. This reloads the offline session from persistent storage and automatically refreshes expiring tokens, preventing long crawls from failing midway.
* **Graceful Loader Error Fallback:** If the backend is down, returns 503, or throws a network error, the route loader catches the rejection and returns `{ state: "backend_error" }`. The UI renders an informative Polaris warning banner rather than triggering the React Router error boundary crash screen.
* **Reinstall Sync Reset:** When a store is reinstalled, `SyncStatusController` queries `created_at >= shop.installed_at`. Historical runs from prior installations are cleanly ignored, allowing the app to trigger a fresh sync for data created during the uninstalled interval.
* **Deterministic Tie-Breaking:** Sync runs are ordered by `orderByDesc('created_at')->orderByDesc('id')`, ensuring consistent ordering when multiple runs occur in the same second.

---

## 5. Verification Evidence

* **Backend Suite:** 167 tests passing (706 assertions) via `docker compose exec backend ./vendor/bin/pest`.
* **Live Probe:** Signed `GET /internal/v1/ingestion/sync-status` for the dev store returned `200` with exactly the 9 contract fields (sample above, including `last_activity_at`).
* **Build:** `npm run build` succeeds with the new route `action`.
* **Shopify App Suite:** 103 tests passing via `cd shopify-app && npm test`.
* **`error_summary` Boundary Probe:** Signed `POST sync-status` on the dev store: 2,001 emoji → `422` ("must not be greater than 2000 characters"); 2,000 emoji → `200`, stored as 2,000 chars / 8,000 bytes (`char_length` / `octet_length` in `psql`). The probe row was deleted afterwards.
* **Typecheck & Lint:** 0 TypeScript errors, 0 ESLint warnings (`npm run typecheck && npm run lint`).
