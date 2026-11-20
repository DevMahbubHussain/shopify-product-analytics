# Shopify App Store Submission & Compliance Guide

**Application Name:** Product Analytics  
**Primary Platform:** Shopify Admin (Embedded Application)  
**API Version:** `2026-10` (Stable)  
**Target Category:** Analytics & Reporting / Product Analytics  
**Compliance Status:** Fully Verified

---

## 1. Executive Summary & Review Readiness

Product Analytics is an embedded Shopify application providing merchant-facing product performance insights, sales trends, variant breakdowns, and financial reporting.

This guide documents the compliance architecture, justification for requested API scopes, mandatory GDPR/privacy webhook handling, and step-by-step testing instructions for Shopify App Review teams.

---

## 2. API Access Scopes Justification

Shopify App Review requires explicit justification for every requested access scope. Product Analytics adheres to the principle of least privilege:

```toml
[access_scopes]
scopes = "read_products,read_orders"
optional_scopes = [ ]
use_legacy_install_flow = false
```

### 2.1 Scope: `read_products`
- **Why It Is Required:** The core functionality of the App is product-level commercial reporting.
- **How It Is Used:**
  - Synchronizes product metadata: product title, handle, product type, vendor name, status (`ACTIVE`, `DRAFT`, `ARCHIVED`), and primary media image URL.
  - Synchronizes variant metadata: variant title, SKU, published price, and inventory parent relationships.
  - Powers product performance tables, product search, vendor/type filters, and product detail analytics pages.
- **Why Lesser Access Is Insufficient:** Without `read_products`, the application cannot map order line items to product titles, vendors, or variants.
- **Data Mutation:** Read-only. The App never requests `write_products` and never modifies merchant catalog items.

### 2.2 Scope: `read_orders`
- **Why It Is Required:** Calculating accurate sales performance metrics (gross sales, discounts, refunds, units sold, and order counts).
- **How It Is Used:**
  - Synchronizes order header metadata: order GID, order name (`#1001`), financial status, fulfillment status, currency code, and processing timestamp.
  - Synchronizes line items: product GID, variant GID, quantity, unit price, and allocated line-item discounts.
  - Synchronizes refunds: refund lines, returned quantities, and refund monetary amounts.
  - Precomputes daily performance aggregates (`product_daily_metrics`) stored in the multi-tenant database.
- **Why Lesser Access Is Insufficient:** Order line items and refunds are required to compute financial metrics according to standard commerce accounting formulas.
- **Data Window:** Initial historical sync defaults to 60 days. Real-time updates occur via webhooks.
- **Data Mutation:** Read-only. The App never requests `write_orders` and never alters or creates store orders.

### 2.3 Scopes Explicitly Avoided
- `read_customers` / `write_customers`: **Not requested.** The App has zero need for customer profiles.
- `read_all_orders`: **Not requested.** The default 60-day window satisfies MVP requirements without requesting extended historical order access.
- `write_*`: **No write scopes requested.**

---

## 3. Protected Customer Data (PCD) Architecture

Shopify requires apps that do not require Protected Customer Data (PCD) to strictly avoid collecting, transmitting, or persisting buyer PII.

### 3.1 Structural PCD Non-Collection
1. **GraphQL Harvester:** The GraphQL order queries explicitly omit customer objects:
   ```graphql
   # Request line items and refunds; strictly NO customer { id, email, name, phone, address }
   lineItems(first: 50) {
     nodes {
       id
       title
       quantity
       originalUnitPriceSet { shopMoney { amount currencyCode } }
       discountAllocations { allocatedAmountSet { shopMoney { amount } } }
       product { id }
       variant { id }
     }
   }
   ```
2. **Ingestion Pipeline:** DTOs (`OrderIngestionDto`, `OrderLineItemDto`) define typed contracts that do not include customer fields.
3. **Database Schema:** Formally verified by automated regression test `backend/tests/Feature/Security/ProtectedCustomerDataAuditTest.php`:
   - All 11 PostgreSQL tables contain zero customer PII columns.
   - Database stores solely commercial numeric values, currency codes, and Shopify GIDs.

---

## 4. Mandatory Shopify Webhooks

All webhooks are registered in `shopify.app.toml` and verified via `X-Shopify-Hmac-Sha256` signatures before processing:

| Topic | URI | Purpose | SLA / Action |
|---|---|---|---|
| `app/uninstalled` | `/webhooks/app/uninstalled` | Marks store `UNINSTALLED` in database, cancels active syncs, and revokes sessions. | Responds `200 OK` within 200ms. |
| `app/scopes_update` | `/webhooks/app/scopes_update` | Validates granted access scopes against expected scopes. | Responds `200 OK`. |
| `orders/create` | `/webhooks/orders/create` | Incrementally ingests new orders and triggers background metric aggregations. | Queued asynchronously in Redis. |
| `orders/updated` | `/webhooks/orders/updated` | Updates order state, financial status, and cancellations. | Queued asynchronously in Redis. |
| `refunds/create` | `/webhooks/refunds/create` | Ingests refund line items and deducts refunded units and net sales. | Queued asynchronously in Redis. |
| `app_subscriptions/update` | `/webhooks/app_subscriptions/update` | Synchronizes subscription states (`ACTIVE`, `DECLINED`, `CANCELLED`) to Laravel entitlements. | Instant S2S state update. |
| `customers/data_request` | `/webhooks/customers/data_request` | Mandatory compliance webhook. Acknowledges receipt confirming no customer data is retained. | Responds `200 OK`. |
| `customers/redact` | `/webhooks/customers/redact` | Mandatory compliance webhook. Acknowledges receipt confirming no customer data requires redaction. | Responds `200 OK`. |
| `shop/redact` | `/webhooks/shop/redact` | Mandatory compliance webhook. Executes complete automated cascade purge of all store records. | Responds `200 OK`. |

