# Shop Lifecycle & Product Deletion (Step 3, Group C)

How Laravel learns that a store installed, uninstalled, or reinstalled the app, and how deleted Shopify products leave reports without losing sales history. Covers the Laravel endpoints, the gateway webhook handler, the delivery problems found during live testing, and their fixes.

---

## 1. What This Phase Provides

- **Uninstall reaches Laravel.** When a merchant uninstalls, Laravel marks the shop `uninstalled` at the time Shopify recorded the event, and the gateway deletes the shop's access token.
- **Ingestion stops for uninstalled shops.** Every tenant-scoped endpoint returns `403` until the shop reinstalls; reinstalling reactivates it (existing `ingestion/shop` behavior).
- **Repeated and late deliveries are harmless.** A duplicate uninstall changes nothing; an uninstall delivered after the merchant already reinstalled is ignored, and the current access token survives.
- **Products can be soft-deleted.** A new endpoint marks products and their variants deleted for one shop; order lines and metrics keep their references.

---

## 2. Design Rules

1. **Shopify's trigger time is the uninstall time.** `uninstalled_at` comes from `X-Shopify-Triggered-At`, not from the arrival time of the request.
2. **Laravel decides, the gateway follows.** The gateway calls Laravel first and deletes sessions only when Laravel confirms the shop is uninstalled (or never existed).
3. **Data is kept after uninstall.** Purging happens only on Shopify's `shop/redact` (Step 7). (PRD open decision 7, decided: keep.)
4. **Webhooks that need no token are verified by HMAC only.** `app/uninstalled` must work after the token has expired and can no longer be refreshed.
5. **Deletes are soft.** `deleted_at` is set; rows are never removed, so history stays reportable (`docs/migrations.md`, rule 4).
6. **Every write is tenant-scoped and idempotent,** inside `DB::transaction(…, 5)`.

---

## 3. Lifecycle

```
Install (merchant opens app)          Uninstall                                Late delivery (after reinstall)
─────────────────────────────         ─────────────────────────────────        ───────────────────────────────
token exchange → session stored       Shopify → POST /webhooks/app/uninstalled  same as uninstall, but
afterAuth → ingestion/shop              verifyShopifyWebhook (HMAC only)        Triggered-At < installed_at
Laravel: active, installed_at=now       handleAppUninstalled:                   Laravel: status stays active
                                          1. ingestion/shop-uninstall           gateway: session kept, 200
                                          2. Laravel says "uninstalled"
                                             → delete sessions → 200
                                        Laravel: uninstalled, uninstalled_at
```

| Laravel answer | Gateway action | Reply to Shopify |
|---|---|---|
| `status: uninstalled` | delete sessions | `200` |
| `status: active` (late delivery) | keep sessions | `200` |
| `404 Unknown shop` (installed, never opened) | delete sessions, if any | `200` |
| Any other failure (Laravel down, 5xx, network) | keep sessions | `500` → Shopify retries |
| Invalid HMAC | nothing | `401` |

---

## 4. Laravel Endpoints

Contracts: [`docs/ingestion-api.md`](ingestion-api.md) (`shop-uninstall`, `products-delete`).

### C-1 … C-6 `POST /internal/v1/ingestion/shop-uninstall`

| Step | File | Role |
|---|---|---|
| C-1 | `backend/tests/Feature/Ingestion/ShopUninstallTest.php` | 8 tests (10 cases), written first |
| C-2 | `backend/app/Data/Ingestion/ShopUninstallData.php` | readonly DTO; trigger time converted to UTC |
| C-3 | `backend/app/Http/Requests/Internal/UninstallShopRequest.php` | `uninstalled_at` via `IngestionRules::timestamp()` |
| C-4 | `backend/app/Ingestion/Pipes/MarkShopUninstalled.php` | row lock, late-delivery guard, idempotent update |
| C-5 | `IngestionController::shopUninstall()` | pipeline `[MarkShopUninstalled]` |
| C-6 | `backend/routes/api.php` | route behind `s2s` + `throttle:s2s-ingestion` |

**Why no `ResolveShopTenant`:** it rejects inactive shops with `403`, which would turn a repeated uninstall into an error. The pipe looks the shop up itself (`lockForUpdate`) so a concurrent reinstall cannot interleave.

**Late-delivery guard:** if `uninstalled_at < installed_at`, the delivery belongs to an earlier installation and is ignored. An already-uninstalled shop keeps its first `uninstalled_at`.

### C-7 … C-12 `POST /internal/v1/ingestion/products-delete`

| Step | File | Role |
|---|---|---|
| C-7 | `backend/tests/Feature/Ingestion/ProductDeletionTest.php` | 9 tests (12 cases), written first |
| C-8 | `backend/app/Data/Ingestion/ProductDeletionBatch.php` | `list<ShopifyGid>` (type `Product`) |
| C-9 | `backend/app/Http/Requests/Internal/DeleteProductsRequest.php` | 1–250 distinct product GIDs |
| C-10 | `backend/app/Ingestion/Pipes/SoftDeleteProducts.php` | `UPDATE … RETURNING id`, then variants; one transaction |
| C-11 | `IngestionController::productsDelete()` | `[ResolveShopTenant, SoftDeleteProducts]` |
| C-12 | `backend/routes/api.php` | route |

Two statements per request regardless of batch size. `GuardedUpsert` never touches `deleted_at`, so a later `products` upsert updates fields but cannot restore a deleted product (pinned by a test). The gateway webhook for `products/delete` arrives with the analytics webhooks (group H).

### C-13 Contract

