# Shopify Product Analytics — Multi-Tenant Architecture & Boilerplate

An enterprise-ready, containerized, multi-tenant Shopify application architecture featuring a decoupled dual-runtime stack:
1. **Shopify App Gateway (Frontend & Webhook Ingestion):** Node.js 22 / TypeScript, Shopify's React Router app template with App Bridge and the GraphQL Admin API.
2. **Analytics & Data Processing Engine (Backend):** Laravel 13 REST API, PostgreSQL 16, Redis 7, and dedicated background queue workers.
3. **Cryptographic S2S Trust Boundary:** Deterministic HMAC-SHA256 request signing with timestamp + single-use nonce replay protection and tenant context binding.

> **Starting a new Shopify app from this repo?** Follow [STARTER.md](STARTER.md) (tag `v0.1.0-starter`).

### Current Status

| Area | Status |
|---|---|
| Docker stack, env/secrets handling, S2S boundary, test harness | Done |
| Shopify app config (`shopify.app.toml` template + gitignored `shopify.app.local.toml`, API `2026-10`, managed install) | Done |
| Multi-tenant domain schema (10 tables) | Done |
| Ingestion API (8 signed endpoints) | Done |
| Embedded Shopify app: official React Router template, Postgres sessions, S2S signer, install and uninstall synced to Laravel | Done (Step 3, groups B–C) |
| Shopify data sync (GraphQL harvester, normalizers, analytics webhooks), Polaris dashboard | Planned |
| Metric aggregation, analytics REST API, billing, exports | Planned |

Boxes in the topology below marked *(planned)* describe the target architecture.

---

## Features

Each feature lists what it provides, where it lives, and how it talks to the rest of the system. Full explainers are linked.

### 1. Service-to-Service (S2S) Trust Boundary

**Provides:** proof that every request reaching Laravel's private API came from our own Shopify gateway, was not tampered with, and is not a replay, plus the verified store (tenant) it belongs to.

| Part | Location |
|---|---|
| Request signer | `shopify-app/app/services/backendClient.server.ts` (tests: `shopify-app/tests/backendClient.server.test.ts`) |
| Signature, timestamp, and nonce check | `backend/app/Http/Middleware/VerifyS2SSignature.php` |
| Correlation IDs for tracing | `backend/app/Http/Middleware/AssignCorrelationId.php` |

