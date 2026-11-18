# Starter Guide — New Shopify App from This Template

Use tag `v0.1.0-starter` to bootstrap an embedded Shopify app that needs a Laravel backend, PostgreSQL, Redis queues, and a signed service-to-service boundary.

---

## What You Get

| Layer | Ready | Notes |
|---|---|---|
| Docker Compose stack | Yes | `postgres` 16 (host port 5433), `redis` 7, `backend` (Laravel 13, PHP 8.3), `laravel-worker`; optional `shopify-app` container under profile `app` |
| Shopify app | Yes | Official React Router template (`@shopify/shopify-app-react-router` 4.x), Node 22, Admin API `2026-10`, Polaris web components, Shopify-managed install |
| Sessions | Yes | Prisma on PostgreSQL database `shopify_sessions`; access tokens stay in the Shopify app |
| S2S trust boundary | Yes | HMAC-SHA256, ±300s timestamp window, single-use Redis nonce, correlation IDs; signer in `app/services/backendClient.server.ts` |
| Install → backend | Yes | `afterAuth` hook queries the shop and registers it in Laravel (`/internal/v1/ingestion/shop`) |
| Secrets handling | Yes | No secrets in git or images; compose fails fast when a value is missing |
| Test harness | Yes | Pest on a dedicated PostgreSQL test DB with `RefreshDatabase` and isolated Redis DBs; `node --test` for the Shopify app |
| Domain schema + ingestion | Product-specific | Product analytics tables and `/internal/v1/ingestion/*` endpoints; keep, adapt, or remove |
| Data sync, webhooks, UI, billing | No | Build on top (see §8) |

---

## 1. Prerequisites

```bash
brew install colima docker docker-compose
colima start --cpu 4 --memory 8 --disk 60 --vm-type=vz --mount-type=virtiofs
npm install -g @shopify/cli@latest     # Node >= 22.12 required
```

If `docker` cannot find the daemon, point it at Colima:

```bash
export DOCKER_HOST=unix://$HOME/.colima/default/docker.sock
```

If `docker compose build` fails with `docker-credential-desktop: executable file not found`, remove the `"credsStore": "desktop"` line from `~/.docker/config.json` (left over from Docker Desktop).

---

## 2. Clone With Fresh History

```bash
git clone --branch v0.1.0-starter --depth 1 https://github.com/DevMahbubHussain/shopify-product-analytics.git my-shopify-app
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
| Sessions database | `infrastructure/docker/postgres/initdb/02-create-sessions-database.sql`, `shopify-app/.env.example`, `docker-compose.yml` | `shopify_sessions` |
| Redis / cache prefixes | `backend/.env.example` | `spa-database-`, `spa-cache-` |
| Test Redis / cache prefixes | `backend/phpunit.xml`, `TestEnvironmentIsolationTest.php` | `spa-test-database-`, `spa-test-cache-` |
| Laravel app name | `backend/.env.example` | `Shopify Product Analytics` |
| Node package name | `shopify-app/package.json`, `shopify-app/package-lock.json` | `product-analytics` |
| Home page heading | `shopify-app/app/routes/app._index.tsx` | `Product Analytics` |
| Shopify app name | `shopify-app/shopify.app.toml` | `Product Analytics` |

Replace or delete product-specific docs: `AGENTS.md`, `CLAUDE.md`, `docs/`, and the README title/topology text.

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

Two app config files:

| File | Committed | Holds |
|---|---|---|
| `shopify-app/shopify.app.toml` | Yes | Name, scopes, API version, webhooks; `client_id` stays `REPLACE_WITH_DEV_DASHBOARD_CLIENT_ID` |
| `shopify-app/shopify.app.local.toml` | No (gitignored) | Same content with your real `client_id` |

```bash
cp shopify-app/shopify.app.toml shopify-app/shopify.app.local.toml
# edit client_id in the local file only; edit name/scopes in both
```

Always run the CLI with `--config local` (or once: `shopify app config use local`). Plain `shopify app dev` reads the placeholder and fails with `no app was found for the provided apiKey`; if it offers to link an app, it writes your real ID into the committed toml — restore the placeholder before committing.

Verify the current stable API version at https://shopify.dev/docs/api/usage/versioning before pinning. Change it in both tomls and in `shopify-app/app/shopify.server.ts` (`ApiVersion.*`).

---

## 5. Environment Files

Three gitignored files. Never commit any of them.

| File | Read by | Holds |
|---|---|---|
| `.env` (root) | `docker compose` interpolation | `DB_*` for `postgres`; `S2S_SHARED_SECRET`, `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SCOPES`, `SHOPIFY_APP_URL` for the `app` profile container |
| `backend/.env` | Every Laravel process (HTTP, worker, CLI, tests) | `APP_KEY`, DB, Redis, prefixes, `S2S_SHARED_SECRET` |
| `shopify-app/.env` | Host dev: Prisma CLI and `shopify app dev` | `DATABASE_URL`, `BACKEND_API_URL`, `S2S_SHARED_SECRET` |

```bash
cp .env.example .env
cp backend/.env.example backend/.env

