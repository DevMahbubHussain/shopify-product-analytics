# Production Deployment & Operational Runbook

**Application Name:** Product Analytics  
**Target Environment:** AWS (ECS Fargate / EC2), Kubernetes, or Containerized Cloud VM  
**Architecture:** Distributed Multi-Tenant Architecture (Shopify App Gateway + Laravel Analytics Engine)

---

## 1. Production Architecture Overview

Product Analytics separates client-facing Shopify integration from high-throughput analytics data processing across a strict service-to-service (S2S) security boundary:

```
[Shopify Merchant Admin]
         │ (Embedded App Bridge Iframe)
         ▼
[AWS ALB / Cloudflare / Nginx Ingress (Port 443 TLS 1.3)]
         │
         ├───► /healthz (Direct health monitoring pass-through)
         │
         ▼
[shopify-app Gateway Container (Node 22 / React Router)]
  ├─ Owns Shopify OAuth, Sessions & App Bridge UI
  ├─ Owns Shopify Admin API Harvesters & Webhook Listeners
  └─ Signs S2S requests (HMAC-SHA256, Nonce, Timestamp ±300s)
         │
         ▼ (Private VPC Network / Port 8000)
[backend Analytics Engine Container (PHP 8.3 / Laravel 13)]
  ├─ Verifies S2S Signatures, Nonces & Tenant Isolation
  ├─ Serves Precomputed Analytics REST APIs (/api/v1/*)
  └─ Ingests Normalized Catalogs & Orders (/internal/v1/*)
         │
         ├──────────────────────────────┐
         ▼                              ▼
[Managed PostgreSQL 16 (RDS)]   [Managed Redis 7 (ElastiCache)]
  - shopify_analytics (Tenant Data) - Queue Buffers (default, exports)
  - shopify_sessions (Prisma Auth)   - Atomic Locks (ShouldBeUnique)
                                     - S2S Nonce Deduplication
                                     - Ingestion Rate Limiters
                                        ▲
                                        │
[laravel-worker Queue Daemon Container (PHP 8.3 / Supervisor)]
  - Processes Metric Aggregation Jobs (ProductDailyMetrics)
  - Processes Asynchronous CSV Report Exports
```

---

## 2. Container Images & Build Artifacts

Production builds utilize multi-stage minimal Dockerfiles:

| Service | Dockerfile | Base Image | Security Standard |
|---|---|---|---|
| **Shopify Gateway** | `shopify-app/Dockerfile.production` | `node:22-alpine` | Non-root `USER node`, multi-stage build, stripped devDependencies, healthcheck. |
| **Laravel Backend** | `infrastructure/docker/php/Dockerfile.production` | `php:8.3-cli-alpine` | Non-root `USER www-data`, pre-compiled OPcache, stripped compiler tools, healthcheck. |
| **Queue Worker** | `infrastructure/docker/php/Dockerfile.production` | `php:8.3-cli-alpine` | Dedicated worker process (`queue:work redis --queue=default,exports`). |
| **Ingress Proxy** | `infrastructure/docker/nginx/nginx.conf` | `nginx:alpine` | Gzip compression, rate limiting, Shopify CSP `frame-ancestors`. |

---

## 3. Environment Variables & Secrets Matrix

Production secrets must be injected securely via AWS Secrets Manager, HashiCorp Vault, or encrypted environment files:

### 3.1 Shopify Gateway (`shopify-app`)

| Variable | Description | Example / Format |
|---|---|---|
| `PORT` | Listening HTTP port | `3000` |
| `NODE_ENV` | Runtime environment | `production` |
| `DATABASE_URL` | PostgreSQL connection for sessions | `postgresql://user:pass@db:5432/shopify_sessions` |
| `BACKEND_API_URL` | Private internal URL to Laravel | `http://backend:8000` |
| `S2S_SHARED_SECRET` | 64-char hex shared secret | `64-character hex string` (matching backend) |
| `SHOPIFY_API_KEY` | Shopify Partner App Client ID | Sourced from Partner Dashboard |
| `SHOPIFY_API_SECRET` | Shopify Partner App Secret | Sourced from Partner Dashboard |
| `SCOPES` | Approved OAuth access scopes | `read_products,read_orders` |
| `SHOPIFY_APP_URL` | Public production URL | `https://app.product-analytics.com` |

### 3.2 Analytics Engine (`backend` & `laravel-worker`)

