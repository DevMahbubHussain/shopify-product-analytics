import { describe, it } from "node:test";
import assert from "node:assert/strict";
import type { DateRange } from "../../lib/dates/datePresets.ts";
import { calculateDateRange } from "../../lib/dates/datePresets.ts";
import type { PromptDateContext, ResolvedPromptRange } from "./promptDates.ts";
import { presetOptions, resolvePromptRange } from "./promptDates.ts";

const ctx: PromptDateContext = {
  today: "2026-11-25",
  historyStart: "2026-09-26",
  maxRangeDays: 365,
};

function custom(from: string, to: string): DateRange {
  return { preset: "custom", from, to };
}

function resolved(range: DateRange, context: PromptDateContext = ctx): ResolvedPromptRange {
  const result = resolvePromptRange(range, context);
  assert.equal(result.ok, true, result.ok ? "" : result.message);
  return result as ResolvedPromptRange;
}

describe("resolvePromptRange: valid ranges", () => {
  it("resolves last 30 days with an equal-length previous period", () => {
    const r = resolved(calculateDateRange("30d", ctx.today));

    assert.equal(r.from, "2026-10-27");
    assert.equal(r.to, "2026-11-25");
    assert.equal(r.days, 30);
    assert.deepEqual(r.comparison, { from: "2026-09-27", to: "2026-10-26", startsBeforeHistory: false });
  });

  it("counts days inclusively for a single day", () => {
    const r = resolved(custom("2026-11-10", "2026-11-10"));

    assert.equal(r.days, 1);
    assert.deepEqual(r.comparison, { from: "2026-11-09", to: "2026-11-09", startsBeforeHistory: false });
  });

  it("marks today as partial and other ranges as complete", () => {
    assert.equal(resolved(calculateDateRange("today", ctx.today)).partialToday, true);
    assert.equal(resolved(calculateDateRange("30d", ctx.today)).partialToday, true);
    assert.equal(resolved(calculateDateRange("yesterday", ctx.today)).partialToday, false);
  });

  it("compares last month with the previous calendar month", () => {
    const r = resolved(calculateDateRange("last_month", ctx.today));

    assert.equal(r.from, "2026-10-01");
    assert.equal(r.to, "2026-10-31");
    assert.deepEqual(r.comparison, { from: "2026-09-01", to: "2026-09-30", startsBeforeHistory: true });
  });

  it("compares month-to-date with the same days of the previous month", () => {
    const r = resolved(calculateDateRange("this_month", ctx.today));

    assert.equal(r.from, "2026-11-01");
    assert.equal(r.to, "2026-11-25");
    assert.deepEqual(r.comparison, { from: "2026-10-01", to: "2026-10-25", startsBeforeHistory: false });
  });

  it("treats this month on the 1st as a one-day range", () => {
    const first = { ...ctx, today: "2026-11-01" };
    const r = resolved(calculateDateRange("this_month", first.today), first);

    assert.equal(r.days, 1);
    assert.equal(r.partialToday, true);
    assert.deepEqual(r.comparison, { from: "2026-10-01", to: "2026-10-01", startsBeforeHistory: false });
  });

  it("clamps month-to-date comparison to the end of a shorter previous month", () => {
    const leap = { today: "2028-03-31", historyStart: "2028-01-01", maxRangeDays: 365 };
    const r = resolved(calculateDateRange("this_month", leap.today), leap);

    assert.deepEqual(r.comparison, { from: "2028-02-01", to: "2028-02-29", startsBeforeHistory: false });
  });

  it("handles February 29 in last month and its comparison", () => {
    const leap = { today: "2028-03-15", historyStart: "2027-12-01", maxRangeDays: 365 };
    const r = resolved(calculateDateRange("last_month", leap.today), leap);

    assert.equal(r.from, "2028-02-01");
    assert.equal(r.to, "2028-02-29");
    assert.equal(r.days, 29);
    assert.deepEqual(r.comparison, { from: "2028-01-01", to: "2028-01-31", startsBeforeHistory: false });
  });

  it("counts calendar days correctly across a daylight saving change", () => {
    const dst = { today: "2026-03-20", historyStart: "2026-01-01", maxRangeDays: 365 };
    const r = resolved(custom("2026-03-01", "2026-03-14"), dst);

    assert.equal(r.days, 14);
    assert.deepEqual(r.comparison, { from: "2026-02-15", to: "2026-02-28", startsBeforeHistory: false });
  });

  it("chooses day, week or month grouping from the range length", () => {
    assert.equal(resolved(custom("2026-10-26", "2026-11-25")).interval, "day");
    assert.equal(resolved(custom("2026-10-25", "2026-11-25")).interval, "week");
    assert.equal(resolved(custom("2026-07-28", "2026-11-24"), { ...ctx, historyStart: "2026-01-01" }).interval, "week");
    assert.equal(resolved(custom("2026-07-27", "2026-11-24"), { ...ctx, historyStart: "2026-01-01" }).interval, "month");
  });
});

