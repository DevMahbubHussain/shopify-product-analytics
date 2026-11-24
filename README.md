# Shopify Product Analytics — Multi-Tenant Architecture & Application

An enterprise-grade, embedded Shopify application delivering product-level sales reporting, variant analytics, and performance insights for Shopify merchants inside Shopify Admin.

Built on a decoupled, dual-runtime architecture:
1. **Shopify App Gateway (Embedded UI & Ingestion):** Node.js 22 / TypeScript, Shopify React Router app template with App Bridge, Polaris web components, and GraphQL Admin API (`2026-10`).
2. **Analytics & Data Processing Engine (Backend):** Laravel 13 REST API on PHP 8.3 CLI, PostgreSQL 16, Redis 7, dedicated background queue workers, and automated task schedulers.
3. **Cryptographic S2S Trust Boundary:** Deterministic HMAC-SHA256 request signing with timestamp (±300 s window), single-use nonce replay protection, and strict tenant context isolation.

---

## Architecture & System Overview

```
                   ┌────────────────────────────────────────────────────────┐
                   │                     Shopify Platform                   │
                   │  - Shopify Admin (Embedded App Bridge UI)              │
                   │  - Webhook Dispatcher (GDPR, Orders, Refunds, App)     │
                   │  - Shopify Billing API (Recurring Subscriptions)       │
                   └──────────────────────────┬─────────────────────────────┘
                                              │ Public HTTPS
                                              ▼
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  Shopify App Gateway (Node.js 22 / TypeScript, React Router)                           │
│  Container: spa_shopify_app | Port: 3000                                               │
│                                                                                        │
│  - Session Storage & Managed Install / Token Exchange (Prisma PostgreSQL)              │
│  - App Bridge Embedded Layout & Polaris Web Components (<s-page>, <s-table>)           │
│  - GraphQL Admin API Harvester (Cursor Pagination & Leaky Bucket Rate Limiting)        │
│  - Webhook Ingestion & HMAC Verification (App Lifecycle, Orders, Refunds, Subscriptions)│
│  - Cryptographic S2S Client (HMAC-SHA256 Signing + Nonce + Correlation IDs)            │
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
│  - Multi-stage Ingestion Pipelines (FormRequest → Readonly DTO → Pipeline → Upsert)   │
│  - Transactional Outbox Pattern (Atomic DB persistence + Fast-path Redis queue push)  │
│  - Analytics REST API (/api/v1/analytics/*) hitting Precomputed Metric Rollups         │
│  - Plan Entitlement Enforcement (Free 30-day limit vs Pro 365-day history)             │
│  - Asynchronous CSV Export Engine & Streaming Dispatcher                               │
└───────────────────────────┬─────────────────────────────────────────┬──────────────────┘
                            │                                         │
                            ▼                                         ▼
┌───────────────────────────────────────┐   ┌────────────────────────────────────────────┐
│  PostgreSQL 16                        │   │  Redis 7 (Queues & Cache)                  │
│  Container: spa_postgres | Port: 5433 │   │  Container: spa_redis | Port: 6379         │
│                                       │   └─────────────────────┬──────────────────────┘
│  - Multi-tenant Relational Schemas    │                         │
│  - Precomputed Daily Rollups          │                         ├───┐
│    (product_daily_metrics, sub-50ms)  │                         │   │
│  - Compound Unique Idempotency Keys   │                         ▼   ▼
│  - Strict Financial Precision         │   ┌────────────────────────────────────────────┐
│    DECIMAL(20,6) & Currency Isolation │   │  Dedicated Queue Worker                    │
│  - Transactional Outbox Ledger        │   │  Container: spa_worker                     │
│    (outbox_events, skip-locked retry) │   │                                            │
│  - Zero Protected Customer Data (PCD) │   │  - AggregateProductDailyMetricsJob         │
│  - Monotonic Timestamp Updates        │   │  - GenerateCsvExportJob                    │
│    (shopify_updated_at freshness)     │   │  - Redis Atomic Locks (WithoutOverlapping) │
│  - Durable Subscriptions & Exports    │   └────────────────────────────────────────────┘
└───────────────────────────────────────┘                         │
                                                                  ▼
                                            ┌────────────────────────────────────────────┐
                                            │  Task Scheduler & Recovery Sweeper         │
                                            │  Container: spa_scheduler                  │
                                            │                                            │
                                            │  - php artisan outbox:publish (Every min)  │
                                            │  - php artisan outbox:prune (Retention)    │
                                            │  - Truncated Exponential Retry Backoff     │
                                            └────────────────────────────────────────────┘
```

---