| Variable | Description | Example / Format |
|---|---|---|
| `APP_ENV` | Laravel environment | `production` |
| `APP_DEBUG` | Debug mode (MUST be false) | `false` |
| `APP_KEY` | Laravel encryption key | `base64:...` |
| `DB_CONNECTION` | Database driver | `pgsql` |
| `DB_HOST` | Managed database host | `postgres` or RDS endpoint |
| `DB_PORT` | PostgreSQL port | `5432` |
| `DB_DATABASE` | Primary database name | `shopify_analytics` |
| `DB_USERNAME` | Database username | `postgres` or application user |
| `DB_PASSWORD` | Database password | Secure password |
| `REDIS_HOST` | Redis host | `redis` or ElastiCache endpoint |
| `REDIS_PORT` | Redis port | `6379` |
| `REDIS_PREFIX` | Redis key namespace | `spa-database-` |
| `CACHE_PREFIX` | Cache key namespace | `spa-cache-` |
| `QUEUE_CONNECTION` | Queue driver | `redis` |
| `S2S_SHARED_SECRET` | 64-char hex shared secret | Must match Gateway exactly |

---

## 4. Deployment Procedures

### 4.1 Deployment with Docker Compose (Single Host / EC2)

1. **Clone and Checkout Release Tag:**
   ```bash
   git clone https://github.com/DevMahbubHussain/shopify-product-analytics.git
   cd shopify-product-analytics
   git checkout <release-tag>
   ```

2. **Configure Secrets:**
   Create `.env` in the repository root and `backend/.env` with production credentials.

3. **Build Production Images:**
   ```bash
   docker-compose -f docker-compose.prod.yml build
   ```

4. **Execute Database Migrations:**
   ```bash
   docker-compose -f docker-compose.prod.yml run --rm backend php artisan migrate --force
   ```

5. **Start Production Stack:**
   ```bash
   docker-compose -f docker-compose.prod.yml up -d
   ```

6. **Verify Running Health Probes:**
   ```bash
   curl -i http://localhost/healthz
   curl -i http://localhost:8000/healthz
   ```

### 4.2 Deployment with AWS ECS Fargate

When deploying across multiple availability zones:
1. **Task Definition 1 (Gateway):** Runs `shopify-app:latest` behind an Application Load Balancer target group probing `/healthz`.
2. **Task Definition 2 (Backend API):** Runs `backend:latest` behind a private internal load balancer probing `/healthz`.
3. **Task Definition 3 (Queue Workers):** Runs `laravel-worker:latest` with autoscaling configured against AWS CloudWatch Redis Queue metric (`ApproximateNumberOfMessages`).

---

## 5. Security & Network Hardening

1. **Zero Protected Customer Data (PCD):**
   - The application does not collect, transmit, or store buyer names, emails, phones, or addresses.
   - Enforced by automated schema regression tests (`ProtectedCustomerDataAuditTest.php`).
2. **Service-to-Service Replay Protection:**
   - Every internal request includes `X-App-Timestamp`, `X-App-Nonce`, and `X-App-Signature`.
   - Redis claims nonces atomically using `SETNX` with a 600-second TTL.
   - Timestamps older or newer than 300 seconds are rejected with `401 Unauthorized`.
3. **CSP Frame-Ancestors:**
   - Embedded Shopify apps require:
     ```nginx
     add_header Content-Security-Policy "frame-ancestors https://*.myshopify.com https://admin.shopify.com;" always;
     ```
   - Prevents unauthorized framing outside Shopify Admin while allowing App Bridge to render cleanly.

---

## 6. Monitoring, Healthchecks & Operational Runbook

### 6.1 Health Probes
- Gateway Health: `GET /healthz` -> `{"status":"ok","app":"product-analytics-gateway"}`
- Backend Health: `GET /healthz` -> `{"status":"healthy","database":"connected","redis":"connected"}`

### 6.2 Zero-Downtime Rolling Update Sequence
1. Build and tag new container images (`:v1.1.0`).
2. Run database migrations: `php artisan migrate --force`. (All migrations must be backwards-compatible).
3. Roll out updated worker containers: `docker-compose -f docker-compose.prod.yml up -d --no-deps laravel-worker`.
4. Roll out updated backend containers: `docker-compose -f docker-compose.prod.yml up -d --no-deps backend`.
5. Roll out updated gateway containers: `docker-compose -f docker-compose.prod.yml up -d --no-deps shopify-app`.
6. Reload Nginx configuration: `docker-compose -f docker-compose.prod.yml exec nginx nginx -s reload`.

### 6.3 Queue Worker Management & Incident Recovery
- If queue lag accumulates during large order sync bursts:
  - Scale worker containers horizontally: `docker-compose -f docker-compose.prod.yml up -d --scale laravel-worker=3`.
  - Inspect failed jobs in PostgreSQL: `SELECT * FROM failed_jobs ORDER BY failed_at DESC LIMIT 10;`.
  - Retry failed jobs: `docker-compose -f docker-compose.prod.yml exec backend php artisan queue:retry all`.

### 6.4 Mandatory Compliance Webhook Verification
- When Shopify dispatches `shop/redact`:
  - Verify purge in logs: `docker-compose -f docker-compose.prod.yml logs -f shopify-app backend | grep shop-redact`.
  - Verify tenant destruction in database:
    ```sql
    SELECT count(*) FROM shops WHERE shop_domain = 'purged-store.myshopify.com';
    ```
    Must return `0`.
