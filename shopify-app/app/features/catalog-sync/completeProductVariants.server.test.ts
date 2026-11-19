import test from "node:test";
import assert from "node:assert/strict";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import type {
  ShopifyProductNode,
  ShopifyVariantNode,
} from "./productsQuery.server.ts";
import {
  completeProductVariants,
  ProductVanishedError,
} from "./completeProductVariants.server.ts";

const COST = {
  cost: {
    requestedQueryCost: 10,
    actualQueryCost: 10,
    throttleStatus: { maximumAvailable: 2000, currentlyAvailable: 2000, restoreRate: 100 },
  },
};

interface RecordedCall {
  query: string;
  variables: Record<string, unknown>;
}

function scriptedAdmin(pages: unknown[]): { admin: GraphqlAdmin; calls: RecordedCall[] } {
  const calls: RecordedCall[] = [];
  const admin: GraphqlAdmin = {
    async graphql(query, options) {
      calls.push({ query, variables: options?.variables ?? {} });
      const data = pages[calls.length - 1];
      if (data === undefined) {
        throw new Error(`Unexpected call #${calls.length}`);
      }
      return Response.json({ data, extensions: COST });
    },
  };
  return { admin, calls };
}

function variant(i: number): ShopifyVariantNode {
  return { id: `gid://shopify/ProductVariant/${i}`, title: `V${i}`, sku: `SKU-${i}`, price: "1.00" };
}

function product(variantCount: number, hasNextPage: boolean): ShopifyProductNode {
  return {
    id: "gid://shopify/Product/1",
    title: "P1",
    handle: "p1",
    vendor: "Acme",
    productType: "Shoes",
    status: "ACTIVE",
    publishedAt: null,
    updatedAt: "2026-11-02T10:00:00Z",
    featuredMedia: null,
    variants: {
      nodes: Array.from({ length: variantCount }, (_, i) => variant(i + 1)),
      pageInfo: { hasNextPage },
    },
  };
}

function variantsPage(from: number, to: number, hasNextPage: boolean, endCursor: string | null): unknown {
  return {
    product: {
      variants: {
        nodes: Array.from({ length: to - from + 1 }, (_, i) => variant(from + i)),
        pageInfo: { hasNextPage, endCursor },
      },
    },
  };
}

test("completeProductVariants returns a complete product without querying", async () => {
  const { admin, calls } = scriptedAdmin([]);
  const node = product(3, false);

  const result = await completeProductVariants(admin, node);

  assert.equal(result, node);
  assert.equal(calls.length, 0);
});

test("completeProductVariants refetches every variant across pages from the product root", async () => {
  const { admin, calls } = scriptedAdmin([
    variantsPage(1, 250, true, "cursor-250"),
    variantsPage(251, 301, false, "cursor-301"),
  ]);

  const result = await completeProductVariants(admin, product(100, true));

  assert.equal(result.variants.nodes.length, 301);
  assert.equal(result.variants.pageInfo.hasNextPage, false);
  assert.equal(new Set(result.variants.nodes.map((v) => v.id)).size, 301);
  assert.equal(result.title, "P1");

  assert.equal(calls.length, 2);
  assert.match(calls[0].query, /product\(id: \$id\)/);
  assert.deepEqual(calls[0].variables, { id: "gid://shopify/Product/1", first: 250, after: null });
  assert.deepEqual(calls[1].variables, { id: "gid://shopify/Product/1", first: 250, after: "cursor-250" });
});

test("completeProductVariants throws ProductVanishedError when the product was deleted mid-sync", async () => {
  const { admin } = scriptedAdmin([{ product: null }]);

  await assert.rejects(
    completeProductVariants(admin, product(100, true)),
    (error: unknown) => {
      assert.ok(error instanceof ProductVanishedError);
      assert.equal(error.productGid, "gid://shopify/Product/1");
      return true;
    },
  );
});

test("completeProductVariants fails when the variants cursor does not advance", async () => {
  const { admin } = scriptedAdmin([
    variantsPage(1, 250, true, "stuck"),
    variantsPage(251, 300, true, "stuck"),
  ]);

  await assert.rejects(completeProductVariants(admin, product(100, true)), /cursor did not advance/);
});
