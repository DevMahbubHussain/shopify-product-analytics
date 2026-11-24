import test from "node:test";
import assert from "node:assert/strict";
import {
  runHistoricalSync,
  type SyncStatusBody,
  type SyncStatusResponse,
} from "./syncOrchestrator.server.ts";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import { BackendError, type BackendClient } from "../../lib/backend/backendClient.server.ts";
import {
  INGESTION_LIMITS,
  type BatchResponse,
} from "../../lib/backend/ingestionContract.server.ts";

function createMockAdmin(): GraphqlAdmin {
  return {
    async graphql(query: string): Promise<Response> {
      if (query.includes("CatalogProducts")) {
        const body = {
          data: {
            products: {
              nodes: [
                {
                  id: "gid://shopify/Product/1",
                  title: "P1",
                  handle: "p1",
                  vendor: "Acme",
                  productType: "Shoes",
                  status: "ACTIVE",
                  featuredMedia: null,
                  publishedAt: "2026-11-01T10:00:00Z",
                  updatedAt: "2026-11-02T10:00:00Z",
                  variants: {
                    nodes: [
                      {
                        id: "gid://shopify/ProductVariant/11",
                        title: "Default",
                        sku: "SKU-1",
                        price: "10.00",
                      },
                    ],
                    pageInfo: { hasNextPage: false },
                  },
                },
              ],
              pageInfo: { hasNextPage: false, endCursor: null },
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
      }

      if (query.includes("OrderSync")) {
        const body = {
          data: {
            orders: {
              nodes: [
                {
                  id: "gid://shopify/Order/1",
                  name: "#1001",
                  test: false,
                  displayFinancialStatus: "PAID",
                  displayFulfillmentStatus: "FULFILLED",
                  currencyCode: "USD",
                  processedAt: "2026-11-01T12:00:00Z",
                  cancelledAt: null,
                  updatedAt: "2026-11-02T12:00:00Z",
                  lineItems: {
                    nodes: [
                      {
                        id: "gid://shopify/LineItem/11",
                        title: "P1 Item",
                        sku: "SKU-1",
                        quantity: 1,
                        product: { id: "gid://shopify/Product/1" },
                        variant: { id: "gid://shopify/ProductVariant/11" },
                        originalUnitPriceSet: {
                          shopMoney: { amount: "10.00", currencyCode: "USD" },
                        },
                        discountAllocations: [],
                      },
                    ],
                    pageInfo: { hasNextPage: false },
                  },
                  refunds: [],
                },
              ],
              pageInfo: { hasNextPage: false, endCursor: null },
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
      }

      throw new Error(`Unexpected query: ${query}`);
    },
  };
}

test("runHistoricalSync runs full lifecycle: starts run, syncs products, syncs orders, marks completed", async () => {
  const admin = createMockAdmin();
  const syncStatusCalls: SyncStatusBody[] = [];
  const ingestionCalls: string[] = [];

  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, shopDomain: string, body: unknown): Promise<T> {
      assert.equal(shopDomain, "test-store.myshopify.com");

      if (path === "/internal/v1/ingestion/sync-status") {
        const payload = body as SyncStatusBody;
        syncStatusCalls.push(payload);
        const res: SyncStatusResponse = {
          sync_run_id: payload.sync_run_id ?? "sync_run_123",
          status: payload.status,
        };
        return res as T;
      }

      if (path === "/internal/v1/ingestion/products") {
        ingestionCalls.push("products");
        const res: BatchResponse = { received: 1, written: 1, skipped_stale: 0 };
        return res as T;
      }

      if (path === "/internal/v1/ingestion/orders") {
        ingestionCalls.push("orders");
        const res: BatchResponse = { received: 1, written: 1, skipped_stale: 0 };
        return res as T;
      }

      throw new Error(`Unexpected path: ${path}`);
    },
  };

  const result = await runHistoricalSync({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
  });

  assert.equal(result.syncRunId, "sync_run_123");
  assert.equal(result.products.written, 1);
  assert.equal(result.orders.written, 1);

  // Ingestion order: products before orders
  assert.deepEqual(ingestionCalls, ["products", "orders"]);

  // Sync run lifecycle: starts with running, ends with completed
  assert.ok(syncStatusCalls.length >= 2);
  assert.equal(syncStatusCalls[0].status, "running");
  assert.equal(syncStatusCalls[0].sync_run_id, null);
  assert.ok(
    typeof (syncStatusCalls[0].cursor_metadata as { window_start: string })
      ?.window_start === "string",
  );
  const finalCall = syncStatusCalls[syncStatusCalls.length - 1];
  assert.equal(finalCall.status, "completed");
  assert.equal(finalCall.sync_run_id, "sync_run_123");
  assert.equal(finalCall.records_processed, 2);
});

test("runHistoricalSync marks sync_run failed when an error occurs and rethrows", async () => {
  const admin: GraphqlAdmin = {
    async graphql(): Promise<Response> {
      throw new Error("GraphQL Network Down");
    },
  };

  const syncStatusCalls: SyncStatusBody[] = [];

  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      if (path === "/internal/v1/ingestion/sync-status") {
        const payload = body as SyncStatusBody;
        syncStatusCalls.push(payload);
        const res: SyncStatusResponse = {
          sync_run_id: payload.sync_run_id ?? "sync_run_failed",
          status: payload.status,
        };
        return res as T;
      }
      throw new Error(`Unexpected path: ${path}`);
    },
  };

  await assert.rejects(
    async () => {
      await runHistoricalSync({
        admin,
        backend: backend as BackendClient,
        shopDomain: "test-store.myshopify.com",
      });
    },
    { message: "GraphQL Network Down" },
  );

  // Sync run must have been marked failed
  assert.ok(syncStatusCalls.length >= 2);
  const failedCall = syncStatusCalls[syncStatusCalls.length - 1];
  assert.equal(failedCall.status, "failed");
  assert.equal(failedCall.sync_run_id, "sync_run_failed");
  assert.equal(failedCall.error_code, "HARVEST_FAILED");
  assert.ok(failedCall.error_summary?.includes("GraphQL Network Down"));
});

test("runHistoricalSync preserves product and order progress counts when order stage fails", async () => {
  const syncStatusCalls: SyncStatusBody[] = [];
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      if (path === "/internal/v1/ingestion/sync-status") {
        const payload = body as SyncStatusBody;
        syncStatusCalls.push(payload);
        return {
          sync_run_id: payload.sync_run_id ?? "sync_run_partial",
          status: payload.status,
        } as T;
      }
      if (path === "/internal/v1/ingestion/products") {
        return { received: 10, written: 10, skipped_stale: 0 } as T;
      }
      if (path === "/internal/v1/ingestion/orders") {
        throw new Error("Order ingestion timeout");
      }
      throw new Error(`Unexpected path: ${path}`);
    },
  };

  const admin = createMockAdmin();

  await assert.rejects(
    () =>
      runHistoricalSync({
        admin,
        backend: backend as BackendClient,
        shopDomain: "test-store.myshopify.com",
      }),
    /Order ingestion timeout/,
  );

  const failedCall = syncStatusCalls.find((c) => c.status === "failed");
  assert.ok(failedCall);
  // Product count must be preserved in the failure report
  assert.equal(failedCall.records_processed, 10);
  assert.ok((failedCall.records_received ?? 0) >= 10);
});