## Core Features & Capabilities

### 1. Embedded Polaris Analytics Dashboard
- **Overview KPI Cards:** Real-time visibility into Net Sales, Gross Sales, Discounts, Refunds, Units Sold, Orders, and Average Order Revenue with preceding period comparisons (percentage change and directional badges).
- **Interactive Metric Definitions Help Panel:** Collapsible definition grid explaining exact accounting formulas.
- **Visual Sales Trend Visualizer:** Interactive daily and monthly bar chart breakdown of store revenue velocity.
- **Product Performance Table:** Searchable, sortable catalog table displaying SKU metrics, status badges (`ACTIVE`, `DRAFT`, `ARCHIVED`), unit velocity, and refund amounts.
- **Product & Variant Drilldown (`/app/products/:id`):** Deep-dive reporting into individual products with variant breakdown tables and inventory-level metrics.
- **Date Preset Selector Toolbar:** Instant filtering across `Today`, `Yesterday`, `Last 7 days`, `Last 30 days`, `Last 90 days`, `This month`, `Last month`, and `Custom` calendar ranges.
- **Day 0 Merchant Onboarding Stepper:** Contextual 4-step sync progress stepper (`Connecting`, `Products`, `Orders & Refunds`, `Metrics Aggregation`) with live record counters and filter reset triggers.

### 2. High-Throughput Background Ingestion & Aggregation
- **Historical Catalogue & Order Harvester:** Automatic bulk ingestion upon install using cursor pagination, GraphQL cost tracking, and rate limiter protection.
- **Deterministic Incremental Webhooks:** Real-time listeners for `orders/create`, `orders/updated`, `refunds/create`, `app_subscriptions/update`, and `app/uninstalled`.
- **Precomputed Daily Rollups (`product_daily_metrics`):** Aggregation worker processes domain events asynchronously into indexed daily rollups, ensuring dashboard response times stay sub-50ms without table scans.
- **Strict Financial Precision:** All monetary amounts are stored as `DECIMAL(20,6)` with ISO 4217 currency isolation. Floating-point arithmetic is prohibited.

### 3. Distributed Messaging & Transactional Outbox Reliability
- **Transactional Outbox Dual-Path Delivery:** HTTP pipelines atomically persist domain events (`OrdersIngested`, `RefundsIngested`) in `outbox_events` inside `DB::transaction(fn() => ..., 5)`. The fast-path immediately pushes to Redis, while a background sweeper (`php artisan outbox:publish`) continuously re-dispatches stranded events older than 30 seconds.
- **Dual-Dispatch Crash Replay Idempotency:** If an ingestion process crashes after pushing to Redis but before database commit, the sweeper re-dispatches. Downstream workers compute pure aggregations over raw rows and upsert precomputed values, guaranteeing mathematical convergence with zero double-counting.
- **Truncated Exponential Retry Backoff:** Transient broker outages back off dynamically ($2^{\min(\text{attempts}, 6)} \times 5\text{s}$) with `retry_after` timestamp filtering, capped at 5 attempts before marking `FAILED`.
- **Poison Pill Quarantine:** Corrupt or malformed payload exceptions (`\InvalidArgumentException`, unresolvable tenant) immediately transition to `FAILED` with diagnostics and 0 retry delay.
- **Physical Redis Queue Validation:** End-to-end integration tests verify physical message broker delivery (`Redis::connection()->llen('queues:default')`) and inspect serialized JSON payloads directly from Redis lists.

### 4. Concurrency, Freshness & Multi-Tenant Database Controls
- **Monotonic Webhook Freshness Guards:** Tracks `shopify_updated_at` (falling back monotonically to `processed_at`) on `refund_line_items` and `order_line_items`. Conditional database updates (`WHERE excluded.shopify_updated_at >= ...`) reject stale out-of-order webhook deliveries (`skipped_stale = 1`), suppressing redundant recalculations.
- **Deterministic Parent Row Locking:** Ingestion pipelines acquiring locks on parent orders sort IDs in ascending order (`ORDER BY id ASC lockForUpdate()`) within `DB::transaction(..., 5)`, eliminating database deadlocks (`SQLSTATE 40P01`) under concurrent webhook bursts.
- **Redis Atomic Locks & Deduplication:** Aggregation jobs use `ShouldBeUniqueUntilProcessing` keys `(shop_id:product_id:metric_date)` and `WithoutOverlapping` locks with 120s expiration and 10s backoff release, running exclusively against Redis atomic primitives (`SETNX` and Lua scripts).
- **Multi-Currency Staleness Elimination:** Recalculation jobs query all historical currencies ever recorded for an affected bucket and explicitly zero out stale rows if order edits or cancellations remove transactions in a foreign currency.
- **Half-Open Day Boundary Standard:** All metric aggregations enforce $[startOfDayUtc, startOfNextDayUtc)$ intervals (`>= startOfDayUtc` and `< startOfNextDayUtc`) with PostgreSQL `timestamptz(6)` microsecond precision, preventing boundary transaction loss or duplication.

