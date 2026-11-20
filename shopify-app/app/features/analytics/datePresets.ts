export type DatePresetKey =
  | "today"
  | "yesterday"
  | "7d"
  | "30d"
  | "90d"
  | "this_month"
  | "last_month"
  | "custom";

export interface DatePresetOption {
  readonly key: DatePresetKey;
  readonly label: string;
}

export const DATE_PRESETS: readonly DatePresetOption[] = [
  { key: "today", label: "Today" },
  { key: "yesterday", label: "Yesterday" },
  { key: "7d", label: "Last 7 days" },
  { key: "30d", label: "Last 30 days" },
  { key: "90d", label: "Last 90 days" },
  { key: "this_month", label: "This month" },
  { key: "last_month", label: "Last month" },
  { key: "custom", label: "Custom" },
] as const;

export interface DateRange {
  readonly preset: DatePresetKey;
  readonly from: string; // YYYY-MM-DD
  readonly to: string;   // YYYY-MM-DD
}

const ISO_DATE_REGEX = /^\d{4}-\d{2}-\d{2}$/;

export function getStoreToday(timezone: string = "UTC", now: Date = new Date()): string {
  try {
    const formatter = new Intl.DateTimeFormat("en-CA", {
      timeZone: timezone,
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    });
    return formatter.format(now);
  } catch {
    // Fallback to UTC if timezone is invalid
    const formatter = new Intl.DateTimeFormat("en-CA", {
      timeZone: "UTC",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
    });
    return formatter.format(now);
  }
}

function formatUtcDate(date: Date): string {
  const year = date.getUTCFullYear();
  const month = String(date.getUTCMonth() + 1).padStart(2, "0");
  const day = String(date.getUTCDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function calculateDateRange(
  preset: DatePresetKey,
  referenceToday: string = getStoreToday(),
): DateRange {
  const parts = referenceToday.split("-").map(Number);
  const year = parts[0];
  const month = parts[1];
  const day = parts[2];

  switch (preset) {
    case "today":
      return { preset: "today", from: referenceToday, to: referenceToday };

    case "yesterday": {
      const d = new Date(Date.UTC(year, month - 1, day - 1));
      const yestStr = formatUtcDate(d);
      return { preset: "yesterday", from: yestStr, to: yestStr };
    }

    case "7d": {
      const d = new Date(Date.UTC(year, month - 1, day - 6));
      return { preset: "7d", from: formatUtcDate(d), to: referenceToday };
    }

    case "30d": {
      const d = new Date(Date.UTC(year, month - 1, day - 29));
      return { preset: "30d", from: formatUtcDate(d), to: referenceToday };
    }

    case "90d": {
      const d = new Date(Date.UTC(year, month - 1, day - 89));
      return { preset: "90d", from: formatUtcDate(d), to: referenceToday };
    }

    case "this_month": {
      const d = new Date(Date.UTC(year, month - 1, 1));
      return { preset: "this_month", from: formatUtcDate(d), to: referenceToday };
    }

    case "last_month": {
      const first = new Date(Date.UTC(year, month - 2, 1));
      const last = new Date(Date.UTC(year, month - 1, 0));
      return { preset: "last_month", from: formatUtcDate(first), to: formatUtcDate(last) };
    }

    case "custom":
      return calculateDateRange("30d", referenceToday);
  }
}

export function parseDateRange(
  searchParams: URLSearchParams,
  referenceToday: string = getStoreToday(),
): DateRange {
  const presetParam = searchParams.get("preset");
  const fromParam = searchParams.get("from");
  const toParam = searchParams.get("to");

  if (presetParam === "custom" || (!presetParam && fromParam && toParam)) {
    if (
      fromParam &&
      toParam &&
      ISO_DATE_REGEX.test(fromParam) &&
      ISO_DATE_REGEX.test(toParam) &&
      fromParam <= toParam
    ) {
      return { preset: "custom", from: fromParam, to: toParam };
    }
    return calculateDateRange("30d", referenceToday);
  }

  if (
    presetParam &&
    (presetParam === "today" ||
      presetParam === "yesterday" ||
      presetParam === "7d" ||
      presetParam === "30d" ||
      presetParam === "90d" ||
      presetParam === "this_month" ||
      presetParam === "last_month")
  ) {
    return calculateDateRange(presetParam, referenceToday);
  }

  return calculateDateRange("30d", referenceToday);
}

export function dateRangeToSearchParams(range: DateRange): URLSearchParams {
  const params = new URLSearchParams();
  params.set("preset", range.preset);
  params.set("from", range.from);
  params.set("to", range.to);
  return params;
}
