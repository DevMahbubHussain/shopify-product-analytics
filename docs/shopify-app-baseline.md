# Shopify App Baseline (Step 3, Group B)

How the embedded Shopify app (`shopify-app/`) was built from Shopify's official React Router template and connected to Laravel: every sub-phase, the file it changed, why, and how it was verified. Released as tag `v0.1.0-starter` (commit `4f9034e`).

---

## 1. What This Phase Provides

- A merchant can **install the app** on a Shopify store and open it inside Shopify Admin.
- The store's **access token is stored** in PostgreSQL (`shopify_sessions`) and never leaves the Shopify app.
- On install (and any re-authentication), the app **registers the store in Laravel** with its Shopify ID, currency, and IANA timezone, over the signed S2S boundary.
- The app makes **no changes to merchant data**: demo code that created products, metafields, and metaobjects is removed.
- A reproducible setup: host development with Shopify CLI, an optional production container, and a starter guide for new projects.

---

## 2. Design Rules

1. **Shopify's template is the base.** Auth, sessions, App Bridge, and webhook verification come from `@shopify/shopify-app-react-router` (4.x); only project-specific changes are made on top.
2. **Tokens stay in the gateway.** Sessions live in their own database (`shopify_sessions`), separate from Laravel's `shopify_analytics`. Laravel never receives a token (C2).
3. **Tenant identity comes from Shopify.** The shop domain sent to Laravel is `session.shop`, verified by the library, never a value from the browser.
4. **Every call to Laravel is signed** (AGENTS §2.4): HMAC-SHA256 over method, path, timestamp, nonce, shop, and body hash.
5. **Read-only toward Shopify.** Scopes are `read_products,read_orders`; no write mutations, metafield definitions, or metaobject definitions.
6. **Secrets never enter git or images.** Real values live in gitignored `.env` files; `.dockerignore` excludes them from the container image.
7. **The committed app config is neutral.** `shopify.app.toml` keeps a placeholder `client_id`; the real one is only in the gitignored `shopify.app.local.toml`.
8. **Server-only code is named `*.server.ts`,** so React Router never bundles it for the browser.

---

## 3. Lifecycle

```
Merchant opens the app in Shopify Admin
        │
        ▼
shopify-app  authenticate.admin(request)
        │  no valid session → token exchange with Shopify
        ▼
PrismaSessionStorage  →  Postgres shopify_sessions."Session"   (offline token, expiring + refresh)
        │
        ▼
hooks.afterAuth({ session, admin })
        │
        ├─► Shopify Admin API 2026-10:  query RegisterShop { shop { id currencyCode ianaTimezone } }
        │
        └─► backendClient().postInternal("/internal/v1/ingestion/shop", session.shop, {...})
                 headers: X-App-Shop-Domain, X-App-Timestamp, X-App-Nonce, X-App-Signature, X-Correlation-ID
                 │
                 ▼
            Laravel VerifyS2SSignature → IngestShopRequest → pipeline → shops upsert
                 │
                 ▼
            Postgres shopify_analytics.shops   (status active, currency, timezone)
        │
        ▼
Home page renders: "Setting up your analytics"
```

If Shopify or Laravel fails, the error propagates out of `afterAuth`: authentication fails visibly and the next app load retries. Laravel's `shop` endpoint is idempotent, so retries are safe.

---

## 4. Sub-phases

Each sub-phase changed one file (C15) unless noted.

### B.1 Template baseline

Scaffolded with Shopify CLI 5.0.2 from the official React Router template (`@shopify/shopify-app-react-router` 4.0.3, React Router 7.18.2). The previous tsx prototype was removed; its signer was restored later in B.2-7. Commit `e234b60`.

### B.2-1 Admin API version → `2026-10`

| File | Change |
|---|---|
| `app/shopify.server.ts` | `ApiVersion.July26` → `ApiVersion.October26` (config and exported `apiVersion`) |

**Why:** data queries and webhooks must use the same pinned version (AGENTS §2.2). **Verified:** `October26 = "2026-10"` exists in the installed `@shopify/shopify-api`; GraphiQL shows `2026-10`.

### B.2-2 Sessions on PostgreSQL

| File | Change |
|---|---|
| `prisma/schema.prisma` | `provider = "postgresql"`, `url = env("DATABASE_URL")`; `Session` model unchanged |

**Why:** durable, Docker-ready storage, separate from Laravel's data. The model stays identical because the Prisma session adapter expects those columns. **Verified:** `prisma validate`, `prisma generate`, typecheck.

### B.2-3 PostgreSQL migration

