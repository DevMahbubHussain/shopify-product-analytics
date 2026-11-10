# Shopify Product Analytics — Multi-Tenant Architecture & Boilerplate

An enterprise-ready, containerized, multi-tenant Shopify application architecture featuring a decoupled dual-runtime stack:
1. **Shopify App Gateway (Frontend & Webhook Ingestion):** Node.js 20 / TypeScript with Shopify App Bridge and GraphQL Admin API.
2. **Analytics & Data Processing Engine (Backend):** Laravel 11/12 REST API, PostgreSQL 16, Redis 7, and dedicated background queue workers.
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
                                              │ Headers: X-Shopify-Shop-Domain,
                                              │          X-S2S-Timestamp,
                                              │          X-S2S-Signature,
                                              │          X-Correlation-ID
                                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  Backend Engine (Laravel 11/12 on PHP 8.3-FPM)                                         │
│  Container: spa_backend | Port: 8000                                                   │
│                                                                                        │
│  - VerifyS2SSignature Middleware (Constant-time HMAC check, ±300s window)              │
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
| **`laravel-worker`** | `spa_worker` | `php:8.3-cli` (custom) | — | Continuous queue worker (`php artisan queue:listen`) | Process status |
| **`shopify-app`** | `spa_shopify_app` | `node:20-alpine` (custom) | `3000` | Shopify Gateway & App Bridge server | HTTP `/health` |

---

## 3. Cryptographic S2S Trust Boundary

All communication between the Shopify Gateway and the Laravel Backend is secured via a zero-trust cryptographic signature scheme:

### Canonical Signature Construction
```
CANONICAL_PAYLOAD = METHOD + "\n" +
                    PATH + "\n" +
                    TIMESTAMP + "\n" +
                    SHOP_DOMAIN + "\n" +
                    SHA256(BODY_JSON)

SIGNATURE = HMAC_SHA256(SECRET, CANONICAL_PAYLOAD)
```

### Required Security Headers
- `X-Shopify-Shop-Domain`: Target merchant store (e.g. `merchant-store.myshopify.com`).
- `X-S2S-Timestamp`: Unix timestamp (UTC seconds). Replayed or drifted requests $> 300$ seconds are rejected with `401 Unauthorized`.
- `X-S2S-Signature`: Hex-encoded HMAC-SHA256 hash verified using `hash_equals()`.
- `X-Correlation-ID`: Unique UUID propagated through gateway, API logs, and queue jobs.

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
1. Create the backend `.env`:
   ```bash
   cp backend/.env.example backend/.env
   ```
2. Generate keys and secrets:
   ```bash
   # Generate Laravel APP_KEY
   cd backend && php artisan key:generate && cd ..

   # Define shared S2S secret (must match in backend and shopify-app)
   # openssl rand -hex 32
   ```
3. Ensure `docker-compose.yml` and `backend/.env` contain matching values:
   - `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE=shopify_analytics`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`
   - `REDIS_HOST=redis`, `REDIS_PORT=6379`
   - `SPA_BACKEND_S2S_SECRET=<your-64-character-hex-secret>`

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
   git clone --branch v0.1.0-starter https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
   cd my-shopify-app
   git checkout -b main
   ```
2. Configure `shopify-app/shopify.app.toml`:
   - Set your `client_id`, app name, and OAuth scopes (`read_products`, `read_orders`, etc.).
3. Define your domain models & migrations in `backend/database/migrations`.
4. Implement your domain DTOs in `backend/app/DTOs`.
5. Connect your App Bridge views in `shopify-app/app/routes/`.

