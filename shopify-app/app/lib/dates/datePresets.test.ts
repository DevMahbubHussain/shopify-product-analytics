import test from "node:test";
import assert from "node:assert/strict";
import {
  calculateDateRange,
  parseDateRange,
  getStoreToday,
  DATE_PRESETS,
} from "./datePresets.ts";

test("getStoreToday returns YYYY-MM-DD formatted date in store timezone", () => {
  // 2026-01-01 02:00:00 UTC is still 2025-12-31 in New York (-5h)
  const fixedUtc = new Date("2026-01-01T02:00:00Z");
  assert.equal(getStoreToday("America/New_York", fixedUtc), "2025-12-31");
  assert.equal(getStoreToday("UTC", fixedUtc), "2026-01-01");
  assert.equal(getStoreToday("Asia/Tokyo", fixedUtc), "2026-01-01");
});

test("calculateDateRange calculates today preset", () => {
  const range = calculateDateRange("today", "2026-06-15");
  assert.deepEqual(range, {
    preset: "today",
    from: "2026-06-15",
    to: "2026-06-15",
  });
});

test("calculateDateRange calculates yesterday across month boundaries", () => {
  const normal = calculateDateRange("yesterday", "2026-06-15");
  assert.deepEqual(normal, {
    preset: "yesterday",
    from: "2026-06-14",
    to: "2026-06-14",
  });

  const monthBoundary = calculateDateRange("yesterday", "2026-06-01");
  assert.deepEqual(monthBoundary, {
    preset: "yesterday",
    from: "2026-05-31",
    to: "2026-05-31",
  });
});

test("calculateDateRange calculates 7d, 30d, and 90d presets", () => {
  const d7 = calculateDateRange("7d", "2026-06-15");
  assert.deepEqual(d7, {
    preset: "7d",
    from: "2026-06-09",
    to: "2026-06-15",
  });

  const d30 = calculateDateRange("30d", "2026-06-15");
  assert.deepEqual(d30, {
    preset: "30d",
    from: "2026-05-17",
    to: "2026-06-15",
  });

  const d90 = calculateDateRange("90d", "2026-06-15");
  assert.deepEqual(d90, {
    preset: "90d",
    from: "2026-03-18",
    to: "2026-06-15",
  });
});

test("calculateDateRange calculates this_month preset", () => {
  const range = calculateDateRange("this_month", "2026-06-15");
  assert.deepEqual(range, {
    preset: "this_month",
    from: "2026-06-01",
    to: "2026-06-15",
  });
});

test("calculateDateRange calculates last_month with leap year and year boundary", () => {
  // Non-leap year February (2026)
  const nonLeap = calculateDateRange("last_month", "2026-03-15");
  assert.deepEqual(nonLeap, {
    preset: "last_month",
    from: "2026-02-01",
    to: "2026-02-28",
  });

  // Leap year February (2024)
  const leap = calculateDateRange("last_month", "2024-03-15");
  assert.deepEqual(leap, {
    preset: "last_month",
    from: "2024-02-01",
    to: "2024-02-29",
  });

  // Year boundary (January 2026 -> December 2025)
  const yearBoundary = calculateDateRange("last_month", "2026-01-10");
  assert.deepEqual(yearBoundary, {
    preset: "last_month",
    from: "2025-12-01",
    to: "2025-12-31",
  });
});

test("parseDateRange defaults to 30d when parameters are absent or empty", () => {
  const range = parseDateRange(new URLSearchParams(), "2026-06-15");
  assert.deepEqual(range, {
    preset: "30d",
    from: "2026-05-17",
    to: "2026-06-15",
  });
});

test("parseDateRange recognizes known presets", () => {
  const range = parseDateRange(new URLSearchParams("preset=7d"), "2026-06-15");
  assert.deepEqual(range, {
    preset: "7d",
    from: "2026-06-09",
    to: "2026-06-15",
  });
});

test("parseDateRange parses custom date range when valid", () => {
  const range = parseDateRange(
    new URLSearchParams("preset=custom&from=2026-04-01&to=2026-04-10"),
    "2026-06-15",
  );
  assert.deepEqual(range, {
    preset: "custom",
    from: "2026-04-01",
    to: "2026-04-10",
  });
});

test("parseDateRange falls back to 30d if custom dates are inverted or malformed", () => {
  const inverted = parseDateRange(
    new URLSearchParams("preset=custom&from=2026-04-10&to=2026-04-01"),
    "2026-06-15",
  );
  assert.equal(inverted.preset, "30d");

  const malformed = parseDateRange(
    new URLSearchParams("preset=custom&from=invalid&to=2026-04-01"),
    "2026-06-15",
  );
  assert.equal(malformed.preset, "30d");
});

test("DATE_PRESETS contains all expected options", () => {
  const keys = DATE_PRESETS.map((p) => p.key);
  assert.deepEqual(keys, [
    "today",
    "yesterday",
    "7d",
    "30d",
    "90d",
    "this_month",
    "last_month",
    "custom",
  ]);
});
