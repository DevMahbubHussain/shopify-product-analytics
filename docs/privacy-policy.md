# Privacy Policy & Merchant Data Agreement

**Last Updated:** November 20, 2026  
**Application Name:** Product Analytics  
**Platform:** Shopify App Store (Embedded Shopify Application)

---

## 1. Overview and Purpose

Product Analytics ("the App", "we", "our") is an embedded reporting and analytics tool built exclusively for Shopify merchants. The App aggregates product sales, variant volumes, return rates, and order counts to provide actionable commerce performance insights inside Shopify Admin.

We believe that high-performance analytics does not require compromising buyer privacy. The App is architected under a **Strict Zero Protected Customer Data (Zero-PCD)** framework.

---

## 2. Information We Collect

To provide product-level and variant-level reporting, the App retrieves and synchronizes strictly necessary commercial data from the Shopify GraphQL Admin API and Shopify Webhooks:

### 2.1 Store Profile & Authentication Data
- Shopify store domain (`{shop}.myshopify.com`)
- Shopify store timezone and currency code (ISO 4217)
- App installation timestamps and active subscription state
- Encrypted OAuth session tokens (stored exclusively on the server side in the Shopify session store; never accessible to browser clients or third parties)

### 2.2 Product & Catalogue Data
- Shopify Product and Variant GIDs (`gid://shopify/Product/...`, `gid://shopify/ProductVariant/...`)
- Product titles, handles, vendor names, and product types
- Variant titles, SKUs, and published unit prices
- Primary product image URLs (for visual identification inside report tables)
- Status flags (`ACTIVE`, `DRAFT`, `ARCHIVED`)

### 2.3 Order Financial Aggregate Data
- Shopify Order GID (`gid://shopify/Order/...`) and order name/number (`#1001`)
- Financial status (`PAID`, `PARTIALLY_REFUNDED`, `REFUNDED`) and fulfillment status
- Timestamps: order processed timestamp, cancellation timestamp, refund timestamp
- Commercial financial amounts: line-item quantity, original unit price, allocated discount amounts, and refund values

---

## 3. Information We Explicitly DO NOT Collect (Zero-PCD)

The App **does not request, access, or store any Protected Customer Data (PCD)** as defined by Shopify. We do not store:

- Customer personal names (first name, last name, full name)
- Customer contact details (email addresses, telephone numbers, SMS numbers)
- Customer delivery/billing addresses (street, city, state/province, postal/ZIP code, country)
- Shopify Customer IDs (`gid://shopify/Customer/...`)
- Customer IP addresses, browser user-agents, device fingerprints, or tracking cookies
- Payment card numbers, bank account details, or payment gateway tokens

All sales and return aggregations are computed exclusively at the product and variant level.

---

## 4. How Data is Used

We use synchronized data strictly to:
1. Precompute daily sales metrics (`product_daily_metrics`) partitioned by store timezone.
2. Present overview KPI cards (Gross Sales, Discounts, Refunds, Net Sales, Units Sold, Orders, Average Order Revenue).
3. Generate product performance tables with sortable, filterable metrics.
4. Render product and variant sales trend visualizations over time.
5. Generate merchant-requested CSV report exports.
6. Enforce plan tier boundaries (Free tier 30-day reporting vs. Pro tier 365-day reporting).

We do not sell, rent, monetize, or train machine learning models on merchant commerce data.

---

## 5. Multi-Tenant Architecture & Data Security

Data security and tenant isolation are foundational to our architecture:

- **Strict Tenant Scoping:** Every database row, query, and background worker job is explicitly scoped by the tenant's unique `shop_id`. Cross-tenant data retrieval is structurally prevented at the database and application levels.
- **Service-to-Service Signing:** Communications between the Shopify App Gateway (React Router) and the Analytics Engine (Laravel) are authenticated via HMAC-SHA256 signatures with nonce deduplication and timestamp tolerance (±300s).
- **Encryption in Transit & at Rest:** All network traffic runs over TLS 1.3 / HTTPS. Database volumes and persistent storage are encrypted using industry-standard AES-256 encryption.
- **Temporary Export Storage:** Generated CSV exports are stored in secured object storage with signed, time-limited URLs that automatically expire.

---

## 6. Mandatory Shopify GDPR / Privacy Webhook Protocols

The App strictly implements the three mandatory Shopify compliance webhooks:

### 6.1 `customers/data_request`
When a customer requests their personal data from a merchant via Shopify:
- Shopify delivers a webhook to `/webhooks/customers/data_request`.
- Because Product Analytics stores zero customer personal identifiers, no buyer data exists in our systems.
- The App immediately acknowledges receipt with `200 OK` confirming no customer PII is retained.

### 6.2 `customers/redact`
When a merchant or customer requests the erasure of customer personal data:
- Shopify delivers a webhook to `/webhooks/customers/redact`.
- Because Product Analytics never captures or persists customer PII, no buyer data requires erasure.
- The App immediately acknowledges receipt with `200 OK`.

### 6.3 `shop/redact` (Store Uninstallation & Data Purge)
When a merchant uninstalls the App and Shopify initiates a shop data redaction request:
- Shopify delivers a signed webhook to `/webhooks/shop/redact`.
- The App initiates an automated cascade purge:
  1. Deletes all session records from the gateway session database.
  2. Dispatches a signed internal request to `/internal/v1/ingestion/shop-redact`.
  3. Permanently deletes all tenant records across `shops`, `products`, `product_variants`, `orders`, `order_line_items`, `refund_line_items`, `product_daily_metrics`, `sync_runs`, `subscriptions`, and `exports`.
  4. Flushes associated Redis cache keys and queue locks.
- Returns `200 OK` upon successful purge.

---

## 7. Data Retention & Merchant Rights

- **Active Installations:** Data is retained for the duration of the merchant's active app installation to provide historical reporting within their plan tier (up to 30 days on Free, up to 365 days on Pro).
- **Uninstallation:** Following app uninstallation, session tokens are immediately invalidated. Following Shopify's `shop/redact` webhook (within 48 hours), all historical data associated with the store is permanently purged from all production databases and caches.
- **Merchant Access & Deletion Requests:** Merchants may request manual data verification or premature data destruction by contacting our support team.

---

## 8. Updates to This Policy

We may update this Privacy Policy from time to time to reflect operational, legal, or regulatory changes. Any updates will be posted to this document and reflected in the Shopify App Store listing.

---

## 9. Contact Us

For privacy inquiries, data protection questions, or compliance assistance, please contact:

- **Application Support:** `support@product-analytics.app`
- **Developer Organization:** DevMahbubHussain
