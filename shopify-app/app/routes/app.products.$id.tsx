import { useCallback, useEffect, useState } from "react";
import type {
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import {
  Link,
  useLoaderData,
  useNavigate,
  useNavigation,
  useSearchParams,
} from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server.ts";
import { backendClient } from "../lib/backend/backendClient.server.ts";
import {
  loadProductDetail,
  type ProductDetailDashboardData,
} from "../features/analytics/productDetailLoader.server.ts";
import { DATE_PRESETS } from "../lib/dates/datePresets.ts";
import type { MetricComparison } from "../lib/backend/analyticsClient.server.ts";
import {
  ProductDetailSkeleton,
  ShimmerStyle,
} from "../features/analytics/AnalyticsSkeleton.tsx";

export const loader = async ({ request, params }: LoaderFunctionArgs) => {
  const { session } = await authenticate.admin(request);
  const backend = backendClient();

  const data: ProductDetailDashboardData = await loadProductDetail({
    request,
    shopDomain: session.shop,
    backend,
    productId: params.id ?? "",
  });

  return data;
};

function formatMoney(amount: string, currency: string = "USD"): string {
  const num = Number(amount);
  if (!Number.isFinite(num)) return `$${amount}`;
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: currency || "USD",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num);
  } catch {
    return `$${num.toFixed(2)}`;
  }
}

function ComparisonBadge({ comparison }: { comparison?: MetricComparison }) {
  if (!comparison) return null;

  const { delta, percentage_change, direction } = comparison;
  if (percentage_change === null && direction === "flat") {
    return <s-badge tone="info">0.0%</s-badge>;
  }

  const sign = direction === "up" ? "+" : "";
  const pctText =
    percentage_change !== null
      ? `${sign}${percentage_change.toFixed(1)}%`
      : `${sign}${delta}`;

  if (direction === "up") {
    return <s-badge tone="success">▲ {pctText}</s-badge>;
  }
  if (direction === "down") {
    return <s-badge tone="critical">▼ {pctText}</s-badge>;
  }
  return <s-badge tone="info">{pctText}</s-badge>;
}

