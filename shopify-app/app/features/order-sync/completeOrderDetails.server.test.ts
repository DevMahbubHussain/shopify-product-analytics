import test from "node:test";
import assert from "node:assert/strict";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import {
  REFUNDS_PER_ORDER,
  type ShopifyLineItemNode,
  type ShopifyOrderNode,
  type ShopifyRefundLineNode,
  type ShopifyRefundNode,
} from "./ordersQuery.server.ts";
import {
  completeOrderDetails,
  OrderDetailsVanishedError,
} from "./completeOrderDetails.server.ts";

const COST = {
  cost: {
    requestedQueryCost: 10,
    actualQueryCost: 10,
    throttleStatus: { maximumAvailable: 2000, currentlyAvailable: 2000, restoreRate: 100 },
  },
};

const ORDER_GID = "gid://shopify/Order/1";

interface RecordedCall {
  operation: string;
  variables: Record<string, unknown>;
}

type Responder = (variables: Record<string, unknown>) => unknown;

function routedAdmin(routes: Record<string, Responder>): { admin: GraphqlAdmin; calls: RecordedCall[] } {
  const calls: RecordedCall[] = [];
  const admin: GraphqlAdmin = {
    async graphql(query, options) {
      const operation = /query (\w+)/.exec(query)?.[1] ?? "unknown";
      const variables = options?.variables ?? {};
      calls.push({ operation, variables });
      const route = routes[operation];
      if (!route) {
        throw new Error(`Unexpected operation ${operation}`);
      }
      return Response.json({ data: route(variables), extensions: COST });
    },
  };
  return { admin, calls };
}

function money(amount: string): { shopMoney: { amount: string; currencyCode: string } } {
  return { shopMoney: { amount, currencyCode: "USD" } };
}

function lineItem(i: number): ShopifyLineItemNode {
  return {
    id: `gid://shopify/LineItem/${i}`,
    title: `Item ${i}`,
    sku: null,
    quantity: 1,
    product: null,
    variant: null,
    originalUnitPriceSet: money("1.00"),
    discountAllocations: [],
  };
}

function refundLine(refund: number, i: number): ShopifyRefundLineNode {
  return {
    id: `gid://shopify/RefundLineItem/${refund}${i}`,
    quantity: 1,
    lineItem: { id: `gid://shopify/LineItem/${i}` },
    subtotalSet: money("1.00"),
  };
}

function refund(i: number, lines = 1, hasNextPage = false): ShopifyRefundNode {
  return {
    id: `gid://shopify/Refund/${i}`,
    createdAt: "2026-11-02T10:00:00Z",
    updatedAt: "2026-11-02T10:00:00Z",
    refundLineItems: {
      nodes: Array.from({ length: lines }, (_, j) => refundLine(i, j + 1)),
      pageInfo: { hasNextPage },
    },
  };
}

function order(overrides: Partial<ShopifyOrderNode> = {}): ShopifyOrderNode {
  return {
    id: ORDER_GID,
    name: "#1001",
    test: false,
    displayFinancialStatus: "PAID",
    displayFulfillmentStatus: "FULFILLED",
    currencyCode: "USD",
    processedAt: "2026-11-01T10:00:00Z",
    cancelledAt: null,
    updatedAt: "2026-11-02T10:00:00Z",
    lineItems: { nodes: [lineItem(1)], pageInfo: { hasNextPage: false } },
    refunds: [],
    ...overrides,
  };
}

test("completeOrderDetails returns a complete order without querying", async () => {
  const { admin, calls } = routedAdmin({});
  const node = order({ refunds: [refund(1)] });

  const result = await completeOrderDetails(admin, node);

  assert.equal(result, node);
  assert.equal(calls.length, 0);
});

test("completeOrderDetails skips follow-up queries for test orders", async () => {
  const { admin, calls } = routedAdmin({});
  const node = order({
    test: true,
    lineItems: { nodes: [lineItem(1)], pageInfo: { hasNextPage: true } },
  });

  const result = await completeOrderDetails(admin, node);

  assert.equal(result, node);
  assert.equal(calls.length, 0);
});

