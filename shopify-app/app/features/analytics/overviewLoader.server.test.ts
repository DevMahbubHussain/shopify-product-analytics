import test from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../../lib/backend/backendClient.server.ts";
import {
  loadOverviewDashboard,
  type OverviewDashboardData,
} from "./overviewLoader.server.ts";
import type { ShopSyncStatus } from "../sync/getSyncStatus.server.ts";
import type {
  OverviewAnalyticsData,
  SalesTrendData,
  ProductListResponse,
} from "../../lib/backend/analyticsClient.server.ts";

const shop = "overview-store.myshopify.com";
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

const mockOverview: OverviewAnalyticsData = {
  date_range: { from: "2026-05-17", to: "2026-06-15" },
  previous_date_range: { from: "2026-04-17", to: "2026-05-16" },
  currency: "USD",
  summary: {
    gross_sales: "5000.00",
    discounts: "200.00",
    refunds: "50.00",
    net_sales: "4750.00",
    units_sold: 120,
    orders: 60,
    average_order_revenue: "79.17",
  },
  comparison: {
    gross_sales: { delta: "500.00", percentage_change: 11.11, direction: "up" },
    net_sales: { delta: "400.00", percentage_change: 9.2, direction: "up" },
    units_sold: { delta: 10, percentage_change: 9.09, direction: "up" },
    orders: { delta: 5, percentage_change: 9.09, direction: "up" },
  },
};

const mockTrend: SalesTrendData = {
  interval: "day",
  currency: "USD",
  points: [
    {
      date: "2026-06-15",
      gross_sales: "500.00",
      discounts: "20.00",
      refunds: "0.00",
      net_sales: "480.00",
      units_sold: 12,
      orders: 6,
    },
  ],
};

const mockProducts: ProductListResponse = {
  data: [
    {
      id: "1",
      shopify_product_gid: "gid://shopify/Product/1",
      title: "Cool Hoodie",
      handle: "cool-hoodie",
      vendor: "Acme",
      product_type: "Apparel",
      status: "ACTIVE",
      image_url: null,
      gross_sales: "2000.00",
      discounts: "50.00",
      refunds: "0.00",
      net_sales: "1950.00",
      units_sold: 40,
      orders: 30,
    },
  ],
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    per_page: 10,
    to: 1,
    total: 1,
  },
};

const completedSyncStatus: ShopSyncStatus = {
  state: "completed",
  syncRun: {
    id: "1",
    syncType: "historical",
    status: "completed",
    recordsReceived: 30,
    recordsProcessed: 30,
    startedAt: "2026-06-15T10:00:00Z",
    completedAt: "2026-06-15T10:05:00Z",
    errorCode: null,
    lastActivityAt: "2026-06-15T10:05:00Z",
  },
};

test("loadOverviewDashboard fetches overview, trend, and products in parallel", async () => {
  const fetchStub = stubFetch((url) => {
    if (url.includes("/api/v1/analytics/overview")) {
      return Response.json({ data: mockOverview });
    }
    if (url.includes("/api/v1/analytics/sales-trend")) {
      return Response.json({ data: mockTrend });
    }
    if (url.includes("/api/v1/products")) {
      return Response.json(mockProducts);
    }
    return new Response("Not found", { status: 404 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app?preset=30d");

    const data: OverviewDashboardData = await loadOverviewDashboard({
      request,
      shopDomain: shop,
      backend,
      syncStatus: completedSyncStatus,
      referenceToday: "2026-06-15",
    });

    assert.equal(data.shopDomain, shop);
    assert.equal(data.syncStatus.state, "completed");
    assert.deepEqual(data.dateRange, {
      preset: "30d",
      from: "2026-05-17",
      to: "2026-06-15",
    });
    assert.deepEqual(data.overview, mockOverview);
    assert.deepEqual(data.salesTrend, mockTrend);
    assert.deepEqual(data.products, mockProducts);
    assert.equal(data.analyticsError, null);

    // Assert that all 3 endpoints were called
    const urls = fetchStub.calls().map((c) => c.url);
    assert.ok(urls.some((u) => u.includes("/api/v1/analytics/overview")));
    assert.ok(urls.some((u) => u.includes("/api/v1/analytics/sales-trend")));
    assert.ok(urls.some((u) => u.includes("/api/v1/products")));
  } finally {
    fetchStub.restore();
  }
});

test("loadOverviewDashboard passes search, sorting, and pagination parameters", async () => {
  const fetchStub = stubFetch((url) => {
    if (url.includes("/api/v1/analytics/overview")) return Response.json({ data: mockOverview });
    if (url.includes("/api/v1/analytics/sales-trend")) return Response.json({ data: mockTrend });
    if (url.includes("/api/v1/products")) return Response.json(mockProducts);
    return new Response("Not found", { status: 404 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request(
      "https://store.myshopify.com/app?search=hoodie&vendor=Acme&sort_by=units_sold&sort_direction=asc&page=2&per_page=20",
    );

    await loadOverviewDashboard({
      request,
      shopDomain: shop,
      backend,
      syncStatus: completedSyncStatus,
      referenceToday: "2026-06-15",
    });

    const productCall = fetchStub.calls().find((c) => c.url.includes("/api/v1/products"));
    assert.ok(productCall);
    const parsed = new URL(productCall.url);
    assert.equal(parsed.searchParams.get("search"), "hoodie");
    assert.equal(parsed.searchParams.get("vendor"), "Acme");
    assert.equal(parsed.searchParams.get("sort_by"), "units_sold");
    assert.equal(parsed.searchParams.get("sort_direction"), "asc");
    assert.equal(parsed.searchParams.get("page"), "2");
    assert.equal(parsed.searchParams.get("per_page"), "20");
  } finally {
    fetchStub.restore();
  }
});

test("loadOverviewDashboard catches backend errors gracefully", async () => {
  const fetchStub = stubFetch(() => {
    return Response.json({ error: "Database timeout" }, { status: 500 });
  });

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app");

    const data = await loadOverviewDashboard({
      request,
      shopDomain: shop,
      backend,
      syncStatus: completedSyncStatus,
      referenceToday: "2026-06-15",
    });

    assert.equal(data.overview, null);
    assert.equal(data.salesTrend, null);
    assert.equal(data.products, null);
    assert.match(data.analyticsError ?? "", /500/);
  } finally {
    fetchStub.restore();
  }
});

test("loadOverviewDashboard skips analytics calls when syncStatus is backend_error", async () => {
  const fetchStub = stubFetch(() => Response.json({}));

  try {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const request = new Request("https://store.myshopify.com/app");

    const data = await loadOverviewDashboard({
      request,
      shopDomain: shop,
      backend,
      syncStatus: { state: "backend_error", syncRun: null, error: "Gateway down" },
      referenceToday: "2026-06-15",
    });

    assert.equal(data.syncStatus.state, "backend_error");
    assert.equal(data.overview, null);
    assert.equal(data.salesTrend, null);
    assert.equal(data.products, null);
    assert.equal(fetchStub.calls().length, 0);
  } finally {
    fetchStub.restore();
  }
});
