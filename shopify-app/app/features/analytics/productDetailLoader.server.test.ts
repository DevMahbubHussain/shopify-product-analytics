import test from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../../lib/backend/backendClient.server.ts";
import {
  loadProductDetail,
  type ProductDetailDashboardData,
} from "./productDetailLoader.server.ts";
import type {
  ProductDetailData,
  VariantAnalyticsData,
} from "../../lib/backend/analyticsClient.server.ts";

const shop = "product-store.myshopify.com";
const secret = "test-s2s-secret-1234567890123456";

function stubFetch(handler: (url: string) => Response) {
  const calls: { url: string; init: RequestInit }[] = [];
  const original = globalThis.fetch;
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const urlStr = String(input);
    calls.push({ url: urlStr, init: init ?? {} });
    return handler(urlStr);
  }) as typeof fetch;
  return {
    calls: () => calls,
    restore: () => {
      globalThis.fetch = original;
    },
  };
}

const mockDetail: ProductDetailData = {
  product: {
    id: "42",
    shopify_product_gid: "gid://shopify/Product/42",
    title: "Awesome Jacket",
    handle: "awesome-jacket",
    vendor: "Nordic Outerwear",
    product_type: "Outerwear",
    status: "ACTIVE",
    image_url: "https://cdn.shopify.com/jacket.jpg",
  },
  date_range: { from: "2026-06-09", to: "2026-06-15" },
  previous_date_range: { from: "2026-06-02", to: "2026-06-08" },
  currency: "USD",
  summary: {
    gross_sales: "1200.00",
    discounts: "50.00",
    refunds: "0.00",
    net_sales: "1150.00",
    units_sold: 10,
    orders: 8,
    average_order_revenue: "143.75",
  },
  comparison: {
    gross_sales: { delta: "200.00", percentage_change: 20.0, direction: "up" },
    net_sales: { delta: "150.00", percentage_change: 15.0, direction: "up" },
    units_sold: { delta: 2, percentage_change: 25.0, direction: "up" },
    orders: { delta: 1, percentage_change: 14.29, direction: "up" },
  },
};

const mockVariants: VariantAnalyticsData = {
  product_id: "42",
  date_range: { from: "2026-06-09", to: "2026-06-15" },
  currency: "USD",
  variants: [
    {
      id: "101",
      shopify_variant_gid: "gid://shopify/ProductVariant/101",
      title: "Black / Large",
      sku: "JKT-BLK-L",
      price: "120.00",
      gross_sales: "720.00",
      discounts: "30.00",
      refunds: "0.00",
      net_sales: "690.00",
      units_sold: 6,
      orders: 5,
    },
    {
      id: "102",
      shopify_variant_gid: "gid://shopify/ProductVariant/102",
      title: "Navy / Medium",
      sku: "JKT-NVY-M",
      price: "120.00",
      gross_sales: "480.00",
      discounts: "20.00",
      refunds: "0.00",
      net_sales: "460.00",
      units_sold: 4,
      orders: 3,
    },
  ],
};

test("loadProductDetail loads detail and variant analytics in parallel", async () => {
  const fetchStub = stubFetch((url) => {
    if (url.includes("/api/v1/products/42/analytics")) {
      return Response.json({ data: mockDetail });
    }
    if (url.includes("/api/v1/products/42/variants/analytics")) {
      return Response.json({ data: mockVariants });
    }
    return new Response("Not found", { status: 404 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app/products/42?preset=7d");

    const data: ProductDetailDashboardData = await loadProductDetail({
      request,
      shopDomain: shop,
      backend,
      productId: "42",
      referenceToday: "2026-06-15",
    });

    assert.equal(data.shopDomain, shop);
    assert.equal(data.productId, "42");
    assert.equal(data.notFound, false);
    assert.equal(data.analyticsError, null);
    assert.deepEqual(data.dateRange, {
      preset: "7d",
      from: "2026-06-09",
      to: "2026-06-15",
    });
    assert.deepEqual(data.productDetail, mockDetail);
    assert.deepEqual(data.variantAnalytics, mockVariants);

    const urls = fetchStub.calls().map((c) => c.url);
    assert.ok(urls.some((u) => u.includes("/api/v1/products/42/analytics")));
    assert.ok(urls.some((u) => u.includes("/api/v1/products/42/variants/analytics")));
  } finally {
    fetchStub.restore();
  }
});

test("loadProductDetail marks notFound: true when backend returns 404", async () => {
  const fetchStub = stubFetch(() => {
    return Response.json({ message: "Product not found." }, { status: 404 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app/products/999");

    const data = await loadProductDetail({
      request,
      shopDomain: shop,
      backend,
      productId: "999",
      referenceToday: "2026-06-15",
    });

    assert.equal(data.notFound, true);
    assert.equal(data.productDetail, null);
    assert.equal(data.variantAnalytics, null);
  } finally {
    fetchStub.restore();
  }
});

test("loadProductDetail handles empty product ID without calling backend", async () => {
  const fetchStub = stubFetch(() => Response.json({}));

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app/products/");

    const data = await loadProductDetail({
      request,
      shopDomain: shop,
      backend,
      productId: "  ",
      referenceToday: "2026-06-15",
    });

    assert.equal(data.notFound, true);
    assert.equal(data.productDetail, null);
    assert.equal(data.variantAnalytics, null);
    assert.equal(fetchStub.calls().length, 0);
  } finally {
    fetchStub.restore();
  }
});

test("loadProductDetail reports analyticsError when backend returns 500", async () => {
  const fetchStub = stubFetch(() => {
    return Response.json({ error: "Server error" }, { status: 500 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app/products/42");

    const data = await loadProductDetail({
      request,
      shopDomain: shop,
      backend,
      productId: "42",
      referenceToday: "2026-06-15",
    });

    assert.equal(data.notFound, false);
    assert.equal(data.productDetail, null);
    assert.equal(data.variantAnalytics, null);
    assert.match(data.analyticsError ?? "", /500/);
  } finally {
    fetchStub.restore();
  }
});
