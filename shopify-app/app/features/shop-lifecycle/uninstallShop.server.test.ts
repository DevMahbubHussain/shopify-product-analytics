import { test } from "node:test";
import assert from "node:assert/strict";
import { uninstallShop } from "./uninstallShop.server.ts";

const shopDomain = "acme-store.myshopify.com";

interface PostedCall {
  path: string;
  shopDomain: string;
  body: object;
}

function recordingClient(): {
  postInternal: <TResponse>(
    path: string,
    shopDomain: string,
    body: object,
  ) => Promise<TResponse>;
  calls: PostedCall[];
} {
  const calls: PostedCall[] = [];
  return {
    calls,
    postInternal: async <TResponse>(
      path: string,
      domain: string,
      body: object,
    ): Promise<TResponse> => {
      calls.push({ path, shopDomain: domain, body });
      return { shop_domain: domain, status: "uninstalled" } as TResponse;
    },
  };
}

function uninstalledAtOf(call: PostedCall | undefined): string {
  return String((call?.body as { uninstalled_at?: unknown }).uninstalled_at);
}

test("posts the trigger time to the shop uninstall endpoint", async () => {
  const client = recordingClient();

  const result = await uninstallShop(
    shopDomain,
    "2026-11-18T02:30:00.000Z",
    client,
  );

  assert.deepEqual(client.calls, [
    {
      path: "/internal/v1/ingestion/shop-uninstall",
      shopDomain,
      body: { uninstalled_at: "2026-11-18T02:30:00.000Z" },
    },
  ]);
  assert.deepEqual(result, { shop_domain: shopDomain, status: "uninstalled" });
});

test("normalizes the trigger time to ISO 8601 UTC", async () => {
  const client = recordingClient();

  await uninstallShop(shopDomain, "2026-11-18T08:30:00+06:00", client);

  assert.equal(uninstalledAtOf(client.calls[0]), "2026-11-18T02:30:00.000Z");
});

test("falls back to the current time when the trigger time is missing or invalid", async () => {
  for (const triggeredAt of [null, "", "not-a-date"]) {
    const client = recordingClient();
    const before = Date.now();

    await uninstallShop(shopDomain, triggeredAt, client);

    const sent = Date.parse(uninstalledAtOf(client.calls[0]));
    assert.ok(
      sent >= before - 1000 && sent <= Date.now() + 1000,
      `expected current time for ${String(triggeredAt)}`,
    );
  }
});

test("propagates a backend failure so the webhook can be retried", async () => {
  const failingClient = {
    postInternal: async <TResponse>(): Promise<TResponse> => {
      throw new Error("Backend S2S call failed (503): down");
    },
  };

  await assert.rejects(
    uninstallShop(shopDomain, "2026-11-18T02:30:00.000Z", failingClient),
    /\(503\)/,
  );
});
