import { afterEach, describe, it } from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../lib/backend/backendClient.server.ts";
import type { PromptLibraryReady } from "./app.mcp.prompts.server.ts";
import { loadPromptLibrary, PRODUCT_PICKER_LIMIT } from "./app.mcp.prompts.server.ts";

const shop = "acme-test.myshopify.com";
const secret = "test-secret-key-12345678901234567890";
const now = new Date("2026-11-25T03:00:00Z");

interface StubOptions {
  status?: Record<string, unknown> | number;
  products?: { data: Array<{ handle: string; title: string }>; total?: number } | number;
  syncRun?: Record<string, unknown> | null;
}

const originalFetch = globalThis.fetch;
afterEach(() => {
  globalThis.fetch = originalFetch;
});

function statusBody(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    has_consent: true,
    consent: null,
    plan: { tier: "pro", can_use_mcp: true, history_days: 365, rate_limit_per_minute: 60 },
    counts: { active_tokens: 1, active_oauth_grants: 0 },
    store: { timezone: "America/New_York", currency_code: "USD", history_start: "2026-09-26" },
    ...overrides,
  };
}

function completedRun(): Record<string, unknown> {
  return {
    id: "run-1",
    sync_type: "initial",
    status: "completed",
    records_received: 10,
    records_processed: 10,
    started_at: "2026-11-24T10:00:00Z",
    completed_at: "2026-11-24T10:05:00Z",
    error_code: null,
    last_activity_at: "2026-11-24T10:05:00Z",
  };
}

function stub(options: StubOptions = {}): string[] {
  const urls: string[] = [];
  globalThis.fetch = (async (input: string | URL | Request) => {
    const url = String(input);
    urls.push(url);

    if (url.includes("/internal/v1/mcp/status")) {
      const status = options.status ?? statusBody();
      return typeof status === "number" ? new Response("down", { status }) : Response.json(status);
    }
    if (url.includes("/api/v1/products")) {
      const products = options.products ?? {
        data: [
          { handle: "classic-tee", title: "Classic Tee" },
          { handle: "hoodie", title: "Hoodie" },
        ],
      };
      if (typeof products === "number") return new Response("down", { status: products });
      return Response.json({
        data: products.data,
        meta: { current_page: 1, from: 1, last_page: 1, per_page: 100, to: products.data.length, total: products.total ?? products.data.length },
      });
    }
    if (url.includes("/internal/v1/sync/status")) {
      return Response.json({ sync_run: options.syncRun === undefined ? completedRun() : options.syncRun });
    }
    return new Response("not found", { status: 404 });
  }) as typeof globalThis.fetch;
  return urls;
}

function backend(): BackendClient {
  return new BackendClient({ baseUrl: "http://backend:8000", secret });
}

async function ready(params = "", options: StubOptions = {}): Promise<PromptLibraryReady> {
  stub(options);
  const data = await loadPromptLibrary(backend(), shop, new URLSearchParams(params), now);
  assert.equal(data.state, "ready");
  return data as PromptLibraryReady;
}

describe("loadPromptLibrary: ready state", () => {
  it("uses the store's timezone for today, not the server's", async () => {
    const data = await ready();

    assert.equal(data.today, "2026-11-24");
    assert.deepEqual(data.store, { timezone: "America/New_York", currency: "USD", historyStart: "2026-09-26" });
  });

  it("defaults to the last 30 days ending store-today", async () => {
    const data = await ready();

    assert.equal(data.range.preset, "30d");
    assert.equal(data.resolved?.from, "2026-10-26");
    assert.equal(data.resolved?.to, "2026-11-24");
    assert.equal(data.rangeError, null);
  });

  it("lists all presets with availability", async () => {
    const data = await ready();

    assert.equal(data.presets.length, 8);
    assert.ok(data.presets.every((p) => p.available));
  });

  it("maps products to handle and title for the picker", async () => {
    const data = await ready();

    assert.deepEqual(data.products, [
      { handle: "classic-tee", title: "Classic Tee" },
      { handle: "hoodie", title: "Hoodie" },
    ]);
    assert.equal(data.productsTruncated, false);
    assert.equal(data.productsError, false);
  });

  it("reports the sync state", async () => {
    assert.equal((await ready()).sync, "completed");
    assert.equal((await ready("", { syncRun: null })).sync, "never_synced");
    assert.equal(
      (await ready("", { syncRun: { ...completedRun(), status: "running", completed_at: null, last_activity_at: new Date().toISOString() } })).sync,
      "syncing",
    );
  });

  it("accepts an unknown history start", async () => {
    const data = await ready("", { status: statusBody({ store: { timezone: "UTC", currency_code: "EUR", history_start: null } }) });

    assert.equal(data.store.historyStart, null);
    assert.equal(data.resolved?.startsBeforeHistory, false);
  });

  it("falls back to UTC when the store timezone is invalid", async () => {
    const data = await ready("", { status: statusBody({ store: { timezone: "Not/AZone", currency_code: "USD", history_start: null } }) });

    assert.equal(data.today, "2026-11-25");
  });
});

