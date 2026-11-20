import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import type { ShopSyncStatus } from "../sync/getSyncStatus.server.ts";
import {
  fetchOverview,
  fetchSalesTrend,
  fetchProductList,
  type OverviewAnalyticsData,
  type SalesTrendData,
  type ProductListResponse,
  type ProductListParams,
} from "../../lib/backend/analyticsClient.server.ts";
import {
  parseDateRange,
  type DateRange,
} from "./datePresets.ts";

export interface OverviewDashboardData {
  readonly shopDomain: string;
  readonly syncStatus: ShopSyncStatus;
  readonly dateRange: DateRange;
  readonly overview: OverviewAnalyticsData | null;
  readonly salesTrend: SalesTrendData | null;
  readonly products: ProductListResponse | null;
  readonly analyticsError: string | null;
}

export interface LoadOverviewDashboardOptions {
  readonly request: Request;
  readonly shopDomain: string;
  readonly backend: BackendClient;
  readonly syncStatus: ShopSyncStatus;
  readonly referenceToday?: string;
}

const VALID_SORT_FIELDS: ReadonlySet<string> = new Set([
  "net_sales",
  "gross_sales",
  "units_sold",
  "orders",
  "title",
]);

export async function loadOverviewDashboard(
  options: LoadOverviewDashboardOptions,
): Promise<OverviewDashboardData> {
  const { request, shopDomain, backend, syncStatus, referenceToday } = options;
  const url = new URL(request.url);
  const searchParams = url.searchParams;

  const dateRange = parseDateRange(searchParams, referenceToday);

  if (syncStatus.state === "backend_error") {
    return {
      shopDomain,
      syncStatus,
      dateRange,
      overview: null,
      salesTrend: null,
      products: null,
      analyticsError: syncStatus.error ?? "Analytics service unavailable",
    };
  }

  const rawSortBy = searchParams.get("sort_by") ?? "net_sales";
  const sortBy = VALID_SORT_FIELDS.has(rawSortBy)
    ? (rawSortBy as ProductListParams["sort_by"])
    : "net_sales";

  const rawSortDir = searchParams.get("sort_direction");
  const sortDirection = rawSortDir === "asc" ? "asc" : "desc";

  const rawPage = parseInt(searchParams.get("page") ?? "1", 10);
  const page = Number.isFinite(rawPage) && rawPage > 0 ? rawPage : 1;

  const rawPerPage = parseInt(searchParams.get("per_page") ?? "10", 10);
  const perPage = Number.isFinite(rawPerPage) && rawPerPage > 0 ? rawPerPage : 10;

  const intervalParam = searchParams.get("interval");
  const interval =
    intervalParam === "week" || intervalParam === "month" || intervalParam === "day"
      ? intervalParam
      : undefined;

  const productParams: ProductListParams = {
    from: dateRange.from,
    to: dateRange.to,
    search: searchParams.get("search") ?? undefined,
    vendor: searchParams.get("vendor") ?? undefined,
    product_type: searchParams.get("product_type") ?? undefined,
    status: searchParams.get("status") ?? undefined,
    sort_by: sortBy,
    sort_direction: sortDirection,
    page,
    per_page: perPage,
  };

  try {
    const [overview, salesTrend, products] = await Promise.all([
      fetchOverview(backend, shopDomain, {
        from: dateRange.from,
        to: dateRange.to,
      }),
      fetchSalesTrend(backend, shopDomain, {
        from: dateRange.from,
        to: dateRange.to,
        interval,
      }),
      fetchProductList(backend, shopDomain, productParams),
    ]);

    return {
      shopDomain,
      syncStatus,
      dateRange,
      overview,
      salesTrend,
      products,
      analyticsError: null,
    };
  } catch (error) {
    const message = error instanceof Error ? error.message : "Failed to load analytics";
    return {
      shopDomain,
      syncStatus,
      dateRange,
      overview: null,
      salesTrend: null,
      products: null,
      analyticsError: message,
    };
  }
}
