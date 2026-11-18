# Database Migrations & Schema Specification

Specification of the core PostgreSQL schema for `shopify-product-analytics`: every migration, column, constraint, and index, with the rules governing multi-tenancy, idempotency, history preservation, and dashboard performance.

---

## 1. Design Rules

1. **UUID primary keys** on every table (`$table->uuid('id')->primary()`).
2. **Tenant scoping in the database.** Every domain table has `shop_id → shops(id) ON DELETE CASCADE`. Every reference between tenant tables is a composite foreign key `(shop_id, x_id) → parent(shop_id, id)`, backed by `UNIQUE (shop_id, id)` on the parent, so a row can never point at another shop's data.
3. **Idempotent ingestion** through compound unique keys:

   | Table | Unique key |
   |---|---|
   | `products` | `(shop_id, shopify_product_gid)` |
   | `product_variants` | `(shop_id, shopify_variant_gid)` |
   | `orders` | `(shop_id, shopify_order_gid)` |
   | `order_line_items` | `(shop_id, shopify_line_item_gid)` |
   | `refund_line_items` | `(shop_id, shopify_refund_line_item_gid)` |
   | `product_daily_metrics` | `(shop_id, product_id, metric_date, currency_code)` |
   | `webhook_events` | `(shop_id, webhook_id)` |
   | `subscriptions` | `(shop_id, shopify_subscription_gid)` |

4. **History is never deleted implicitly.** `products` and `product_variants` use soft deletes (`deleted_at`). Foreign keys from history tables (`order_line_items`, `product_daily_metrics`) to catalogue rows use `NO ACTION`, so hard-deleting a product or variant with sales history fails. Shop purge (`shop/redact`) still works: `NO ACTION` is checked at end of statement, after the `shops` cascade has removed every child row.
5. **Money:** `DECIMAL(20,6)`; floating-point types are prohibited.
6. **Currency isolation:** every monetary row carries `currency_code CHAR(3)` with `CHECK (currency_code ~ '^[A-Z]{3}$')`. Never sum across currencies.
7. **Value guards:** purchased and refunded quantities `> 0`; unit price, discount, refunded subtotal, and variant price `>= 0`. Aggregates (`net_sales`, `units_sold`) are unconstrained because refunds can make them negative.
8. **No silent defaults for facts.** Status and plan columns sourced from Shopify have no default; a missing value fails the insert instead of becoming `ACTIVE`.
9. **Time:** `TIMESTAMPTZ` everywhere (UTC). `product_daily_metrics.metric_date` is the store-local calendar date.
10. **No protected customer data** (names, emails, phones, addresses) and **no Shopify access tokens** in any table.
11. **Every foreign key is index-backed** with `shop_id` leading; enforced by a schema test.

Shopify-sourced status columns store the GraphQL enum values verbatim (uppercase), verified against Admin API `2026-10`.

---

## 2. Relationship Map

```
[shops] (tenant root; ON DELETE CASCADE to every table below)
  ├── [products] (soft delete) ──(shop_id, product_id) CASCADE──► [product_variants] (soft delete)
  │        ▲                                                            ▲
  │        │ (shop_id, product_id) NO ACTION                            │ (shop_id, variant_id) NO ACTION
  │        ├──────────────── [product_daily_metrics]                    │
  │        │                                                            │
  ├── [orders] ──(shop_id, order_id) CASCADE──► [order_line_items] ─────┘
  │        │                                         ▲
  │        └──(shop_id, order_id) CASCADE──► [refund_line_items] ──(shop_id, line_item_id) CASCADE
  ├── [sync_runs]
  ├── [webhook_events]
  └── [subscriptions]
```

---

## 3. Tables

### Migration 1: `shops` (tenant root)
* **File:** `backend/database/migrations/2026_11_18_000001_create_shops_table.php`
* **Test:** `backend/tests/Feature/Database/ShopsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_domain` | `VARCHAR(255)` | No | — | Canonical `*.myshopify.com` domain |
| `shopify_shop_gid` | `VARCHAR(128)` | Yes | `NULL` | Shopify `Shop` GID |
| `currency_code` | `CHAR(3)` | No | — | Store reporting currency (ISO 4217) |
| `timezone` | `VARCHAR(64)` | No | — | IANA timezone, e.g. `America/New_York` |
| `status` | `VARCHAR(32)` | No | `'active'` | App lifecycle: `active`, `uninstalled`, `suspended` |
| `installed_at` | `TIMESTAMPTZ` | No | — | Installation time |
| `uninstalled_at` | `TIMESTAMPTZ` | Yes | `NULL` | `app/uninstalled` receipt time |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints: `PRIMARY KEY (id)`, `UNIQUE (shop_domain)`, `CHECK shops_currency_code_format`.