describe("loadPromptLibrary: date range from the URL", () => {
  it("applies a known preset", async () => {
    const data = await ready("preset=yesterday");

    assert.equal(data.resolved?.from, "2026-11-23");
    assert.equal(data.resolved?.to, "2026-11-23");
  });

  it("falls back to 30 days for an unknown preset", async () => {
    assert.equal((await ready("preset=forever")).range.preset, "30d");
  });

  it("keeps a valid custom range", async () => {
    const data = await ready("preset=custom&from=2026-11-01&to=2026-11-10");

    assert.equal(data.resolved?.days, 10);
  });

  it("shows the real error for an invalid custom range instead of replacing it", async () => {
    const data = await ready("preset=custom&from=2026-11-10&to=2026-11-01");

    assert.equal(data.resolved, null);
    assert.match(data.rangeError ?? "", /start date/);
    assert.equal(data.range.from, "2026-11-10");
  });

  it("rejects a custom range longer than the plan allows", async () => {
    const data = await ready("preset=custom&from=2025-10-01&to=2026-11-20", {
      status: statusBody({ store: { timezone: "America/New_York", currency_code: "USD", history_start: null } }),
    });

    assert.equal(data.resolved, null);
    assert.match(data.rangeError ?? "", /365 days/);
  });

  it("treats missing custom dates as malformed", async () => {
    const data = await ready("preset=custom");

    assert.match(data.rangeError ?? "", /YYYY-MM-DD/);
  });
});

describe("loadPromptLibrary: products", () => {
  it("passes a trimmed search term to the backend", async () => {
    const urls = stub();
    await loadPromptLibrary(backend(), shop, new URLSearchParams("q=%20tee%20"), now);

    const productUrl = new URL(urls.find((u) => u.includes("/api/v1/products")) ?? "");
    assert.equal(productUrl.searchParams.get("search"), "tee");
    assert.equal(productUrl.searchParams.get("per_page"), String(PRODUCT_PICKER_LIMIT));
    assert.equal(productUrl.searchParams.get("sort_by"), "title");
  });

  it("caps an overlong search term", async () => {
    const urls = stub();
    await loadPromptLibrary(backend(), shop, new URLSearchParams(`q=${"a".repeat(400)}`), now);

    const productUrl = new URL(urls.find((u) => u.includes("/api/v1/products")) ?? "");
    assert.equal(productUrl.searchParams.get("search")?.length, 255);
  });

  it("flags when more products exist than the picker shows", async () => {
    const data = await ready("", { products: { data: [{ handle: "a", title: "A" }], total: 250 } });

    assert.equal(data.productsTruncated, true);
  });

  it("keeps the page usable when products fail to load", async () => {
    const data = await ready("", { products: 503 });

    assert.deepEqual(data.products, []);
    assert.equal(data.productsError, true);
  });
});

describe("loadPromptLibrary: locked and error states", () => {
  it("locks the library on a plan without MCP and skips other calls", async () => {
    const urls = stub({ status: statusBody({ plan: { tier: "free", can_use_mcp: false, history_days: 30, rate_limit_per_minute: 0 } }) });
    const data = await loadPromptLibrary(backend(), shop, new URLSearchParams(), now);

    assert.deepEqual(data, { state: "locked", reason: "plan" });
    assert.equal(urls.filter((u) => u.includes("/api/v1/products")).length, 0);
  });

  it("locks the library without data-sharing consent", async () => {
    stub({ status: statusBody({ has_consent: false }) });
    const data = await loadPromptLibrary(backend(), shop, new URLSearchParams(), now);

    assert.deepEqual(data, { state: "locked", reason: "consent" });
  });

  it("returns an error state when status cannot be loaded", async () => {
    stub({ status: 500 });
    const data = await loadPromptLibrary(backend(), shop, new URLSearchParams(), now);

    assert.equal(data.state, "error");
    assert.doesNotMatch(data.state === "error" ? data.message : "", /down|500/);
  });
});