**How it communicates:** the gateway signs `METHOD, PATH, TIMESTAMP, NONCE, SHOP_DOMAIN, sha256(body)` with the shared secret and sends `X-App-*` headers. Laravel recomputes the HMAC, rejects clock drift beyond ±300 s, claims the nonce once in Redis, then exposes the verified `shop_domain` to the rest of the request. Contract: [§3](#3-cryptographic-s2s-trust-boundary) and `AGENTS.md` §2.4.

### 2. Multi-Tenant Domain Schema

**Provides:** PostgreSQL storage for shops, products, variants, orders, line items, refunds, daily metrics, sync runs, webhook deliveries, and subscriptions, where the database itself guarantees correctness:
- every row belongs to one shop, and cross-shop references are impossible (composite foreign keys)
- re-imports cannot duplicate rows (unique Shopify GID keys)
- sales history survives product deletion (soft deletes plus guarded foreign keys)
- money is exact `DECIMAL(20,6)` with a validated currency code

| Part | Location |
|---|---|
| Migrations | `backend/database/migrations/2026_11_18_0000*` |
| Schema tests | `backend/tests/Feature/Database/` |
| Explainer | [docs/migrations.md](docs/migrations.md) |

**How it communicates:** only Laravel reads or writes it. Dashboards will read only the precomputed `product_daily_metrics` table (2 ms for a 30-day overview at 109,000 rows).

### 3. Ingestion API

**Provides:** eight private endpoints through which the gateway pushes Shopify data into Laravel:

| Endpoint | Purpose |
|---|---|
| `POST /internal/v1/ingestion/shop` | Install, refresh, or reactivate a store |
| `POST /internal/v1/ingestion/shop-uninstall` | Mark a store uninstalled (ignores late deliveries after reinstall) |
| `POST /internal/v1/ingestion/products` | Upsert products with variants |
| `POST /internal/v1/ingestion/products-delete` | Soft-delete products and their variants; history kept |
| `POST /internal/v1/ingestion/orders` | Upsert orders with line items |
| `POST /internal/v1/ingestion/refunds` | Upsert refund lines |
| `POST /internal/v1/ingestion/webhook-events` | Record a webhook delivery and detect repeats |
| `POST /internal/v1/ingestion/sync-status` | Track a sync run from start to finish |

Guarantees: safe retries (no duplicates), stale out-of-order updates ignored, tenant taken only from the signed header, strict validation (string money, timezone-aware timestamps, typed GIDs), and under 200 ms per request (250 line items in about 125 ms).

| Part | Location |
|---|---|
| Endpoint → pipeline mapping | `backend/app/Http/Controllers/Internal/IngestionController.php` |
| Validation and typed input | `backend/app/Http/Requests/Internal/`, `backend/app/Data/Ingestion/` |
| Pipeline steps and safe upserts | `backend/app/Ingestion/` |
| Payload contract | [docs/ingestion-api.md](docs/ingestion-api.md) |
| File-by-file explainer | [docs/ingestion-pipeline.md](docs/ingestion-pipeline.md) |

**How it communicates:** the gateway calls it with signed JSON (Feature 1). Each request passes through `FormRequest → readonly DTO → Pipeline (ResolveShopTenant → Upsert… → DispatchIngestedEvents)`. After the write commits, Laravel fires `OrdersIngested` / `RefundsIngested`, which the Step 4 aggregation jobs will consume through Redis queues.

### 4. Platform Foundations

**Provides:** a reproducible local stack, with no secrets in git and tests that never touch development data.
- Secrets live only in gitignored `.env` files; compose refuses to start when a value is missing.
- `backend/.env` is the single Laravel config source, so the HTTP server and the queue worker share cache prefixes and Redis locks.
- Tests run on their own PostgreSQL database and Redis databases, guarded by `TestEnvironmentIsolationTest`.

Details: [§4](#4-quick-start--local-setup), `AGENTS.md` §2.1–2.3, [STARTER.md](STARTER.md).

### 5. Embedded Shopify App (Template Baseline)

**Provides:** the merchant-facing Shopify app, built from Shopify's official React Router template (Shopify CLI 5.0.2, `@shopify/shopify-app-react-router` 4.0.3, React Router 7.18.2). It gives us Shopify's own, maintained implementation of:
- **Install and authentication:** Shopify-managed install; `authenticate.admin()` validates each merchant request and handles token exchange.
- **Session storage:** each store's access token is stored server-side (Prisma `Session` table in PostgreSQL database `shopify_sessions`) and never leaves `shopify-app` (C2).
- **Shop registration:** after install or re-authentication, the `afterAuth` hook reads `shop { id currencyCode ianaTimezone }` and registers the store in Laravel (`/internal/v1/ingestion/shop`).
- **Uninstall sync:** the `app/uninstalled` webhook is verified by HMAC only (no token refresh), Laravel records the uninstall at Shopify's trigger time, and only then are the store's sessions deleted. Late deliveries after a reinstall leave the shop and its token intact. Details: [docs/shop-lifecycle.md](docs/shop-lifecycle.md).
- **Embedded UI shell:** App Bridge layout inside Shopify Admin (`app/routes/app.tsx`), with routes created from file names.
- **Verified webhooks:** `authenticate.webhook()` checks Shopify's `X-Shopify-Hmac-Sha256` signature before any handler code runs.
- **GraphQL Admin API client:** `admin.graphql()` with the store's token.

| Part | Location | Role |
|---|---|---|
| Shopify setup | `shopify-app/app/shopify.server.ts` | API key/secret, scopes, API version `2026-10`, session storage, `afterAuth` hook |
| Sessions | `shopify-app/prisma/schema.prisma`, `prisma/migrations/`, `app/db.server.ts` | PostgreSQL `Session` table holding store tokens |
| S2S signer | `shopify-app/app/services/backendClient.server.ts` | Signs every request to Laravel |
| Shop registration | `shopify-app/app/services/registerShop.server.ts` | Shop query → `ingestion/shop` |
| Uninstall sync | `shopify-app/app/services/{verifyWebhook,uninstallShop,appUninstalled}.server.ts` | HMAC check, signed `ingestion/shop-uninstall`, Laravel-first session cleanup |
| Install/login callbacks | `shopify-app/app/routes/auth.$.tsx` | Shopify `/auth/*` flow |
| Admin layout | `shopify-app/app/routes/app.tsx` | Authenticates the merchant, starts App Bridge, nav |
| Webhooks | `shopify-app/app/routes/webhooks.*.tsx` | `app/uninstalled` (HMAC-only, synced to Laravel), `app/scopes_update` (template) |
| App config | `shopify-app/shopify.app.toml` (placeholder `client_id`) + gitignored `shopify.app.local.toml` (real `client_id`) | Scopes `read_products,read_orders`, webhooks API `2026-10`, managed install |
| Dev runner | `shopify-app/shopify.web.toml` | How `shopify app dev` starts the server; loads `shopify-app/.env` via `--env-file-if-exists` |
| Container | `shopify-app/Dockerfile`, `.dockerignore` | Production build under compose profile `app`; no secrets in the image |

**How it communicates:**
- **Merchant browser ⇄ shopify-app:** HTTPS inside the Shopify Admin iframe (App Bridge session token).
- **shopify-app ⇄ Shopify:** GraphQL Admin API `2026-10` with the stored token; webhooks inbound, verified by HMAC.
- **shopify-app → backend:** S2S-signed JSON (Feature 1).

**Customizations applied to the template (Step 3, one file per step):**

| # | Change | Why |
|---|---|---|
| 1 | API version `July26` → `October26` | Match the pinned `2026-10` |
| 2 | Sessions: SQLite → PostgreSQL database `shopify_sessions` | Durable, Docker/production-ready, separate from Laravel's data |
| 3 | Removed template demo code (product generator page, additional page, demo metafield/metaobject/event, demo product webhook) | Demo needs `write_products`, which this app does not request |
| 4 | Ported the S2S signer and its tests (`npm test`) | Talk to Laravel |
| 5 | After install → `ingestion/shop` | Laravel knows every installed store |
| 6 | Node 22 container under compose profile `app`; `.dockerignore` keeps secrets out | Template requires Node ≥ 22.12 |
| 7 | `app/uninstalled` handler: HMAC-only verification, Laravel first, then sessions | The template handler returned 500 once the expiring offline token could no longer be refreshed |

**Starter template:** this state is tagged **`v0.1.0-starter`**: new Shopify projects start with a working, installable embedded app connected to Laravel.

---

## How the Parts Communicate

```
 Merchant browser (Shopify Admin)
        │  App Bridge iframe                    (template baseline; dashboard: Step 6)
        ▼
 ┌──────────────────────────┐   GraphQL Admin API (OAuth token)   ┌─────────────────┐
 │ shopify-app (Node 22)    │ ──────────────────────────────────► │ Shopify         │
 │ gateway / integration    │ ◄────────────────────────────────── │ platform        │
 │                          │   Webhooks (X-Shopify-Hmac-Sha256)  └─────────────────┘
 └────────────┬─────────────┘
              │  Signed JSON over the internal Docker network
              │  X-App-Shop-Domain · X-App-Timestamp · X-App-Nonce · X-App-Signature
              ▼
 ┌──────────────────────────┐  writes / reads   ┌──────────────────┐
 │ backend (Laravel 13)     │ ────────────────► │ PostgreSQL 16    │
 │ /internal/v1/ingestion/* │                   └──────────────────┘
 │ /api/v1/*  (planned)     │  nonces, rate     ┌──────────────────┐
 │                          │  limits, queue ─► │ Redis 7          │
 └──────────────────────────┘                   └────────┬─────────┘
                                                         │ OrdersIngested / RefundsIngested
                                                         ▼
                                                ┌──────────────────┐
                                                │ laravel-worker   │  aggregation (planned: Step 4)
                                                └──────────────────┘
```

| From → To | Channel | Security | Status |
|---|---|---|---|
| Shopify → shopify-app | HTTPS webhooks | `X-Shopify-Hmac-Sha256`, verified by `authenticate.webhook()` | Template baseline (app lifecycle topics); analytics topics in Step 3 |
| shopify-app → Shopify | GraphQL Admin API `2026-10` | Store access token from session storage (never leaves shopify-app) | Client available (template); sync queries in Step 3 |
| shopify-app → backend | Signed JSON (host dev: `localhost:8000`; container: `backend:8000`) | HMAC-SHA256 + ±300 s timestamp + single-use nonce + per-shop rate limit | Done (shop registration on install); data sync in Step 3 |
| backend → PostgreSQL | SQL | Tenant-scoped queries, composite foreign keys | Done |
| backend → Redis | Cache, rate limiter, queues | Internal network only | Done (queues used from Step 4) |
| Redis → laravel-worker | Queued jobs from ingestion events | — | Planned (Step 4) |
| Browser → backend | Never direct; always through shopify-app | — | By design |

---

## 1. Architectural Topology

```
                   ┌────────────────────────────────────────────────────────┐
                   │                     Shopify Platform                   │
                   │  - Shopify Admin (Embedded App Bridge UI)              │
                   │  - Webhook Dispatcher (GDPR, Orders, Products, App)    │
                   └──────────────────────────┬─────────────────────────────┘
                                              │ Public HTTPS
                                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  Shopify App Gateway (Node.js 22 / TypeScript, React Router)                           │
│  Container: spa_shopify_app | Port: 3000                                               │
│                                                                                        │
│  - Session Storage & Managed Install / Token Exchange (template)                       │
│  - App Bridge Embedded Layout (template); Polaris dashboard (planned)                  │
│  - Webhook HMAC Verification (template); analytics webhooks (planned)                  │
│  - Cryptographic S2S Client (HMAC-SHA256 Signing + Correlation IDs)                    │
└─────────────────────────────────────────────┬──────────────────────────────────────────┘
                                              │ Internal Network (S2S Signed Traffic)
                                              │ Headers: X-App-Shop-Domain,
                                              │          X-App-Timestamp,
                                              │          X-App-Nonce,
                                              │          X-App-Signature,
                                              │          X-Correlation-ID
                                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  Backend Engine (Laravel 13 on PHP 8.3 CLI, artisan serve)                             │
│  Container: spa_backend | Port: 8000                                                   │
│                                                                                        │
│  - VerifyS2SSignature Middleware (Constant-time HMAC, ±300s window, Redis nonce)       │
│  - Multi-tenant Context Injection ($request->attributes->set('shop_domain', ...))      │
│  - Ingestion API: FormRequest → Readonly DTO → Pipeline → Guarded Upsert               │
│  - Ingestion Events → Aggregation Jobs (aggregation planned)                           │
└───────────────────────────┬─────────────────────────────────────────┬──────────────────┘
                            │                                         │
                            ▼                                         ▼
┌───────────────────────────────────────┐   ┌────────────────────────────────────────────┐
│  PostgreSQL 16                        │   │  Redis 7 (Queues & Cache)                  │
│  Container: spa_postgres | Port: 5433 │   │  Container: spa_redis | Port: 6379         │
│                                       │   └─────────────────────┬──────────────────────┘
│  - Multi-tenant Relational Schemas    │                         │
│  - Metric Aggregations & Rollups      │                         ▼
│  - Idempotent Event Log Ledger        │   ┌────────────────────────────────────────────┐
└───────────────────────────────────────┘   │  Dedicated Queue Worker                    │
                                            │  Container: spa_worker                     │
                                            │                                            │
                                            │  - Asynchronous Webhook Pipelines          │
                                            │  - Daily & Hourly Aggregation Rollups      │
                                            └────────────────────────────────────────────┘
```

---

## 2. Infrastructure Services

The stack is containerized with Docker Compose and tuned for macOS virtualization (Colima with `vz` hypervisor and `virtiofs` mounts):

| Service | Container Name | Base Image | Host Port | Purpose | Health Check |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **`postgres`** | `spa_postgres` | `postgres:16-alpine` | `5433` (container `5432`) | Databases `shopify_analytics`, `shopify_analytics_test`, `shopify_sessions` | `pg_isready -U postgres` |
| **`redis`** | `spa_redis` | `redis:7-alpine` | `6379` | Queue broker, cache, rate limits | `redis-cli ping` |
| **`backend`** | `spa_backend` | `php:8.3-cli` (custom) | `8000` | Laravel REST API application | HTTP `/api/v1/health` |
| **`laravel-worker`** | `spa_worker` | `php:8.3-cli` (custom) | — | Continuous queue worker (`php artisan queue:work redis`) | Process status |
| **`shopify-app`** | `spa_shopify_app` | `node:22-alpine` (`shopify-app/Dockerfile`) | `3000` | Production-style app server; compose profile `app` only. Development runs on the host with `shopify app dev --config local` | — |

Host port 5433 avoids a local PostgreSQL on 5432 intercepting connections. Containers still reach the database as `postgres:5432`.

---

## 3. Cryptographic S2S Trust Boundary

All communication between the Shopify Gateway and the Laravel Backend is secured via a zero-trust cryptographic signature scheme:

### Canonical Signature Construction
```
CANONICAL_PAYLOAD = METHOD + "\n" +
                    PATH + "\n" +
                    TIMESTAMP + "\n" +
                    NONCE + "\n" +
                    SHOP_DOMAIN + "\n" +
                    SHA256(BODY_JSON)

SIGNATURE = HMAC_SHA256(SECRET, CANONICAL_PAYLOAD)
```

### Required Security Headers
- `X-App-Shop-Domain`: Target merchant store (e.g. `merchant-store.myshopify.com`).
- `X-App-Timestamp`: Unix timestamp (UTC seconds). Drift beyond ±300 seconds (inclusive bound, `S2S_TOLERANCE_SECONDS`) is rejected with `401 Unauthorized`.
- `X-App-Nonce`: UUID, single use. Claimed atomically in Redis (`Cache::store('redis')->add`) for twice the tolerance window after the signature verifies; reuse is rejected with `401 Unauthorized`.
- `X-App-Signature`: Hex-encoded HMAC-SHA256 of the canonical payload, verified with `hash_equals()`.
- `X-Correlation-ID`: UUID echoed on every Laravel response, generated when absent or malformed, and attached to log context and queued job payloads via `Context`.

The shared secret is `S2S_SHARED_SECRET`. Both sides refuse to run without it; there is no fallback value.

---

## 4. Quick Start & Local Setup

### Prerequisites
- macOS with [Colima](https://github.com/abiosoft/colima) and Docker CLI:
  ```bash
  brew install colima docker docker-compose
  ```

### Step 1: Start Colima
Run Colima with native virtualization and high-speed filesystem mounting:
```bash
colima start --cpu 4 --memory 8 --disk 60 --vm-type=vz --mount-type=virtiofs
export DOCKER_HOST=unix://$HOME/.colima/default/docker.sock   # if docker cannot find the daemon
```

### Step 2: Configure Environment Files
Secrets are never committed. Three gitignored files, each with one job:

| File | Read by | Holds |
|---|---|---|
| `backend/.env` | Every Laravel process (HTTP server, queue worker, CLI, tests) | All Laravel config: `APP_KEY`, DB, Redis, prefixes, `S2S_SHARED_SECRET` |
| `.env` (root) | `docker compose` interpolation only | `DB_*` for `postgres`; `S2S_SHARED_SECRET`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SCOPES`, `SHOPIFY_APP_URL` for the `shopify-app` container |
| `shopify-app/.env` | Host dev: Prisma CLI and `shopify app dev` | `DATABASE_URL` (`localhost:5433/shopify_sessions`), `BACKEND_API_URL`, `S2S_SHARED_SECRET` |

Compose injects no Laravel environment into `backend` or `laravel-worker`, so the HTTP server and the worker always resolve identical config (same `APP_NAME`, `REDIS_PREFIX=spa-database-`, `CACHE_PREFIX=spa-cache-`), which keeps cache keys and Redis locks shared across processes.

1. Create the root and backend env files:
   ```bash
   cp .env.example .env
   cp backend/.env.example backend/.env
   ```
2. Generate secrets:
   ```bash
   echo "APP_KEY=base64:$(openssl rand -base64 32)"   # backend/.env only
   echo "S2S_SHARED_SECRET=$(openssl rand -hex 32)"   # same value in .env and backend/.env
   ```
3. Fill the Shopify values in the root `.env` from Dev Dashboard → App → Settings:
   ```dotenv
   SHOPIFY_API_KEY=<client id>
   SHOPIFY_API_SECRET=<client secret>
   SCOPES=read_products,read_orders
   SHOPIFY_APP_URL=http://localhost:3000
   ```
   `SHOPIFY_API_SECRET` lives only in the root `.env`; Laravel never receives Shopify credentials.
   The committed `shopify-app/shopify.app.toml` keeps a placeholder `client_id`; put the real one in the gitignored `shopify-app/shopify.app.local.toml` and run the CLI with `--config local` (see [STARTER.md](STARTER.md#4-create-the-app-in-the-shopify-dev-dashboard)).
4. Values that must match across the root and backend files: `S2S_SHARED_SECRET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
5. Create `shopify-app/.env` from the root values (secrets are copied, never printed):
   ```bash
   cd shopify-app && set -a && . ../.env && set +a && printf 'DATABASE_URL="postgresql://%s:%s@localhost:5433/shopify_sessions"\nBACKEND_API_URL=http://localhost:8000\nS2S_SHARED_SECRET=%s\n' "$DB_USERNAME" "$DB_PASSWORD" "$S2S_SHARED_SECRET" > .env && cd ..
   ```

### Step 3: Install Dependencies and Launch Containers
The backend image does not bundle dependencies (source is bind-mounted):
```bash
docker compose build
docker compose run --rm --no-deps backend composer install
docker compose up -d                # backend, laravel-worker, postgres, redis
```

The Shopify app runs on the host with the Shopify CLI during development (Node ≥ 22.12, `npm install -g @shopify/cli@latest`):
```bash
cd shopify-app
npm install
npm run setup                       # prisma generate + migrate deploy (shopify_sessions)
shopify app dev --config local      # tunnel + hot reload; uses the real client_id from shopify.app.local.toml
```
Always pass `--config local`: plain `shopify app dev` reads the placeholder `client_id` and fails with `no app was found for the provided apiKey`.

Verify service status:
```bash
docker compose ps
```

### Step 4: Run Database Migrations
```bash
docker compose exec backend php artisan migrate
```

---

## 5. Universal Command Reference

### Service Management
```bash
# Start all services in background
docker compose up -d

# Stop all services
docker compose down

# View aggregated logs
docker compose logs -f

# View specific service logs
docker compose logs -f backend
docker compose logs -f laravel-worker

# Optional: production-style Shopify app container (profile "app")
docker compose --profile app up -d --build shopify-app
docker compose logs -f shopify-app
```

### Testing & Quality Control
```bash
# Run Laravel Pest test suite (Feature & Unit tests)
# Runs on PostgreSQL database shopify_analytics_test (RefreshDatabase) and
# Redis DBs 2/3 with spa-test-* prefixes; never touches development data.
docker compose exec backend ./vendor/bin/pest

# Shopify app (host, inside shopify-app/)
npm test              # node --test: S2S signer, shop registration, webhook verification, uninstall
npm run typecheck     # React Router type generation + tsc --noEmit
npm run lint          # ESLint
```

### Live S2S Verification
Install or open the app in your dev store (`shopify app dev --config local`, press `p`). The `afterAuth` hook sends a signed request to Laravel; confirm the store arrived:
```bash
docker compose exec postgres psql -U postgres -d shopify_analytics -c "select shop_domain, status, currency_code, timezone from shops"
```
Expected: one row per installed store, `status = active`, with the store's currency and IANA timezone. Unsigned requests are rejected:
```bash
curl -s -X POST http://localhost:8000/internal/v1/ingestion/shop -H "Content-Type: application/json" -d '{}'
# {"error":"..."}  (HTTP 401)
```

### Laravel Artisan & CLI
```bash
# Run Artisan commands inside container
docker compose exec backend php artisan route:list
docker compose exec backend php artisan tinker

# Queue monitoring
docker compose exec backend php artisan queue:monitor default
```

### Database & Cache Direct Access
```bash
# Connect to PostgreSQL CLI (from the host directly: localhost:5433)
docker compose exec postgres psql -U postgres -d shopify_analytics   # Laravel data
docker compose exec postgres psql -U postgres -d shopify_sessions    # Shopify app sessions

# Connect to Redis CLI
docker compose exec redis redis-cli ping
```

---

## 6. Using as a Starter Template for Any Shopify App

The full step-by-step guide lives in [STARTER.md](STARTER.md): fresh-history clone of `v0.1.0-starter`, identifiers to rename, Dev Dashboard app creation, env files, boot, install, and the recommended build order.

`v0.1.0-starter` contains the backend stack, the domain schema and ingestion API, and the customized Shopify React Router app (Feature 5) that registers each installed store in Laravel. Starter tags use the `-starter` suffix so product releases keep plain version numbers.

```bash
git clone --branch v0.1.0-starter --depth 1 https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
cd my-shopify-app && rm -rf .git && git init -b main
```
