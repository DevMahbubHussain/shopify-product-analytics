import test from "node:test";
import assert from "node:assert/strict";
import { harvestOrders } from "./harvestOrders.server.ts";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import type {
  OrderBatchBody,
  RefundBatchBody,
  BatchResponse,
} from "../../lib/backend/ingestionContract.server.ts";
import type { ShopifyOrderNode } from "./ordersQuery.server.ts";

function createMockAdmin(
  pages: Array<{ nodes: ShopifyOrderNode[]; hasNextPage: boolean; endCursor: string | null }>,
): GraphqlAdmin {
  let callIndex = 0;
  return {
    async graphql(): Promise<Response> {
      const page = pages[callIndex++] ?? { nodes: [], hasNextPage: false, endCursor: null };
      const body = {
        data: {
          orders: {
            nodes: page.nodes,
            pageInfo: {
              hasNextPage: page.hasNextPage,
              endCursor: page.endCursor,
            },
          },
        },
        extensions: {
          cost: {
            requestedQueryCost: 50,
            actualQueryCost: 50,
            throttleStatus: {
              maximumAvailable: 1000,
              currentlyAvailable: 1000,
              restoreRate: 50,
            },
          },
        },
      };
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    },
  };
}

function createSampleOrderNode({
  id,
  name = "#1001",
  lineCount = 1,
  hasRefund = false,
  isTest = false,
}: {
  id: string;
  name?: string;
  lineCount?: number;
  hasRefund?: boolean;
  isTest?: boolean;
}): ShopifyOrderNode {
  return {
    id: `gid://shopify/Order/${id}`,
    name,
    test: isTest,
    displayFinancialStatus: "PAID",
    displayFulfillmentStatus: "FULFILLED",
    currencyCode: "USD",
    processedAt: "2026-11-01T12:00:00Z",
    cancelledAt: null,
    updatedAt: "2026-11-02T12:00:00Z",
    lineItems: {
      nodes: Array.from({ length: lineCount }, (_, i) => ({
        id: `gid://shopify/LineItem/${id}${i + 1}`,
        title: `Item ${i + 1}`,
        sku: `SKU-${id}-${i + 1}`,
        quantity: 1,
        product: { id: `gid://shopify/Product/${id}` },
        variant: { id: `gid://shopify/ProductVariant/${id}1` },
        originalUnitPriceSet: {
          shopMoney: { amount: "25.00", currencyCode: "USD" },
        },
        discountAllocations: [],
      })),
      pageInfo: { hasNextPage: false },
    },
    refunds: hasRefund
      ? [
          {
            id: `gid://shopify/Refund/${id}9`,
            createdAt: "2026-11-03T12:00:00Z",
            updatedAt: "2026-11-03T12:00:00Z",
            refundLineItems: {
              nodes: [
                {
                  id: `gid://shopify/RefundLineItem/${id}91`,
                  quantity: 1,
                  lineItem: { id: `gid://shopify/LineItem/${id}1` },
                  subtotalSet: {
                    shopMoney: { amount: "25.00", currencyCode: "USD" },
                  },
                },
              ],
              pageInfo: { hasNextPage: false },
            },
          },
        ]
      : [],
  };
}

