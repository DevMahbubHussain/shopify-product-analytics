import { test } from "node:test";
import assert from "node:assert/strict";
import {
  normalizeOrder,
  CurrencyMismatchError,
  LineItemsTruncatedError,
} from "./normalizeOrder.server.ts";
import type {
  ShopifyLineItemNode,
  ShopifyOrderNode,
} from "./ordersQuery.server.ts";

const usd = (amount: string, currencyCode = "USD") => ({
  shopMoney: { amount, currencyCode },
});

function lineItem(
  overrides: Partial<ShopifyLineItemNode> = {},
): ShopifyLineItemNode {
  return {
    id: "gid://shopify/LineItem/1",
    title: "Selling Plans Ski Wax",
    sku: null,
    quantity: 2,
    product: { id: "gid://shopify/Product/10" },
    variant: { id: "gid://shopify/ProductVariant/11" },
    originalUnitPriceSet: usd("24.95"),
    discountAllocations: [],
    ...overrides,
  };
}

function order(overrides: Partial<ShopifyOrderNode> = {}): ShopifyOrderNode {
  return {
    id: "gid://shopify/Order/1001",
    name: "#1001",
    test: false,
    displayFinancialStatus: "PAID",
    displayFulfillmentStatus: "UNFULFILLED",
    currencyCode: "USD",
    processedAt: "2026-11-18T10:52:03Z",
    cancelledAt: null,
    updatedAt: "2026-11-18T10:52:05Z",
    lineItems: { nodes: [lineItem()], pageInfo: { hasNextPage: false } },
    refunds: [],
    ...overrides,
  };
}

test("maps order #1001 with its order-level discount spread across lines", () => {
  const result = normalizeOrder(
    order({
      lineItems: {
        nodes: [
          lineItem({
            discountAllocations: [{ allocatedAmountSet: usd("0.1") }],
          }),
          lineItem({
            id: "gid://shopify/LineItem/2",
            title: "The 3p Fulfilled Snowboard",
            sku: "sku-hosted-1",
            quantity: 1,
            product: { id: "gid://shopify/Product/20" },
            variant: { id: "gid://shopify/ProductVariant/21" },
            originalUnitPriceSet: usd("2629.95"),
            discountAllocations: [{ allocatedAmountSet: usd("4.9") }],
          }),
        ],
        pageInfo: { hasNextPage: false },
      },
    }),
  );

  assert.deepEqual(result, {
    payload: {
      gid: "gid://shopify/Order/1001",
      name: "#1001",
      financial_status: "PAID",
      fulfillment_status: "UNFULFILLED",
      currency_code: "USD",
      processed_at: "2026-11-18T10:52:03Z",
      cancelled_at: null,
      shopify_updated_at: "2026-11-18T10:52:05Z",
      line_items: [
        {
          gid: "gid://shopify/LineItem/1",
          product_gid: "gid://shopify/Product/10",
          variant_gid: "gid://shopify/ProductVariant/11",
          title: "Selling Plans Ski Wax",
          sku: null,
          quantity: 2,
          original_unit_price: "24.95",
          discount_amount: "0.1",
        },
        {
          gid: "gid://shopify/LineItem/2",
          product_gid: "gid://shopify/Product/20",
          variant_gid: "gid://shopify/ProductVariant/21",
          title: "The 3p Fulfilled Snowboard",
          sku: "sku-hosted-1",
          quantity: 1,
          original_unit_price: "2629.95",
          discount_amount: "4.9",
        },
      ],
    },
  });
});

test("adds several discount allocations on one line exactly", () => {
  const result = normalizeOrder(
    order({
      lineItems: {
        nodes: [
          lineItem({
            discountAllocations: [
              { allocatedAmountSet: usd("0.1") },
              { allocatedAmountSet: usd("0.2") },
            ],
          }),
        ],
        pageInfo: { hasNextPage: false },
      },
    }),
  );

  assert.ok("payload" in result);
  assert.equal(result.payload.line_items[0]?.discount_amount, "0.3");
});

test("uses zero discount when a line has no allocations", () => {
  const result = normalizeOrder(order());

  assert.ok("payload" in result);
  assert.equal(result.payload.line_items[0]?.discount_amount, "0");
});

test("keeps custom items without product or variant", () => {
  const result = normalizeOrder(
    order({
      lineItems: {
        nodes: [lineItem({ product: null, variant: null })],
        pageInfo: { hasNextPage: false },
      },
    }),
  );

  assert.ok("payload" in result);
  assert.equal(result.payload.line_items[0]?.product_gid, null);
  assert.equal(result.payload.line_items[0]?.variant_gid, null);
});

test("passes through cancellation and a missing financial status", () => {
  const result = normalizeOrder(
    order({
      cancelledAt: "2026-11-18T10:57:48Z",
      displayFinancialStatus: null,
    }),
  );

  assert.ok("payload" in result);
  assert.equal(result.payload.cancelled_at, "2026-11-18T10:57:48Z");
  assert.equal(result.payload.financial_status, null);
});

test("skips test orders", () => {
  assert.deepEqual(normalizeOrder(order({ test: true })), {
    skipped: "test_order",
  });
});

test("refuses an order whose line items were truncated", () => {
  assert.throws(
    () =>
      normalizeOrder(
        order({
          lineItems: { nodes: [lineItem()], pageInfo: { hasNextPage: true } },
        }),
      ),
    (error: unknown) => {
      assert.ok(error instanceof LineItemsTruncatedError);
      assert.equal(error.orderGid, "gid://shopify/Order/1001");
      return true;
    },
  );
});

test("refuses amounts in a currency other than the order's", () => {
  for (const line of [
    lineItem({ originalUnitPriceSet: usd("24.95", "EUR") }),
    lineItem({
      discountAllocations: [{ allocatedAmountSet: usd("1.00", "EUR") }],
    }),
  ]) {
    assert.throws(
      () =>
        normalizeOrder(
          order({
            lineItems: { nodes: [line], pageInfo: { hasNextPage: false } },
          }),
        ),
      CurrencyMismatchError,
    );
  }
});
