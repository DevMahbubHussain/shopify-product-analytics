# Starter Guide — New Shopify App from This Template

Use tag `v0.3.0-starter` to bootstrap any embedded Shopify app that needs a Laravel backend, PostgreSQL, Redis queues, and a signed service-to-service boundary.

---

## What You Get

| Layer | Ready | Notes |
|---|---|---|
| Docker Compose stack | Yes | `postgres` 16, `redis` 7, `backend` (Laravel 13, PHP 8.3), `laravel-worker`, `shopify-app` (Node 20) |
| S2S trust boundary | Yes | HMAC-SHA256, ±300s timestamp window, single-use Redis nonce, correlation IDs |
| Secrets handling | Yes | No secrets in git; compose fails fast when a value is missing |
| Test harness | Yes | Pest on a dedicated PostgreSQL test DB with `RefreshDatabase`, isolated Redis DBs; Node test runner |
| Shopify app config | Yes | `shopify.app.toml` pinned to API `2026-10`, Shopify-managed install |
| Shopify OAuth, sessions, Polaris UI, webhooks | No | `shopify-app` is a minimal Node server; React Router app wiring is the first build step |
| Domain schema | No | Only Laravel default migrations |

---

## 1. Prerequisites

```bash
brew install colima docker docker-compose
colima start --cpu 4 --memory 8 --disk 60 --vm-type=vz --mount-type=virtiofs
```

If `docker` cannot find the daemon, point it at Colima:

```bash
export DOCKER_HOST=unix://$HOME/.colima/default/docker.sock
```

If `docker compose` fails with `docker-credential-desktop: executable file not found`, remove the `"credsStore": "desktop"` line from `~/.docker/config.json` (left over from Docker Desktop).

---

## 2. Clone With Fresh History

```bash
git clone --branch v0.3.0-starter --depth 1 https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
cd my-shopify-app
rm -rf .git && git init -b main
```

---

## 3. Rename Project Identifiers

Pick a short slug (example: `acme`) and replace these values. Keep each pair consistent.

| What | Where | Template value |
|---|---|---|
| Container names | `docker-compose.yml` | `spa_postgres`, `spa_redis`, `spa_backend`, `spa_worker`, `spa_shopify_app` |
| Dev database | `.env.example`, `backend/.env.example`, `docker-compose.yml` default | `shopify_analytics` |
| Test database | `backend/phpunit.xml`, `infrastructure/docker/postgres/initdb/01-create-test-database.sql`, `backend/tests/Feature/Infrastructure/TestEnvironmentIsolationTest.php` | `shopify_analytics_test` |
| Redis / cache prefixes | `backend/.env.example` | `spa-database-`, `spa-cache-` |
| Test Redis / cache prefixes | `backend/phpunit.xml`, `TestEnvironmentIsolationTest.php` | `spa-test-database-`, `spa-test-cache-` |
| Laravel app name | `backend/.env.example` | `Shopify Product Analytics` |
| Node package name | `shopify-app/package.json`, `shopify-app/package-lock.json` | `shopify-product-analytics-app` |
| Gateway label | `shopify-app/app/index.ts` | `Shopify Product Analytics` |
| Shopify app name / handle | `shopify-app/shopify.app.toml` | `Product Analytics` / `product-analytics` |

Replace or delete product-specific docs: `AGENTS.md`, `CLAUDE.md`, `docs/system-design.html`, and the README title/topology text.

---

## 4. Create the App in the Shopify Dev Dashboard

Dev Dashboard → Apps → Create an app:

| Field | Value |
|---|---|
| App name | Your app name. The word "Shopify" is not allowed. |
| App URL | `https://example.com` for now; replaced by the CLI tunnel during development |
| Embed app in Shopify admin | Checked |
| Allowed redirection URL(s) | Empty for now; the CLI manages them during development |
| Use legacy install flow | Unchecked (Shopify-managed install) |
| Scopes | Minimum your app needs, comma-separated (template: `read_products,read_orders`) |
| Webhooks API version | Latest stable (template: `2026-10`) |