---

### Migration 2: `products` (catalogue)
* **File:** `backend/database/migrations/2026_11_18_000002_create_products_table.php`
* **Test:** `backend/tests/Feature/Database/ProductsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `shopify_product_gid` | `VARCHAR(128)` | No | — | Shopify `Product` GID |
| `title` | `VARCHAR(255)` | No | — | Current title |
| `handle` | `VARCHAR(255)` | No | — | URL handle |
| `vendor` | `VARCHAR(128)` | Yes | `NULL` | Vendor / brand |
| `product_type` | `VARCHAR(128)` | Yes | `NULL` | Merchant product type |
| `status` | `VARCHAR(32)` | No | — | `ProductStatus`: `ACTIVE`, `ARCHIVED`, `DRAFT`, `UNLISTED` |
| `image_url` | `TEXT` | Yes | `NULL` | Featured image URL |
| `published_at` | `TIMESTAMPTZ` | Yes | `NULL` | Publication time |
| `shopify_updated_at` | `TIMESTAMPTZ` | Yes | `NULL` | Shopify `updatedAt`, for out-of-order update guards |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |
| `deleted_at` | `TIMESTAMPTZ` | Yes | `NULL` | Soft delete on `products/delete` |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `UNIQUE products_shop_id_unique (shop_id, id)` — composite FK target
* `UNIQUE products_shop_gid_unique (shop_id, shopify_product_gid)`
* `INDEX products_shop_status_idx (shop_id, status)`
* `INDEX products_shop_vendor_idx (shop_id, vendor)`

---

### Migration 3: `product_variants`
* **File:** `backend/database/migrations/2026_11_18_000003_create_product_variants_table.php`
* **Test:** `backend/tests/Feature/Database/ProductVariantsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `product_id` | `UUID` | No | — | Parent product |
| `shopify_variant_gid` | `VARCHAR(128)` | No | — | Shopify `ProductVariant` GID |
| `title` | `VARCHAR(255)` | No | — | Option title, e.g. `Blue / XL` |
| `sku` | `VARCHAR(128)` | Yes | `NULL` | SKU |
| `price` | `DECIMAL(20,6)` | Yes | `NULL` | Current catalogue price |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |
| `deleted_at` | `TIMESTAMPTZ` | Yes | `NULL` | Soft delete |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `FOREIGN KEY variants_product_tenant_fk (shop_id, product_id) → products(shop_id, id) ON DELETE CASCADE`
* `UNIQUE variants_shop_id_unique (shop_id, id)` — composite FK target
* `UNIQUE variants_shop_gid_unique (shop_id, shopify_variant_gid)`
* `INDEX variants_shop_product_idx (shop_id, product_id)`
* `INDEX variants_shop_sku_idx (shop_id, sku)`
* `CHECK variants_price_non_negative (price IS NULL OR price >= 0)`

---

### Migration 4: `orders`
* **File:** `backend/database/migrations/2026_11_18_000004_create_orders_table.php`
* **Test:** `backend/tests/Feature/Database/OrdersMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `shopify_order_gid` | `VARCHAR(128)` | No | — | Shopify `Order` GID |
| `order_name` | `VARCHAR(64)` | Yes | `NULL` | Display name, e.g. `#1042` |
| `financial_status` | `VARCHAR(32)` | Yes | `NULL` | `OrderDisplayFinancialStatus`: `AUTHORIZED`, `EXPIRED`, `PAID`, `PARTIALLY_PAID`, `PARTIALLY_REFUNDED`, `PENDING`, `REFUNDED`, `VOIDED` |
| `fulfillment_status` | `VARCHAR(32)` | Yes | `NULL` | `OrderDisplayFulfillmentStatus`, e.g. `FULFILLED`, `PARTIALLY_FULFILLED`, `UNFULFILLED`, `IN_PROGRESS` |
| `currency_code` | `CHAR(3)` | No | — | Order currency |
| `processed_at` | `TIMESTAMPTZ` | Yes | `NULL` | Processing time; source of `metric_date` |
| `cancelled_at` | `TIMESTAMPTZ` | Yes | `NULL` | Cancellation time |
| `shopify_updated_at` | `TIMESTAMPTZ` | Yes | `NULL` | Shopify `updatedAt` |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `UNIQUE orders_shop_id_unique (shop_id, id)` — composite FK target
* `UNIQUE orders_shop_gid_unique (shop_id, shopify_order_gid)`
* `INDEX orders_shop_processed_idx (shop_id, processed_at)`
* `INDEX orders_shop_financial_status_idx (shop_id, financial_status)`
* `CHECK orders_currency_code_format`

