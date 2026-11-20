import { describe, it } from "node:test";
import assert from "node:assert/strict";
import {
  requestAppSubscription,
  type AppSubscriptionCreateResponse,
} from "./billingClient.server.ts";
import type { GraphqlAdmin } from "../shopify/graphql/shopifyGraphql.server.ts";

describe("requestAppSubscription", () => {
  it("creates an app subscription mutation with verified parameters and returns confirmationUrl", async () => {
    let capturedQuery = "";
    let capturedVariables: Record<string, unknown> | undefined;

    const mockAdmin: GraphqlAdmin = {
      graphql: async (query, options) => {
        capturedQuery = query;
        capturedVariables = options?.variables;

        const payload: { data: AppSubscriptionCreateResponse } = {
          data: {
            appSubscriptionCreate: {
              appSubscription: {
                id: "gid://shopify/AppSubscription/12345",
                name: "Pro Plan",
                status: "PENDING",
              },
              confirmationUrl: "https://admin.shopify.com/store/test/charges/confirm",
              userErrors: [],
            },
          },
        };

        return new Response(JSON.stringify(payload), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      },
    };

    const result = await requestAppSubscription(
      mockAdmin,
      "pro",
      "https://example.com/app/pricing?confirmed=true",
      true,
    );

    assert.equal(
      result.confirmationUrl,
      "https://admin.shopify.com/store/test/charges/confirm",
    );
    assert.equal(result.appSubscription?.id, "gid://shopify/AppSubscription/12345");
    assert.ok(capturedQuery.includes("mutation AppSubscriptionCreate"));
    assert.equal(capturedVariables?.name, "Pro Plan");
    assert.equal(capturedVariables?.trialDays, 7);
    assert.equal(capturedVariables?.test, true);
    assert.equal(
      capturedVariables?.returnUrl,
      "https://example.com/app/pricing?confirmed=true",
    );
  });

  it("throws when Shopify returns userErrors", async () => {
    const mockAdmin: GraphqlAdmin = {
      graphql: async () => {
        const payload: { data: AppSubscriptionCreateResponse } = {
          data: {
            appSubscriptionCreate: {
              appSubscription: null,
              confirmationUrl: null,
              userErrors: [
                {
                  field: ["lineItems"],
                  message: "Recurring charge already exists.",
                },
              ],
            },
          },
        };

        return new Response(JSON.stringify(payload), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        });
      },
    };

    await assert.rejects(
      requestAppSubscription(
        mockAdmin,
        "pro",
        "https://example.com/return",
      ),
      {
        message: "Recurring charge already exists.",
      },
    );
  });

  it("throws when requesting an unknown planKey", async () => {
    const mockAdmin: GraphqlAdmin = {
      graphql: async () => new Response(JSON.stringify({ data: {} })),
    };

    await assert.rejects(
      requestAppSubscription(
        mockAdmin,
        "unknown_plan",
        "https://example.com/return",
      ),
      {
        message: "Unknown plan key: unknown_plan",
      },
    );
  });
});