test("harvestOrders streams orders first, then associated refunds, preserving dependency order", async () => {
  const admin = createMockAdmin([
    {
      nodes: [
        createSampleOrderNode({ id: "1", name: "#1001", lineCount: 2, hasRefund: true }),
        createSampleOrderNode({ id: "2", name: "#1002", lineCount: 1, hasRefund: false }),
      ],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  const callLog: Array<{ path: string; count: number }> = [];

  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, shopDomain: string, body: unknown): Promise<T> {
      assert.equal(shopDomain, "test-store.myshopify.com");
      if (path === "/internal/v1/ingestion/orders") {
        const batch = body as OrderBatchBody;
        callLog.push({ path, count: batch.orders.length });
        const res: BatchResponse = {
          received: batch.orders.length,
          written: batch.orders.length,
          skipped_stale: 0,
        };
        return res as T;
      }
      if (path === "/internal/v1/ingestion/refunds") {
        const batch = body as RefundBatchBody;
        callLog.push({ path, count: batch.refund_lines.length });
        const res: BatchResponse = {
          received: batch.refund_lines.length,
          written: batch.refund_lines.length,
          skipped_stale: 0,
        };
        return res as T;
      }
      throw new Error(`Unexpected path ${path}`);
    },
  };

  const summary = await harvestOrders({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
  });

  assert.equal(summary.orders.received, 2);
  assert.equal(summary.orders.written, 2);
  assert.equal(summary.refunds.received, 1);
  assert.equal(summary.refunds.written, 1);

  // Orders MUST be posted before refunds
  assert.equal(callLog.length, 2);
  assert.equal(callLog[0].path, "/internal/v1/ingestion/orders");
  assert.equal(callLog[0].count, 2);
  assert.equal(callLog[1].path, "/internal/v1/ingestion/refunds");
  assert.equal(callLog[1].count, 1);
});

test("harvestOrders chunks orders when total line items exceed 250", async () => {
  // 3 orders with 100 line items each = 300 line items (exceeds 250 limit)
  const admin = createMockAdmin([
    {
      nodes: [
        createSampleOrderNode({ id: "1", lineCount: 100 }),
        createSampleOrderNode({ id: "2", lineCount: 100 }),
        createSampleOrderNode({ id: "3", lineCount: 100 }),
      ],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  const orderBatches: OrderBatchBody[] = [];

  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      if (path === "/internal/v1/ingestion/orders") {
        const batch = body as OrderBatchBody;
        orderBatches.push(batch);
        const res: BatchResponse = {
          received: batch.orders.length,
          written: batch.orders.length,
          skipped_stale: 0,
        };
        return res as T;
      }
      throw new Error(`Unexpected path ${path}`);
    },
  };

  await harvestOrders({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
  });

  // Should split into at least 2 batches so no single batch has > 250 lines
  assert.ok(orderBatches.length >= 2, "Orders should be split to respect line-item limit");
  for (const batch of orderBatches) {
    const totalLines = batch.orders.reduce((sum, o) => sum + o.line_items.length, 0);
    assert.ok(totalLines <= 250, `Batch line items (${totalLines}) exceeded 250`);
  }
});

test("harvestOrders skips test orders and handles empty responses cleanly", async () => {
  const admin = createMockAdmin([
    {
      nodes: [createSampleOrderNode({ id: "99", isTest: true })],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  let backendCalled = false;
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal() {
      backendCalled = true;
      throw new Error("Should not be called");
    },
  };

  const summary = await harvestOrders({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
  });

  assert.equal(summary.orders.received, 0);
  assert.equal(summary.orders.written, 0);
  assert.equal(summary.refunds.received, 0);
  assert.equal(backendCalled, false);
});

test("harvestOrders completes truncated line items and refunds before posting", async () => {
  const full = createSampleOrderNode({ id: "7", lineCount: 60 });
  const refunds = Array.from({ length: 6 }, (_, r) => ({
    id: `gid://shopify/Refund/7${r}`,
    createdAt: "2026-11-03T12:00:00Z",
    updatedAt: "2026-11-03T12:00:00Z",
    refundLineItems: {
      nodes: [
        {
          id: `gid://shopify/RefundLineItem/7${r}1`,
          quantity: 1,
          lineItem: { id: `gid://shopify/LineItem/7${r + 1}` },
          subtotalSet: { shopMoney: { amount: "25.00", currencyCode: "USD" } },
        },
      ],
      pageInfo: { hasNextPage: false },
    },
  }));
  const truncated: ShopifyOrderNode = {
    ...full,
    lineItems: { nodes: full.lineItems.nodes.slice(0, 50), pageInfo: { hasNextPage: true } },
    refunds: refunds.slice(0, 5),
  };
  const cost = {
    cost: {
      requestedQueryCost: 10,
      actualQueryCost: 10,
      throttleStatus: { maximumAvailable: 1000, currentlyAvailable: 1000, restoreRate: 50 },
    },
  };
  const operations: string[] = [];
  const admin: GraphqlAdmin = {
    async graphql(query: string): Promise<Response> {
      const operation = /query (\w+)/.exec(query)?.[1] ?? "unknown";
      operations.push(operation);
      const data =
        operation === "OrderLineItemsPage"
          ? { order: { lineItems: { nodes: full.lineItems.nodes, pageInfo: { hasNextPage: false, endCursor: "c" } } } }
          : operation === "OrderRefunds"
            ? { order: { refunds } }
            : { orders: { nodes: [truncated], pageInfo: { hasNextPage: false, endCursor: null } } };
      return Response.json({ data, extensions: cost });
    },
  };

  const orderBodies: OrderBatchBody[] = [];
  const refundBodies: RefundBatchBody[] = [];
  const backend = {
    async postInternal<T>(path: string, _shop: string, body: unknown): Promise<T> {
      if (path.endsWith("/orders")) {
        orderBodies.push(body as OrderBatchBody);
      } else {
        refundBodies.push(body as RefundBatchBody);
      }
      const res: BatchResponse = { received: 1, written: 1, skipped_stale: 0 };
      return res as T;
    },
  } as unknown as BackendClient;

  await harvestOrders({ admin, backend, shopDomain: "acme.myshopify.com", query: "" });

  assert.deepEqual(operations, ["OrderSync", "OrderLineItemsPage", "OrderRefunds"]);
  assert.equal(orderBodies[0]?.orders[0]?.line_items.length, 60);
  assert.equal(refundBodies.flatMap((b) => b.refund_lines).length, 6);
});

test("harvestOrders slices an order with more than 250 line items into separate requests", async () => {
  const admin = createMockAdmin([
    {
      nodes: [
        createSampleOrderNode({ id: "1", lineCount: 10 }),
        createSampleOrderNode({ id: "2", lineCount: 600 }),
        createSampleOrderNode({ id: "3", lineCount: 10 }),
      ],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  const orderBatches: OrderBatchBody[] = [];
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      assert.equal(path, "/internal/v1/ingestion/orders");
      const batch = body as OrderBatchBody;
      orderBatches.push(batch);
      const res: BatchResponse = { received: batch.orders.length, written: batch.orders.length, skipped_stale: 0 };
      return res as T;
    },
  };

  const summary = await harvestOrders({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
    query: "",
  });

  for (const batch of orderBatches) {
    const lines = batch.orders.reduce((sum, o) => sum + o.line_items.length, 0);
    assert.ok(lines <= 250, `batch carried ${lines} line items`);
  }

  const bigSlices = orderBatches.flatMap((b) => b.orders).filter((o) => o.gid === "gid://shopify/Order/2");
  assert.equal(bigSlices.length, 3);
  assert.deepEqual(bigSlices.map((o) => o.line_items.length), [250, 250, 100]);
  assert.equal(new Set(bigSlices.flatMap((o) => o.line_items.map((l) => l.gid))).size, 600);
  for (const slice of bigSlices) {
    assert.equal(slice.shopify_updated_at, "2026-11-02T12:00:00Z");
    assert.equal(slice.name, "#1001");
  }

  const continuationBatches = orderBatches.filter(
    (b) => b.orders.some((o) => o.gid === "gid://shopify/Order/2") && b.orders.length !== 1,
  );
  assert.equal(continuationBatches.length, 0, "every slice of the large order travels alone");

  assert.deepEqual(summary.orders, { received: 3, written: 3, skippedStale: 0 });
});