### 5. Plans, Subscriptions & Monetization
- **Shopify Managed App Subscriptions:** Embedded billing integration using Shopify Billing GraphQL API with recurring 30-day billing intervals and a 7-day risk-free trial.
- **Free Tier ($0/month):** Up to 30 days of historical reporting, standard overview KPIs, product performance table, and variant drilldowns.
- **Pro Tier ($19/month):** Unlocks 365 days of historical reporting, preceding period comparisons, priority webhook processing, and unlimited CSV exports.
- **Plan Entitlement Gating:** Free tier requests beyond 30 days are automatically restricted with informative upgrade barriers.

### 6. Asynchronous CSV Data Exports
- **Background Queue Processing:** Heavy export requests are dispatched to Redis workers (`GenerateCsvExportJob`) using chunked database cursors (`lazy()`) to keep memory usage flat.
- **Signed Download Links:** Completed exports generate secure temporary files with automated status polling.

### 7. Security & Shopify App Store Compliance
- **Zero Protected Customer Data (PCD):** Automated audit test suites verify that zero customer personal data (names, email addresses, phone numbers, physical addresses) is ever collected or persisted.
- **Mandatory Compliance Webhooks:** Verified handlers for `customers/data_request`, `customers/redact`, and `shop/redact`.
- **Strict S2S Trust Boundary:** Request signing with HMAC-SHA256, single-use Redis nonces, and timestamp verification (±300 s window). Shopify access tokens never leave the gateway or touch Laravel.

### 8. Automated E2E Browser Testing (Playwright)
- Headless Chromium end-to-end browser specifications verifying gateway availability, route authentication barriers, KPI rendering, interactive accordions, search/filter controls, and billing flows.

---

## Technical Documentation Index

Detailed architectural explainers, database migration specifications, and engineering checklists are maintained in the [`docs/`](./docs) directory:

| Document | Topic & Focus Area |
|---|---|
| [`financial_distributed_systems_and_concurrency_checklist.md`](./docs/financial_distributed_systems_and_concurrency_checklist.md) | **Core Engineering Checklist:** Financial precision, currency isolation, outbox reliability, deterministic locking, and pre-merge verification rules. |
| [`performance_and_memory_benchmarks.md`](./docs/performance_and_memory_benchmarks.md) | **Scale Benchmarks:** High-volume load benchmarks (50k lines), natural index query planner evaluation, constant $O(1)$ query counts, and Zend Engine heap stability. |
| [`transactional_outbox_reliability.md`](./docs/transactional_outbox_reliability.md) | **Outbox Architecture:** Atomic persistence, fast-path dispatch, recovery sweeper daemon, and crash replay idempotency. |
| [`concurrency_retry_and_idempotency_verification.md`](./docs/concurrency_retry_and_idempotency_verification.md) | **Concurrency Controls:** Retry backoff, poison pill isolation, deterministic parent locking, and out-of-order webhook protection. |
| [`refund_freshness_guard.md`](./docs/refund_freshness_guard.md) | **Webhook Ordering:** Monotonic timestamp tracking, conditional updates, and stale delivery suppression on refunds. |
| [`half_open_day_boundary.md`](./docs/half_open_day_boundary.md) | **Timezone Precision:** Store-local date projection, microsecond precision, and $[startOfDayUtc, startOfNextDayUtc)$ intervals. |
| [`metrics_recalculation_on_updates.md`](./docs/metrics_recalculation_on_updates.md) | **Recalculation Engine:** Pre/post-update bucket dispatches and multi-currency staleness elimination. |
| [`job_concurrency_and_lost_updates.md`](./docs/job_concurrency_and_lost_updates.md) | **Worker Concurrency:** Redis atomic locks (`WithoutOverlapping`), unique jobs, and deadlock recovery. |
| [`migrations.md`](./docs/migrations.md) | **Database Schema:** PostgreSQL relational tables, composite indexes, and multi-tenant constraints. |
| [`ingestion-pipeline.md`](./docs/ingestion-pipeline.md) | **Pipeline Pattern:** S2S ingestion pipes, FormRequests, and immutable DTO mapping. |
| [`analytics-engine.md`](./docs/analytics-engine.md) | **Analytics Engine:** REST contracts, precomputed rollups, and preceding period comparisons. |
| [`polaris-ui.md`](./docs/polaris-ui.md) | **Embedded UI:** App Bridge layout, Polaris Web Components, and state reducers. |
| [`billing-exports.md`](./docs/billing-exports.md) | **Monetization & Exports:** Shopify recurring app subscriptions and chunked streaming CSV generation. |