Release the version, then copy the **Client ID** and **Client secret** from Settings.

Update `shopify-app/shopify.app.toml`:

```toml
client_id = "<your 32-char client id>"   # public value
name = "<your app name>"
handle = "<your-app-handle>"

[access_scopes]
scopes = "<same scopes as the dashboard>"

[webhooks]
api_version = "<latest stable>"
```

Verify the current stable API version at https://shopify.dev/docs/api/usage/versioning before pinning.

---

## 5. Environment Files

Two gitignored files. Never commit either.

| File | Read by | Holds |
|---|---|---|
| `.env` (root) | `docker compose` interpolation | `DB_*` for `postgres`; `S2S_SHARED_SECRET`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SCOPES`, `SHOPIFY_APP_URL` for `shopify-app` |
| `backend/.env` | Every Laravel process (HTTP, worker, CLI, tests) | `APP_KEY`, DB, Redis, prefixes, `S2S_SHARED_SECRET` |

```bash
cp .env.example .env
cp backend/.env.example backend/.env

echo "APP_KEY=base64:$(openssl rand -base64 32)"   # put in backend/.env
echo "S2S_SHARED_SECRET=$(openssl rand -hex 32)"   # same value in BOTH files
```

Root `.env`:

```dotenv
SHOPIFY_API_KEY=<client id>
SHOPIFY_API_SECRET=<client secret, starts with shpss_>
SCOPES=read_products,read_orders
SHOPIFY_APP_URL=http://localhost:3000
```

Rules:
- `S2S_SHARED_SECRET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` must match across both files.
- `SHOPIFY_API_SECRET` goes in the root `.env` only. Laravel never receives Shopify credentials.
- Compose sets no Laravel env vars on purpose: `php artisan serve` strips container env from its HTTP workers, so `backend/.env` is the single Laravel source of truth and the HTTP server and queue worker share identical cache prefixes and locks.

---

## 6. Boot and Verify

Images do not bundle dependencies (source is bind-mounted), so install them once before starting:

```bash
docker compose build
docker compose run --rm --no-deps backend composer install
docker compose run --rm --no-deps shopify-app npm install

docker compose up -d
docker compose ps                                        # all services Up, postgres/redis healthy

docker compose exec backend php artisan migrate
docker compose exec backend ./vendor/bin/pest            # all green, runs on <db>_test
docker compose exec shopify-app npm test
docker compose exec shopify-app npm run lint

curl -s "http://localhost:3000/s2s-handshake?shop=demo.myshopify.com"
# {"s2s":"verified", ...}
curl -s http://localhost:8000/api/v1/health
# {"status":"healthy","database":"connected","redis":"connected", ...}
```

On an existing Postgres volume created before the initdb script existed, create the test DB once:

```bash
docker compose exec postgres psql -U postgres -d postgres -f /docker-entrypoint-initdb.d/01-create-test-database.sql
```

---

## 7. Build Your App

Recommended order:

1. Convert `shopify-app` into a React Router app with `@shopify/shopify-app-react-router` (OAuth, session storage, App Bridge, Polaris), reading `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SCOPES`, `SHOPIFY_APP_URL`.
2. Add domain migrations in `backend/database/migrations`, every tenant table keyed by `shop_id`.
3. Add ingestion endpoints under `/internal/v1/*` behind the `s2s` middleware; send data with `backendClient().postInternal(...)`.
4. Add read endpoints under `/api/v1/*`, called server-side from React Router loaders only.
5. Register webhooks in the toml; verify `X-Shopify-Hmac-Sha256` in `shopify-app`, then forward signed to Laravel.

Keep these invariants:
- Shopify access tokens never leave `shopify-app`; Laravel never calls Shopify.
- Every Laravel query and job is scoped by `shop_id` taken from the verified S2S header, never from the request body.
- Money is `DECIMAL(20,6)` with an ISO currency code.
