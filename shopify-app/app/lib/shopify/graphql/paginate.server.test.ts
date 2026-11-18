import { test } from "node:test";
import assert from "node:assert/strict";
import { paginate, type Connection } from "./paginate.server.ts";
import type { GraphqlAdmin } from "./shopifyGraphql.server.ts";

const QUERY =
  "#graphql\n query Products($first: Int!, $after: String) { products(first: $first, after: $after) { nodes { id } pageInfo { hasNextPage endCursor } } }";

interface ProductNode {
  id: string;
}
interface ProductsData {
  products: Connection<ProductNode>;
}

function page(
  ids: number[],
  endCursor: string | null,
  hasNextPage: boolean,
  currentlyAvailable = 1000,
): () => Response {
  return () =>
    Response.json({
      data: {
        products: {
          nodes: ids.map((id) => ({ id: `gid://shopify/Product/${id}` })),
          pageInfo: { hasNextPage, endCursor },
        },
      },
      extensions: {
        cost: {
          requestedQueryCost: 52,
          actualQueryCost: 12,
          throttleStatus: {
            maximumAvailable: 1000,
            currentlyAvailable,
            restoreRate: 50,
          },
        },
      },
    });
}

function scriptedAdmin(steps: (() => Response)[]): {
  admin: GraphqlAdmin;
  variables: Record<string, unknown>[];
} {
  const variables: Record<string, unknown>[] = [];
  return {
    variables,
    admin: {
      graphql: async (_query, options) => {
        variables.push(options?.variables ?? {});
        const step = steps[variables.length - 1];
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

const selectProducts = (data: ProductsData) => data.products;

async function collect(
  pages: AsyncGenerator<ProductNode[]>,
): Promise<string[][]> {
  const result: string[][] = [];
  for await (const nodes of pages) {
    result.push(nodes.map((node) => node.id));
  }
  return result;
}

test("follows the cursor until the last page", async () => {
  const { admin, variables } = scriptedAdmin([
    page([1, 2], "c1", true),
    page([3, 4], "c2", true),
    page([5], "c3", false),
  ]);
  const { sleep } = recordingSleep();

  const ids = await collect(
    paginate(admin, QUERY, { query: "status:active" }, selectProducts, {
      sleep,
    }),
  );

  assert.deepEqual(ids, [
    ["gid://shopify/Product/1", "gid://shopify/Product/2"],
    ["gid://shopify/Product/3", "gid://shopify/Product/4"],
    ["gid://shopify/Product/5"],
  ]);
  assert.deepEqual(variables, [
    { query: "status:active", first: 50, after: null },
    { query: "status:active", first: 50, after: "c1" },
    { query: "status:active", first: 50, after: "c2" },
  ]);
});

test("makes one call when the first page is the last", async () => {
  const { admin, variables } = scriptedAdmin([page([1], null, false)]);
  const { sleep, waits } = recordingSleep();

  const ids = await collect(
    paginate(admin, QUERY, {}, selectProducts, { sleep }),
  );

  assert.deepEqual(ids, [["gid://shopify/Product/1"]]);
  assert.equal(variables.length, 1);
  assert.deepEqual(waits, []);
});

test("uses the requested page size", async () => {
  const { admin, variables } = scriptedAdmin([page([], null, false)]);
  const { sleep } = recordingSleep();

  await collect(
    paginate(admin, QUERY, {}, selectProducts, { sleep, pageSize: 10 }),
  );

  assert.equal(variables[0]?.first, 10);
});

test("waits for the cost bucket before fetching the next page", async () => {
  const { admin } = scriptedAdmin([
    page([1], "c1", true, 2),
    page([2], "c2", false, 900),
  ]);
  const { sleep, waits } = recordingSleep();

  await collect(paginate(admin, QUERY, {}, selectProducts, { sleep }));

  assert.deepEqual(waits, [1000]);
});

test("stops with an error when the cursor does not advance", async () => {
  const { admin, variables } = scriptedAdmin([
    page([1], "same", true),
    page([2], "same", true),
  ]);
  const { sleep } = recordingSleep();

  await assert.rejects(
    collect(paginate(admin, QUERY, {}, selectProducts, { sleep })),
    /cursor did not advance/,
  );
  assert.equal(variables.length, 2);
});

test("stops with an error when a next page has no cursor", async () => {
  const { admin } = scriptedAdmin([page([1], null, true)]);
  const { sleep } = recordingSleep();

  await assert.rejects(
    collect(paginate(admin, QUERY, {}, selectProducts, { sleep })),
    /cursor did not advance/,
  );
});