---

## Development and Deployment Using Docker (with Commands)

The project includes Docker Compose configurations for both local development (Colima on macOS or Docker on Linux) and production-hardened container deployments.

### Local Development Environment

```
Host (macOS / Linux)
├── shopify-app/ (Node 22, host dev server: shopify app dev --config local)
└── Docker Compose Containers (Colima daemon):
    ├── spa_postgres   (PostgreSQL 16 on host port 5433, container port 5432)
    ├── spa_redis      (Redis 7 on host port 6379)
    ├── spa_backend    (Laravel 13 REST API on host port 8000)
    ├── spa_worker     (Continuous queue worker)
    └── spa_scheduler  (Task scheduler & outbox recovery sweeper daemon)
```

#### Step 1: Start Docker / Colima
If running on macOS with Colima:
```bash
colima start --cpu 4 --memory 8 --disk 60 --vm-type=vz --mount-type=virtiofs
export DOCKER_HOST=unix://$HOME/.colima/default/docker.sock
```

#### Step 2: Configure Environment Files
Secrets are never committed. Three environment files isolate concerns:

| File | Read by | Purpose |
|---|---|---|
| `backend/.env` | Laravel HTTP, CLI, queue workers, tests | Database, Redis, S2S secret, cache prefixes |
| `.env` (root) | Docker Compose | Container credentials and Shopify app secrets |
| `shopify-app/.env` | Host dev server and Prisma CLI | Session database URL, S2S shared secret |

1. Create environment files from examples:
   ```bash
   cp .env.example .env
   cp backend/.env.example backend/.env
   ```

2. Generate application keys and S2S shared secret:
   ```bash
   echo "APP_KEY=base64:$(openssl rand -base64 32)"     # Add to backend/.env
   echo "S2S_SHARED_SECRET=$(openssl rand -hex 32)"     # Must match in .env and backend/.env
   ```

3. Configure Shopify app credentials in the root `.env`:
   ```dotenv
   SHOPIFY_API_KEY=<your-shopify-client-id>
   SHOPIFY_API_SECRET=<your-shopify-client-secret>
   SCOPES=read_products,read_orders
   SHOPIFY_APP_URL=http://localhost:3000
   ```

4. Generate `shopify-app/.env` matching the database password and S2S secret:
   ```bash
   cd shopify-app && set -a && . ../.env && set +a && printf 'DATABASE_URL="postgresql://%s:%s@localhost:5433/shopify_sessions"\nBACKEND_API_URL=http://localhost:8000\nS2S_SHARED_SECRET=%s\n' "$DB_USERNAME" "$DB_PASSWORD" "$S2S_SHARED_SECRET" > .env && cd ..
   ```

#### Step 3: Build & Launch Local Docker Services
The backend image bind-mounts source code for rapid iteration:
```bash
# Build backend, worker, and scheduler images
docker compose build

# Install Composer dependencies inside container
docker compose run --rm --no-deps backend composer install

# Start backend, postgres, redis, laravel-worker, and laravel-scheduler
docker compose up -d

# Verify container health
docker compose ps
```

#### Step 4: Run Database Migrations
```bash
# Migrate application tables (shops, products, orders, metrics, outbox, subscriptions, exports)
docker compose exec backend php artisan migrate
```

#### Step 5: Start the Shopify App Gateway (Host)
The embedded app runs on the host using the Shopify CLI (Node ≥ 22.12):
```bash
cd shopify-app

# Install dependencies and generate Prisma client
npm install
npm run setup

# Launch dev server and tunnel with local config (uses shopify.app.local.toml)
shopify app dev --config local
```

Press `p` in the terminal to open the application in your Shopify development store.

---

### Production Container Deployment

The production deployment runs fully containerized via `docker-compose.prod.yml`, utilizing multi-stage production Dockerfiles, non-root users (`node`, `www-data`), OPcache optimization, and an Nginx reverse proxy enforcing Shopify `frame-ancestors` Content Security Policy (CSP).

