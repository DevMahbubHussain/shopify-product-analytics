import test from "node:test";
import assert from "node:assert/strict";
import {
  triggerHistoricalSync,
  isSyncRunning,
  _resetRunningSyncsForTest,
} from "./syncTrigger.server.ts";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";

function createDummyAdmin(): GraphqlAdmin {
  return {
    async graphql(): Promise<Response> {
      return new Response(JSON.stringify({ data: {} }), { status: 200 });
    },
  };
}

function createDummyBackend(): BackendClient {
  return {
    async postInternal<T>(): Promise<T> {
      return {} as T;
    },
  } as unknown as BackendClient;
}

test("triggerHistoricalSync launches sync in background and reports started: true", async () => {
  _resetRunningSyncsForTest();

  let syncExecutionCount = 0;
  const runner = async () => {
    syncExecutionCount++;
    // Simulate async work
    await new Promise((resolve) => setTimeout(resolve, 50));
  };

  const result = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-a.myshopify.com",
    syncRunner: runner,
  });

  assert.equal(result.started, true);
  assert.equal(isSyncRunning("store-a.myshopify.com"), true);

  // Wait for background execution to complete
  await new Promise((resolve) => setTimeout(resolve, 100));

  assert.equal(syncExecutionCount, 1);
  assert.equal(isSyncRunning("store-a.myshopify.com"), false);
});

test("triggerHistoricalSync rejects overlapping syncs for the same shop", async () => {
  _resetRunningSyncsForTest();

  let executionCount = 0;
  const runner = async () => {
    executionCount++;
    await new Promise((resolve) => setTimeout(resolve, 100));
  };

  const first = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-overlap.myshopify.com",
    syncRunner: runner,
  });

  const second = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-overlap.myshopify.com",
    syncRunner: runner,
  });

  assert.equal(first.started, true);
  assert.equal(second.started, false);
  assert.equal(second.reason, "already_running");

  // Wait for background execution to complete
  await new Promise((resolve) => setTimeout(resolve, 150));
  assert.equal(executionCount, 1);
  assert.equal(isSyncRunning("store-overlap.myshopify.com"), false);
});

test("triggerHistoricalSync allows different shops to run concurrently", async () => {
  _resetRunningSyncsForTest();

  const executedShops: string[] = [];
  const runner = async ({ shopDomain }: { shopDomain: string }) => {
    executedShops.push(shopDomain);
    await new Promise((resolve) => setTimeout(resolve, 50));
  };

  const shop1 = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-one.myshopify.com",
    syncRunner: runner,
  });

  const shop2 = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-two.myshopify.com",
    syncRunner: runner,
  });

  assert.equal(shop1.started, true);
  assert.equal(shop2.started, true);

  await new Promise((resolve) => setTimeout(resolve, 100));
  assert.deepEqual(executedShops.sort(), [
    "store-one.myshopify.com",
    "store-two.myshopify.com",
  ]);
});

test("triggerHistoricalSync releases lock even when background runner throws", async () => {
  _resetRunningSyncsForTest();

  const runner = async () => {
    throw new Error("Simulated background crash");
  };

  const result = triggerHistoricalSync({
    admin: createDummyAdmin(),
    backend: createDummyBackend(),
    shopDomain: "store-fail.myshopify.com",
    syncRunner: runner,
    onError: (err) => {
      assert.equal(err.message, "Simulated background crash");
    },
  });

  assert.equal(result.started, true);

  // Wait for background catch
  await new Promise((resolve) => setTimeout(resolve, 50));
  assert.equal(isSyncRunning("store-fail.myshopify.com"), false);
});

test("triggerHistoricalSync uses getAdmin to refresh offline admin client inside background runner", async () => {
  _resetRunningSyncsForTest();

  let adminUsed: GraphqlAdmin | null = null;
  const initialAdmin = createDummyAdmin();
  const refreshedAdmin = createDummyAdmin();

  const runner = async ({ admin }: { admin: GraphqlAdmin }) => {
    adminUsed = admin;
  };

  const result = triggerHistoricalSync({
    admin: initialAdmin,
    backend: createDummyBackend(),
    shopDomain: "store-refresh.myshopify.com",
    getAdmin: async (shop) => {
      assert.equal(shop, "store-refresh.myshopify.com");
      return refreshedAdmin;
    },
    syncRunner: runner,
  });

  assert.equal(result.started, true);

  await new Promise((resolve) => setTimeout(resolve, 50));
  assert.equal(adminUsed, refreshedAdmin);
  assert.equal(isSyncRunning("store-refresh.myshopify.com"), false);
});
