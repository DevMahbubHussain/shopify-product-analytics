import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../../lib/backend/backendClient.server.ts";
import { getSubscriptionStatus } from "./getSubscription.server.ts";

describe("getSubscriptionStatus", () => {
  const secret = "test-secret-key-12345678901234567890";

  it("fetches subscription from backend via S2S", async () => {
    let capturedUrl = "";
    let capturedShop = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      const headers = new Headers(init?.headers);
      capturedShop = headers.get("X-App-Shop-Domain") ?? "";

      return new Response(
        JSON.stringify({
          plan_key: "pro",
          status: "ACTIVE",
          max_analytics_days: 365,
          can_export_csv: true,
          trial_ends_at: null,
          current_period_end: null,
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const status = await getSubscriptionStatus(backend, "test.myshopify.com");

      assert.match(capturedUrl, /\/internal\/v1\/subscription$/);
      assert.equal(capturedShop, "test.myshopify.com");
      assert.equal(status.plan_key, "pro");
      assert.equal(status.status, "ACTIVE");
      assert.equal(status.max_analytics_days, 365);
      assert.equal(status.can_export_csv, true);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("gracefully falls back to free tier on backend error", async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async () => {
      return new Response("Internal Server Error", { status: 500 });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const status = await getSubscriptionStatus(backend, "test.myshopify.com");

      assert.equal(status.plan_key, "free");
      assert.equal(status.status, null);
      assert.equal(status.max_analytics_days, 30);
      assert.equal(status.can_export_csv, false);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});
