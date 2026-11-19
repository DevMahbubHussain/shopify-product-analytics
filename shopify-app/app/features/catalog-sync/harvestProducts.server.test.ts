import test from "node:test";
import assert from "node:assert/strict";
import { harvestProducts } from "./harvestProducts.server.ts";
import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import type { ProductBatchBody, BatchResponse } from "../../lib/backend/ingestionContract.server.ts";
import type { ShopifyProductNode } from "./productsQuery.server.ts";

function createMockAdmin(pages: Array<{ nodes: ShopifyProductNode[]; hasNextPage: boolean; endCursor: string | null }>): GraphqlAdmin {
  let callIndex = 0;
  return {
    async graphql(): Promise<Response> {
      const page = pages[callIndex++] ?? { nodes: [], hasNextPage: false, endCursor: null };
      const body = {
        data: {
          products: {
            nodes: page.nodes,
            pageInfo: {
              hasNextPage: page.hasNextPage,
              endCursor: page.endCursor,
            },
          },
        },
        extensions: {
          cost: {
            requestedQueryCost: 50,
            actualQueryCost: 50,
            throttleStatus: {
              maximumAvailable: 1000,
              currentlyAvailable: 1000,
              restoreRate: 50,
            },
          },
        },
      };
      return new Response(JSON.stringify(body), { status: 200, headers: { "Content-Type": "application/json" } });
    },
  };
}

function createSampleProductNode(id: string, title = "Sample Product"): ShopifyProductNode {
  return {
    id: `gid://shopify/Product/${id}`,
    title,
    handle: `sample-product-${id}`,
    vendor: "Acme",
    productType: "Shoes",
    status: "ACTIVE",
    featuredMedia: null,
    publishedAt: "2026-11-01T10:00:00Z",
    updatedAt: "2026-11-02T10:00:00Z",
    variants: {
      nodes: [
        {
          id: `gid://shopify/ProductVariant/${id}1`,
          title: "Default",
          sku: `SKU-${id}`,
          price: "49.99",
        },
      ],
      pageInfo: {
        hasNextPage: false,
      },
    },
  };
}

test("harvestProducts streams pages of products to Laravel ingestion and tracks counts", async () => {
  const admin = createMockAdmin([
    {
      nodes: [createSampleProductNode("1", "P1"), createSampleProductNode("2", "P2")],
      hasNextPage: true,
      endCursor: "cursor_1",
    },
    {
      nodes: [createSampleProductNode("3", "P3")],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  const postedBatches: ProductBatchBody[] = [];
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal<T>(path: string, shopDomain: string, body: unknown): Promise<T> {
      assert.equal(path, "/internal/v1/ingestion/products");
      assert.equal(shopDomain, "test-store.myshopify.com");
      const batch = body as ProductBatchBody;
      postedBatches.push(batch);
      const res: BatchResponse = {
        received: batch.products.length,
        written: batch.products.length,
        skipped_stale: 0,
      };
      return res as T;
    },
  };

  const progressReports: Array<{ received: number; written: number; skippedStale: number }> = [];

  const summary = await harvestProducts({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
    onProgress: (progress) => progressReports.push(progress),
  });

  assert.equal(summary.received, 3);
  assert.equal(summary.written, 3);
  assert.equal(summary.skippedStale, 0);
  assert.equal(postedBatches.length, 2);
  assert.equal(postedBatches[0].products.length, 2);
  assert.equal(postedBatches[1].products.length, 1);
  assert.equal(progressReports.length, 2);
  assert.deepEqual(progressReports[1], { received: 3, written: 3, skippedStale: 0 });
});

test("harvestProducts handles empty catalogue with zero backend calls", async () => {
  const admin = createMockAdmin([
    {
      nodes: [],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  let backendCalled = false;
  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal() {
      backendCalled = true;
      throw new Error("Should not be called");
    },
  };

  const summary = await harvestProducts({
    admin,
    backend: backend as BackendClient,
    shopDomain: "test-store.myshopify.com",
  });

  assert.equal(summary.received, 0);
  assert.equal(summary.written, 0);
  assert.equal(summary.skippedStale, 0);
  assert.equal(backendCalled, false);
});

test("harvestProducts propagates backend errors and stops pagination", async () => {
  const admin = createMockAdmin([
    {
      nodes: [createSampleProductNode("1")],
      hasNextPage: true,
      endCursor: "cursor_1",
    },
    {
      nodes: [createSampleProductNode("2")],
      hasNextPage: false,
      endCursor: null,
    },
  ]);

  const backend: Pick<BackendClient, "postInternal"> = {
    async postInternal() {
      throw new Error("Backend S2S 500 error");
    },
  };

  await assert.rejects(
    async () => {
      await harvestProducts({
        admin,
        backend: backend as BackendClient,
        shopDomain: "test-store.myshopify.com",
      });
    },
    { message: "Backend S2S 500 error" }
  );
});

test("harvestProducts completes truncated variants before posting the product", async () => {
  const truncated = createSampleProductNode("9", "Many Variants");
  truncated.variants = {
    nodes: Array.from({ length: 100 }, (_, i) => ({
      id: `gid://shopify/ProductVariant/9${i}`,
      title: `V${i}`,
      sku: null,
      price: "1.00",
    })),
    pageInfo: { hasNextPage: true },
  };
  const allVariants = Array.from({ length: 101 }, (_, i) => ({
    id: `gid://shopify/ProductVariant/9${i}`,
    title: `V${i}`,
    sku: null,
    price: "1.00",
  }));
  const cost = {
    cost: {
      requestedQueryCost: 10,
      actualQueryCost: 10,
      throttleStatus: { maximumAvailable: 1000, currentlyAvailable: 1000, restoreRate: 50 },
    },
  };
  const queries: string[] = [];
  const admin: GraphqlAdmin = {
    async graphql(query: string): Promise<Response> {
      queries.push(query);
      if (query.includes("ProductVariantsPage")) {
        return Response.json({
          data: {
            product: {
              variants: { nodes: allVariants, pageInfo: { hasNextPage: false, endCursor: "c" } },
            },
          },
          extensions: cost,
        });
      }
      return Response.json({
        data: { products: { nodes: [truncated], pageInfo: { hasNextPage: false, endCursor: null } } },
        extensions: cost,
      });
    },
  };

  const posted: ProductBatchBody[] = [];
  const backend = {
    async postInternal<T>(_path: string, _shop: string, body: unknown): Promise<T> {
      posted.push(body as ProductBatchBody);
      const res: BatchResponse = { received: 1, written: 1, skipped_stale: 0 };
      return res as T;
    },
  } as unknown as BackendClient;

  await harvestProducts({ admin, backend, shopDomain: "acme.myshopify.com" });

  assert.equal(queries.filter((q) => q.includes("ProductVariantsPage")).length, 1);
  assert.equal(posted.length, 1);
  assert.equal(posted[0].products[0].variants.length, 101);
});