No customer columns exist on this table (Protected Customer Data exclusion).

---

### Migration 5: `order_line_items` (historical snapshot)
* **File:** `backend/database/migrations/2026_11_18_000005_create_order_line_items_table.php`
* **Test:** `backend/tests/Feature/Database/OrderLineItemsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `order_id` | `UUID` | No | — | Parent order |
| `product_id` | `UUID` | Yes | `NULL` | Product, when the line maps to one (custom items do not) |
| `variant_id` | `UUID` | Yes | `NULL` | Variant, when known |
| `shopify_line_item_gid` | `VARCHAR(128)` | No | — | Shopify `LineItem` GID |
| `title_snapshot` | `VARCHAR(255)` | No | — | Title at purchase time |
| `sku_snapshot` | `VARCHAR(128)` | Yes | `NULL` | SKU at purchase time |
| `quantity` | `INTEGER` | No | — | Purchased units |
| `original_unit_price` | `DECIMAL(20,6)` | No | — | Unit price before discounts |
| `discount_amount` | `DECIMAL(20,6)` | No | `0` | Discount allocated to this line |
| `currency_code` | `CHAR(3)` | No | — | Line currency |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `FOREIGN KEY line_items_order_tenant_fk (shop_id, order_id) → orders(shop_id, id) ON DELETE CASCADE`
* `FOREIGN KEY line_items_product_tenant_fk (shop_id, product_id) → products(shop_id, id) ON DELETE NO ACTION`
* `FOREIGN KEY line_items_variant_tenant_fk (shop_id, variant_id) → product_variants(shop_id, id) ON DELETE NO ACTION`
* `UNIQUE line_items_shop_id_unique (shop_id, id)` — composite FK target
* `UNIQUE line_items_shop_gid_unique (shop_id, shopify_line_item_gid)`
* `INDEX line_items_shop_product_idx (shop_id, product_id)`
* `INDEX line_items_shop_variant_idx (shop_id, variant_id)`
* `INDEX line_items_shop_order_idx (shop_id, order_id)`
* `CHECK line_items_quantity_positive (quantity > 0)`
* `CHECK line_items_amounts_non_negative (original_unit_price >= 0 AND discount_amount >= 0)`
* `CHECK line_items_currency_code_format`

Snapshots keep historical reports correct after products are renamed, repriced, or soft-deleted.

---

### Migration 6: `refund_line_items`
* **File:** `backend/database/migrations/2026_11_18_000006_create_refund_line_items_table.php`
* **Test:** `backend/tests/Feature/Database/RefundLineItemsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `order_id` | `UUID` | No | — | Parent order |
| `line_item_id` | `UUID` | Yes | `NULL` | Refunded line item |
| `shopify_refund_gid` | `VARCHAR(128)` | No | — | Shopify `Refund` GID (groups lines of one refund) |
| `shopify_refund_line_item_gid` | `VARCHAR(128)` | No | — | Shopify `RefundLineItem.id`; idempotency key |
| `refunded_quantity` | `INTEGER` | No | — | Refunded units |
| `refunded_subtotal` | `DECIMAL(20,6)` | No | — | Refunded amount excluding tax and shipping |
| `currency_code` | `CHAR(3)` | No | — | Refund currency |
| `processed_at` | `TIMESTAMPTZ` | Yes | `NULL` | Refund processing time |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `FOREIGN KEY refund_lines_order_tenant_fk (shop_id, order_id) → orders(shop_id, id) ON DELETE CASCADE`
* `FOREIGN KEY refund_lines_line_item_tenant_fk (shop_id, line_item_id) → order_line_items(shop_id, id) ON DELETE CASCADE`
* `UNIQUE refund_lines_shop_gid_unique (shop_id, shopify_refund_line_item_gid)`
* `INDEX refund_lines_shop_order_idx (shop_id, order_id)`
* `INDEX refund_lines_shop_line_idx (shop_id, line_item_id)`
* `INDEX refund_lines_shop_processed_idx (shop_id, processed_at)`
* `CHECK refund_lines_quantity_positive (refunded_quantity > 0)`
* `CHECK refund_lines_subtotal_non_negative (refunded_subtotal >= 0)`
* `CHECK refund_lines_currency_code_format`