| File | Change |
|---|---|
| `prisma/migrations/20261118000000_create_session_table/migration.sql` | New Postgres DDL (generated with `prisma migrate diff --from-empty`) |
| `prisma/migrations/migration_lock.toml` | `provider = "postgresql"` |
| `prisma/migrations/20240530213853_…` | Removed (SQLite `DATETIME` DDL) |

**Decision:** the generated `CREATE SCHEMA IF NOT EXISTS "public"` line was dropped; `migrate dev` does not emit it and it can need extra privileges on managed hosts. **Verified:** `migrate deploy` on a throwaway database, `migrate status` up to date, drift check exit 0, `\d "Session"` shows 17 columns.

### B.2-4 Sessions database in Docker

| File | Change |
|---|---|
| `infrastructure/docker/postgres/initdb/02-create-sessions-database.sql` | Creates `shopify_sessions` if missing (`\gexec` pattern) |

**Verified:** ran twice against the live volume (create, then no-op). The Postgres container was recreated so the initdb mount, previously shadowed by an empty directory, shows both scripts.

### B.2-5 Environment for the Shopify app

| File | Change |
|---|---|
| `docker-compose.yml` | Postgres published on host port **5433** (container still `5432`) |
| `shopify-app/.gitignore` | `!.env.example` so the example is tracked |
| `shopify-app/.env.example` | `DATABASE_URL` (`localhost:5433/shopify_sessions`) |

**Why 5433:** a native PostgreSQL on the host owned `127.0.0.1:5432`, so host connections reached the wrong server (`P1010`). **Verified:** Prisma reports "Environment variables loaded from .env" and connects to Docker's Postgres; Laravel unaffected (`migrate:status`, `/api/v1/health` 200).

### B.2-6 Demo code removed

| Step | File | Change |
|---|---|---|
| a | `app/routes/app._index.tsx` | Demo product generator (3 write mutations) → placeholder home page |
| a-fix | `tsconfig.json` | `types` gains `@shopify/app-bridge-types` (was loaded only through the removed import) |
| b | `app/routes/app.tsx` | "Additional page" nav link removed |
| c | `app/routes/app.additional.tsx` | Deleted |
| d | `shopify.app.toml` | Demo metafield, metaobject, and `[events]` subscription removed |
| e | `shopify.app.local.toml` (gitignored) | Same removals; shared fields identical again |
| f | `app/routes/webhooks.products.update.tsx` | Deleted (logged full payloads; real handler comes later) |

**Why:** the demo writes to merchant data and needs `write_products`, which this app never requests. **Verified:** typecheck, lint, no write mutations left in `app/`, TOML parsed, Admin shows only "Home".

### B.2-7 S2S signer

| Step | File | Change |
|---|---|---|
| a | `tsconfig.json` | `allowImportingTsExtensions` (Node runs `.ts` tests directly) |
| b | `tests/backendClient.server.test.ts` | Red test: canonical signature, missing secret, all headers, error status |
| c | `app/services/backendClient.server.ts` | `BackendClient` with `generateSignature`, `postInternal`, lazy `backendClient()` |
| d | `package.json` | `"test": "node --test \"tests/**/*.test.ts\""` (built-in runner, no new dependency) |
| e | `.env.example` | `BACKEND_API_URL`, `S2S_SHARED_SECRET` (empty) |
| f | `shopify.web.toml` | Dev command runs `node --env-file-if-exists=.env …react-router dev` |

**Why B.2-7f:** Shopify CLI does not load `shopify-app/.env`; values reached the app only as a side effect of Prisma, which Prisma 7 removes. Loading the file explicitly makes it independent of import order. **Verified:** 4/4 tests; live signed ping to Laravel returns `S2S handshake verified successfully.`; a wrong secret returns `401 Invalid S2S request signature.`; the dev process arguments show the flag.

### B.2-8 Install → Laravel shop registration

| Step | File | Change |
|---|---|---|
| a | `tests/registerShop.server.test.ts` | Red test: mapping and single POST; GraphQL errors, missing shop, and backend failure all post nothing or propagate |
| b | `app/services/registerShop.server.ts` | Shop query → `{ shopify_shop_gid, currency_code, timezone }` → `/internal/v1/ingestion/shop` |
| c | `app/shopify.server.ts` | `hooks.afterAuth` calls `registerShop(session.shop, admin)` |

**Fields** (verified on shopify.dev for `2026-10`): `Shop.id: ID!`, `currencyCode: CurrencyCode!`, `ianaTimezone: String!`. **Decision:** failures propagate (install retried) rather than being logged and swallowed, so Laravel can never silently miss a shop.

### B.2-9 Production container

