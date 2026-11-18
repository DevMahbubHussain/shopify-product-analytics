# Shopify Product Analytics — Multi-Tenant Architecture & Boilerplate

An enterprise-ready, containerized, multi-tenant Shopify application architecture featuring a decoupled dual-runtime stack:
1. **Shopify App Gateway (Frontend & Webhook Ingestion):** Node.js 20 / TypeScript with Shopify App Bridge and GraphQL Admin API.
2. **Analytics & Data Processing Engine (Backend):** Laravel 13 REST API, PostgreSQL 16, Redis 7, and dedicated background queue workers.
3. **Cryptographic S2S Trust Boundary:** Deterministic HMAC-SHA256 request signing with timestamp + single-use nonce replay protection and tenant context binding.

> **Starting a new Shopify app from this repo?** Follow [STARTER.md](STARTER.md) (tag `v0.3.1-starter`).

### Current Status

| Area | Status |
|---|---|
| Docker stack, env/secrets handling, S2S boundary, test harness | Done |
| Shopify app config (`shopify.app.toml` template + gitignored `shopify.app.local.toml`, API `2026-10`, managed install) | Done |
| Multi-tenant domain schema (10 tables) | Done |
| Ingestion API (6 signed endpoints) | Done |
| React Router OAuth, session storage, Polaris UI, webhooks | Planned (gateway is currently a minimal Node server) |
| Metric aggregation, analytics REST API, billing, exports | Planned |

Boxes in the topology below marked *(planned)* describe the target architecture.

---

## Features

Each feature lists what it provides, where it lives, and how it talks to the rest of the system. Full explainers are linked.

### 1. Service-to-Service (S2S) Trust Boundary

**Provides:** proof that every request reaching Laravel's private API came from our own Shopify gateway, was not tampered with, and is not a replay, plus the verified store (tenant) it belongs to.

| Part | Location |
|---|---|
| Request signer | `shopify-app/app/services/backendClient.ts` |
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

**Provides:** six private endpoints through which the gateway pushes Shopify data into Laravel:

| Endpoint | Purpose |
|---|---|
| `POST /internal/v1/ingestion/shop` | Install, refresh, or reactivate a store |
| `POST /internal/v1/ingestion/products` | Upsert products with variants |
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

---

## How the Parts Communicate

```
 Merchant browser (Shopify Admin)
        │  App Bridge iframe                                    (planned: Step 3/6)
        ▼
 ┌──────────────────────────┐   GraphQL Admin API (OAuth token)   ┌─────────────────┐
 │ shopify-app (Node 20)    │ ──────────────────────────────────► │ Shopify         │
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
| Shopify → shopify-app | HTTPS webhooks | `X-Shopify-Hmac-Sha256` | Planned (Step 3) |
| shopify-app → Shopify | GraphQL Admin API `2026-10` | OAuth access token (never leaves shopify-app) | Planned (Step 3) |
| shopify-app → backend | JSON over internal network | HMAC-SHA256 + ±300 s timestamp + single-use nonce + per-shop rate limit | Done |
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
│  Shopify App Gateway (Node.js 20 / TypeScript)                                         │
│  Container: spa_shopify_app | Port: 3000                                               │
│                                                                                        │
│  - Session Storage & OAuth 2.0 Token Exchange (planned)                                │
│  - Polaris UI & App Bridge Actions (planned)                                           │
│  - Webhook Ingestion & HMAC Verification (planned)                                     │
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
│  Container: spa_postgres | Port: 5432 │   │  Container: spa_redis | Port: 6379         │
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
| **`postgres`** | `spa_postgres` | `postgres:16-alpine` | `5432` | Primary relational datastore | `pg_isready -U postgres` |
| **`redis`** | `spa_redis` | `redis:7-alpine` | `6379` | Queue broker, cache, rate limits | `redis-cli ping` |
| **`backend`** | `spa_backend` | `php:8.3-cli` (custom) | `8000` | Laravel REST API application | HTTP `/api/v1/health` |
| **`laravel-worker`** | `spa_worker` | `php:8.3-cli` (custom) | — | Continuous queue worker (`php artisan queue:work redis`) | Process status |
| **`shopify-app`** | `spa_shopify_app` | `node:20-alpine` (custom) | `3000` | Shopify Gateway & App Bridge server | HTTP `/health` |

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
Secrets are never committed. Two gitignored files, each with one job:

| File | Read by | Holds |
|---|---|---|
| `backend/.env` | Every Laravel process (HTTP server, queue worker, CLI, tests) | All Laravel config: `APP_KEY`, DB, Redis, prefixes, `S2S_SHARED_SECRET` |
| `.env` (root) | `docker compose` interpolation only | `DB_*` for `postgres`; `S2S_SHARED_SECRET`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SCOPES`, `SHOPIFY_APP_URL` for `shopify-app` |

Compose injects no Laravel environment into `backend` or `laravel-worker`, so the HTTP server and the worker always resolve identical config (same `APP_NAME`, `REDIS_PREFIX=spa-database-`, `CACHE_PREFIX=spa-cache-`), which keeps cache keys and Redis locks shared across processes.

1. Create both env files:
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
4. Values that must match across the two files: `S2S_SHARED_SECRET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

### Step 3: Install Dependencies and Launch Containers
Images do not bundle dependencies (source is bind-mounted):
```bash
docker compose build
docker compose run --rm --no-deps backend composer install
docker compose run --rm --no-deps shopify-app npm install
docker compose up -d
```

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
docker compose logs -f shopify-app
```

### Testing & Quality Control
```bash
# Run Laravel Pest test suite (Feature & Unit tests)
# Runs on PostgreSQL database shopify_analytics_test (RefreshDatabase) and
# Redis DBs 2/3 with spa-test-* prefixes; never touches development data.
docker compose exec backend ./vendor/bin/pest

# Run TypeScript test runner
docker compose exec shopify-app npm test

# Run TypeScript type check
docker compose exec shopify-app npm run lint
```

### Live S2S Cryptographic Verification
Test the live gateway-to-backend cryptographic handshake:
```bash
curl -s "http://localhost:3000/s2s-handshake?shop=merchant-store.myshopify.com"
```
Expected output:
```json
{
  "s2s": "verified",
  "backendResponse": {
    "status": "ok",
    "message": "S2S handshake verified successfully.",
    "shop_domain": "merchant-store.myshopify.com",
    "timestamp": 1794302490
  }
}
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
# Connect to PostgreSQL CLI
docker compose exec postgres psql -U postgres -d shopify_analytics

# Connect to Redis CLI
docker compose exec redis redis-cli ping
```

---

## 6. Using as a Starter Template for Any Shopify App

The full step-by-step guide lives in [STARTER.md](STARTER.md): fresh-history clone of `v0.3.1-starter`, identifiers to rename, Dev Dashboard app creation, env files, boot and verification, and the recommended build order.

```bash
git clone --branch v0.3.1-starter --depth 1 https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
cd my-shopify-app && rm -rf .git && git init -b main
```