`RefundLineItem.id` is typed nullable (`ID`) in Admin API `2026-10`. The column is required, so a payload without it fails loudly instead of creating duplicate refunds; the normalizer must supply it.

---

### Migration 7: `product_daily_metrics` (precomputed aggregates)
* **File:** `backend/database/migrations/2026_11_18_000007_create_product_daily_metrics_table.php`
* **Test:** `backend/tests/Feature/Database/ProductDailyMetricsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `product_id` | `UUID` | No | — | Product |
| `metric_date` | `DATE` | No | — | Store-local date |
| `currency_code` | `CHAR(3)` | No | — | Currency of all amounts on the row |
| `gross_sales` | `DECIMAL(20,6)` | No | `0` | Σ(quantity × original unit price) |
| `discounts` | `DECIMAL(20,6)` | No | `0` | Allocated discounts |
| `refunds` | `DECIMAL(20,6)` | No | `0` | Refunds processed on this date |
| `net_sales` | `DECIMAL(20,6)` | No | `0` | Gross − Discounts − Refunds (may be negative) |
| `units_sold` | `INTEGER` | No | `0` | Purchased − refunded units (may be negative) |
| `order_count` | `INTEGER` | No | `0` | Distinct orders containing the product |
| `calculated_at` | `TIMESTAMPTZ` | No | — | Last recalculation |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes:
* `FOREIGN KEY (shop_id) → shops(id) ON DELETE CASCADE`
* `FOREIGN KEY metrics_product_tenant_fk (shop_id, product_id) → products(shop_id, id) ON DELETE NO ACTION`
* `UNIQUE metrics_shop_prod_date_curr_unique (shop_id, product_id, metric_date, currency_code)` — one bucket per product/day/currency; also serves product time-series reads
* `INDEX metrics_shop_date_idx (shop_id, metric_date)` — store overview range reads
* `CHECK metrics_currency_code_format`

Dashboards read only this table, never raw line items.

---

### Migration 8: `sync_runs`
* **File:** `backend/database/migrations/2026_11_18_000008_create_sync_runs_table.php`
* **Test:** `backend/tests/Feature/Database/SyncRunsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `sync_type` | `VARCHAR(32)` | No | — | `initial`, `incremental`, `reconciliation` |
| `status` | `VARCHAR(32)` | No | `'pending'` | `pending`, `running`, `completed`, `failed` (app-defined starting state) |
| `started_at` | `TIMESTAMPTZ` | Yes | `NULL` | Start time |
| `completed_at` | `TIMESTAMPTZ` | Yes | `NULL` | Completion time |
| `cursor_metadata` | `JSONB` | Yes | `NULL` | Resumable pagination checkpoints |
| `records_received` | `INTEGER` | No | `0` | Records delivered by the harvester |
| `records_processed` | `INTEGER` | No | `0` | Records upserted |
| `error_code` | `VARCHAR(64)` | Yes | `NULL` | Error classification |
| `error_summary` | `TEXT` | Yes | `NULL` | Diagnostic message |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Indexes: `sync_runs_shop_status_idx (shop_id, status)`, `sync_runs_shop_created_idx (shop_id, created_at)`. No credentials stored.

---

