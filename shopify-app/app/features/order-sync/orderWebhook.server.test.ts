import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  handleOrderWebhook,
  handleRefundWebhook,
} from "./orderWebhook.server.ts";

describe("handleOrderWebhook", () => {
  it("parses valid order webhook payload and forwards to backend ingestion", async () => {
    let capturedShop = "";
    let capturedEndpoint = "";
    let capturedPayload: unknown;

    const rawBody = JSON.stringify({
      id: 7443305168944,
      admin_graphql_api_id: "gid://shopify/Order/7443305168944",
      name: "#1001",
      currency: "USD",
      financial_status: "paid",
      fulfillment_status: "unfulfilled",
      processed_at: "2026-11-18T10:52:03Z",
      cancelled_at: null,
      updated_at: "2026-11-18T10:55:00Z",
      test: false,
      line_items: [
        {
          id: 1455667788,
          admin_graphql_api_id: "gid://shopify/LineItem/1455667788",
          product_id: 998877,
          variant_id: 665544,
          title: "The 3p Fulfilled Snowboard",
          sku: "SKU-3P",
          quantity: 1,
          price: "2629.95",
          total_discount: "4.90",
        },
      ],
    });

    const status = await handleOrderWebhook(
      { shop: "order-store.myshopify.com", rawBody },
      async (endpoint, shop, payload) => {
        capturedEndpoint = endpoint;
        capturedShop = shop;
        capturedPayload = payload;
        return { received: 1, written: 1, skipped_stale: 0 };
      },
    );

    assert.equal(status, 200);
    assert.equal(capturedEndpoint, "/internal/v1/ingestion/orders");
    assert.equal(capturedShop, "order-store.myshopify.com");
    assert.deepEqual(capturedPayload, {
      orders: [
        {
          gid: "gid://shopify/Order/7443305168944",
          name: "#1001",
          financial_status: "PAID",
          fulfillment_status: "UNFULFILLED",
          currency_code: "USD",
          processed_at: "2026-11-18T10:52:03Z",
          cancelled_at: null,
          shopify_updated_at: "2026-11-18T10:55:00Z",
          line_items: [
            {
              gid: "gid://shopify/LineItem/1455667788",
              product_gid: "gid://shopify/Product/998877",
              variant_gid: "gid://shopify/ProductVariant/665544",
              title: "The 3p Fulfilled Snowboard",
              sku: "SKU-3P",
              quantity: 1,
              original_unit_price: "2629.950000",
              discount_amount: "4.900000",
            },
          ],
        },
      ],
    });
  });

  it("skips test orders without calling backend ingestion", async () => {
    let ingestCalled = false;

    const rawBody = JSON.stringify({
      id: 999,
      test: true,
      line_items: [],
    });

    const status = await handleOrderWebhook(
      { shop: "order-store.myshopify.com", rawBody },
      async () => {
        ingestCalled = true;
        return { received: 1, written: 1, skipped_stale: 0 };
      },
    );

    assert.equal(status, 200);
    assert.equal(ingestCalled, false);
  });

  it("returns 500 when backend ingestion fails so Shopify will retry", async () => {
    const rawBody = JSON.stringify({
      id: 7443305168944,
      name: "#1001",
      currency: "USD",
      test: false,
      line_items: [],
    });

    const status = await handleOrderWebhook(
      { shop: "order-store.myshopify.com", rawBody },
      async () => {
        throw new Error("Backend S2S error");
      },
    );

    assert.equal(status, 500);
  });
});

describe("handleRefundWebhook", () => {
  it("parses valid refund webhook payload and forwards to backend ingestion", async () => {
    let capturedEndpoint = "";
    let capturedPayload: unknown;

    const rawBody = JSON.stringify({
      id: 554433,
      admin_graphql_api_id: "gid://shopify/Refund/554433",
      order_id: 7443305168944,
      currency: "USD",
      created_at: "2026-11-18T11:00:00Z",
      processed_at: "2026-11-18T11:00:00Z",
      refund_line_items: [
        {
          id: 112233,
          admin_graphql_api_id: "gid://shopify/RefundLineItem/112233",
          line_item_id: 1455667788,
          quantity: 1,
          subtotal: "49.95",
        },
      ],
    });

    const status = await handleRefundWebhook(
      { shop: "order-store.myshopify.com", rawBody },
      async (endpoint, _shop, payload) => {
        capturedEndpoint = endpoint;
        capturedPayload = payload;
        return { received: 1, written: 1, skipped_stale: 0 };
      },
    );

    assert.equal(status, 200);
    assert.equal(capturedEndpoint, "/internal/v1/ingestion/refunds");
    assert.deepEqual(capturedPayload, {
      refund_lines: [
        {
          refund_gid: "gid://shopify/Refund/554433",
          refund_line_item_gid: "gid://shopify/RefundLineItem/112233",
          order_gid: "gid://shopify/Order/7443305168944",
          line_item_gid: "gid://shopify/LineItem/1455667788",
          quantity: 1,
          subtotal: "49.950000",
          currency_code: "USD",
          processed_at: "2026-11-18T11:00:00Z",
          shopify_updated_at: "2026-11-18T11:00:00Z",
        },
      ],
    });
  });
});
