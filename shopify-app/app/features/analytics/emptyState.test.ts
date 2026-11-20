import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { getEmptyStateConfig } from "./emptyState.ts";

describe("getEmptyStateConfig", () => {
  it("generates zero_sales config with date boundaries and shopify admin orders link", () => {
    const config = getEmptyStateConfig({
      variant: "zero_sales",
      dateRange: { from: "2026-11-01", to: "2026-11-20" },
      shopDomain: "demo-shop.myshopify.com",
    });

    assert.equal(config.variant, "zero_sales");
    assert.equal(config.icon, "📊");
    assert.equal(config.heading, "No sales recorded for this date range");
    assert.ok(config.description.includes("between 2026-11-01 and 2026-11-20"));
    assert.equal(config.primaryAction?.actionKey, "select_30d");
    assert.equal(config.primaryAction?.label, "View Last 30 Days");
    assert.equal(config.secondaryAction?.actionKey, "shopify_admin_orders");
    assert.equal(
      config.secondaryAction?.url,
      "https://admin.shopify.com/store/demo-shop/orders",
    );
    assert.ok(config.tip?.includes("create a test order"));
  });

  it("generates zero_filter_results with combined search and status filters", () => {
    const config = getEmptyStateConfig({
      variant: "zero_filter_results",
      searchQuery: "Cotton T-Shirt",
      statusFilter: "draft",
    });

    assert.equal(config.variant, "zero_filter_results");
    assert.equal(config.icon, "🔍");
    assert.equal(config.heading, "No products matched your filters");
    assert.ok(config.description.includes('search "Cotton T-Shirt"'));
    assert.ok(config.description.includes('status "draft"'));
    assert.equal(config.primaryAction?.actionKey, "clear_filters");
    assert.equal(config.primaryAction?.label, "Clear all filters");
  });

  it("generates initial_syncing config with progress notice", () => {
    const config = getEmptyStateConfig({
      variant: "initial_syncing",
    });

    assert.equal(config.variant, "initial_syncing");
    assert.equal(config.icon, "⏳");
    assert.equal(config.heading, "Synchronizing your store data");
    assert.ok(config.description.includes("importing your catalogue"));
  });
});
