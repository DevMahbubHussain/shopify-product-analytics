# Ingestion API (S2S)

Private endpoints that the Shopify integration layer (`shopify-app`) calls to push normalized Shopify data into Laravel. They are not browser-facing.

All endpoints:
- `POST /internal/v1/ingestion/{endpoint}` with a JSON body.
- Signed per the S2S contract (`AGENTS.md` §2.4). The tenant is the verified `X-App-Shop-Domain`; any shop identifier in the body is ignored.
- Rate limited per shop: `S2S_INGESTION_PER_MINUTE` (default 600) through Redis. The signature is verified before throttling, so unsigned traffic cannot consume a shop's quota.
- Money is a **string** decimal with up to 14 integer and 6 fractional digits (Shopify `Decimal` is serialized as a string). JSON numbers are rejected.
- Timestamps are ISO 8601 with `Z` or an offset (Shopify `DateTime`), stored in UTC.
- GIDs must match their type, e.g. `gid://shopify/Product/123`.
- Status values are the Shopify Admin API `2026-10` enum values, verbatim.

## Responses

| Status | Body | Meaning | Client action |
|---|---|---|---|
| 200 / 201 | Endpoint-specific | Accepted | — |
| 401 | `{"error": "..."}` | Signature, timestamp, or nonce rejected | Fix signing; do not retry blindly |
| 403 | `{"error": "Shop is not active."}` | Shop uninstalled or suspended | Stop syncing this shop |
| 404 | `{"error": "Unknown shop."}` | Shop never installed | Call `shop` first |
| 409 | `{"error": "...", ...}` | Ordering conflict (see endpoint) | Retry later |
| 422 | `{"message": "...", "errors": {...}}` | Invalid payload | Permanent; fix the normalizer |
| 429 | Laravel throttle body | Rate limited | Back off and retry |

Batch endpoints return `{"received": n, "written": n, "skipped_stale": n}`. Re-sending a batch is always safe: rows are upserted on their Shopify GID, and retries create no duplicates.

---

## `shop` — install or refresh a shop

Creates the shop on first call, refreshes metadata afterwards, and reactivates an uninstalled shop (`installed_at` reset, `uninstalled_at` cleared). The header domain must match `*.myshopify.com`.

```json
{ "shopify_shop_gid": "gid://shopify/Shop/1", "currency_code": "USD", "timezone": "America/New_York" }
```

Response: `{"shop_domain": "acme.myshopify.com", "status": "active"}`.

## `shop-uninstall` — mark a shop uninstalled

Called when Shopify delivers `app/uninstalled`. `uninstalled_at` is the delivery's `X-Shopify-Triggered-At`, so the stored time is when the merchant uninstalled, not when the request arrived.

```json
{ "uninstalled_at": "2026-11-18T02:30:00Z" }
```

| Shop state | Result |
|---|---|
| Active, `uninstalled_at` after `installed_at` | `status` → `uninstalled`, `uninstalled_at` stored (UTC). Response `{"shop_domain": "...", "status": "uninstalled"}` |
| Already uninstalled | No change; the first `uninstalled_at` is kept. Same response |
| `uninstalled_at` earlier than `installed_at` | Late delivery after a reinstall: ignored. Response `"status": "active"` |
| Never installed | `404 {"error": "Unknown shop."}` |

- Data is kept. Purging happens only on Shopify's `shop/redact` (Step 7).
- While uninstalled, every tenant-scoped endpoint returns `403`. Calling `shop` again (reinstall) reactivates it.
- Safe to repeat: Shopify can deliver the same webhook more than once.

## `products` — products with variants

Up to 100 products per request; each carries its full variant list.

```json
{
  "products": [{
    "gid": "gid://shopify/Product/1", "title": "Tee", "handle": "tee", "vendor": "Acme", "product_type": "Shirts",
    "status": "ACTIVE", "image_url": null, "published_at": "2026-10-01T10:00:00Z", "shopify_updated_at": "2026-11-01T10:00:00Z",
    "variants": [{ "gid": "gid://shopify/ProductVariant/11", "title": "S", "sku": "TEE-S", "price": "19.99" }]
  }]
}
```

`status`: `ACTIVE`, `ARCHIVED`, `DRAFT`, `UNLISTED`. A product whose `shopify_updated_at` is older than the stored row is skipped together with its variants (`skipped_stale`).

## `products-delete` — soft delete products

Up to 250 distinct product GIDs per request.