describe("resolvePromptRange: history window", () => {
  it("allows a range that starts before history but flags it", () => {
    const r = resolved(calculateDateRange("90d", ctx.today));

    assert.equal(r.startsBeforeHistory, true);
    assert.equal(r.from, "2026-08-28");
  });

  it("drops the comparison when the previous period ends before history", () => {
    const r = resolved(calculateDateRange("90d", ctx.today));

    assert.equal(r.comparison, null);
  });

  it("flags a comparison that only starts before history", () => {
    const r = resolved(calculateDateRange("30d", { ...ctx, historyStart: "2026-10-01" }.today), {
      ...ctx,
      historyStart: "2026-10-01",
    });

    assert.equal(r.comparison?.startsBeforeHistory, true);
  });

  it("accepts any past range when history start is unknown", () => {
    const r = resolved(custom("2025-01-01", "2025-01-31"), { ...ctx, historyStart: null });

    assert.equal(r.startsBeforeHistory, false);
    assert.notEqual(r.comparison, null);
  });
});

describe("resolvePromptRange: rejected ranges", () => {
  const cases: Array<[string, DateRange, string]> = [
    ["malformed from", custom("2026-13-01", "2026-11-25"), "malformed"],
    ["malformed to", custom("2026-11-01", "25/11/2026"), "malformed"],
    ["impossible date", custom("2026-02-30", "2026-03-01"), "malformed"],
    ["empty", custom("", ""), "malformed"],
    ["reversed", custom("2026-11-20", "2026-11-10"), "reversed"],
    ["future end", custom("2026-11-20", "2026-11-26"), "future"],
    ["ends before history", custom("2026-08-01", "2026-09-25"), "before_history"],
  ];

  for (const [name, range, issue] of cases) {
    it(`rejects ${name}`, () => {
      const result = resolvePromptRange(range, ctx);

      assert.equal(result.ok, false);
      assert.equal(result.ok ? "" : result.issue, issue);
      assert.ok(result.ok ? false : result.message.length > 0);
    });
  }

  it("rejects ranges longer than the plan allows", () => {
    const result = resolvePromptRange(custom("2026-10-01", "2026-11-25"), { ...ctx, maxRangeDays: 30 });

    assert.equal(result.ok, false);
    assert.equal(result.ok ? "" : result.issue, "too_long");
    assert.match(result.ok ? "" : result.message, /30 days/);
  });

  it("accepts a range exactly at the plan limit", () => {
    const r = resolved(custom("2026-10-27", "2026-11-25"), { ...ctx, maxRangeDays: 30 });

    assert.equal(r.days, 30);
  });
});

describe("presetOptions", () => {
  it("lists every preset with custom always available", () => {
    const options = presetOptions(ctx);

    assert.deepEqual(
      options.map((o) => o.key),
      ["today", "yesterday", "7d", "30d", "90d", "this_month", "last_month", "custom"],
    );
    assert.ok(options.every((o) => o.available));
  });

  it("disables presets the plan cannot query, with a reason", () => {
    const ninety = presetOptions({ ...ctx, maxRangeDays: 30 }).find((o) => o.key === "90d");

    assert.equal(ninety?.available, false);
    assert.match(ninety?.reason ?? "", /30 days/);
  });

  it("disables presets that end before history", () => {
    const lastMonth = presetOptions({ ...ctx, historyStart: "2026-11-05" }).find((o) => o.key === "last_month");

    assert.equal(lastMonth?.available, false);
    assert.match(lastMonth?.reason ?? "", /2026-11-05/);
  });
});
