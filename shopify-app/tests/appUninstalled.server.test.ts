import { test } from "node:test";
import assert from "node:assert/strict";
import { handleAppUninstalled } from "../app/services/appUninstalled.server.ts";
import { BackendError } from "../app/services/backendClient.server.ts";

const webhook = {
  shop: "acme-store.myshopify.com",
  triggeredAt: "2026-11-18T09:51:31.386Z",
};

function harness(laravel: () => Promise<{ status: string }>): {
  deps: Parameters<typeof handleAppUninstalled>[1];
  calls: string[];
} {
  const calls: string[] = [];
  return {
    calls,
    deps: {
      uninstall: async (shop, triggeredAt) => {
        calls.push(`laravel:${shop}:${triggeredAt}`);
        return { shop_domain: shop, ...(await laravel()) };
      },
      deleteSessions: async (shop) => {
        calls.push(`delete-sessions:${shop}`);
      },
    },
  };
}

test("deletes sessions after Laravel records the uninstall", async () => {
  const { deps, calls } = harness(async () => ({ status: "uninstalled" }));

  assert.equal(await handleAppUninstalled(webhook, deps), 200);
  assert.deepEqual(calls, [
    `laravel:${webhook.shop}:${webhook.triggeredAt}`,
    `delete-sessions:${webhook.shop}`,
  ]);
});

test("keeps sessions when Laravel ignores a late delivery after reinstall", async () => {
  const { deps, calls } = harness(async () => ({ status: "active" }));

  assert.equal(await handleAppUninstalled(webhook, deps), 200);
  assert.deepEqual(calls, [`laravel:${webhook.shop}:${webhook.triggeredAt}`]);
});

test("deletes sessions and acknowledges when Laravel never knew the shop", async () => {
  const { deps, calls } = harness(async () => {
    throw new BackendError(404, '{"error":"Unknown shop."}');
  });

  assert.equal(await handleAppUninstalled(webhook, deps), 200);
  assert.deepEqual(calls, [
    `laravel:${webhook.shop}:${webhook.triggeredAt}`,
    `delete-sessions:${webhook.shop}`,
  ]);
});

test("keeps sessions and asks Shopify to retry when Laravel fails", async () => {
  for (const failure of [
    new BackendError(503, "down"),
    new TypeError("fetch failed"),
  ]) {
    const { deps, calls } = harness(async () => {
      throw failure;
    });

    assert.equal(await handleAppUninstalled(webhook, deps), 500);
    assert.deepEqual(calls, [`laravel:${webhook.shop}:${webhook.triggeredAt}`]);
  }
});