```json
{ "product_gids": ["gid://shopify/Product/1", "gid://shopify/Product/2"] }
```

- Sets `deleted_at` on each product and its variants, for the verified shop only. Rows are never removed, so order lines and daily metrics keep their references and history stays reportable.
- Response uses the batch shape: `written` is the number deleted by this call; `skipped_stale` counts GIDs that were unknown or already deleted. A repeat call is a no-op and keeps the first `deleted_at`.
- A later `products` upsert for a deleted GID updates its fields but does not restore it.
- `403` when the shop is not active.

## `orders` — orders with line items

Up to 50 orders and **at most 250 line items in total** per request (keeps the endpoint within its 200 ms budget); split larger pages.

```json
{
  "orders": [{
    "gid": "gid://shopify/Order/1", "name": "#1001", "financial_status": "PAID", "fulfillment_status": "UNFULFILLED",
    "currency_code": "USD", "processed_at": "2026-11-02T15:00:00Z", "cancelled_at": null, "shopify_updated_at": "2026-11-02T15:00:00Z",
    "line_items": [{
      "gid": "gid://shopify/LineItem/11", "product_gid": "gid://shopify/Product/1", "variant_gid": "gid://shopify/ProductVariant/11",
      "title": "Tee", "sku": "TEE-S", "quantity": 2, "original_unit_price": "19.99", "discount_amount": "1.00"
    }]
  }]
}
```

- Line items inherit the order's `currency_code`.
- `product_gid` / `variant_gid` may be `null` (custom items). Unknown GIDs are stored as `null` references; re-sending the order after the product is ingested fills them in.
- A stale order (older `shopify_updated_at`) is skipped together with its line items.
- Each accepted batch dispatches `App\Events\OrdersIngested(shopId, orderIds)` for aggregation (Step 4).

## `refunds` — refund line items

Up to 250 refund lines per request.

```json
{
  "refund_lines": [{
    "refund_gid": "gid://shopify/Refund/1", "refund_line_item_gid": "gid://shopify/RefundLineItem/1",
    "order_gid": "gid://shopify/Order/1", "line_item_gid": "gid://shopify/LineItem/11",
    "quantity": 1, "subtotal": "19.99", "currency_code": "USD", "processed_at": "2026-11-05T09:00:00Z"
  }]
}
```

- `refund_line_item_gid` is required and is the idempotency key.
- If any referenced order is not ingested yet, nothing is written and the response is `409 {"error": "Referenced orders are not ingested yet.", "missing_order_gids": [...]}`. Ingest the orders, then retry.
- Dispatches `App\Events\RefundsIngested(shopId, orderIds)`.

## `webhook-events` — delivery deduplication

Record each verified Shopify webhook delivery before processing it.

```json
{ "webhook_id": "b54557e4-...", "topic": "orders/create", "payload_hash": "<sha256 hex of raw body>", "received_at": "2026-11-18T08:00:00Z" }
```

Response: `{"duplicate": false}` on first delivery, `{"duplicate": true}` on any repeat with the same `X-Shopify-Webhook-Id` for that shop. Skip processing duplicates.

## `sync-status` — sync run lifecycle

Create a run (no `sync_run_id`) → `201 {"sync_run_id": "...", "status": "running"}`; update it with the returned id → `200`.

```json
{ "sync_run_id": null, "sync_type": "initial", "status": "running", "records_received": 0, "records_processed": 0, "cursor_metadata": {} }
```

- `sync_type`: `initial`, `incremental`, `reconciliation` (required when creating).
- Transitions: `pending → running | failed`, `running → running | completed | failed`. `completed` and `failed` are terminal (`409` with `from`/`to`).
- Counters never decrease (`422`). `error_code` is required when `status` is `failed`.
- A run belonging to another shop returns `404`.

---

## Measured performance (2026-11-18, dev stack, `artisan serve`)

| Request | Latency |
|---|---|
| 100 products + 100 variants | 64 ms |
| 25 orders / 250 line items (insert) | 125 ms |
| Same batch retried | 94 ms |
| 20 refund lines | 20 ms |
| 5 stale orders (skipped) | 27 ms |
| `products-delete`, 250 products / 500 variants in a 50,000-product shop (SQL only, `EXPLAIN ANALYZE`) | 3.2 ms + 9.0 ms |

Order ingestion runs a bounded number of queries regardless of batch size (≤ 12 for 250 lines; enforced by a test). GID lookups use the `(shop_id, gid)` unique indexes (0.12 ms for 50 GIDs against 50,000 products).
