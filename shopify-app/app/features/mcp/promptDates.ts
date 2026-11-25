import type { DatePresetKey, DateRange } from "../../lib/dates/datePresets.ts";
import { DATE_PRESETS, calculateDateRange } from "../../lib/dates/datePresets.ts";

/** All dates are store-local YYYY-MM-DD; ranges are inclusive on both ends. */
export interface PromptDateContext {
  today: string;
  historyStart: string | null;
  maxRangeDays: number;
}

export type PromptInterval = "day" | "week" | "month";

export interface ComparisonRange {
  from: string;
  to: string;
  startsBeforeHistory: boolean;
}

export interface ResolvedPromptRange {
  ok: true;
  from: string;
  to: string;
  days: number;
  interval: PromptInterval;
  partialToday: boolean;
  startsBeforeHistory: boolean;
  comparison: ComparisonRange | null;
}

export type PromptRangeIssue = "malformed" | "reversed" | "future" | "too_long" | "before_history";

export interface RejectedPromptRange {
  ok: false;
  issue: PromptRangeIssue;
  message: string;
}

export interface PresetOption {
  key: DatePresetKey;
  label: string;
  available: boolean;
  reason?: string;
}

const DAY_GROUPING_MAX_DAYS = 31;
const WEEK_GROUPING_MAX_DAYS = 120;
const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const MS_PER_DAY = 86_400_000;

function toUtcDate(value: string): Date | null {
  const match = ISO_DATE.exec(value);
  if (!match) return null;
  const [, y, m, d] = match.map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d
    ? date
    : null;
}

function formatDate(date: Date): string {
  return date.toISOString().slice(0, 10);
}

function addDays(value: string, days: number): string {
  const date = toUtcDate(value) as Date;
  return formatDate(new Date(date.getTime() + days * MS_PER_DAY));
}

function inclusiveDays(from: string, to: string): number {
  return Math.round(((toUtcDate(to) as Date).getTime() - (toUtcDate(from) as Date).getTime()) / MS_PER_DAY) + 1;
}

function lastDayOfMonth(year: number, monthIndex: number): number {
  return new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();
}

function previousCalendarMonth(from: string, to: string, monthToDate: boolean): { from: string; to: string } {
  const start = toUtcDate(from) as Date;
  const year = start.getUTCFullYear();
  const prevMonthIndex = start.getUTCMonth() - 1;
  const prevStart = new Date(Date.UTC(year, prevMonthIndex, 1));
  const prevLastDay = lastDayOfMonth(prevStart.getUTCFullYear(), prevStart.getUTCMonth());
  const endDay = monthToDate ? Math.min((toUtcDate(to) as Date).getUTCDate(), prevLastDay) : prevLastDay;

  return {
    from: formatDate(prevStart),
    to: formatDate(new Date(Date.UTC(prevStart.getUTCFullYear(), prevStart.getUTCMonth(), endDay))),
  };
}

function comparisonPeriod(range: DateRange, days: number): { from: string; to: string } {
  if (range.preset === "last_month") return previousCalendarMonth(range.from, range.to, false);
  if (range.preset === "this_month") return previousCalendarMonth(range.from, range.to, true);

  const to = addDays(range.from, -1);
  return { from: addDays(to, -(days - 1)), to };
}

function chooseInterval(days: number): PromptInterval {
  if (days <= DAY_GROUPING_MAX_DAYS) return "day";
  if (days <= WEEK_GROUPING_MAX_DAYS) return "week";
  return "month";
}

function reject(issue: PromptRangeIssue, message: string): RejectedPromptRange {
  return { ok: false, issue, message };
}

export function resolvePromptRange(
  range: DateRange,
  ctx: PromptDateContext,
): ResolvedPromptRange | RejectedPromptRange {
  if (!toUtcDate(range.from) || !toUtcDate(range.to)) {
    return reject("malformed", "Enter valid dates as YYYY-MM-DD.");
  }
  if (range.from > range.to) {
    return reject("reversed", "The start date must be on or before the end date.");
  }
  if (range.to > ctx.today) {
    return reject("future", `The end date is after today (${ctx.today}) in the store's timezone.`);
  }

  const days = inclusiveDays(range.from, range.to);
  if (days > ctx.maxRangeDays) {
    return reject(
      "too_long",
      `Your plan allows ranges of up to ${ctx.maxRangeDays} days; this range is ${days} days.`,
    );
  }
  if (ctx.historyStart !== null && range.to < ctx.historyStart) {
    return reject(
      "before_history",
      `This range ends before the store's imported history, which starts on ${ctx.historyStart}.`,
    );
  }

  const previous = comparisonPeriod(range, days);
  const comparison =
    ctx.historyStart !== null && previous.to < ctx.historyStart
      ? null
      : {
          ...previous,
          startsBeforeHistory: ctx.historyStart !== null && previous.from < ctx.historyStart,
        };

  return {
    ok: true,
    from: range.from,
    to: range.to,
    days,
    interval: chooseInterval(days),
    partialToday: range.to === ctx.today,
    startsBeforeHistory: ctx.historyStart !== null && range.from < ctx.historyStart,
    comparison,
  };
}

export function presetOptions(ctx: PromptDateContext): PresetOption[] {
  return DATE_PRESETS.map(({ key, label }) => {
    if (key === "custom") return { key, label, available: true };

    const result = resolvePromptRange(calculateDateRange(key, ctx.today), ctx);
    return result.ok ? { key, label, available: true } : { key, label, available: false, reason: result.message };
  });
}
