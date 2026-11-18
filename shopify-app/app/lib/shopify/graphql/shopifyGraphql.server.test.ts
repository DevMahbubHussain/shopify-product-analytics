import { test } from "node:test";
import assert from "node:assert/strict";
import {
  shopifyQuery,
  ShopifyGraphqlError,
  type GraphqlAdmin,
} from "./shopifyGraphql.server.ts";

const QUERY = "#graphql\n query Probe { shop { id } }";

function costExtension(currentlyAvailable: number, requested = 52) {
  return {
    cost: {
      requestedQueryCost: requested,
      actualQueryCost: null,
      throttleStatus: {
        maximumAvailable: 1000,
        currentlyAvailable,
        restoreRate: 50,
      },
    },
  };
}

/** Same shape the Shopify library throws for GraphQL errors on HTTP 200. */
function graphqlQueryError(
  graphQLErrors: { message: string; extensions?: { code?: string } }[],
  extensions: object = costExtension(1000),
): Error {
  return Object.assign(new Error(graphQLErrors[0]?.message ?? "failed"), {
    name: "GraphqlQueryError",
    body: { errors: { message: "GraphQL Client", graphQLErrors }, extensions },
  });
}

const throttled = () =>
  graphqlQueryError(
    [{ message: "Throttled", extensions: { code: "THROTTLED" } }],
    costExtension(2),
  );

function scriptedAdmin(steps: (() => Response)[]): {
  admin: GraphqlAdmin;
  calls: { query: string; variables: unknown }[];
} {
  const calls: { query: string; variables: unknown }[] = [];
  return {
    calls,
    admin: {
      graphql: async (query, options) => {
        calls.push({ query, variables: options?.variables });
        const step = steps[calls.length - 1];
        if (!step) throw new Error("unexpected extra call");
        return step();
      },
    },
  };
}

function recordingSleep(): {
  sleep: (ms: number) => Promise<void>;
  waits: number[];
} {
  const waits: number[] = [];
  return { waits, sleep: async (ms) => void waits.push(ms) };
}

test("returns data and the parsed cost", async () => {
  const { admin, calls } = scriptedAdmin([
    () =>
      Response.json({
        data: { shop: { id: "gid://shopify/Shop/1" } },
        extensions: costExtension(948),
      }),
  ]);
  const { sleep, waits } = recordingSleep();

  const result = await shopifyQuery<{ shop: { id: string } }>(
    admin,
    QUERY,
    { first: 50 },
    { sleep },
  );

  assert.deepEqual(result.data, { shop: { id: "gid://shopify/Shop/1" } });
  assert.equal(result.cost?.throttleStatus.currentlyAvailable, 948);
  assert.deepEqual(calls, [{ query: QUERY, variables: { first: 50 } }]);
  assert.deepEqual(waits, []);
});

test("maps GraphQL errors to ShopifyGraphqlError without retrying", async () => {
  const { admin, calls } = scriptedAdmin([
    () => {
      throw graphqlQueryError([
        {
          message: "Field 'nope' doesn't exist on type 'Shop'",
          extensions: { code: "undefinedField" },
        },
      ]);
    },
  ]);
  const { sleep } = recordingSleep();

  await assert.rejects(
    shopifyQuery(admin, QUERY, {}, { sleep }),
    (error: unknown) => {
      assert.ok(error instanceof ShopifyGraphqlError);
      assert.deepEqual(error.codes, ["undefinedField"]);
      assert.match(error.message, /Field 'nope' doesn't exist/);
      return true;
    },
  );
  assert.equal(calls.length, 1);
});

test("waits for the bucket to refill and retries when throttled", async () => {
  const { admin, calls } = scriptedAdmin([
    () => {
      throw throttled();
    },
    () =>
      Response.json({
        data: { shop: { id: "gid://shopify/Shop/1" } },
        extensions: costExtension(900),
      }),
  ]);
  const { sleep, waits } = recordingSleep();

  const result = await shopifyQuery<{ shop: { id: string } }>(
    admin,
    QUERY,
    {},
    { sleep },
  );

  assert.equal(result.data.shop.id, "gid://shopify/Shop/1");
  assert.equal(calls.length, 2);
  assert.deepEqual(waits, [1000]);
});

test("gives up after the maximum number of attempts", async () => {
  const { admin, calls } = scriptedAdmin([
    () => {
      throw throttled();
    },
    () => {
      throw throttled();
    },
    () => {
      throw throttled();
    },
  ]);
  const { sleep, waits } = recordingSleep();

  await assert.rejects(
    shopifyQuery(admin, QUERY, {}, { sleep, maxAttempts: 3 }),
    (error: unknown) => {
      assert.ok(error instanceof ShopifyGraphqlError);
      assert.deepEqual(error.codes, ["THROTTLED"]);
      return true;
    },
  );
  assert.equal(calls.length, 3);
  assert.equal(waits.length, 2);
});

test("rethrows errors that are not GraphQL errors", async () => {
  const networkError = new TypeError("fetch failed");
  const { admin } = scriptedAdmin([
    () => {
      throw networkError;
    },
  ]);
  const { sleep } = recordingSleep();

  await assert.rejects(
    shopifyQuery(admin, QUERY, {}, { sleep }),
    (error: unknown) => error === networkError,
  );
});

test("rejects a successful response without data", async () => {
  const { admin } = scriptedAdmin([
    () => Response.json({ extensions: costExtension(1000) }),
  ]);
  const { sleep } = recordingSleep();

  await assert.rejects(
    shopifyQuery(admin, QUERY, {}, { sleep }),
    ShopifyGraphqlError,
  );
});
