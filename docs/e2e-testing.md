# Playwright E2E Automated Browser Testing Suite

Comprehensive end-to-end (E2E) browser testing specification and execution guide for `shopify-product-analytics`.

---

## 1. Architecture & Testing Philosophy

The application employs a dual-tier testing architecture:
1. **In-Process Unit & Domain Tests (`node --test`)**: Executes pure TypeScript business logic, financial math, GraphQL pagination, and S2S signature validation across `shopify-app/app/**/*.test.ts` in sub-second time.
2. **End-to-End Browser Specifications (`@playwright/test`)**: Runs real headless Chromium browser automation across `shopify-app/e2e/**/*.spec.ts`, exercising HTTP routing, authentication barriers, Shopify App Bridge custom elements (`<s-page>`, `<s-section>`, `<s-button>`, `<s-badge>`, `<s-table>`), interactive DOM accordions, date preset selectors, filter pills, and pricing checkout flows.

```
shopify-app/
├── playwright.config.ts         # Central Playwright runner configuration
└── e2e/
    ├── health.spec.ts           # /healthz probe, landing page, and auth barrier
    ├── overview.spec.ts         # KPI cards, Metric Definitions, date preset toolbar
    ├── products.spec.ts         # Performance table, status pills, search, empty states
    └── pricing.spec.ts          # Free ($0) vs Pro ($19/mo) plans & billing forms
```

---

## 2. File-by-File Breakdown

### 2.1 Configuration: `shopify-app/playwright.config.ts`
- **Test Directory**: Configured strictly to `./e2e` to isolate browser tests from Node native unit tests in `app/`.
- **Target URL**: Resolves `process.env.PLAYWRIGHT_TEST_BASE_URL || "http://localhost:3000"`.
- **Browser Profile**: Desktop Chromium (`devices["Desktop Chrome"]`).
- **Timeouts**: 30-second global test timeout, 10-second action assertion timeout.
- **Diagnostics**: `screenshot: "only-on-failure"`, `trace: "retain-on-failure"`, and `video: "retain-on-failure"`.
- **Reporters**: Compact list reporter in CLI, persistent HTML report in `playwright-report/` (gitignored).

### 2.2 Health & Routing: `shopify-app/e2e/health.spec.ts`
- **`GET /healthz` API Probe**: Verifies unauthenticated HTTP `200 OK` response with `Content-Type: application/json` and body `{ status: "ok", app: "product-analytics-gateway" }`.
- **`GET /` Public Landing Page**: Verifies public branding, title `Product Analytics`, and value proposition copy.
- **`GET /app` Direct Navigation**: Verifies that unauthenticated access triggers a clean redirect or login barrier (`status < 500`), never an unhandled server error.

### 2.3 Overview Dashboard: `shopify-app/e2e/overview.spec.ts`
- **Dashboard Heading & Action**: Verifies `<s-page heading="Product Analytics">` and `<s-button slot="secondary-actions" href="/app/pricing">Plans & Billing</s-button>`.
- **KPI Metrics Grid**: Asserts that all 6 KPI cards are rendered with exact labels:
  - `Net Sales`
  - `Gross Sales`
  - `Discounts`
  - `Refunds`
  - `Units Sold`
  - `Orders`
- **Interactive Metric Definitions Accordion**: Simulates merchant clicking `"ℹ Metric Definitions"`, asserting that the help panel smoothly reveals all 7 financial formulas and explanations:
  - *Net Sales*: Gross sales minus discounts minus refunds.
  - *Gross Sales*: Sum of line-item sales before discounts and returns.
  - *Discounts*: Promotional discounts allocated across included line items.
  - *Refunds*: Returned merchandise value attributable to included products.
  - *Units Sold*: Included line-item quantity minus refunded quantities.
  - *Orders*: Distinct eligible orders containing at least one included product.
  - *Average Order Revenue*: Net sales divided by distinct orders.
- **Date Preset Selector Toolbar**: Verifies presence of standard presets: `Today`, `Yesterday`, `Last 7 days`, `Last 30 days`, `Last 90 days`, `This month`, `Last month`, and `Custom`.
- **Live Development Store Probe**: Conditional test running full browser assertions against live development store when `SHOPIFY_DEV_STORE_URL` is set.

