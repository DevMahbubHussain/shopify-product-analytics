import { test } from "node:test";
import assert from "node:assert/strict";
import {
  normalizeProduct,
  VariantsTruncatedError,
} from "./normalizeProduct.server.ts";
import type { ShopifyProductNode } from "./productsQuery.server.ts";

function productNode(
  overrides: Partial<ShopifyProductNode> = {},
): ShopifyProductNode {
  return {
    id: "gid://shopify/Product/10389141159984",
    title: "Selling Plans Ski Wax",
    handle: "selling-plans-ski-wax",
    vendor: "Snowboard Vendor",
    productType: "Accessories",
    status: "ACTIVE",
    publishedAt: "2026-11-18T06:46:25Z",
    updatedAt: "2026-11-18T06:46:37Z",
    featuredMedia: {
      preview: { image: { url: "https://cdn.shopify.com/s/files/wax.png" } },
    },
    variants: {
      nodes: [
        {
          id: "gid://shopify/ProductVariant/1",
          title: "Default",
          sku: "WAX-1",
          price: "24.95",
        },
        {
          id: "gid://shopify/ProductVariant/2",
          title: "Sample",
          sku: null,
          price: "9.95",
        },
      ],
      pageInfo: { hasNextPage: false },
    },
    ...overrides,
  };
}

test("maps a full product to the Laravel product payload", () => {
  assert.deepEqual(normalizeProduct(productNode()), {
    gid: "gid://shopify/Product/10389141159984",
    title: "Selling Plans Ski Wax",
    handle: "selling-plans-ski-wax",
    vendor: "Snowboard Vendor",
    product_type: "Accessories",
    status: "ACTIVE",
    image_url: "https://cdn.shopify.com/s/files/wax.png",
    published_at: "2026-11-18T06:46:25Z",
    shopify_updated_at: "2026-11-18T06:46:37Z",
    variants: [
      {
        gid: "gid://shopify/ProductVariant/1",
        title: "Default",
        sku: "WAX-1",
        price: "24.95",
      },
      {
        gid: "gid://shopify/ProductVariant/2",
        title: "Sample",
        sku: null,
        price: "9.95",
      },
    ],
  });
});

test("turns empty vendor and product type into null", () => {
  const payload = normalizeProduct(
    productNode({ vendor: "", productType: "" }),
  );

  assert.equal(payload.vendor, null);
  assert.equal(payload.product_type, null);
});

test("returns a null image when the product has no usable media", () => {
  for (const featuredMedia of [
    null,
    { preview: null },
    { preview: { image: null } },
  ]) {
    assert.equal(
      normalizeProduct(productNode({ featuredMedia })).image_url,
      null,
    );
  }
});

test("keeps an unpublished product's null publish time", () => {
  assert.equal(
    normalizeProduct(productNode({ publishedAt: null })).published_at,
    null,
  );
});

test("passes prices through as the strings Shopify sent", () => {
  const node = productNode();
  node.variants.nodes = [
    {
      id: "gid://shopify/ProductVariant/3",
      title: "A",
      sku: null,
      price: "19.9",
    },
    { id: "gid://shopify/ProductVariant/4", title: "B", sku: null, price: "0" },
  ];

  assert.deepEqual(
    normalizeProduct(node).variants.map((v) => v.price),
    ["19.9", "0"],
  );
});

test("refuses a product whose variant list was truncated", () => {
  const node = productNode();
  node.variants.pageInfo.hasNextPage = true;

  assert.throws(
    () => normalizeProduct(node),
    (error: unknown) => {
      assert.ok(error instanceof VariantsTruncatedError);
      assert.equal(error.productGid, "gid://shopify/Product/10389141159984");
      return true;
    },
  );
});
