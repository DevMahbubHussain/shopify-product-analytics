import type { BackendClient } from "../lib/backend/backendClient.server.ts";
import { fetchProductList } from "../lib/backend/analyticsClient.server.ts";
import type { DatePresetKey, DateRange } from "../lib/dates/datePresets.ts";
import { DATE_PRESETS, calculateDateRange, getStoreToday } from "../lib/dates/datePresets.ts";
import type { McpStatusResponse } from "../features/mcp/mcpClient.server.ts";
import { getMcpStatus } from "../features/mcp/mcpClient.server.ts";
import type { PresetOption, ResolvedPromptRange } from "../features/mcp/promptDates.ts";
import { presetOptions, resolvePromptRange } from "../features/mcp/promptDates.ts";
import type { PromptProduct, PromptStoreContext } from "../features/mcp/promptLibrary.ts";
import type { ShopSyncStatus } from "../features/sync/getSyncStatus.server.ts";
import { getSyncStatus } from "../features/sync/getSyncStatus.server.ts";

export const PRODUCT_PICKER_LIMIT = 100;
const SEARCH_MAX_LENGTH = 255;
const DEFAULT_PRESET: DatePresetKey = "30d";
const STATUS_UNAVAILABLE = "The prompt library could not load your store settings. Refresh the page to try again.";

export interface PromptLibraryReady {
  state: "ready";
  store: PromptStoreContext;
  today: string;
  range: DateRange;
  resolved: ResolvedPromptRange | null;
  rangeError: string | null;
  presets: PresetOption[];
  sync: ShopSyncStatus["state"];
  products: PromptProduct[];
  productsTruncated: boolean;
  productsError: boolean;
  productSearch: string;
}

export type PromptLibraryData =
  | PromptLibraryReady
  | { state: "locked"; reason: "plan" | "consent" }
  | { state: "error"; message: string };

function validTimezone(timezone: string): string {
  try {
    new Intl.DateTimeFormat("en-US", { timeZone: timezone });
    return timezone;
  } catch {
    return "UTC";
  }
}

function isPresetKey(value: string | null): value is Exclude<DatePresetKey, "custom"> {
  return DATE_PRESETS.some((p) => p.key === value && p.key !== "custom");
}

function rangeFromParams(params: URLSearchParams, today: string): DateRange {
  const preset = params.get("preset");
  if (preset === "custom") {
    return { preset: "custom", from: params.get("from") ?? "", to: params.get("to") ?? "" };
  }
  return calculateDateRange(isPresetKey(preset) ? preset : DEFAULT_PRESET, today);
}

async function loadProducts(
  backend: BackendClient,
  shop: string,
  today: string,
  search: string,
): Promise<Pick<PromptLibraryReady, "products" | "productsTruncated" | "productsError">> {
  const window = calculateDateRange(DEFAULT_PRESET, today);
  try {
    const list = await fetchProductList(backend, shop, {
      from: window.from,
      to: window.to,
      search: search || undefined,
      sort_by: "title",
      sort_direction: "asc",
      per_page: PRODUCT_PICKER_LIMIT,
    });
    return {
      products: list.data.map(({ handle, title }) => ({ handle, title })),
      productsTruncated: list.meta.total > list.data.length,
      productsError: false,
    };
  } catch (error) {
    console.error("Failed to load products for the prompt library:", error);
    return { products: [], productsTruncated: false, productsError: true };
  }
}

export async function loadPromptLibrary(
  backend: BackendClient,
  shop: string,
  params: URLSearchParams,
  now: Date = new Date(),
): Promise<PromptLibraryData> {
  let status: McpStatusResponse;
  try {
    status = await getMcpStatus(backend, shop);
  } catch (error) {
    console.error("Failed to load MCP status for the prompt library:", error);
    return { state: "error", message: STATUS_UNAVAILABLE };
  }

  if (!status.plan.can_use_mcp) return { state: "locked", reason: "plan" };
  if (!status.has_consent) return { state: "locked", reason: "consent" };

  const timezone = validTimezone(status.store.timezone);
  const store: PromptStoreContext = {
    timezone,
    currency: status.store.currency_code,
    historyStart: status.store.history_start,
  };
  const today = getStoreToday(timezone, now);
  const dateContext = { today, historyStart: store.historyStart, maxRangeDays: status.plan.history_days };

  const range = rangeFromParams(params, today);
  const result = resolvePromptRange(range, dateContext);
  const productSearch = (params.get("q") ?? "").trim().slice(0, SEARCH_MAX_LENGTH);

  const [products, sync] = await Promise.all([
    loadProducts(backend, shop, today, productSearch),
    getSyncStatus({ backend, shopDomain: shop }),
  ]);

  return {
    state: "ready",
    store,
    today,
    range,
    resolved: result.ok ? result : null,
    rangeError: result.ok ? null : result.message,
    presets: presetOptions(dateContext),
    sync: sync.state,
    ...products,
    productSearch,
  };
}
