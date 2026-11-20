import test from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "./backendClient.server.ts";
import {
  fetchOverview,
  fetchSalesTrend,
  fetchProductList,
  fetchProductDetail,
  fetchVariantAnalytics,
  type OverviewAnalyticsData,
  type SalesTrendData,
  type ProductListResponse,
  type ProductDetailData,
  type VariantAnalyticsData,
} from "./analyticsClient.server.ts";

const shop = "store-analytics.myshopify.com";
const secret = "0123456789abcdef0123456789abcdef";

function stubFetch(response: Response) {
  const calls: { url: string; init: RequestInit }[] = [];
  const original = globalThis.fetch;
  globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push({ url: String(input), init: init ?? {} });
    return response;
  }) as typeof fetch;
  return {
    calls: () => calls,
    restore: () => {
      globalThis.fetch = original;
    },
  };
}

test("fetchOverview requests /api/v1/analytics/overview with date range", async () => {
  const mockData: OverviewAnalyticsData = {
    date_range: { from: "2026-01-01", to: "2026-01-07" },
    previous_date_range: { from: "2025-12-25", to: "2025-12-31" },
    currency: "USD",
    summary: {
      gross_sales: "1000.00",
      discounts: "50.00",
      refunds: "20.00",
      net_sales: "930.00",
      units_sold: 45,
      orders: 20,
      average_order_revenue: "46.50",
    },
    comparison: {
      gross_sales: { delta: "100.00", percentage_change: 11.11, direction: "up" },
      net_sales: { delta: "80.00", percentage_change: 9.41, direction: "up" },
      units_sold: { delta: 5, percentage_change: 12.5, direction: "up" },
      orders: { delta: 2, percentage_change: 11.11, direction: "up" },
    },
  };

  const fetchStub = stubFetch(Response.json({ data: mockData }));
  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const result = await fetchOverview(backend, shop, {
      from: "2026-01-01",
      to: "2026-01-07",
    });

    assert.deepEqual(result, mockData);
    const [call] = fetchStub.calls();
    assert.ok(call);
    const parsedUrl = new URL(call.url);
    assert.equal(parsedUrl.pathname, "/api/v1/analytics/overview");
    assert.equal(parsedUrl.searchParams.get("from"), "2026-01-01");
    assert.equal(parsedUrl.searchParams.get("to"), "2026-01-07");
  } finally {
    fetchStub.restore();
  }
});

test("fetchSalesTrend requests /api/v1/analytics/sales-trend with interval", async () => {
  const mockData: SalesTrendData = {
    interval: "day",
    currency: "USD",
    points: [
      {
        date: "2026-01-01",
        gross_sales: "500.00",
        discounts: "25.00",
        refunds: "0.00",
        net_sales: "475.00",
        units_sold: 20,
        orders: 10,
      },
    ],
  };

  const fetchStub = stubFetch(Response.json({ data: mockData }));
  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const result = await fetchSalesTrend(backend, shop, {
      from: "2026-01-01",
      to: "2026-01-07",
      interval: "day",
    });

    assert.deepEqual(result, mockData);
    const [call] = fetchStub.calls();
    assert.ok(call);
    const parsedUrl = new URL(call.url);
    assert.equal(parsedUrl.pathname, "/api/v1/analytics/sales-trend");
    assert.equal(parsedUrl.searchParams.get("from"), "2026-01-01");
    assert.equal(parsedUrl.searchParams.get("to"), "2026-01-07");
    assert.equal(parsedUrl.searchParams.get("interval"), "day");
  } finally {
    fetchStub.restore();
  }
});

