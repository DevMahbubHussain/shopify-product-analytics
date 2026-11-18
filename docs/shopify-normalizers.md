# Shopify Normalizers (Step 3, Group E)

How Shopify Admin GraphQL responses become the exact payloads Laravel's ingestion endpoints accept. Covers the payload contract, the products and orders queries, the three normalizers, exact money arithmetic, and what live testing against the demo store revealed.

---

## 1. What This Phase Provides

- **One source of truth for Laravel payloads** in TypeScript, copied rule for rule from the Laravel FormRequests.
- **Products, orders and refunds** turned into payloads that Laravel accepts without a 422, verified against the real endpoints.
- **Correct discounts**, including order-level discounts that Shopify spreads across lines.
- **Exact money**: amounts stay decimal strings; sums use integer arithmetic, never floating point.
- **No silent data loss**: truncated lists, foreign currencies and unusable refund lines stop or are counted, never dropped quietly.

Pure functions only: no network, no database. The harvester (group F) and webhooks (group H) call them.

---

## 2. Design Rules

1. **Mirror Laravel, don't guess.** Field names, nullability, enums and batch limits come from `backend/app/Http/Requests/Internal/*Request.php` and `backend/app/Enums/*.php`.
2. **Store currency only** (`shopMoney`, PRD MVP). Every amount is checked against the order's `currencyCode`; a mismatch throws (C5).
3. **Money is a string end to end.** Shopify's `Money` and `Decimal` arrive as strings (`"24.95"`, `"5.0"`); they pass through unchanged or are added with `sumDecimals`.
4. **Original quantities; refunds reduce units** through refund lines (PRD formula). `currentQuantity` is not used.
5. **Test orders are excluded** (`test: true` → skipped).
6. **Refuse partial records.** A product, order or refund whose nested list was cut off is an error, because Laravel treats each payload as complete.
7. **No PII.** No customer fields are queried (protected customer data Level 1 only).

---

## 3. Data Flow

```
Shopify Admin API 2026-10
  PRODUCTS_QUERY ─► ShopifyProductNode ─► normalizeProduct ─► ProductPayload ─► POST ingestion/products
  ORDERS_QUERY   ─► ShopifyOrderNode   ─► normalizeOrder   ─► OrderPayload   ─► POST ingestion/orders
                                       └► normalizeRefunds ─► RefundLinePayload[] ─► POST ingestion/refunds
```

---

## 4. Files

| Step | File | Role |
|---|---|---|
| E-1 | `app/lib/backend/ingestionContract.server.ts` | Payload types, enums, `INGESTION_LIMITS` (100 products, 50 orders, 250 line items, 250 refund lines) |
| E-2 | `app/features/catalog-sync/productsQuery.server.ts` | `PRODUCTS_QUERY` and node types |
| E-3/4 | `app/features/catalog-sync/normalizeProduct.server.ts` (+ test, 6 cases) | `ProductPayload`, `VariantsTruncatedError` |
| E-5 | `app/features/order-sync/ordersQuery.server.ts` | `ORDERS_QUERY` (orders → line items → refunds → refund lines), page sizes |
| E-5a/b | `app/lib/money/decimal.server.ts` (+ test, 6 cases) | `sumDecimals()` with BigInt micro-units |
| E-6/7 | `app/features/order-sync/normalizeOrder.server.ts` (+ test, 8 cases) | `OrderPayload` or `{ skipped: "test_order" }`, `LineItemsTruncatedError`, `CurrencyMismatchError`, `shopAmount()` |
| E-8/9 | `app/features/order-sync/normalizeRefunds.server.ts` (+ test, 7 cases) | `RefundLinePayload[]` + `skippedWithoutId`, `RefundsTruncatedError` |

### Field mapping

| Laravel field | Shopify source | Rule |
|---|---|---|
| product `vendor`, `product_type` | `vendor`, `productType` | `""` → `null` |
| product `image_url` | `featuredMedia.preview.image.url` | `null` when any level is missing (`featuredImage` is deprecated) |
| variant `price` | `price` (`Money`) | string as sent |
| order `financial_status` / `fulfillment_status` | `displayFinancialStatus` / `displayFulfillmentStatus` | verbatim enum; financial may be `null` |
| order `shopify_updated_at` | `updatedAt` | freshness guard in Laravel |
| line `product_gid`, `variant_gid` | `product.id`, `variant.id` | `null` for custom items |
| line `original_unit_price` | `originalUnitPriceSet.shopMoney.amount` | currency checked |
| line `discount_amount` | **sum of `discountAllocations[].allocatedAmountSet.shopMoney.amount`** | exact sum; `"0"` when none |
| refund `refund_line_item_gid` | `RefundLineItem.id` | lines with `null` id skipped and counted |
| refund `processed_at` | `Refund.createdAt` | falls back to `updatedAt` when null |
| refund `subtotal` | `subtotalSet.shopMoney.amount` | currency checked |