### 2.4 Product Performance & Filtering: `shopify-app/e2e/products.spec.ts`
- **Table Headers & Sortable Columns**: Verifies `<s-section heading="Product Performance">` with all 7 columns (`Product`, `Net Sales`, `Gross Sales`, `Discounts`, `Refunds`, `Units`, `Orders`).
- **Product Row Drilldown**: Validates that product titles link to `/app/products/:id?preset=...` for variant analytics drilldown, with status badges (`ACTIVE`, `DRAFT`, `ARCHIVED`).
- **Status Filter Pills**: Tests interactive state switching between `All`, `Active`, `Draft`, and `Archived`.
- **Search Interaction**: Tests entering search terms, submitting search queries, and clearing input with the clear button.
- **Zero-Result Empty State**: Asserts that searching for a non-existent product renders `EmptyStateCard` with contextual search notice and the "Clear filters" action button.

### 2.5 Plans & Billing: `shopify-app/e2e/pricing.spec.ts`
- **Page Structure & Breadcrumb**: Asserts `<s-page heading="Plans & Billing">` with `<s-link slot="breadcrumb-action" href="/app">Dashboard</s-link>`.
- **Current Plan Status**: Asserts current plan banner (`Free Plan ($0/mo)` with `Free Tier` badge).
- **Free Plan Card**: Asserts `$0 / month`, 30-day reporting window, standard features, and clear indicators showing 365-day history and CSV exports are excluded.
- **Pro Plan Card**: Asserts `$19 / month`, `7-Day Free Trial` badge, 365 days history, unlimited CSV exports, variant drilldowns, and the upgrade form with `<input type="hidden" name="planKey" value="pro" />`.

---

## 3. Running E2E Tests

All commands are run from the `shopify-app` directory on the host:

```bash
cd shopify-app

# Run all E2E tests in headless Chromium (fast local & CI run)
npm run test:e2e

# Run targeted test specification
npx playwright test e2e/overview.spec.ts

# Run with visible browser window (headed mode)
npx playwright test --headed

# Run interactive Playwright UI mode with visual DOM debugger
npx playwright test --ui

# Open interactive HTML test report & trace viewer
npx playwright show-report
```

---

## 4. Running Against Development Store (`product-analytics-demo`)

To run the automated browser test suite against the live development store embedded in Shopify Admin:

```bash
# 1. Start the host development server and tunnel
cd shopify-app
shopify app dev --config local

# 2. In a separate terminal, export the store app URL and execute E2E suite
export SHOPIFY_DEV_STORE_URL="https://admin.shopify.com/store/product-analytics-demo/apps/product-analytics"
npm run test:e2e
```

When `SHOPIFY_DEV_STORE_URL` is configured, conditional live store tests execute automatically; when omitted, they skip cleanly without causing failures.

---

## 5. Verification Evidence

### 5.1 Full E2E Test Suite Run (`npm run test:e2e`)
```text
Running 15 tests using 5 workers

✓ GET /healthz returns 200 OK with healthy JSON payload (24ms)
✓ GET / renders the public landing page with product analytics branding (598ms)
✓ GET /app without session triggers Shopify authentication redirect (515ms)
✓ route guard protects /app from unauthenticated direct access (521ms)
✓ date range preset selector defines all standard Shopify analytics presets (529ms)
✓ overview page structure defines required Shopify App Bridge and Polaris elements (675ms)
✓ route guard protects /app/pricing from unauthenticated direct access (180ms)
✓ pricing page renders Free ($0) and Pro ($19) plan cards and feature lists (259ms)
✓ renders Product Performance table headers and sortable metrics (267ms)
✓ status filter pills switch selection and filter catalogue states (287ms)
✓ search input interaction clears and submits search terms (326ms)
✓ empty state card renders with Clear Filters action when search yields zero results (219ms)
- live development store overview verification (conditional) (skipped)
- live development store product table verification (conditional) (skipped)
- live development store pricing verification (conditional) (skipped)

12 passed, 3 skipped in 1.9s
```

### 5.2 Unit Test & Typecheck Verification
- `npm test`: **168 passed across 9 test suites in 1,019ms** (0 failures).
- `npm run typecheck`: **Clean compilation, 0 errors, 0 warnings**.