### Migration 9: `webhook_events` (delivery deduplication)
* **File:** `backend/database/migrations/2026_11_18_000009_create_webhook_events_table.php`
* **Test:** `backend/tests/Feature/Database/WebhookEventsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `webhook_id` | `VARCHAR(128)` | No | — | `X-Shopify-Webhook-Id` header |
| `topic` | `VARCHAR(128)` | No | — | e.g. `orders/create` |
| `payload_hash` | `CHAR(64)` | No | — | SHA-256 of the raw body |
| `status` | `VARCHAR(32)` | No | `'received'` | `received`, `processed`, `failed` (app-defined starting state) |
| `received_at` | `TIMESTAMPTZ` | No | — | Receipt time |
| `processed_at` | `TIMESTAMPTZ` | Yes | `NULL` | Processing completion |
| `error_summary` | `TEXT` | Yes | `NULL` | Failure details |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes: `UNIQUE webhook_events_shop_webhook_unique (shop_id, webhook_id)`, `INDEX (shop_id, status)`, `INDEX (shop_id, received_at)`.

Shopify documents `X-Shopify-Webhook-Id` as the key for deduplicating individual deliveries; one event delivered to multiple subscriptions shares `X-Shopify-Event-Id` but has distinct webhook IDs.

---

### Migration 10: `subscriptions` (entitlement snapshot)
* **File:** `backend/database/migrations/2026_11_18_000010_create_subscriptions_table.php`
* **Test:** `backend/tests/Feature/Database/SubscriptionsMigrationTest.php`

| Column | Type | Nullable | Default | Description |
|---|---|---|---|---|
| `id` | `UUID` | No | — | Primary key |
| `shop_id` | `UUID` | No | — | Tenant |
| `shopify_subscription_gid` | `VARCHAR(128)` | Yes | `NULL` | Shopify `AppSubscription` GID (`NULL` for the free plan) |
| `plan_key` | `VARCHAR(32)` | No | — | App plan: `free`, `starter`, `growth`, `advanced` |
| `status` | `VARCHAR(32)` | No | — | `AppSubscriptionStatus`: `ACTIVE`, `CANCELLED`, `DECLINED`, `EXPIRED`, `FROZEN`, `PENDING` |
| `trial_ends_at` | `TIMESTAMPTZ` | Yes | `NULL` | Trial end; a trial is `ACTIVE` with a future `trial_ends_at` |
| `current_period_end` | `TIMESTAMPTZ` | Yes | `NULL` | Billing period end |
| `created_at`, `updated_at` | `TIMESTAMPTZ` | Yes | — | Row timestamps |

Constraints & indexes: `UNIQUE subscriptions_shop_gid_unique (shop_id, shopify_subscription_gid)` (PostgreSQL treats `NULL`s as distinct), `INDEX subscriptions_shop_status_idx (shop_id, status)`.

Shopify Billing remains the source of truth; this table is a snapshot for server-side feature gating.

---

## 4. Verification

### Automated tests
```bash
docker compose exec backend ./vendor/bin/pest tests/Feature/Database
```

* Per-table tests (`*MigrationTest.php`): columns, unique keys, and foreign keys, each asserting the exact SQLSTATE (`23505` unique, `23503` foreign key).
* `SchemaIntegrityTest.php`: refund and subscription idempotency, cross-tenant references rejected on every relation, soft-delete columns, hard-delete guards, full shop purge across all 9 tenant tables, CHECK constraints (including negative metrics allowed), no silent defaults, no redundant metrics index, every foreign key index-backed, and the dashboard range query using `metrics_shop_date_idx`.

### Reversibility (test database)
```bash
docker compose exec -e DB_DATABASE=shopify_analytics_test backend php artisan migrate:fresh --force
docker compose exec -e DB_DATABASE=shopify_analytics_test backend php artisan migrate:rollback --force
docker compose exec -e DB_DATABASE=shopify_analytics_test backend php artisan migrate --force
```

### Measured performance (2026-11-18)
Test database with 2 shops, 1,000 products, 109,000 metric rows:

| Query | Plan | Execution |
|---|---|---|
| Overview: 30-day aggregate, one shop | Bitmap index scan on `metrics_shop_date_idx` | 2.1 ms |
| Product detail: 90-day series, one product | Index scan on `metrics_shop_prod_date_curr_unique` | 0.07 ms |
| Shop purge: 54,500 metric rows, 500 products | Cascades through all tenant tables | 48 ms |
| Hard-delete product with metrics | Rejected by `metrics_product_tenant_fk` | — |

### Direct inspection
```bash
docker compose exec postgres psql -U postgres -d shopify_analytics -P pager=off -c "\dt"
docker compose exec postgres psql -U postgres -d shopify_analytics -P pager=off -c "\d product_daily_metrics"
```