---

## 5. App Bridge & Polaris Embedded UX Compliance

Product Analytics strictly implements Shopify App Bridge v3 / React Router design standards:

1. **Embedded App Requirement:** App runs embedded inside Shopify Admin iframe with App Bridge communication.
2. **Shopify Title Bar:** Integrated `<ui-title-bar>` and Polaris Web Components (`<s-page>`, `<s-section>`, `<s-table>`, `<s-badge>`).
3. **Responsive Design:** Responsive desktop layout (`inlinesize="large"`), flex-wrapping toolbars, and responsive KPI grids.
4. **Data Freshness & Transparency:**
   - Visual `Last synced: ...` timestamp in the header.
   - Prominent Date Range pill reflecting the store's configured timezone.
   - Explicit `ℹ Metric Definitions` help panel defining calculations according to standard commerce accounting.
5. **Billing Integration:**
   - In-app pricing page (`/app/pricing`) integrated with Shopify App Subscription API (`appSubscriptionCreate`).
   - Graceful plan-tier feature gating (Free tier 30-day reporting vs. Pro tier 365-day reporting).
   - Seamless return flows preserving store context.

---

## 6. Security & Infrastructure Protocols

1. **Service-to-Service (S2S) HMAC-SHA256 Security:**
   - React Router communicates with Laravel through private `/internal/v1/*` endpoints.
   - Every request is signed with a shared secret: `METHOD\nPATH\nTIMESTAMP\nNONCE\nSHOP_DOMAIN\nsha256(BODY)`.
   - Single-use UUID nonce verified and claimed in Redis with replay protection (±300 seconds).
2. **Rate Limiting & Queue Resilience:**
   - Redis-backed rate limiter on ingestion endpoints (`Limit::perMinute(300)`).
   - Persistent queue workers running with exponential backoff (`$tries = 5`, `[5, 15, 60]`).
   - Redis atomic locks (`ShouldBeUnique` and `WithoutOverlapping`) prevent race conditions during metric calculation.
3. **Financial Math Precision:**
   - Monetary amounts stored exclusively as `DECIMAL(20,6)`.
   - Calculations performed using arbitrary-precision arithmetic (`bcmath`), preventing floating-point rounding errors.
   - Multi-currency isolation: Metrics grouped strictly by ISO 4217 currency code.

---

## 7. App Review Testing Instructions

For Shopify App Reviewers testing the application on a development store:

### 7.1 Installation & Onboarding
1. Install the app via the provided test installation URL.
2. Review the requested scopes (`read_products`, `read_orders`). Note that no customer data scopes are requested.
3. Upon authorization, the app automatically redirects to `/app` inside Shopify Admin.
4. Initial historical synchronization initiates in the background, importing store products and recent eligible orders.

### 7.2 Overview & Performance Verification
1. Navigate to **Product Analytics** in Shopify Admin.
2. Verify the **Overview Performance** KPI cards display: Net Sales, Gross Sales, Discounts, Refunds, Units Sold, and Orders.
3. Click **`ℹ Metric Definitions`** to view clear calculation explanations.
4. Change date filters using presets (`Today`, `Last 7 days`, `Last 30 days`, `This month`). Verify that charts and KPIs update dynamically.
5. In the **Product Performance** table:
   - Filter by status (`Active`, `Draft`, `Archived`).
   - Search for a specific product title.
   - Click column headers to sort by Net Sales, Gross Sales, Units, or Orders.
   - Click a product title to open the **Product Detail** page.

### 7.3 Billing & Feature Gating Verification
1. Click **Plans & Billing** in the top navigation.
2. Review the Free tier (30-day historical window) and Pro tier ($19/mo, 365-day historical window + CSV exports).
3. Click **Upgrade to Pro**.
4. The app redirects to Shopify's standard recurring application charge approval screen in test mode.
5. Approving the charge redirects back to the app with Pro tier entitlements unlocked.

### 7.4 CSV Export Verification
1. In the Product Performance table toolbar, click **Export CSV**.
2. A background export job processes the filtered dataset and delivers a secure, temporary download link.

### 7.5 Uninstallation & Purge Verification
1. Uninstall the app from Shopify Admin.
2. Shopify dispatches `app/uninstalled` and `shop/redact`.
3. The app invalidates all sessions and permanently deletes tenant records from PostgreSQL and Redis.

---

## 8. App Store Listing Metadata Checklist

- [x] **App Name:** Product Analytics (compliant with Shopify naming policy; does not contain "Shopify").
- [x] **Icon:** 1200x1200px PNG format ready.
- [x] **Tagline:** Product-level sales reporting, variant analytics, and performance insights.
- [x] **Key Benefits:**
  - Understand top-grossing products, highest volume items, and refund drivers.
  - Analyze variant-level sales momentum and customer demand trends.
  - Zero Protected Customer Data storage for complete merchant and buyer privacy.
- [x] **Privacy Policy URL:** `https://product-analytics.app/privacy-policy` (sourced from `docs/privacy-policy.md`).
- [x] **Support Email:** `support@product-analytics.app`.
- [x] **Pricing Disclosure:** Free tier available; Pro tier at $19/month with 7-day free trial.
