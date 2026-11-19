import test from "node:test";
import assert from "node:assert/strict";
import { getSyncStatus } from "./getSyncStatus.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";

function createMockBackend(response: unknown): BackendClient {
  return {
    async getInternal<T>(path: string, shopDomain: string): Promise<T> {
      assert.equal(path, "/internal/v1/sync/status");
      assert.equal(shopDomain, "acme.myshopify.com");
      return response as T;
    },
  } as unknown as BackendClient;
}

test("getSyncStatus returns never_synced when shop has no sync runs and not running in-memory", async () => {
  const backend = createMockBackend({ sync_run: null });

  const result = await getSyncStatus({
    backend,
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
  });

  assert.equal(result.state, "never_synced");
  assert.equal(result.syncRun, null);
});

test("getSyncStatus returns syncing when an in-memory sync is running even if backend sync_run is null", async () => {
  const backend = createMockBackend({ sync_run: null });

  const result = await getSyncStatus({
    backend,
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => true,
  });

  assert.equal(result.state, "syncing");
  assert.equal(result.syncRun, null);
});

test("getSyncStatus returns completed when latest sync run is completed and not locally running", async () => {
  const backend = createMockBackend({
    sync_run: {
      id: "run-123",
      sync_type: "initial",
      status: "completed",
      records_received: 250,
      records_processed: 250,
      started_at: "2026-11-18T10:00:00.000Z",
      completed_at: "2026-11-18T10:05:00.000Z",
      error_code: null,
    },
  });

  const result = await getSyncStatus({
    backend,
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
  });

  assert.equal(result.state, "completed");
  assert.ok(result.syncRun);
  assert.equal(result.syncRun.id, "run-123");
  assert.equal(result.syncRun.status, "completed");
  assert.equal(result.syncRun.recordsReceived, 250);
  assert.equal(result.syncRun.recordsProcessed, 250);
  assert.equal(result.syncRun.completedAt, "2026-11-18T10:05:00.000Z");
});

test("getSyncStatus returns syncing when backend reports status as running", async () => {
  const backend = createMockBackend({
    sync_run: {
      id: "run-456",
      sync_type: "initial",
      status: "running",
      records_received: 50,
      records_processed: 25,
      started_at: "2026-11-18T11:00:00.000Z",
      completed_at: null,
      error_code: null,
    },
  });

  const result = await getSyncStatus({
    backend,
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
  });

  assert.equal(result.state, "syncing");
  assert.ok(result.syncRun);
  assert.equal(result.syncRun.id, "run-456");
  assert.equal(result.syncRun.status, "running");
});

test("getSyncStatus returns failed when backend reports failed status", async () => {
  const backend = createMockBackend({
    sync_run: {
      id: "run-789",
      sync_type: "initial",
      status: "failed",
      records_received: 10,
      records_processed: 5,
      started_at: "2026-11-18T12:00:00.000Z",
      completed_at: "2026-11-18T12:01:00.000Z",
      error_code: "rate_limited",
    },
  });

  const result = await getSyncStatus({
    backend,
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
  });

  assert.equal(result.state, "failed");
  assert.ok(result.syncRun);
  assert.equal(result.syncRun.errorCode, "rate_limited");
});

test("getSyncStatus propagates backend client rejection", async () => {
  const backend = {
    async getInternal(): Promise<never> {
      throw new Error("S2S network failure");
    },
  } as unknown as BackendClient;

  await assert.rejects(
    getSyncStatus({
      backend,
      shopDomain: "acme.myshopify.com",
    }),
    /S2S network failure/,
  );
});

const NOW = Date.parse("2026-11-19T12:00:00.000Z");

function runWithActivity(status: string, lastActivityAt: string | null): unknown {
  return {
    sync_run: {
      id: "run-stale",
      sync_type: "initial",
      status,
      records_received: 10,
      records_processed: 10,
      started_at: "2026-11-19T11:00:00.000000Z",
      completed_at: null,
      error_code: null,
      last_activity_at: lastActivityAt,
    },
  };
}

test("getSyncStatus returns stalled when a running run has no activity past the threshold and is not local", async () => {
  const result = await getSyncStatus({
    backend: createMockBackend(runWithActivity("running", "2026-11-19T11:49:59.000000Z")),
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
    now: () => NOW,
  });

  assert.equal(result.state, "stalled");
  assert.equal(result.syncRun?.lastActivityAt, "2026-11-19T11:49:59.000000Z");
});

test("getSyncStatus treats a pending run past the threshold as stalled", async () => {
  const result = await getSyncStatus({
    backend: createMockBackend(runWithActivity("pending", "2026-11-19T11:00:00.000000Z")),
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
    now: () => NOW,
  });

  assert.equal(result.state, "stalled");
});

test("getSyncStatus keeps syncing at exactly the stale threshold", async () => {
  const result = await getSyncStatus({
    backend: createMockBackend(runWithActivity("running", "2026-11-19T11:50:00.000000Z")),
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
    now: () => NOW,
  });

  assert.equal(result.state, "syncing");
});

test("getSyncStatus keeps syncing for an old run that is running in this process", async () => {
  const result = await getSyncStatus({
    backend: createMockBackend(runWithActivity("running", "2026-11-19T10:00:00.000000Z")),
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => true,
    now: () => NOW,
  });

  assert.equal(result.state, "syncing");
});

test("getSyncStatus keeps syncing when last_activity_at is missing", async () => {
  const result = await getSyncStatus({
    backend: createMockBackend(runWithActivity("running", null)),
    shopDomain: "acme.myshopify.com",
    isLocalRunning: () => false,
    now: () => NOW,
  });

  assert.equal(result.state, "syncing");
});
