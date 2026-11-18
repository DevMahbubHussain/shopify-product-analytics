# Shopify Product Analytics — Multi-Tenant Architecture & Boilerplate

An enterprise-ready, containerized, multi-tenant Shopify application architecture featuring a decoupled dual-runtime stack:
1. **Shopify App Gateway (Frontend & Webhook Ingestion):** Node.js 20 / TypeScript with Shopify App Bridge and GraphQL Admin API.
2. **Analytics & Data Processing Engine (Backend):** Laravel 13 REST API, PostgreSQL 16, Redis 7, and dedicated background queue workers.
3. **Cryptographic S2S Trust Boundary:** Deterministic HMAC-SHA256 request signing with anti-replay protection and automatic tenant isolation.

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
│  - Session Storage & OAuth 2.0 Token Exchange                                          │
│  - Polaris UI & App Bridge Actions                                                     │
│  - Webhook Ingestion & HMAC Verification                                               │
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
│  - Multi-tenant Context Injection ($request->attributes->set('shop_domain', ...))       │
│  - Typed REST API with Readonly Data Transfer Objects (DTOs)                           │
│  - Asynchronous Ingestion & Aggregation Job Dispatcher                                │
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
```

### Step 2: Configure Environment Files
Secrets are never committed. Two gitignored files, each with one job:

| File | Read by | Holds |
|---|---|---|
| `backend/.env` | Every Laravel process (HTTP server, queue worker, CLI, tests) | All Laravel config: `APP_KEY`, DB, Redis, prefixes, `S2S_SHARED_SECRET` |
| `.env` (root) | `docker compose` interpolation only | `DB_*` for the `postgres` container, `S2S_SHARED_SECRET` for `shopify-app` |

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
3. Values that must match across the two files: `S2S_SHARED_SECRET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

### Step 3: Launch Containers
```bash
docker compose up -d --build
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

This repository serves as a boilerplate for building any Shopify application requiring a scalable background processing engine.

### How to Initialize a New Project from this Template:
1. Clone the starter tag:
   ```bash
   git clone --branch v0.2.0-starter https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
   cd my-shopify-app
   git checkout -b main
   ```
2. Configure `shopify-app/shopify.app.toml`:
   - Set your `client_id`, app name, and OAuth scopes (`read_products`, `read_orders`, etc.).
3. Define your domain models & migrations in `backend/database/migrations`.
4. Implement your domain DTOs in `backend/app/DTOs`.
5. Connect your App Bridge views in `shopify-app/app/routes/`.