| Step | File | Change |
|---|---|---|
| — | `docker-compose.yml` | `shopify-app` builds `shopify-app/Dockerfile` (Node 22), profile `app`, `DATABASE_URL` to `postgres:5432`, waits for healthy Postgres |
| a | `shopify-app/.dockerignore` | Excludes `.env*` (except example), `shopify.app.*.toml`, `.shopify`, `tests` |
| b | `infrastructure/docker/node/Dockerfile` | Deleted (Node 20, ran `npm run dev`) |
| c, d | `AGENTS.md` | Commands, env table, signer path, Step 1 status |
| e | `CLAUDE.md` | Architecture and current-state rows |

**Why a profile:** `shopify app dev` needs an interactive login and tunnel, so development runs on the host; the container is a production-style build started on purpose. **Why B.2-9a:** without it, `COPY . .` put `.env` (S2S secret, DB password) into the image.

### B.2-10 / B.2-11 Starter guide and README

| File | Change |
|---|---|
| `STARTER.md` | Rewritten for `v0.1.0-starter`: three env files, `--config local`, port 5433, install check |
| `README.md` | Status, Feature 5, services, Quick Start, commands, live S2S verification |

### Release

Commit `4f9034e` on `shopify-integration`, fast-forwarded into `main`, tagged **`v0.1.0-starter`**. The obsolete `v0.3.1-starter` tag was deleted. Starter tags keep the `-starter` suffix so product releases keep plain version numbers.

---

## 5. Edge Cases Handled

| Case | Behavior |
|---|---|
| Plain `shopify app dev` (no `--config local`) | CLI reads the placeholder `client_id` and fails with `no app was found for the provided apiKey`; documented in STARTER and README |
| CLI "link app" writes the real ID into the committed toml | Restored to the placeholder before commit; secret scan of the staged diff |
| Native Postgres on host port 5432 | Docker Postgres published on 5433 |
| Older Postgres volume without the init scripts | Documented loop runs both scripts; idempotent |
| Empty `/docker-entrypoint-initdb.d` in the container | `docker compose up -d --force-recreate postgres` |
| Shopify returns GraphQL `errors` or no `shop` | Throws; nothing sent to Laravel |
| Laravel down or failing during install | Error propagates; next app load retries; endpoint is idempotent |
| Re-authentication of an already registered shop | Same row refreshed, no duplicate, `installed_at` unchanged |
| Unsigned or wrongly signed request to Laravel | `401` |
| Missing `S2S_SHARED_SECRET` or `BACKEND_API_URL` | Signer throws on first use; no fallback secret |
| `.env` missing in production | `--env-file-if-exists` only applies to dev; the container reads compose env |
| Docker Desktop `credsStore` left on a Colima machine | Build fails with `docker-credential-desktop`; fix documented in STARTER |

---

## 6. Verification

### Automated tests

```bash
cd shopify-app && npm test    # 8 tests: signer (4), shop registration (4)
npm run typecheck && npm run lint
```

### Live install (2026-11-18, dev store `product-analytics-demo`)

| Check | Result |
|---|---|
| Session | offline, scopes `read_orders,read_products`, expiring token with refresh token |
| Laravel `shops` row | `active`, `gid://shopify/Shop/79616737328`, `USD`, `America/New_York` |
| Repeat registration | still 1 row, `installed_at` unchanged |
| Admin UI | placeholder home page, nav shows only "Home"; GraphiQL on `2026-10` |

### Measured performance

| Request | Latency |
|---|---|
| `ingestion/shop`, first request after dev restart | 506 ms (cold start) |
| `ingestion/shop`, warm | 83 ms, 17 ms round trip |

### Container

`docker compose --profile app up -d --build shopify-app`: Node 22.23.5; `No pending migrations to apply`; `GET /` 200; unauthenticated `/app` refused; container reaches `backend:8000`. Image contains no `.env`, local toml, `.shopify`, or tests.

### Fresh clone

The release commit, cloned into an empty directory: no local secret files, placeholder `client_id`, `npm ci`, 8/8 tests, typecheck, lint, and `prisma validate` all pass. The tag clones from GitHub as STARTER.md describes.

### Direct inspection

```bash
docker compose exec postgres psql -U postgres -d shopify_sessions -c 'select shop, "isOnline", scope from "Session"'
docker compose exec postgres psql -U postgres -d shopify_analytics -c "select shop_domain, status, currency_code, timezone from shops"
docker compose logs backend | grep internal/v1
```

---

## 7. Not in This Phase

- **Uninstall → Laravel:** the `app/uninstalled` webhook deletes sessions only; marking the shop `uninstalled` in Laravel needs the Laravel uninstall endpoint (next group).
- Data sync (products, orders, refunds), analytics webhooks, dashboard UI, and billing.
- Small follow-ups: declare `@shopify/app-bridge-types` directly in `package.json`; stop Pest writing to the dev `laravel.log`; format three template files with Prettier.
