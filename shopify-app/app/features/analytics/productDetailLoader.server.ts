import {
  BackendError,
  type BackendClient,
} from "../../lib/backend/backendClient.server.ts";
import {
  fetchProductDetail,
  fetchVariantAnalytics,
  type ProductDetailData,
  type VariantAnalyticsData,
} from "../../lib/backend/analyticsClient.server.ts";
import {
  parseDateRange,
  type DateRange,
} from "../../lib/dates/datePresets.ts";

export interface ProductDetailDashboardData {
  readonly shopDomain: string;
  readonly productId: string;
  readonly dateRange: DateRange;
  readonly productDetail: ProductDetailData | null;
  readonly variantAnalytics: VariantAnalyticsData | null;
  readonly notFound: boolean;
  readonly analyticsError: string | null;
}

export interface LoadProductDetailOptions {
  readonly request: Request;
  readonly shopDomain: string;
  readonly backend: BackendClient;
  readonly productId: string;
  readonly referenceToday?: string;
}

export async function loadProductDetail(
  options: LoadProductDetailOptions,
): Promise<ProductDetailDashboardData> {
  const { request, shopDomain, backend, productId, referenceToday } = options;
  const trimmedId = productId.trim();
  const url = new URL(request.url);
  const dateRange = parseDateRange(url.searchParams, referenceToday);

  if (!trimmedId) {
    return {
      shopDomain,
      productId: "",
      dateRange,
      productDetail: null,
      variantAnalytics: null,
      notFound: true,
      analyticsError: "Product ID is required",
    };
  }

  try {
    const [productDetail, variantAnalytics] = await Promise.all([
      fetchProductDetail(backend, shopDomain, trimmedId, {
        from: dateRange.from,
        to: dateRange.to,
      }),
      fetchVariantAnalytics(backend, shopDomain, trimmedId, {
        from: dateRange.from,
        to: dateRange.to,
      }),
    ]);

    return {
      shopDomain,
      productId: trimmedId,
      dateRange,
      productDetail,
      variantAnalytics,
      notFound: false,
      analyticsError: null,
    };
  } catch (error) {
    if (error instanceof BackendError && error.status === 404) {
      return {
        shopDomain,
        productId: trimmedId,
        dateRange,
        productDetail: null,
        variantAnalytics: null,
        notFound: true,
        analyticsError: null,
      };
    }

    const message = error instanceof Error ? error.message : "Failed to load product analytics";
    return {
      shopDomain,
      productId: trimmedId,
      dateRange,
      productDetail: null,
      variantAnalytics: null,
      notFound: false,
      analyticsError: message,
    };
  }
}
