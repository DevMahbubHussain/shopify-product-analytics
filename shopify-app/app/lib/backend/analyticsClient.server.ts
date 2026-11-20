import type { BackendClient, QueryParams } from "./backendClient.server.ts";

export interface MetricComparison {
  readonly delta: string | number;
  readonly percentage_change: number | null;
  readonly direction: "up" | "down" | "flat";
}

export interface OverviewSummary {
  readonly gross_sales: string;
  readonly discounts: string;
  readonly refunds: string;
  readonly net_sales: string;
  readonly units_sold: number;
  readonly orders: number;
  readonly average_order_revenue: string;
}

export interface OverviewComparison {
  readonly gross_sales: MetricComparison;
  readonly net_sales: MetricComparison;
  readonly units_sold: MetricComparison;
  readonly orders: MetricComparison;
}

export interface OverviewAnalyticsData {
  readonly date_range: { readonly from: string; readonly to: string };
  readonly previous_date_range: { readonly from: string; readonly to: string };
  readonly currency: string;
  readonly summary: OverviewSummary;
  readonly comparison: OverviewComparison;
}

export interface SalesTrendPoint {
  readonly date: string;
  readonly gross_sales: string;
  readonly discounts: string;
  readonly refunds: string;
  readonly net_sales: string;
  readonly units_sold: number;
  readonly orders: number;
}

export interface SalesTrendData {
  readonly interval: "day" | "week" | "month";
  readonly currency: string;
  readonly points: readonly SalesTrendPoint[];
}

export interface ProductPerformanceItem {
  readonly id: string;
  readonly shopify_product_gid: string;
  readonly title: string;
  readonly handle: string;
  readonly vendor: string | null;
  readonly product_type: string | null;
  readonly status: string;
  readonly image_url: string | null;
  readonly gross_sales: string;
  readonly discounts: string;
  readonly refunds: string;
  readonly net_sales: string;
  readonly units_sold: number;
  readonly orders: number;
}

export interface PaginationMeta {
  readonly current_page: number;
  readonly from: number | null;
  readonly last_page: number;
  readonly per_page: number;
  readonly to: number | null;
  readonly total: number;
}

export interface ProductListResponse {
  readonly data: readonly ProductPerformanceItem[];
  readonly meta: PaginationMeta;
}

export interface ProductListParams {
  readonly from: string;
  readonly to: string;
  readonly search?: string;
  readonly vendor?: string;
  readonly product_type?: string;
  readonly status?: string;
  readonly sort_by?: "net_sales" | "gross_sales" | "units_sold" | "orders" | "title";
  readonly sort_direction?: "asc" | "desc";
  readonly page?: number;
  readonly per_page?: number;
}

export interface ProductDetailData {
  readonly product: {
    readonly id: string;
    readonly shopify_product_gid: string;
    readonly title: string;
    readonly handle: string;
    readonly vendor: string | null;
    readonly product_type: string | null;
    readonly status: string;
    readonly image_url: string | null;
  };
  readonly date_range: { readonly from: string; readonly to: string };
  readonly previous_date_range: { readonly from: string; readonly to: string };
  readonly currency: string;
  readonly summary: OverviewSummary;
  readonly comparison: OverviewComparison;
}

export interface VariantAnalyticsItem {
  readonly id: string;
  readonly shopify_variant_gid: string;
  readonly title: string;
  readonly sku: string | null;
  readonly price: string;
  readonly gross_sales: string;
  readonly discounts: string;
  readonly refunds: string;
  readonly net_sales: string;
  readonly units_sold: number;
  readonly orders: number;
}

export interface VariantAnalyticsData {
  readonly product_id: string;
  readonly date_range: { readonly from: string; readonly to: string };
  readonly currency: string;
  readonly variants: readonly VariantAnalyticsItem[];
}

export async function fetchOverview(
  backend: BackendClient,
  shopDomain: string,
  params: { from: string; to: string },
): Promise<OverviewAnalyticsData> {
  const response = await backend.getInternal<{ data: OverviewAnalyticsData }>(
    "/api/v1/analytics/overview",
    shopDomain,
    undefined,
    params,
  );
  return response.data;
}

export async function fetchSalesTrend(
  backend: BackendClient,
  shopDomain: string,
  params: { from: string; to: string; interval?: "day" | "week" | "month" },
): Promise<SalesTrendData> {
  const response = await backend.getInternal<{ data: SalesTrendData }>(
    "/api/v1/analytics/sales-trend",
    shopDomain,
    undefined,
    params,
  );
  return response.data;
}

export async function fetchProductList(
  backend: BackendClient,
  shopDomain: string,
  params: ProductListParams,
): Promise<ProductListResponse> {
  const queryParams: QueryParams = {
    from: params.from,
    to: params.to,
    search: params.search,
    vendor: params.vendor,
    product_type: params.product_type,
    status: params.status,
    sort_by: params.sort_by,
    sort_direction: params.sort_direction,
    page: params.page,
    per_page: params.per_page,
  };

  return backend.getInternal<ProductListResponse>(
    "/api/v1/products",
    shopDomain,
    undefined,
    queryParams,
  );
}

export async function fetchProductDetail(
  backend: BackendClient,
  shopDomain: string,
  productId: string,
  params: { from: string; to: string },
): Promise<ProductDetailData> {
  const response = await backend.getInternal<{ data: ProductDetailData }>(
    `/api/v1/products/${encodeURIComponent(productId)}/analytics`,
    shopDomain,
    undefined,
    params,
  );
  return response.data;
}

export async function fetchVariantAnalytics(
  backend: BackendClient,
  shopDomain: string,
  productId: string,
  params: { from: string; to: string },
): Promise<VariantAnalyticsData> {
  const response = await backend.getInternal<{ data: VariantAnalyticsData }>(
    `/api/v1/products/${encodeURIComponent(productId)}/variants/analytics`,
    shopDomain,
    undefined,
    params,
  );
  return response.data;
}