function failingAdmin(message: string): GraphqlAdmin {
  return {
    async graphql(): Promise<Response> {
      throw new Error(message);
    },
  };
}

function recordingBackend(
  calls: SyncStatusBody[],
  rejectFailureReport = false,
): BackendClient {
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      const payload = body as SyncStatusBody;
      calls.push(payload);
      if (rejectFailureReport && payload.status === "failed") {
        throw new BackendError(422, '{"message":"error_summary too long"}');
      }
      const res: SyncStatusResponse = {
        sync_run_id: payload.sync_run_id ?? "sync_run_1",
        status: payload.status,
      };
      return res as T;
    },
  };
  return backend as BackendClient;
}

test("runHistoricalSync truncates error_summary to the ingestion limit", async () => {
  const calls: SyncStatusBody[] = [];

  await assert.rejects(
    runHistoricalSync({
      admin: failingAdmin("x".repeat(5000)),
      backend: recordingBackend(calls),
      shopDomain: "test-store.myshopify.com",
    }),
  );

  const summary = calls.at(-1)?.error_summary ?? "";
  assert.equal(Array.from(summary).length, INGESTION_LIMITS.errorSummaryLength);
});

test("runHistoricalSync truncates error_summary by code point without splitting surrogate pairs", async () => {
  const calls: SyncStatusBody[] = [];

  await assert.rejects(
    runHistoricalSync({
      admin: failingAdmin("a" + "😀".repeat(3000)),
      backend: recordingBackend(calls),
      shopDomain: "test-store.myshopify.com",
    }),
  );

  const summary = calls.at(-1)?.error_summary ?? "";
  assert.equal(Array.from(summary).length, INGESTION_LIMITS.errorSummaryLength);
  assert.equal(Buffer.from(summary, "utf8").toString("utf8"), summary);
});