echo "APP_KEY=base64:$(openssl rand -base64 32)"   # put in backend/.env
echo "S2S_SHARED_SECRET=$(openssl rand -hex 32)"   # same value in root .env and backend/.env
```

Root `.env` Shopify values:

```dotenv
SHOPIFY_API_KEY=<client id>
SHOPIFY_API_SECRET=<client secret, starts with shpss_>
SCOPES=read_products,read_orders
SHOPIFY_APP_URL=http://localhost:3000
```

Create `shopify-app/.env` from the root values without printing secrets:

```bash
cd shopify-app && set -a && . ../.env && set +a && printf 'DATABASE_URL="postgresql://%s:%s@localhost:5433/shopify_sessions"\nBACKEND_API_URL=http://localhost:8000\nS2S_SHARED_SECRET=%s\n' "$DB_USERNAME" "$DB_PASSWORD" "$S2S_SHARED_SECRET" > .env && cd ..
```

Rules:
- `S2S_SHARED_SECRET`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` must match across the root and backend files; the S2S secret and DB password in `shopify-app/.env` must match them too.
- `SHOPIFY_API_SECRET` lives in the root `.env` only (Shopify CLI provides it during dev). Laravel never receives Shopify credentials.
- Compose sets no Laravel env vars on purpose: `php artisan serve` strips container env from its HTTP workers, so `backend/.env` is the single Laravel source of truth.
- Postgres is published on host port **5433** so a local Postgres on 5432 cannot intercept connections.

---

## 6. Boot the Backend

```bash
docker compose build
docker compose run --rm --no-deps backend composer install
docker compose up -d
docker compose ps                                        # backend, laravel-worker, postgres, redis Up

docker compose exec backend php artisan migrate
docker compose exec backend ./vendor/bin/pest            # all green, runs on <db>_test
curl -s http://localhost:8000/api/v1/health
# {"status":"healthy","database":"connected","redis":"connected", ...}
```

Fresh volumes create the test and sessions databases automatically. On an older volume, run the init scripts once:

```bash
for f in infrastructure/docker/postgres/initdb/*.sql; do docker compose exec -T postgres psql -U postgres -d postgres < "$f"; done
```

If `/docker-entrypoint-initdb.d` is empty inside the container, recreate it: `docker compose up -d --force-recreate postgres` (data volume is kept).

---

## 7. Run the Shopify App and Install

```bash
cd shopify-app
npm install
npm run setup                     # prisma generate + migrate deploy → shopify_sessions
npm test && npm run typecheck && npm run lint
shopify app dev --config local    # pick a dev store; press p to open the app
```

Open the app in the dev store. Then confirm the install reached Laravel:

```bash
docker compose exec postgres psql -U postgres -d shopify_analytics -c "select shop_domain, status, currency_code, timezone from shops"
# one row, status active, the store's currency and IANA timezone
```

Optional production-style container (built image, no secrets inside):

```bash
docker compose --profile app up -d --build shopify-app   # serves on :3000, runs prisma migrate deploy first
```

---

## 8. Build Your App

Recommended order:

1. Adapt or replace the domain migrations in `backend/database/migrations`; every tenant table keyed by `shop_id`.
2. Add or adapt ingestion endpoints under `/internal/v1/*` behind the `s2s` middleware; send data with `backendClient().postInternal(...)`.
3. Harvest Shopify data with `admin.graphql` (cursor pagination, cost throttling) in `shopify-app`, then forward normalized batches.
4. Register webhooks in both tomls; `authenticate.webhook` verifies `X-Shopify-Hmac-Sha256`; forward signed to Laravel.
5. Add read endpoints under `/api/v1/*`, called server-side from React Router loaders only.

Keep these invariants:
- Shopify access tokens never leave `shopify-app`; Laravel never calls Shopify.
- Every Laravel query and job is scoped by `shop_id` taken from the verified S2S header, never from the request body.
- Money is `DECIMAL(20,6)` with an ISO currency code.
- Files that touch secrets are named `*.server.ts`, so React Router never bundles them for the browser.