### Query sizing

Nested connections multiply Shopify's requested cost (single query max 1,000).

| Query | Page | Nested | Requested cost (live) |
|---|---|---|---|
| Products | 50 | variants 100 | 107 |
| Orders | 25 | line items 50, refunds 5, refund lines 20 | 266 |

---

## 5. Findings From Live Testing

### Order-level discounts were missing from `totalDiscountSet`

Order #1001 has a $5.00 order discount (`allocationMethod: ACROSS`). Every line reported `totalDiscountSet = 0.0`; the amount appeared only in `discountAllocations` (0.1 + 4.9). Using `totalDiscountSet` would have overstated net sales. The query reads `discountAllocations` instead.

### Orders need protected customer data access

Once the store had orders, every order query failed with *"This app is not approved to access the Order object."* Fixed in the Dev Dashboard: Apps → Product Analytics → Settings → Access requests → Protected customer data → **Level 1** (reasons: App functionality, Analytics), no Level 2 fields, data protection answers saved. Development stores need no review submission.

### Empty strings and nulls

Shopify returns `""` for an unset product type, `null` for most SKUs, `null` for unpublished products' `publishedAt`, and `null` media for products without images. Each is normalized as listed above.

---

## 6. Edge Cases Handled

| Case | Behavior |
|---|---|
| Product with more variants than fetched | `VariantsTruncatedError`; never send a partial variant list |
| Order with more line items than fetched | `LineItemsTruncatedError` |
| Five or more refunds on an order (`refunds` has no `pageInfo`) | `RefundsTruncatedError` |
| Refund with more lines than fetched | `RefundsTruncatedError` |
| Amount in a currency other than the order's | `CurrencyMismatchError` |
| Several discount allocations on one line | Exact sum (`0.1 + 0.2 = 0.3`) |
| Sum larger than `DECIMAL(20,6)` | Error; Laravel would reject it |
| Test order | Skipped; its refunds produce no lines |
| Custom item | `product_gid` and `variant_gid` are `null` |
| Refund line without an id | Skipped and counted |

Truncation errors are deliberate: the harvester (group F) catches them and fetches the remaining items separately.

---

## 7. Verification

### Automated

```bash
cd shopify-app && npm test   # 72 passed (27 for this group)
npm run typecheck && npm run lint
```

### Live (2026-11-18, dev store `product-analytics-demo`)

Each entity was queried, normalized and posted to the local Laravel endpoint twice (the second post proves retries create no duplicates).

| Endpoint | Response | Stored |
|---|---|---|
| `ingestion/products` | `received 17, written 17, skipped_stale 0` | 17 products (1 null type, 1 null image), 26 variants (23 null SKU) |
| `ingestion/orders` | `received 6, written 6, skipped_stale 0` | 6 orders, 10 lines; #1006 custom line without product |
| `ingestion/refunds` | `received 4, written 4, skipped_stale 0` | #1002 9.95, #1003 74.90, #1004 49.95 |

Stored order totals against Shopify Admin:

| Order | Gross | Discount | Refunded | Shopify Admin |
|---|---|---|---|---|
| #1001 | 2679.85 | 5.00 | — | paid 2674.85 |
| #1002 | 29.85 | 0 | 9.95 | partially refunded |
| #1003 | 74.90 | 0 | 74.90 | refunded |
| #1004 (cancelled) | 49.95 | 0 | 49.95 | cancelled, refunded |
| #1005 | 164.65 | 0 | — | paid |
| #1006 | 30.00 | 0 | — | paid |

The planned combined check (E-10) is covered by these per-entity runs, which used the same queries and endpoints in order.

### Direct inspection

```bash
docker compose exec postgres psql -U postgres -d shopify_analytics -c "select o.order_name, sum(l.quantity*l.original_unit_price) gross, sum(l.discount_amount) discounts from orders o join order_line_items l on l.order_id=o.id group by 1 order by 1"
```

---

## 8. Not in This Phase

- Fetching remaining variants, line items or refunds after a truncation error (group F).
- Batching to `INGESTION_LIMITS`, sync status reporting, and incremental `updated_at` filters (group F).
- Webhook payloads (group H) will reuse these normalizers.