test("runHistoricalSync logs a rejected failure report and still rethrows the original error", async (t) => {
  const logged = t.mock.method(console, "error", () => {});
  const calls: SyncStatusBody[] = [];

  await assert.rejects(
    runHistoricalSync({
      admin: failingAdmin("GraphQL Network Down"),
      backend: recordingBackend(calls, true),
      shopDomain: "test-store.myshopify.com",
    }),
    { message: "GraphQL Network Down" },
  );

  assert.equal(calls.at(-1)?.status, "failed");
  assert.equal(logged.mock.callCount(), 1);
  const logLine = String(logged.mock.calls[0]?.arguments[0]);
  assert.match(logLine, /test-store\.myshopify\.com/);
  assert.match(logLine, /sync_run_1/);
  assert.match(logLine, /422/);
});

function batchBackend(calls: SyncStatusBody[]): BackendClient {
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, _shopDomain: string, body: unknown): Promise<T> {
      if (path === "/internal/v1/ingestion/sync-status") {
        const payload = body as SyncStatusBody;
        calls.push(payload);
        const res: SyncStatusResponse = {
          sync_run_id: payload.sync_run_id ?? "sync_run_hb",
          status: payload.status,
        };
        return res as T;
      }
      const res: BatchResponse = { received: 1, written: 1, skipped_stale: 0 };
      return res as T;
    },
  };
  return backend as BackendClient;
}

function assertMonotonic(calls: SyncStatusBody[]): void {
  for (let i = 1; i < calls.length; i++) {
    assert.ok((calls[i].records_received ?? 0) >= (calls[i - 1].records_received ?? 0));
    assert.ok((calls[i].records_processed ?? 0) >= (calls[i - 1].records_processed ?? 0));
  }
}

test("runHistoricalSync sends running heartbeats with monotonic counters during the orders stage", async () => {
  const calls: SyncStatusBody[] = [];

  await runHistoricalSync({
    admin: createMockAdmin(),
    backend: batchBackend(calls),
    shopDomain: "test-store.myshopify.com",
    heartbeatMs: 0,
  });

  const orderStageBeat = calls.find(
    (call) => call.status === "running" && call.records_processed === 2,
  );
  assert.ok(orderStageBeat, "expected a heartbeat carrying products + orders counters");
  assert.equal(orderStageBeat.sync_run_id, "sync_run_hb");
  assert.equal(calls.at(-1)?.status, "completed");
  assertMonotonic(calls);
});

test("runHistoricalSync throttles heartbeats to the interval", async () => {
  const calls: SyncStatusBody[] = [];

  await runHistoricalSync({
    admin: createMockAdmin(),
    backend: batchBackend(calls),
    shopDomain: "test-store.myshopify.com",
    heartbeatMs: 60_000,
    now: () => 0,
  });

  assert.deepEqual(
    calls.map((call) => call.status),
    ["running", "running", "completed"],
  );
});

test("runHistoricalSync reports orders-stage counters when it fails after products", async () => {
  const calls: SyncStatusBody[] = [];
  const base = createMockAdmin();
  const admin: GraphqlAdmin = {
    async graphql(query: string): Promise<Response> {
      if (query.includes("OrderSync")) {
        throw new Error("orders page failed");
      }
      return base.graphql(query);
    },
  };

  await assert.rejects(
    runHistoricalSync({
      admin,
      backend: batchBackend(calls),
      shopDomain: "test-store.myshopify.com",
    }),
    { message: "orders page failed" },
  );

  const failed = calls.at(-1);
  assert.equal(failed?.status, "failed");
  assert.equal(failed?.records_received, 1);
  assertMonotonic(calls);
});

test("runHistoricalSync sends the order history notice only when starting the run", async () => {
  const calls: SyncStatusBody[] = [];

  await runHistoricalSync({
    admin: createMockAdmin(),
    backend: batchBackend(calls),
    shopDomain: "test-store.myshopify.com",
  });

  assert.equal(calls[0].history_notice, "Order history limited to the last 60 days.");
  assert.ok(calls.slice(1).every((call) => call.history_notice === undefined));
});
