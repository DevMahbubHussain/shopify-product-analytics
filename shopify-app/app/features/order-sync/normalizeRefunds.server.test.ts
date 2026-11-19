import { test } from "node:test";
import assert from "node:assert/strict";
import {
  normalizeRefunds,
  RefundsTruncatedError,
} from "./normalizeRefunds.server.ts";
import { CurrencyMismatchError } from "./normalizeOrder.server.ts";
import {
  REFUNDS_PER_ORDER,
  type ShopifyOrderNode,
  type ShopifyRefundLineNode,
  type ShopifyRefundNode,
} from "./ordersQuery.server.ts";

const usd = (amount: string, currencyCode = "USD") => ({
  shopMoney: { amount, currencyCode },
});

function refundLine(
  overrides: Partial<ShopifyRefundLineNode> = {},
): ShopifyRefundLineNode {
  return {
    id: "gid://shopify/RefundLineItem/1",
    quantity: 1,
    lineItem: { id: "gid://shopify/LineItem/31" },
    subtotalSet: usd("24.95"),
    ...overrides,
  };
}

function refund(overrides: Partial<ShopifyRefundNode> = {}): ShopifyRefundNode {
  return {
    id: "gid://shopify/Refund/1003411669040",
    createdAt: "2026-11-18T11:05:00Z",
    updatedAt: "2026-11-18T11:05:02Z",
    refundLineItems: {
      nodes: [refundLine()],
      pageInfo: { hasNextPage: false },
    },
    ...overrides,
  };
}

function order(refunds: ShopifyRefundNode[], test = false): ShopifyOrderNode {
  return {
    id: "gid://shopify/Order/1003",
    name: "#1003",
    test,
    displayFinancialStatus: "REFUNDED",
    displayFulfillmentStatus: "FULFILLMENT_NOT_REQUIRED",
    currencyCode: "USD",
    processedAt: "2026-11-18T10:53:41Z",
    cancelledAt: null,
    updatedAt: "2026-11-18T11:05:02Z",
    lineItems: { nodes: [], pageInfo: { hasNextPage: false } },
    refunds,
  };
}

test("maps order #1003's full refund to two refund lines", () => {
  const result = normalizeRefunds(
    order([
      refund({
        refundLineItems: {
          nodes: [
            refundLine(),
            refundLine({
              id: "gid://shopify/RefundLineItem/2",
              lineItem: { id: "gid://shopify/LineItem/32" },
              subtotalSet: usd("49.95"),
            }),
          ],
          pageInfo: { hasNextPage: false },
        },
      }),
    ]),
  );

  assert.deepEqual(result, {
    lines: [
      {
        refund_gid: "gid://shopify/Refund/1003411669040",
        refund_line_item_gid: "gid://shopify/RefundLineItem/1",
        order_gid: "gid://shopify/Order/1003",
        line_item_gid: "gid://shopify/LineItem/31",
        quantity: 1,
        subtotal: "24.95",
        currency_code: "USD",
        processed_at: "2026-11-18T11:05:00Z",
      },
      {
        refund_gid: "gid://shopify/Refund/1003411669040",
        refund_line_item_gid: "gid://shopify/RefundLineItem/2",
        order_gid: "gid://shopify/Order/1003",
        line_item_gid: "gid://shopify/LineItem/32",
        quantity: 1,
        subtotal: "49.95",
        currency_code: "USD",
        processed_at: "2026-11-18T11:05:00Z",
      },
    ],
    skippedWithoutId: 0,
  });
});

test("uses the refund update time when its creation time is missing", () => {
  const result = normalizeRefunds(order([refund({ createdAt: null })]));

  assert.equal(result.lines[0]?.processed_at, "2026-11-18T11:05:02Z");
});

test("skips and counts refund lines without an id", () => {
  const result = normalizeRefunds(
    order([
      refund({
        refundLineItems: {
          nodes: [refundLine({ id: null }), refundLine()],
          pageInfo: { hasNextPage: false },
        },
      }),
    ]),
  );

  assert.equal(result.lines.length, 1);
  assert.equal(result.skippedWithoutId, 1);
});

test("returns nothing for orders without refunds and for test orders", () => {
  assert.deepEqual(normalizeRefunds(order([])), {
    lines: [],
    skippedWithoutId: 0,
  });
  assert.deepEqual(normalizeRefunds(order([refund()], true)), {
    lines: [],
    skippedWithoutId: 0,
  });
});

test("refuses a refund whose lines were truncated", () => {
  assert.throws(
    () =>
      normalizeRefunds(
        order([
          refund({
            refundLineItems: {
              nodes: [refundLine()],
              pageInfo: { hasNextPage: true },
            },
          }),
        ]),
      ),
    RefundsTruncatedError,
  );
});

test("normalizes an order with a full page of refunds once the harvester completed them", () => {
  const refunds = Array.from({ length: REFUNDS_PER_ORDER + 1 }, (_, i) =>
    refund({ id: `gid://shopify/Refund/${i + 1}` }),
  );

  const result = normalizeRefunds(order(refunds));

  assert.equal(
    new Set(result.lines.map((line) => line.refund_gid)).size,
    REFUNDS_PER_ORDER + 1,
  );
});

test("refuses a refund subtotal in another currency", () => {
  assert.throws(
    () =>
      normalizeRefunds(
        order([
          refund({
            refundLineItems: {
              nodes: [refundLine({ subtotalSet: usd("24.95", "EUR") })],
              pageInfo: { hasNextPage: false },
            },
          }),
        ]),
      ),
    CurrencyMismatchError,
  );
});