test("completeOrderDetails refetches all line items across pages when truncated", async () => {
  const { admin, calls } = routedAdmin({
    OrderLineItemsPage: (vars) => {
      const first = vars.after === null;
      const range = first ? [1, 250] : [251, 260];
      return {
        order: {
          lineItems: {
            nodes: Array.from({ length: range[1] - range[0] + 1 }, (_, i) => lineItem(range[0] + i)),
            pageInfo: { hasNextPage: first, endCursor: first ? "c250" : "c260" },
          },
        },
      };
    },
  });
  const node = order({
    lineItems: {
      nodes: Array.from({ length: 50 }, (_, i) => lineItem(i + 1)),
      pageInfo: { hasNextPage: true },
    },
  });

  const result = await completeOrderDetails(admin, node);

  assert.equal(result.lineItems.nodes.length, 260);
  assert.equal(result.lineItems.pageInfo.hasNextPage, false);
  assert.deepEqual(calls.map((c) => c.variables), [
    { id: ORDER_GID, first: 250, after: null },
    { id: ORDER_GID, first: 250, after: "c250" },
  ]);
});

test("completeOrderDetails accepts an order with exactly the refund page size after refetching", async () => {
  const exact = Array.from({ length: REFUNDS_PER_ORDER }, (_, i) => refund(i + 1));
  const { admin, calls } = routedAdmin({
    OrderRefunds: () => ({ order: { refunds: exact } }),
  });

  const result = await completeOrderDetails(admin, order({ refunds: exact }));

  assert.equal(result.refunds.length, REFUNDS_PER_ORDER);
  assert.deepEqual(calls.map((c) => c.operation), ["OrderRefunds"]);
  assert.deepEqual(calls[0].variables, { id: ORDER_GID });
});

test("completeOrderDetails refetches the full refund list when the page may be truncated", async () => {
  const all = Array.from({ length: 7 }, (_, i) => refund(i + 1));
  const { admin } = routedAdmin({
    OrderRefunds: () => ({ order: { refunds: all } }),
  });

  const result = await completeOrderDetails(admin, order({ refunds: all.slice(0, REFUNDS_PER_ORDER) }));

  assert.equal(result.refunds.length, 7);
});

test("completeOrderDetails pages truncated refund lines from the refund root", async () => {
  const { admin, calls } = routedAdmin({
    RefundLineItemsPage: (vars) => {
      assert.equal(vars.id, "gid://shopify/Refund/1");
      return {
        refund: {
          refundLineItems: {
            nodes: Array.from({ length: 25 }, (_, j) => refundLine(1, j + 1)),
            pageInfo: { hasNextPage: false, endCursor: "r25" },
          },
        },
      };
    },
  });

  const result = await completeOrderDetails(admin, order({ refunds: [refund(1, 20, true)] }));

  assert.equal(result.refunds[0]?.refundLineItems.nodes.length, 25);
  assert.equal(result.refunds[0]?.refundLineItems.pageInfo.hasNextPage, false);
  assert.deepEqual(calls.map((c) => c.operation), ["RefundLineItemsPage"]);
});

test("completeOrderDetails also completes refund lines of refetched refunds", async () => {
  const refetched = [...Array.from({ length: 5 }, (_, i) => refund(i + 1)), refund(6, 20, true)];
  const { admin, calls } = routedAdmin({
    OrderRefunds: () => ({ order: { refunds: refetched } }),
    RefundLineItemsPage: () => ({
      refund: {
        refundLineItems: {
          nodes: Array.from({ length: 21 }, (_, j) => refundLine(6, j + 1)),
          pageInfo: { hasNextPage: false, endCursor: "r21" },
        },
      },
    }),
  });

  const result = await completeOrderDetails(
    admin,
    order({ refunds: refetched.slice(0, REFUNDS_PER_ORDER) }),
  );

  assert.equal(result.refunds.length, 6);
  assert.equal(result.refunds[5]?.refundLineItems.nodes.length, 21);
  assert.deepEqual(calls.map((c) => c.operation), ["OrderRefunds", "RefundLineItemsPage"]);
});

test("completeOrderDetails throws OrderDetailsVanishedError when the order was deleted mid-sync", async () => {
  const { admin } = routedAdmin({ OrderRefunds: () => ({ order: null }) });

  await assert.rejects(
    completeOrderDetails(admin, order({ refunds: Array.from({ length: 5 }, (_, i) => refund(i + 1)) })),
    (error: unknown) => {
      assert.ok(error instanceof OrderDetailsVanishedError);
      assert.equal(error.resourceGid, ORDER_GID);
      return true;
    },
  );
});

test("completeOrderDetails throws OrderDetailsVanishedError when a refund was deleted mid-sync", async () => {
  const { admin } = routedAdmin({ RefundLineItemsPage: () => ({ refund: null }) });

  await assert.rejects(
    completeOrderDetails(admin, order({ refunds: [refund(1, 20, true)] })),
    (error: unknown) => error instanceof OrderDetailsVanishedError && error.resourceGid === "gid://shopify/Refund/1",
  );
});