test("fetchProductList requests /api/v1/products with filtering and pagination", async () => {
  const mockData: ProductListResponse = {
    data: [
      {
        id: "1",
        shopify_product_gid: "gid://shopify/Product/123",
        title: "T-Shirt",
        handle: "t-shirt",
        vendor: "Acme",
        product_type: "Apparel",
        status: "ACTIVE",
        image_url: "https://cdn.shopify.com/tshirt.png",
        gross_sales: "500.00",
        discounts: "20.00",
        refunds: "0.00",
        net_sales: "480.00",
        units_sold: 25,
        orders: 15,
      },
    ],
    meta: {
      current_page: 1,
      from: 1,
      last_page: 2,
      per_page: 25,
      to: 1,
      total: 30,
    },
  };

  const fetchStub = stubFetch(Response.json(mockData));
  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const result = await fetchProductList(backend, shop, {
      from: "2026-01-01",
      to: "2026-01-07",
      search: "shirt",
      vendor: "Acme",
      sort_by: "net_sales",
      sort_direction: "desc",
      page: 1,
      per_page: 25,
    });

    assert.deepEqual(result, mockData);
    const [call] = fetchStub.calls();
    assert.ok(call);
    const parsedUrl = new URL(call.url);
    assert.equal(parsedUrl.pathname, "/api/v1/products");
    assert.equal(parsedUrl.searchParams.get("from"), "2026-01-01");
    assert.equal(parsedUrl.searchParams.get("to"), "2026-01-07");
    assert.equal(parsedUrl.searchParams.get("search"), "shirt");
    assert.equal(parsedUrl.searchParams.get("vendor"), "Acme");
    assert.equal(parsedUrl.searchParams.get("sort_by"), "net_sales");
    assert.equal(parsedUrl.searchParams.get("sort_direction"), "desc");
    assert.equal(parsedUrl.searchParams.get("page"), "1");
    assert.equal(parsedUrl.searchParams.get("per_page"), "25");
  } finally {
    fetchStub.restore();
  }
});

test("fetchProductDetail requests /api/v1/products/:id/analytics with date range", async () => {
  const mockData: ProductDetailData = {
    product: {
      id: "1",
      shopify_product_gid: "gid://shopify/Product/123",
      title: "T-Shirt",
      handle: "t-shirt",
      vendor: "Acme",
      product_type: "Apparel",
      status: "ACTIVE",
      image_url: "https://cdn.shopify.com/tshirt.png",
    },
    date_range: { from: "2026-01-01", to: "2026-01-07" },
    previous_date_range: { from: "2025-12-25", to: "2025-12-31" },
    currency: "USD",
    summary: {
      gross_sales: "500.00",
      discounts: "20.00",
      refunds: "0.00",
      net_sales: "480.00",
      units_sold: 25,
      orders: 15,
      average_order_revenue: "32.00",
    },
    comparison: {
      gross_sales: { delta: "50.00", percentage_change: 11.11, direction: "up" },
      net_sales: { delta: "40.00", percentage_change: 9.09, direction: "up" },
      units_sold: { delta: 2, percentage_change: 8.7, direction: "up" },
      orders: { delta: 1, percentage_change: 7.14, direction: "up" },
    },
  };

  const fetchStub = stubFetch(Response.json({ data: mockData }));
  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const result = await fetchProductDetail(backend, shop, "1", {
      from: "2026-01-01",
      to: "2026-01-07",
    });

    assert.deepEqual(result, mockData);
    const [call] = fetchStub.calls();
    assert.ok(call);
    const parsedUrl = new URL(call.url);
    assert.equal(parsedUrl.pathname, "/api/v1/products/1/analytics");
    assert.equal(parsedUrl.searchParams.get("from"), "2026-01-01");
    assert.equal(parsedUrl.searchParams.get("to"), "2026-01-07");
  } finally {
    fetchStub.restore();
  }
});

test("fetchVariantAnalytics requests /api/v1/products/:id/variants/analytics", async () => {
  const mockData: VariantAnalyticsData = {
    product_id: "1",
    date_range: { from: "2026-01-01", to: "2026-01-07" },
    currency: "USD",
    variants: [
      {
        id: "10",
        shopify_variant_gid: "gid://shopify/ProductVariant/456",
        title: "Medium / Blue",
        sku: "TS-M-BLU",
        price: "25.00",
        gross_sales: "250.00",
        discounts: "10.00",
        refunds: "0.00",
        net_sales: "240.00",
        units_sold: 10,
        orders: 8,
      },
    ],
  };

  const fetchStub = stubFetch(Response.json({ data: mockData }));
  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const result = await fetchVariantAnalytics(backend, shop, "1", {
      from: "2026-01-01",
      to: "2026-01-07",
    });

    assert.deepEqual(result, mockData);
    const [call] = fetchStub.calls();
    assert.ok(call);
    const parsedUrl = new URL(call.url);
    assert.equal(parsedUrl.pathname, "/api/v1/products/1/variants/analytics");
    assert.equal(parsedUrl.searchParams.get("from"), "2026-01-01");
    assert.equal(parsedUrl.searchParams.get("to"), "2026-01-07");
  } finally {
    fetchStub.restore();
  }
});