```
[ Internet / Load Balancer ]
            │
            ▼ Port 80
┌───────────────────────────────────────┐
│  Nginx Reverse Proxy (spa_nginx_prod) │
│  - Gzip compression                   │
│  - Shopify CSP frame-ancestors        │
│  - Path routing: /api -> backend      │
│                  /*   -> shopify-app  │
└───────────┬───────────────┬───────────┘
            │               │
            ▼               ▼
┌───────────────────┐   ┌────────────────────────────────────────┐
│  shopify-app      │   │  backend (PHP 8.3 OPcache / Artisan)   │
│  Port: 3000       │   │  Port: 8000                            │
└───────────────────┘   └────────────────────────────────────────┘
```

#### Production Build & Launch Commands
```bash
# 1. Build production images (multi-stage, production dependencies only)
docker compose -f docker-compose.prod.yml build

# 2. Start the production stack in background
docker compose -f docker-compose.prod.yml up -d

# 3. Execute database migrations
docker compose -f docker-compose.prod.yml exec backend php artisan migrate --force

# 4. Verify service status and logs
docker compose -f docker-compose.prod.yml ps
docker compose -f docker-compose.prod.yml logs -f nginx
docker compose -f docker-compose.prod.yml logs -f backend
docker compose -f docker-compose.prod.yml logs -f shopify-app
```

#### Production Health Probes
```bash
# Gateway load balancer health check
curl -I http://localhost/healthz
# HTTP/1.1 200 OK
# {"status":"ok","app":"product-analytics-gateway"}

# Backend API health probe
curl -I http://localhost:8000/api/v1/health
# HTTP/1.1 200 OK
```

---

## Testing & Quality Control

### Backend Test Suite (Pest — Laravel)
Tests run against a dedicated PostgreSQL database (`shopify_analytics_test`) and isolated Redis databases (DB 2/3), ensuring zero interference with development data:
```bash
# Run all backend unit, feature, security, outbox, and performance benchmark tests
docker compose exec backend ./vendor/bin/pest

# Run targeted test file
docker compose exec backend ./vendor/bin/pest tests/Feature/Ingestion/OutboxDualDispatchCrashReplayTest.php
```
**Current Status:** `337 passed (3,637 assertions)`

### Frontend Unit & Domain Tests (Node Native Runner)
In-process unit testing for date calculations, S2S request signatures, GraphQL pagination, and state reducers:
```bash
cd shopify-app

# Run all TypeScript unit tests
npm test

# Run React Router type generation & strict TypeScript compilation
npm run typecheck

# Run ESLint
npm run lint
```
**Current Status:** `169 passed (9 test suites)`

### Frontend End-to-End Browser Tests (Playwright)
Headless Chromium browser automation exercising App Bridge layout, KPI cards, metric definitions accordion, filter pills, search input, and pricing tables:
```bash
cd shopify-app

# Run full E2E test suite in headless mode
npm run test:e2e

# Run with visible browser window
npx playwright test --headed

# Run interactive Playwright UI mode with DOM visual debugger
npx playwright test --ui

# Inspect HTML report and trace archives
npx playwright show-report
```
**Current Status:** `12 passed, 3 skipped (conditional development store tests)`

**Combined Quality Control Status:** `506 tests passing, 0 failures, clean strict TypeScript`.

---

## Universal Operational Commands

### Container Management
```bash
# Tail logs for all containers
docker compose logs -f

# Tail specific container logs
docker compose logs -f backend
docker compose logs -f laravel-worker
docker compose logs -f laravel-scheduler

# Stop all local containers
docker compose down
```

### Database & Cache Direct Access
```bash
# Connect to PostgreSQL analytics database (host port 5433)
docker compose exec postgres psql -U postgres -d shopify_analytics

# Connect to PostgreSQL session database
docker compose exec postgres psql -U postgres -d shopify_sessions

# Connect to Redis CLI
docker compose exec redis redis-cli ping
```

### Transactional Outbox & Scheduler Operations
```bash
# Manually trigger the outbox recovery sweeper (processes stranded records older than 30s)
docker compose exec backend php artisan outbox:publish --age=30 --limit=100

# Prune published outbox records older than 7 days
docker compose exec backend php artisan outbox:prune --days=7

# Inspect scheduled cron jobs
docker compose exec backend php artisan schedule:list
```

### Laravel CLI & Queue Monitoring
```bash
# Monitor active queue workers
docker compose exec backend php artisan queue:monitor default,exports

# Open Laravel Tinker REPL
docker compose exec backend php artisan tinker

# View registered routes
docker compose exec backend php artisan route:list
```

### Live S2S Verification
After installing the app in a store (`shopify app dev --config local`), verify that the shop was ingested into PostgreSQL:
```bash
docker compose exec postgres psql -U postgres -d shopify_analytics -c "select shop_domain, status, currency_code, timezone from shops"
```