`docs/ingestion-api.md` gained both sections and the delete timing.

---

## 5. Gateway

| Step | File | Role |
|---|---|---|
| C-14 | `shopify-app/app/features/shop-lifecycle/uninstallShop.server.test.ts` | trigger time sent, normalized to UTC, fallback to now, failure propagates |
| C-15 | `shopify-app/app/features/shop-lifecycle/uninstallShop.server.ts` | signed call to `shop-uninstall` |
| C-16a | `shopify-app/app/lib/shopify/verifyWebhook.server.test.ts` | valid, wrong secret, tampered body, malformed signature, missing headers, foreign domain, missing secret |
| C-16b | `shopify-app/app/lib/shopify/verifyWebhook.server.ts` | base64 HMAC-SHA256 of the raw body, `timingSafeEqual`, `*.myshopify.com` |
| C-16d | `shopify-app/app/lib/backend/backendClient.server.test.ts` | failed response throws `BackendError` with `status` |
| C-16e | `shopify-app/app/lib/backend/backendClient.server.ts` | `BackendError` (message unchanged) |
| C-16f | `shopify-app/app/features/shop-lifecycle/appUninstalled.server.test.ts` | the four rows of the table in §3, including call order |
| C-16g | `shopify-app/app/features/shop-lifecycle/appUninstalled.server.ts` | Laravel first, then sessions |
| C-16 / C-16c / C-16h | `shopify-app/app/routes/webhooks.app.uninstalled.tsx` | verify → `handleAppUninstalled` → status |

---

## 6. Problems Found in Live Testing

### All `app/uninstalled` deliveries returned 500

Shopify's log showed seven deliveries, each `500 Error`; Laravel and the session table were untouched.

**Cause:** `authenticate.webhook` verifies the HMAC, then loads the offline session. With `expiringOfflineAccessTokens`, an expired token is refreshed first. After uninstall Shopify refuses the refresh, and `refresh-token.mjs` converts any refusal other than `401 invalid_request` into `throw new Response(…, { status: 500 })` — before the route's own code runs. It happens whenever the token is older than about an hour, which is common at uninstall time.

**Found by:** a correctly signed delivery to the dev server (500, empty body), a second dev instance with visible logs (no handler error), and calling the route's `action` through Vite's SSR loader (`THROWN: Response 500`), then reading the library source.

**Fix:** `verifyShopifyWebhook` (HMAC only) replaces `authenticate.webhook` for this topic. The privacy webhooks in Step 7 reuse it.

### A late delivery deleted the current token

After reinstall, replaying the 09:51 uninstall left Laravel `active` (correct) but the first handler had already deleted the session.

**Fix:** `handleAppUninstalled` asks Laravel first and deletes sessions only on `uninstalled` or `404`. `BackendError.status` lets it tell `404` from an outage.

### Install not seen by Laravel

With Shopify-managed install, the app receives a token, and `afterAuth` runs, only when the merchant first opens the app. A store that installs and uninstalls without opening it is never known to Laravel; its uninstall gets `404` and is acknowledged. This is expected; a store becomes known on its first open.

---

## 7. Edge Cases Handled

| Case | Behavior |
|---|---|
| Duplicate `app/uninstalled` | Laravel unchanged, first time kept; sessions already gone |
| Uninstall delivered after reinstall | Ignored by Laravel; session kept |
| Uninstall for a never-opened store | `404` → sessions cleared, `200` |
| Laravel down during uninstall | `500`; sessions kept; Shopify retries; endpoints idempotent |
| Session delete fails after Laravel recorded it | `500`; the retry repeats an idempotent Laravel call and the delete |
| Missing or unparseable `X-Shopify-Triggered-At` | Current time used; late-delivery guard cannot apply |
| Forged webhook | `401` before any work |
| Ingestion for an uninstalled shop | `403 Shop is not active.` |
| Delete of unknown or already deleted products | Counted as `skipped_stale`; nothing changed |
| Same GID in another shop | Untouched |
| Product update after delete | Fields updated, stays deleted |

---

## 8. Verification

### Automated

```bash
docker compose exec backend ./vendor/bin/pest   # 159 passed (10 uninstall, 12 deletion)
cd shopify-app && npm test                      # 24 passed (8 verifier, 4 uninstall, 4 decision, signer, registration)
npm run typecheck && npm run lint
```

### Live (2026-11-18, dev store `product-analytics-demo`)

| Event | Result |
|---|---|
| Uninstall with the fixed handler | Shopify delivery `200`; `shops.status = uninstalled`, `uninstalled_at = 10:22:22` (trigger time); 0 sessions |
| Reinstall + open | `active`, new `installed_at 10:18:19`, `uninstalled_at` cleared, new session |
| Late 09:51 delivery after reinstall | `200`; shop stays `active`; session kept (same expiry before and after) |
| Signed uninstall for a never-installed shop | `BackendError 404 Unknown shop.`; no row created |
| Forged signature | `401` |

### Performance

| Query | Result |
|---|---|
| Uninstall lookup | `LockRows → Index Scan using shops_shop_domain_unique` |
| `products-delete`, 250 products / 500 variants in a 50,000-product shop | 3.2 ms (`products_shop_gid_unique`) + 9.0 ms (`variants_shop_product_idx`), measured in a rolled-back transaction |

---

## 9. Not in This Phase

- Gateway webhook for `products/delete` (group H).
- `shop/redact` purge and the other privacy webhooks (Step 7); they will use `verifyShopifyWebhook`.
- A query-count assertion for `products-delete` (the count is fixed by construction: tenant lookup + 2 statements).
