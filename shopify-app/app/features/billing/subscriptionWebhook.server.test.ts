import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { handleAppSubscriptionUpdate } from "./subscriptionWebhook.server.ts";

describe("handleAppSubscriptionUpdate", () => {
  it("parses valid webhook payload and forwards to backend ingestion", async () => {
    let capturedShop = "";
    let capturedPayload: unknown;

    const rawBody = JSON.stringify({
      app_subscription: {
        admin_graphql_api_id: "gid://shopify/AppSubscription/998877",
        name: "Pro Plan",
        status: "ACTIVE",
        current_period_end: "2026-12-20T00:00:00Z",
      },
    });

    const status = await handleAppSubscriptionUpdate(
      { shop: "billing-store.myshopify.com", rawBody },
      async (shop, payload) => {
        capturedShop = shop;
        capturedPayload = payload;
        return {
          status: "recorded",
          plan_key: "pro",
          subscription_status: "ACTIVE",
        };
      },
    );

    assert.equal(status, 200);
    assert.equal(capturedShop, "billing-store.myshopify.com");
    assert.deepEqual(capturedPayload, {
      shopify_subscription_gid: "gid://shopify/AppSubscription/998877",
      plan_key: "pro",
      status: "ACTIVE",
      current_period_end: "2026-12-20T00:00:00Z",
    });
  });

  it("handles malformed payload gracefully without throwing", async () => {
    let ingestCalled = false;

    const status = await handleAppSubscriptionUpdate(
      { shop: "billing-store.myshopify.com", rawBody: "{}" },
      async () => {
        ingestCalled = true;
        return {
          status: "recorded",
          plan_key: "pro",
          subscription_status: "ACTIVE",
        };
      },
    );

    assert.equal(status, 200);
    assert.equal(ingestCalled, false);
  });

  it("returns 500 when backend ingestion fails so Shopify will retry", async () => {
    const rawBody = JSON.stringify({
      app_subscription: {
        admin_graphql_api_id: "gid://shopify/AppSubscription/998877",
        name: "Pro Plan",
        status: "ACTIVE",
      },
    });

    const status = await handleAppSubscriptionUpdate(
      { shop: "billing-store.myshopify.com", rawBody },
      async () => {
        throw new Error("Backend S2S error");
      },
    );

    assert.equal(status, 500);
  });
});
