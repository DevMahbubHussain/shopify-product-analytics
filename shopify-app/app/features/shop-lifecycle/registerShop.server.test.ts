import { test } from "node:test";
import assert from "node:assert/strict";
import { registerShop } from "./registerShop.server.ts";

const shopDomain = "acme-store.myshopify.com";

interface PostedCall {
  path: string;
  shopDomain: string;
  body: object;
}

function adminReturning(payload: unknown): {
  graphql: (query: string) => Promise<Response>;
  queries: string[];
} {
  const queries: string[] = [];
  return {
    queries,
    graphql: async (query: string): Promise<Response> => {
      queries.push(query);
      return Response.json(payload);
    },
  };
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
      return { shop_domain: domain, status: "active" } as TResponse;
    },
  };
}

test("posts the shop GID, currency and IANA timezone to the shop ingestion endpoint", async () => {
  const admin = adminReturning({
    data: {
      shop: {
        id: "gid://shopify/Shop/123",
        currencyCode: "USD",
        ianaTimezone: "America/New_York",
      },
    },
  });
  const client = recordingClient();

  const result = await registerShop(shopDomain, admin, client);

  assert.match(
    admin.queries[0] ?? "",
    /shop\s*\{\s*id\s+currencyCode\s+ianaTimezone\s*\}/,
  );
  assert.deepEqual(client.calls, [
    {
      path: "/internal/v1/ingestion/shop",
      shopDomain,
      body: {
        shopify_shop_gid: "gid://shopify/Shop/123",
        currency_code: "USD",
        timezone: "America/New_York",
      },
    },
  ]);
  assert.deepEqual(result, { shop_domain: shopDomain, status: "active" });
});

test("throws and posts nothing when Shopify returns GraphQL errors", async () => {
  const admin = adminReturning({
    errors: [{ message: "Throttled" }],
  });
  const client = recordingClient();

  await assert.rejects(registerShop(shopDomain, admin, client), /Throttled/);
  assert.equal(client.calls.length, 0);
});

test("throws and posts nothing when the shop is missing from the response", async () => {
  const admin = adminReturning({ data: { shop: null } });
  const client = recordingClient();

  await assert.rejects(registerShop(shopDomain, admin, client), /shop/i);
  assert.equal(client.calls.length, 0);
});

test("propagates a backend failure so the install can be retried", async () => {
  const admin = adminReturning({
    data: {
      shop: {
        id: "gid://shopify/Shop/123",
        currencyCode: "USD",
        ianaTimezone: "UTC",
      },
    },
  });
  const failingClient = {
    postInternal: async <TResponse>(): Promise<TResponse> => {
      throw new Error("Backend S2S call failed (503): down");
    },
  };

  await assert.rejects(
    registerShop(shopDomain, admin, failingClient),
    /\(503\)/,
  );
});