export default function ProductDetailPage() {
  const {
    productId,
    dateRange,
    productDetail,
    variantAnalytics,
    notFound,
    analyticsError,
  } = useLoaderData<typeof loader>();

  const navigation = useNavigation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const isLoading = navigation.state === "loading";
  const currentPreset = dateRange.preset;
  const currency = productDetail?.currency || "USD";

  const buildUrl = useCallback(
    (updates: Record<string, string | number | undefined>): string => {
      const next = new URLSearchParams(searchParams);
      for (const [key, value] of Object.entries(updates)) {
        if (value === undefined || value === "") {
          next.delete(key);
        } else {
          next.set(key, String(value));
        }
      }
      return `?${next.toString()}`;
    },
    [searchParams],
  );

  const handleNavigate = useCallback(
    (updates: Record<string, string | number | undefined>) => {
      navigate(buildUrl(updates), { preventScrollReset: true });
    },
    [buildUrl, navigate],
  );

  const [showCustomPicker, setShowCustomPicker] = useState(
    currentPreset === "custom",
  );
  const [customFrom, setCustomFrom] = useState(dateRange.from);
  const [customTo, setCustomTo] = useState(dateRange.to);
  const [dateError, setDateError] = useState<string | null>(null);

  useEffect(() => {
    setCustomFrom(dateRange.from);
    setCustomTo(dateRange.to);
    if (currentPreset === "custom") {
      setShowCustomPicker(true);
    }
  }, [dateRange, currentPreset]);

  const handleApplyCustomDate = useCallback(() => {
    if (!customFrom || !customTo) {
      setDateError("Please select both start and end dates.");
      return;
    }
    if (customFrom > customTo) {
      setDateError("Start date cannot be after end date.");
      return;
    }
    setDateError(null);
    handleNavigate({
      preset: "custom",
      from: customFrom,
      to: customTo,
    });
  }, [customFrom, customTo, handleNavigate]);

  if (notFound || !productDetail) {
    return (
      <s-page heading="Product Analytics">
        <s-banner heading="Product not found" tone="critical">
          <s-paragraph>
            Product #{productId} could not be found in your store&apos;s catalogue. It may have
            been deleted or not yet synchronized.
          </s-paragraph>
          <div style={{ marginTop: "12px" }}>
            <Link to="/app" style={{ textDecoration: "none" }}>
              <s-button variant="primary">Return to Overview</s-button>
            </Link>
          </div>
        </s-banner>
      </s-page>
    );
  }

  const prod = productDetail.product;
  const summary = productDetail.summary;
  const comparison = productDetail.comparison;
  const variants = variantAnalytics?.variants ?? [];

  const backUrl =
    currentPreset === "custom"
      ? `/app?preset=custom&from=${dateRange.from}&to=${dateRange.to}`
      : `/app?preset=${currentPreset}`;

  return (
    <s-page heading={prod.title}>
      <ShimmerStyle />

      {/* Navigation Breadcrumb */}
      <div style={{ marginBottom: "16px" }}>
        <Link
          to={backUrl}
          style={{
            color: "#005bd3",
            textDecoration: "none",
            fontSize: "14px",
            fontWeight: 500,
          }}
        >
          ← Back to Overview
        </Link>
      </div>

      {analyticsError && (
        <s-banner heading="Analytics notice" tone="warning">
          <s-paragraph>{analyticsError}</s-paragraph>
        </s-banner>
      )}

      {/* Date Range Selector Toolbar */}
      <s-section>
        <div
          style={{
            display: "flex",
            flexWrap: "wrap",
            justifyContent: "space-between",
            alignItems: "center",
            gap: "12px",
            marginBottom: "8px",
          }}
        >
          <div style={{ display: "flex", flexWrap: "wrap", gap: "6px" }}>
            {DATE_PRESETS.map((preset) => {
              const isActive =
                preset.key === "custom"
                  ? currentPreset === "custom" || showCustomPicker
                  : currentPreset === preset.key;

              return (
                <s-button
                  key={preset.key}
                  variant={isActive ? "primary" : "secondary"}
                  onClick={() => {
                    if (preset.key === "custom") {
                      setShowCustomPicker((prev) => !prev);
                    } else {
                      setShowCustomPicker(false);
                      handleNavigate({
                        preset: preset.key,
                        from: undefined,
                        to: undefined,
                      });
                    }
                  }}
                >
                  {preset.label}
                </s-button>
              );
            })}
          </div>
          <div style={{ fontSize: "13px", color: "#616161" }}>
            <strong>{dateRange.from}</strong> to <strong>{dateRange.to}</strong>
          </div>
        </div>

        {/* Polaris Custom Date Range Selector Panel */}
        {showCustomPicker && (
          <div
            style={{
              background: "#ffffff",
              border: "1px solid #e1e3e5",
              borderRadius: "8px",
              padding: "16px",
              marginTop: "8px",
              marginBottom: "8px",
            }}
          >
            <div
              style={{
                display: "flex",
                flexWrap: "wrap",
                gap: "16px",
                alignItems: "flex-end",
              }}
            >
              <div style={{ minWidth: "160px" }}>
                <s-date-field
                  label="Start date"
                  value={customFrom}
                  defaultValue={customFrom}
                  onChange={(e: Event) => {
                    const target = (e.currentTarget || e.target) as HTMLInputElement | null;
                    const val = target?.value;
                    if (val) {
                      setCustomFrom(val);
                      setDateError(null);
                    }
                  }}
                  onInput={(e: Event) => {
                    const target = (e.currentTarget || e.target) as HTMLInputElement | null;
                    const val = target?.value;
                    if (val) {
                      setCustomFrom(val);
                      setDateError(null);
                    }
                  }}
                />
              </div>
              <div style={{ minWidth: "160px" }}>
                <s-date-field
                  label="End date"
                  value={customTo}
                  defaultValue={customTo}
                  onChange={(e: Event) => {
                    const target = (e.currentTarget || e.target) as HTMLInputElement | null;
                    const val = target?.value;
                    if (val) {
                      setCustomTo(val);
                      setDateError(null);
                    }
                  }}
                  onInput={(e: Event) => {
                    const target = (e.currentTarget || e.target) as HTMLInputElement | null;
                    const val = target?.value;
                    if (val) {
                      setCustomTo(val);
                      setDateError(null);
                    }
                  }}
                />
              </div>
              <div style={{ display: "flex", gap: "8px" }}>
                <s-button variant="primary" onClick={handleApplyCustomDate}>
                  Apply Range
                </s-button>
                <s-button
                  variant="secondary"
                  onClick={() => {
                    setShowCustomPicker(false);
                    setCustomFrom(dateRange.from);
                    setCustomTo(dateRange.to);
                    setDateError(null);
                  }}
                >
                  Cancel
                </s-button>
              </div>
            </div>

            {dateError && (
              <div
                style={{
                  fontSize: "12px",
                  color: "#b00020",
                  marginTop: "8px",
                }}
              >
                {dateError}
              </div>
            )}
          </div>
        )}
      </s-section>

      {/* Main Content or Loading Skeleton */}
      {isLoading ? (
        <ProductDetailSkeleton />
      ) : (
        <>
          {/* Product Header Card */}
          <s-section>
            <div
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
                display: "flex",
                alignItems: "center",
                gap: "16px",
              }}
            >
              {prod.image_url ? (
                <img
                  src={prod.image_url}
                  alt={prod.title}
                  style={{
                    width: "56px",
                    height: "56px",
                    objectFit: "cover",
                    borderRadius: "6px",
                    border: "1px solid #e1e3e5",
                  }}
                />
              ) : (
                <div
                  style={{
                    width: "56px",
                    height: "56px",
                    background: "#f1f2f3",
                    borderRadius: "6px",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    fontSize: "24px",
                  }}
                >
                  📦
                </div>
              )}

              <div style={{ flex: 1 }}>
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "8px",
                    marginBottom: "4px",
                  }}
                >
                  <h2 style={{ fontSize: "18px", fontWeight: 700, margin: 0 }}>
                    {prod.title}
                  </h2>
                  <s-badge
                    tone={
                      prod.status === "ACTIVE"
                        ? "success"
                        : prod.status === "DRAFT"
                        ? "warning"
                        : "info"
                    }
                  >
                    {prod.status}
                  </s-badge>
                </div>
                <div style={{ fontSize: "13px", color: "#616161" }}>
                  Vendor: <strong>{prod.vendor || "None"}</strong> • Type:{" "}
                  <strong>{prod.product_type || "None"}</strong> • Handle:{" "}
                  <code>{prod.handle}</code>
                </div>
              </div>
            </div>
          </s-section>

          {/* Product KPI Cards */}
          <s-section heading="Product Performance Summary">
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))",
                gap: "16px",
              }}
            >
              {/* Net Sales */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Net Sales
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                  }}
                >
                  {formatMoney(summary.net_sales, currency)}
                </div>
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "8px",
                    fontSize: "12px",
                  }}
                >
                  <ComparisonBadge comparison={comparison.net_sales} />
                  <span style={{ color: "#616161" }}>vs previous period</span>
                </div>
              </div>

              {/* Gross Sales */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Gross Sales
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                  }}
                >
                  {formatMoney(summary.gross_sales, currency)}
                </div>
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "8px",
                    fontSize: "12px",
                  }}
                >
                  <ComparisonBadge comparison={comparison.gross_sales} />
                  <span style={{ color: "#616161" }}>vs previous period</span>
                </div>
              </div>

              {/* Discounts */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Discounts
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                  }}
                >
                  {formatMoney(summary.discounts, currency)}
                </div>
                <div style={{ fontSize: "12px", color: "#616161" }}>
                  Applied discounts
                </div>
              </div>

              {/* Refunds */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Refunds
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                    color: Number(summary.refunds) > 0 ? "#b00020" : "#202223",
                  }}
                >
                  {formatMoney(summary.refunds, currency)}
                </div>
                <div style={{ fontSize: "12px", color: "#616161" }}>
                  Refunded amount
                </div>
              </div>

              {/* Units Sold */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Units Sold
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                  }}
                >
                  {summary.units_sold.toLocaleString()}
                </div>
                <div
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: "8px",
                    fontSize: "12px",
                  }}
                >
                  <ComparisonBadge comparison={comparison.units_sold} />
                  <span style={{ color: "#616161" }}>vs previous period</span>
                </div>
              </div>

              {/* Orders & AOR */}
              <div
                style={{
                  background: "#ffffff",
                  border: "1px solid #e1e3e5",
                  borderRadius: "8px",
                  padding: "16px",
                }}
              >
                <div
                  style={{
                    fontSize: "13px",
                    color: "#616161",
                    marginBottom: "4px",
                  }}
                >
                  Orders
                </div>
                <div
                  style={{
                    fontSize: "24px",
                    fontWeight: 700,
                    marginBottom: "8px",
                  }}
                >
                  {summary.orders.toLocaleString()}
                </div>
                <div style={{ fontSize: "12px", color: "#616161" }}>
                  AOR:{" "}
                  <strong>
                    {formatMoney(summary.average_order_revenue, currency)}
                  </strong>
                </div>
              </div>
            </div>
          </s-section>

          {/* Variant Performance Breakdown */}
          <s-section heading="Variant Performance Breakdown">
            <div
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                overflow: "hidden",
              }}
            >
              {variants.length > 0 ? (
                <s-table>
                  <s-table-header-row>
                    <s-table-header>Variant</s-table-header>
                    <s-table-header>SKU</s-table-header>
                    <s-table-header>Price</s-table-header>
                    <s-table-header>Gross Sales</s-table-header>
                    <s-table-header>Discounts</s-table-header>
                    <s-table-header>Refunds</s-table-header>
                    <s-table-header>Net Sales</s-table-header>
                    <s-table-header>Units Sold</s-table-header>
                    <s-table-header>Orders</s-table-header>
                  </s-table-header-row>
                  <s-table-body>
                    {variants.map((v) => (
                  <s-table-row key={v.id}>
                    <s-table-cell>
                      <strong>{v.title}</strong>
                    </s-table-cell>
                    <s-table-cell>{v.sku || "—"}</s-table-cell>
                    <s-table-cell>{formatMoney(v.price, currency)}</s-table-cell>
                    <s-table-cell>{formatMoney(v.gross_sales, currency)}</s-table-cell>
                    <s-table-cell>{formatMoney(v.discounts, currency)}</s-table-cell>
                    <s-table-cell>{formatMoney(v.refunds, currency)}</s-table-cell>
                    <s-table-cell>
                      <strong>{formatMoney(v.net_sales, currency)}</strong>
                    </s-table-cell>
                    <s-table-cell>{v.units_sold.toLocaleString()}</s-table-cell>
                    <s-table-cell>{v.orders.toLocaleString()}</s-table-cell>
                  </s-table-row>
                ))}
              </s-table-body>
            </s-table>
          ) : (
            <div style={{ padding: "40px", textAlign: "center", color: "#616161" }}>
              <div style={{ fontSize: "28px", marginBottom: "8px" }}>🏷️</div>
              <div style={{ fontWeight: 600, marginBottom: "4px" }}>No variant sales in this period</div>
              <div style={{ fontSize: "13px" }}>
                This product has no recorded line items or refunds within the selected date range.
              </div>
            </div>
          )}
        </div>
      </s-section>
        </>
      )}
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
