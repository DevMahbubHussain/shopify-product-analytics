import { useCallback, useEffect, useState } from "react";
import type {
  ActionFunctionArgs,
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import {
  Link,
  useFetcher,
  useLoaderData,
  useNavigate,
  useNavigation,
  useRevalidator,
  useSearchParams,
} from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate, unauthenticated } from "../shopify.server.ts";
import { backendClient } from "../lib/backend/backendClient.server.ts";
import {
  getSyncStatus,
  type ShopSyncStatus,
} from "../features/sync/getSyncStatus.server.ts";
import { triggerHistoricalSync } from "../features/sync/syncTrigger.server.ts";
import {
  loadOverviewDashboard,
  type OverviewDashboardData,
} from "../features/analytics/overviewLoader.server.ts";
import { DATE_PRESETS } from "../features/analytics/datePresets.ts";
import type { MetricComparison } from "../lib/backend/analyticsClient.server.ts";
import {
  KpiCardsSkeleton,
  ProductTableSkeleton,
  ShimmerStyle,
  TrendChartSkeleton,
} from "../features/analytics/AnalyticsSkeleton.tsx";
import { SyncProgressCard } from "../features/analytics/SyncProgressCard.tsx";
import { EmptyStateCard } from "../features/analytics/EmptyStateCard.tsx";
import {
  getEmptyStateConfig,
  type EmptyStateAction,
} from "../features/analytics/emptyState.ts";

type SyncState = ShopSyncStatus["state"];

const RETRYABLE_STATES: ReadonlySet<SyncState> = new Set(["failed", "stalled"]);

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);
  const backend = backendClient();
  const shopDomain = session.shop;

  let syncStatus: ShopSyncStatus;
  try {
    syncStatus = await getSyncStatus({ backend, shopDomain });

    if (syncStatus.state === "never_synced") {
      const triggerResult = triggerHistoricalSync({
        admin,
        backend,
        shopDomain,
        getAdmin: async (shop) => (await unauthenticated.admin(shop)).admin,
      });

      if (triggerResult.started) {
        syncStatus = { state: "syncing", syncRun: null };
      }
    }
  } catch (error) {
    console.error(`Backend unreachable in app loader for ${shopDomain}:`, error);
    syncStatus = {
      state: "backend_error",
      syncRun: null,
      error: error instanceof Error ? error.message : "Service unavailable",
    };
  }

  const dashboardData: OverviewDashboardData = await loadOverviewDashboard({
    request,
    shopDomain,
    backend,
    syncStatus,
  });

  return dashboardData;
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const { admin, session } = await authenticate.admin(request);
  const backend = backendClient();
  const shopDomain = session.shop;

  const formData = await request.formData();
  const intent = formData.get("intent")?.toString() || "retry";

  try {
    const syncStatus = await getSyncStatus({ backend, shopDomain });

    if (intent === "sync") {
      if (syncStatus.state === "syncing") {
        return { retried: false, state: syncStatus.state };
      }
    } else if (!RETRYABLE_STATES.has(syncStatus.state)) {
      return { retried: false, state: syncStatus.state };
    }

    const triggerResult = triggerHistoricalSync({
      admin,
      backend,
      shopDomain,
      getAdmin: async (shop) => (await unauthenticated.admin(shop)).admin,
    });

    return { retried: triggerResult.started, state: syncStatus.state };
  } catch (error) {
    console.error(`Backend unreachable in app action for ${shopDomain}:`, error);
    return { retried: false, state: "backend_error" as const };
  }
};

function RetryButton() {
  const fetcher = useFetcher<typeof action>();
  const submitting = fetcher.state !== "idle";

  return (
    <fetcher.Form method="post">
      <input type="hidden" name="intent" value="retry" />
      <s-button type="submit" variant="primary" loading={submitting ? true : undefined}>
        Retry sync
      </s-button>
    </fetcher.Form>
  );
}

function SyncStoreButton({ isSyncing }: { isSyncing: boolean }) {
  const fetcher = useFetcher<typeof action>();
  const submitting = fetcher.state !== "idle" || isSyncing;

  return (
    <fetcher.Form method="post" style={{ display: "inline-block" }}>
      <input type="hidden" name="intent" value="sync" />
      <s-button
        type="submit"
        variant="secondary"
        loading={submitting ? true : undefined}
      >
        {submitting ? "Syncing..." : "Sync Store"}
      </s-button>
    </fetcher.Form>
  );
}

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

function formatSyncTime(isoString: string | null | undefined): string | null {
  if (!isoString) return null;
  try {
    const date = new Date(isoString);
    if (isNaN(date.getTime())) return null;
    return new Intl.DateTimeFormat("en-US", {
      month: "short",
      day: "numeric",
      hour: "numeric",
      minute: "2-digit",
    }).format(date);
  } catch {
    return null;
  }
}

function ComparisonBadge({ comparison }: { comparison?: MetricComparison }) {
  if (!comparison) return null;

  const { percentage_change, direction } = comparison;
  const dir = String(direction);
  const isUp = dir === "increase" || dir === "up";
  const isDown = dir === "decrease" || dir === "down";
  const isFlat = dir === "unchanged" || dir === "flat";

  if (percentage_change === null && isFlat) {
    return <s-badge tone="info">0.0%</s-badge>;
  }

  const sign = isUp ? "+" : "";
  const pctText =
    percentage_change !== null
      ? `${sign}${percentage_change.toFixed(1)}%`
      : isUp
        ? "+100%"
        : isDown
          ? "-100%"
          : "0.0%";

  if (isUp) {
    return <s-badge tone="success">▲ {pctText}</s-badge>;
  }
  if (isDown) {
    return <s-badge tone="critical">▼ {pctText}</s-badge>;
  }
  return <s-badge tone="info">{pctText}</s-badge>;
}

function ExportButton({ from, to }: { from: string; to: string }) {
  const [exportState, setExportState] = useState<
    "idle" | "requesting" | "polling" | "ready" | "error"
  >("idle");
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  async function handleExport() {
    setExportState("requesting");
    setErrorMessage(null);

    const formData = new FormData();
    formData.append("from", from);
    formData.append("to", to);

    try {
      const res = await fetch("/app/export", {
        method: "POST",
        body: formData,
      });

      const data = (await res.json()) as {
        exportId?: string;
        message?: string;
      };

      if (!res.ok) {
        if (res.status === 403) {
          setErrorMessage(
            data.message || "CSV exports require an active Pro subscription.",
          );
        } else {
          setErrorMessage(data.message || "Export failed. Please try again.");
        }
        setExportState("error");
        return;
      }

      const exportId = data.exportId;
      setExportState("polling");

      const pollInterval = setInterval(async () => {
        try {
          const pollRes = await fetch(`/app/export?id=${exportId}`);
          const pollData = (await pollRes.json()) as { status: string };

          if (pollData.status === "COMPLETED") {
            clearInterval(pollInterval);
            setExportState("ready");
            try {
              const downloadRes = await fetch(
                `/app/export?id=${exportId}&download=true`,
              );
              if (!downloadRes.ok) {
                setErrorMessage("Failed to download CSV file.");
                setExportState("error");
                return;
              }
              const blob = await downloadRes.blob();
              const downloadUrl = window.URL.createObjectURL(blob);
              const a = document.createElement("a");
              a.href = downloadUrl;
              a.download = `products_${from}_${to}.csv`;
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
              window.URL.revokeObjectURL(downloadUrl);
              setTimeout(() => setExportState("idle"), 2000);
            } catch {
              setErrorMessage("Failed to download CSV.");
              setExportState("error");
            }
          } else if (pollData.status === "FAILED") {
            clearInterval(pollInterval);
            setErrorMessage("Export processing failed on worker.");
            setExportState("error");
          }
        } catch {
          clearInterval(pollInterval);
          setErrorMessage("Failed to check export status.");
          setExportState("error");
        }
      }, 1500);
    } catch {
      setErrorMessage("Network error initiating export.");
      setExportState("error");
    }
  }

  return (
    <div style={{ display: "flex", alignItems: "center", gap: "8px" }}>
      <s-button
        variant="secondary"
        onClick={handleExport}
        loading={
          exportState === "requesting" || exportState === "polling"
            ? true
            : undefined
        }
      >
        {exportState === "polling"
          ? "Generating CSV..."
          : exportState === "ready"
            ? "Downloading..."
            : "Export CSV"}
      </s-button>
      {errorMessage && (
        <span
          style={{
            fontSize: "12px",
            color: "#b00020",
            display: "inline-flex",
            alignItems: "center",
            gap: "4px",
          }}
        >
          {errorMessage}
          {errorMessage.includes("Pro") && (
            <Link
              to="/app/pricing"
              style={{ color: "#008060", fontWeight: 600 }}
            >
              Upgrade
            </Link>
          )}
        </span>
      )}
    </div>
  );
}

export default function Index() {
  const {
    shopDomain,
    syncStatus,
    dateRange,
    overview,
    salesTrend,
    products,
    analyticsError,
  } = useLoaderData<typeof loader>();

  const revalidator = useRevalidator();
  const navigation = useNavigation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const isLoading = navigation.state === "loading";

  useEffect(() => {
    if (syncStatus.state !== "syncing") {
      return;
    }

    const interval = setInterval(() => {
      if (revalidator.state === "idle") {
        revalidator.revalidate();
      }
    }, 3000);

    return () => clearInterval(interval);
  }, [syncStatus.state, revalidator]);

  const currency = overview?.currency || "USD";
  const currentPreset = dateRange.preset;

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
    (
      updates: Record<string, string | number | undefined>,
      options?: { replace?: boolean },
    ) => {
      navigate(buildUrl(updates), {
        preventScrollReset: true,
        replace: options?.replace ?? false,
      });
    },
    [buildUrl, navigate],
  );

  useEffect(() => {
    const page = document.querySelector("s-page");
    if (page) {
      page.setAttribute("inlinesize", "large");
    }
  }, []);

  const [showCustomPicker, setShowCustomPicker] = useState(
    currentPreset === "custom",
  );
  const [showProductDatePicker, setShowProductDatePicker] = useState(false);
  const [showMetricDefinitions, setShowMetricDefinitions] = useState(false);
  const [customFrom, setCustomFrom] = useState(dateRange.from);
  const [customTo, setCustomTo] = useState(dateRange.to);
  const [dateError, setDateError] = useState<string | null>(null);

  useEffect(() => {
    setCustomFrom(dateRange.from);
    setCustomTo(dateRange.to);
    if (currentPreset !== "custom") {
      setShowCustomPicker(false);
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
      page: 1,
    });
  }, [customFrom, customTo, handleNavigate]);

  const [searchValue, setSearchValue] = useState(
    searchParams.get("search") ?? "",
  );

  useEffect(() => {
    setSearchValue(searchParams.get("search") ?? "");
  }, [searchParams]);

  const handleSearchSubmit = useCallback(() => {
    const trimmed = searchValue.trim();
    const currentQuery = searchParams.get("search") ?? "";
    if (trimmed !== currentQuery) {
      handleNavigate(
        { search: trimmed || undefined, page: 1 },
        { replace: true },
      );
    }
  }, [searchValue, searchParams, handleNavigate]);

  const handleSearchClear = useCallback(() => {
    setSearchValue("");
    if (searchParams.has("search")) {
      handleNavigate({ search: undefined, page: 1 }, { replace: true });
    }
  }, [searchParams, handleNavigate]);

  const handleEmptyStateAction = useCallback(
    (actionKey: EmptyStateAction["actionKey"]) => {
      if (actionKey === "clear_filters") {
        setSearchValue("");
        handleNavigate({ search: undefined, status: undefined, page: 1 }, { replace: true });
      } else if (actionKey === "select_30d") {
        setShowCustomPicker(false);
        handleNavigate({ preset: "30d", from: undefined, to: undefined, page: 1 });
      } else if (actionKey === "select_90d") {
        setShowCustomPicker(false);
        handleNavigate({ preset: "90d", from: undefined, to: undefined, page: 1 });
      }
    },
    [handleNavigate],
  );

  return (
    <s-page heading="Product Analytics" inlineSize="large">
      <s-button slot="secondary-actions" variant="tertiary" href="/app/pricing">
        Plans &amp; Billing
      </s-button>
      <ShimmerStyle />
      <style>{`
        s-page {
          display: block !important;
          width: 100% !important;
          max-width: 100% !important;
        }
        .spa-kpi-grid {
          display: grid;
          grid-template-columns: repeat(3, 1fr);
          gap: 16px;
        }
        @media (min-width: 1300px) {
          .spa-kpi-grid {
            grid-template-columns: repeat(6, 1fr);
          }
        }
        @media (max-width: 800px) {
          .spa-kpi-grid {
            grid-template-columns: repeat(2, 1fr);
          }
        }
        @media (max-width: 500px) {
          .spa-kpi-grid {
            grid-template-columns: 1fr;
          }
        }
      `}</style>

      {/* Plan Entitlement Notification */}
      {analyticsError &&
        (analyticsError.includes("plan") ||
          analyticsError.includes("30 days")) && (
          <s-banner heading="Historical Reporting Limit" tone="info">
            <s-paragraph>
              The Free plan includes up to 30 days of analytics history. Upgrade
              to Pro to unlock 365 days of reporting and unlimited CSV data
              exports.
            </s-paragraph>
            <s-link slot="primary-action" href="/app/pricing">
              Upgrade to Pro
            </s-link>
          </s-banner>
        )}

      {/* Sync Status Notifications */}
      {syncStatus.state === "syncing" && (
        <SyncProgressCard
          syncRun={syncStatus.syncRun}
          isSyncing={true}
        />
      )}

      {syncStatus.state === "failed" && (
        <s-banner heading="Sync interrupted" tone="critical">
          <s-paragraph>
            We encountered an issue during data synchronization
            {syncStatus.syncRun?.errorCode ? ` (${syncStatus.syncRun.errorCode})` : ""}.
          </s-paragraph>
          <RetryButton />
        </s-banner>
      )}

      {syncStatus.state === "stalled" && (
        <s-banner heading="Sync stopped responding" tone="warning">
          <s-paragraph>
            The data import has not reported progress for over 10 minutes and may
            have been interrupted.
          </s-paragraph>
          <RetryButton />
        </s-banner>
      )}

      {syncStatus.state === "backend_error" && (
        <s-banner heading="Analytics engine temporarily unreachable" tone="critical">
          <s-paragraph>
            We could not connect to the analytics backend. Background sync status
            and analytics reports will automatically reconnect when the service responds.
          </s-paragraph>
        </s-banner>
      )}

      {analyticsError && syncStatus.state !== "backend_error" && (
        <s-banner heading="Analytics temporarily unavailable" tone="warning">
          <s-paragraph>{analyticsError}</s-paragraph>
        </s-banner>
      )}

      {/* Date Range Selector Toolbar */}
      <s-section>
        <div
          style={{
            background: "#ffffff",
            border: "1px solid #e1e3e5",
            borderRadius: "8px",
            padding: "12px 16px",
            display: "flex",
            justifyContent: "space-between",
            alignItems: "center",
            gap: "12px",
            flexWrap: "nowrap",
          }}
        >
          <div
            style={{
              display: "flex",
              flexWrap: "wrap",
              gap: "6px",
              alignItems: "center",
              flex: "1 1 auto",
              minWidth: 0,
            }}
          >
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
                        page: 1,
                      });
                    }
                  }}
                >
                  {preset.label}
                </s-button>
              );
            })}
          </div>
          <div
            style={{
              display: "flex",
              alignItems: "center",
              gap: "10px",
              flexShrink: 0,
              whiteSpace: "nowrap",
            }}
          >
            {syncStatus.syncRun?.completedAt && (
              <span style={{ fontSize: "12px", color: "#616161" }}>
                Last synced: {formatSyncTime(syncStatus.syncRun.completedAt)}
              </span>
            )}
            <s-badge tone="info">
              {dateRange.from} to {dateRange.to}
            </s-badge>
            <SyncStoreButton isSyncing={syncStatus.state === "syncing"} />
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
              <div style={{ minWidth: "180px" }}>
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

      {/* Overview KPI Cards */}
      <s-section heading="Overview Performance">
        <div style={{ display: "flex", justifyContent: "flex-end", marginBottom: "12px" }}>
          <s-button
            variant="tertiary"
            onClick={() => setShowMetricDefinitions((prev) => !prev)}
          >
            {showMetricDefinitions ? "Hide Metric Definitions" : "ℹ Metric Definitions"}
          </s-button>
        </div>

        {showMetricDefinitions && (
          <div
            style={{
              background: "#ffffff",
              border: "1px solid #e1e3e5",
              borderRadius: "8px",
              padding: "16px",
              marginBottom: "16px",
            }}
          >
            <div style={{ fontWeight: 600, fontSize: "14px", marginBottom: "8px", color: "#202223" }}>
              Analytics Metric Definitions
            </div>
            <div
              style={{
                display: "grid",
                gridTemplateColumns: "repeat(auto-fit, minmax(280px, 1fr))",
                gap: "12px",
                fontSize: "13px",
                color: "#616161",
              }}
            >
              <div>
                <strong style={{ color: "#202223" }}>Net Sales:</strong> Gross sales minus discounts minus refunds.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Gross Sales:</strong> Sum of line-item sales before discounts and returns.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Discounts:</strong> Promotional discounts allocated across included line items.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Refunds:</strong> Returned merchandise value attributable to included products.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Units Sold:</strong> Included line-item quantity minus refunded quantities.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Orders:</strong> Distinct eligible orders containing at least one included product.
              </div>
              <div>
                <strong style={{ color: "#202223" }}>Average Order Revenue:</strong> Net sales divided by distinct orders.
              </div>
            </div>
          </div>
        )}

        {isLoading ? (
          <KpiCardsSkeleton />
        ) : overview ? (
          <div className="spa-kpi-grid">
            {/* Net Sales */}
            <div
              title="Gross sales minus discounts minus refunds"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Net Sales
              </div>
              <div style={{ fontSize: "24px", fontWeight: 700, marginBottom: "8px" }}>
                {formatMoney(overview.summary.net_sales, currency)}
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "8px",
                  fontSize: "12px",
                }}
              >
                <ComparisonBadge comparison={overview.comparison.net_sales} />
                <span style={{ color: "#616161" }}>vs previous period</span>
              </div>
            </div>

            {/* Gross Sales */}
            <div
              title="Sum of line-item sales before discounts and returns"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Gross Sales
              </div>
              <div style={{ fontSize: "24px", fontWeight: 700, marginBottom: "8px" }}>
                {formatMoney(overview.summary.gross_sales, currency)}
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "8px",
                  fontSize: "12px",
                }}
              >
                <ComparisonBadge comparison={overview.comparison.gross_sales} />
                <span style={{ color: "#616161" }}>vs previous period</span>
              </div>
            </div>

            {/* Discounts */}
            <div
              title="Discounts allocated to included line items"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Discounts
              </div>
              <div style={{ fontSize: "24px", fontWeight: 700, marginBottom: "8px" }}>
                {formatMoney(overview.summary.discounts, currency)}
              </div>
              <div style={{ fontSize: "12px", color: "#616161" }}>Applied across lines</div>
            </div>

            {/* Refunds */}
            <div
              title="Returned merchandise value attributable to products"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Refunds
              </div>
              <div
                style={{
                  fontSize: "24px",
                  fontWeight: 700,
                  marginBottom: "8px",
                  color: Number(overview.summary.refunds) > 0 ? "#b00020" : "#202223",
                }}
              >
                {formatMoney(overview.summary.refunds, currency)}
              </div>
              <div style={{ fontSize: "12px", color: "#616161" }}>Returned value</div>
            </div>

            {/* Units Sold */}
            <div
              title="Included line-item quantity minus refunded quantities"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Units Sold
              </div>
              <div style={{ fontSize: "24px", fontWeight: 700, marginBottom: "8px" }}>
                {overview.summary.units_sold.toLocaleString()}
              </div>
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "8px",
                  fontSize: "12px",
                }}
              >
                <ComparisonBadge comparison={overview.comparison.units_sold} />
                <span style={{ color: "#616161" }}>vs previous period</span>
              </div>
            </div>

            {/* Orders & Average Revenue */}
            <div
              title="Distinct eligible orders containing included products"
              style={{
                background: "#ffffff",
                border: "1px solid #e1e3e5",
                borderRadius: "8px",
                padding: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161", marginBottom: "4px" }}>
                Orders
              </div>
              <div style={{ fontSize: "24px", fontWeight: 700, marginBottom: "8px" }}>
                {overview.summary.orders.toLocaleString()}
              </div>
              <div style={{ fontSize: "12px", color: "#616161" }}>
                AOR:{" "}
                <strong>
                  {formatMoney(overview.summary.average_order_revenue, currency)}
                </strong>
              </div>
            </div>
          </div>
        ) : null}

        {overview && Number(overview.summary.orders) === 0 && Number(overview.summary.gross_sales) === 0 && (
          <EmptyStateCard
            config={getEmptyStateConfig({
              variant: "zero_sales",
              dateRange,
              shopDomain,
            })}
            onAction={handleEmptyStateAction}
          />
        )}
      </s-section>

      {/* Sales Trend Visualization */}
      <s-section heading="Sales Trend">
        {isLoading ? (
          <TrendChartSkeleton />
        ) : salesTrend && salesTrend.points.length > 0 ? (
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
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                marginBottom: "16px",
              }}
            >
              <div style={{ fontSize: "13px", color: "#616161" }}>
                Net Sales by {salesTrend.interval} ({currency})
              </div>
              <div style={{ display: "flex", gap: "6px" }}>
                {(["day", "week", "month"] as const).map((int) => (
                  <s-button
                    key={int}
                    variant={salesTrend.interval === int ? "primary" : "secondary"}
                    onClick={() => handleNavigate({ interval: int })}
                  >
                    {int.charAt(0).toUpperCase() + int.slice(1)}
                  </s-button>
                ))}
              </div>
            </div>

            {/* Simple Responsive Bar Chart */}
            {(() => {
              const maxVal = Math.max(
                ...salesTrend.points.map((p) => Math.max(0, Number(p.net_sales))),
                1,
              );
              return (
                <div
                  style={{
                    display: "flex",
                    alignItems: "flex-end",
                    gap: "6px",
                    height: "140px",
                    paddingTop: "10px",
                    borderBottom: "1px solid #e1e3e5",
                  }}
                >
                  {salesTrend.points.map((pt) => {
                    const val = Math.max(0, Number(pt.net_sales));
                    const heightPct = Math.round((val / maxVal) * 100);
                    return (
                      <div
                        key={pt.date}
                        title={`${pt.date}: ${formatMoney(pt.net_sales, currency)} (${pt.units_sold} units)`}
                        style={{
                          flex: 1,
                          display: "flex",
                          flexDirection: "column",
                          alignItems: "center",
                          height: "100%",
                          justifyContent: "flex-end",
                        }}
                      >
                        <div
                          style={{
                            width: "100%",
                            maxWidth: "32px",
                            height: `${Math.max(4, heightPct)}%`,
                            backgroundColor: val > 0 ? "#008060" : "#e1e3e5",
                            borderRadius: "3px 3px 0 0",
                            transition: "height 0.2s ease",
                          }}
                        />
                      </div>
                    );
                  })}
                </div>
              );
            })()}
            <div
              style={{
                display: "flex",
                justifyContent: "space-between",
                fontSize: "11px",
                color: "#8c9196",
                marginTop: "6px",
              }}
            >
              <span>{salesTrend.points[0]?.date}</span>
              {salesTrend.points.length > 2 && (
                <span>
                  {salesTrend.points[Math.floor(salesTrend.points.length / 2)]?.date}
                </span>
              )}
              <span>{salesTrend.points[salesTrend.points.length - 1]?.date}</span>
            </div>
          </div>
        ) : null}
      </s-section>

      {/* Product Performance Table */}
      <s-section heading="Product Performance">
        {isLoading ? (
          <ProductTableSkeleton />
        ) : (
          <div
            style={{
              background: "#ffffff",
              border: "1px solid #e1e3e5",
              borderRadius: "8px",
              overflow: "hidden",
            }}
          >
            {/* Table Search & Controls */}
            <div
              style={{
                padding: "12px 16px",
                borderBottom: "1px solid #e1e3e5",
                display: "flex",
                flexWrap: "wrap",
                gap: "12px",
                alignItems: "center",
                justifyContent: "space-between",
              }}
            >
              <div
                style={{
                  display: "flex",
                  gap: "12px",
                  flex: "1 1 auto",
                  alignItems: "center",
                  flexWrap: "wrap",
                }}
              >
                <div
                  style={{
                    display: "flex",
                    gap: "8px",
                    flex: "1 1 240px",
                    maxWidth: "360px",
                    alignItems: "center",
                  }}
                >
                  <div
                    style={{
                      position: "relative",
                      flex: 1,
                      display: "flex",
                      alignItems: "center",
                    }}
                  >
                    <input
                      type="text"
                      value={searchValue}
                      onChange={(e) => setSearchValue(e.target.value)}
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          handleSearchSubmit();
                        } else if (e.key === "Escape") {
                          e.preventDefault();
                          handleSearchClear();
                        }
                      }}
                      placeholder="Search products by title..."
                      style={{
                        width: "100%",
                        padding: "6px 28px 6px 10px",
                        borderRadius: "4px",
                        border: "1px solid #c9cccf",
                        fontSize: "13px",
                      }}
                    />
                    {searchValue.length > 0 && (
                      <button
                        type="button"
                        onClick={handleSearchClear}
                        title="Clear search"
                        style={{
                          position: "absolute",
                          right: "8px",
                          background: "none",
                          border: "none",
                          cursor: "pointer",
                          fontSize: "13px",
                          color: "#8c9196",
                          padding: "2px",
                          display: "flex",
                          alignItems: "center",
                          justifyContent: "center",
                        }}
                      >
                        ✕
                      </button>
                    )}
                  </div>

                  <s-button onClick={handleSearchSubmit}>Search</s-button>

                  {(searchParams.has("search") || searchValue.length > 0) && (
                    <s-button variant="secondary" onClick={handleSearchClear}>
                      Clear
                    </s-button>
                  )}
                </div>

                {/* Status Filter Pills */}
                <div style={{ display: "flex", gap: "4px", alignItems: "center" }}>
                  {(
                    [
                      { key: "all", label: "All" },
                      { key: "active", label: "Active" },
                      { key: "draft", label: "Draft" },
                      { key: "archived", label: "Archived" },
                    ] as const
                  ).map((st) => {
                    const currentStatus = searchParams.get("status") ?? "all";
                    const isSelected = currentStatus.toLowerCase() === st.key;
                    return (
                      <s-button
                        key={st.key}
                        variant={isSelected ? "primary" : "secondary"}
                        onClick={() => {
                          handleNavigate({
                            status: st.key === "all" ? undefined : st.key,
                            page: 1,
                          });
                        }}
                      >
                        {st.label}
                      </s-button>
                    );
                  })}
                </div>
              </div>

              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  gap: "8px",
                  flexWrap: "wrap",
                }}
              >
                <s-badge tone="info">
                  {dateRange.from} to {dateRange.to}
                </s-badge>
                <s-button
                  variant="secondary"
                  onClick={() => setShowProductDatePicker((prev) => !prev)}
                >
                  {showProductDatePicker ? "Close Date" : "Change Date"}
                </s-button>
                <ExportButton from={dateRange.from} to={dateRange.to} />
              </div>
            </div>

            {/* Inline Polaris Quick Date Picker for Products */}
            {showProductDatePicker && (
              <div
                style={{
                  padding: "16px",
                  borderBottom: "1px solid #e1e3e5",
                  background: "#f9fafb",
                }}
              >
                <div
                  style={{
                    display: "flex",
                    flexWrap: "wrap",
                    gap: "6px",
                    marginBottom: "12px",
                  }}
                >
                  {DATE_PRESETS.map((preset) => {
                    const isActive =
                      preset.key === "custom"
                        ? currentPreset === "custom"
                        : currentPreset === preset.key;

                    return (
                      <s-button
                        key={preset.key}
                        variant={isActive ? "primary" : "secondary"}
                        onClick={() => {
                          if (preset.key !== "custom") {
                            setShowProductDatePicker(false);
                            handleNavigate({
                              preset: preset.key,
                              from: undefined,
                              to: undefined,
                              page: 1,
                            });
                          }
                        }}
                      >
                        {preset.label}
                      </s-button>
                    );
                  })}
                </div>

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
                    <s-button
                      variant="primary"
                      onClick={() => {
                        if (!customFrom || !customTo) {
                          setDateError("Please select both start and end dates.");
                          return;
                        }
                        if (customFrom > customTo) {
                          setDateError("Start date cannot be after end date.");
                          return;
                        }
                        setDateError(null);
                        setShowProductDatePicker(false);
                        handleNavigate({
                          preset: "custom",
                          from: customFrom,
                          to: customTo,
                          page: 1,
                        });
                      }}
                    >
                      Apply Range
                    </s-button>
                    <s-button
                      variant="secondary"
                      onClick={() => {
                        setShowProductDatePicker(false);
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

            {/* Table Data */}
            {products && products.data.length > 0 ? (
              <s-table>
                <s-table-header-row>
                  <s-table-header>Product</s-table-header>
                  <s-table-header>
                    <s-button
                      variant="tertiary"
                      onClick={() =>
                        handleNavigate({
                          sort_by: "net_sales",
                          sort_direction:
                            searchParams.get("sort_by") === "net_sales" &&
                            searchParams.get("sort_direction") === "desc"
                              ? "asc"
                              : "desc",
                        })
                      }
                    >
                      Net Sales{" "}
                      {searchParams.get("sort_by") === "net_sales"
                        ? searchParams.get("sort_direction") === "asc"
                          ? "▲"
                          : "▼"
                        : ""}
                    </s-button>
                  </s-table-header>
                  <s-table-header>
                    <s-button
                      variant="tertiary"
                      onClick={() =>
                        handleNavigate({
                          sort_by: "gross_sales",
                          sort_direction:
                            searchParams.get("sort_by") === "gross_sales" &&
                            searchParams.get("sort_direction") === "desc"
                              ? "asc"
                              : "desc",
                        })
                      }
                    >
                      Gross Sales{" "}
                      {searchParams.get("sort_by") === "gross_sales"
                        ? searchParams.get("sort_direction") === "asc"
                          ? "▲"
                          : "▼"
                        : ""}
                    </s-button>
                  </s-table-header>
                  <s-table-header>Discounts</s-table-header>
                  <s-table-header>Refunds</s-table-header>
                  <s-table-header>
                    <s-button
                      variant="tertiary"
                      onClick={() =>
                        handleNavigate({
                          sort_by: "units_sold",
                          sort_direction:
                            searchParams.get("sort_by") === "units_sold" &&
                            searchParams.get("sort_direction") === "desc"
                              ? "asc"
                              : "desc",
                        })
                      }
                    >
                      Units{" "}
                      {searchParams.get("sort_by") === "units_sold"
                        ? searchParams.get("sort_direction") === "asc"
                          ? "▲"
                          : "▼"
                        : ""}
                    </s-button>
                  </s-table-header>
                  <s-table-header>
                    <s-button
                      variant="tertiary"
                      onClick={() =>
                        handleNavigate({
                          sort_by: "orders",
                          sort_direction:
                            searchParams.get("sort_by") === "orders" &&
                            searchParams.get("sort_direction") === "desc"
                              ? "asc"
                              : "desc",
                        })
                      }
                    >
                      Orders{" "}
                      {searchParams.get("sort_by") === "orders"
                        ? searchParams.get("sort_direction") === "asc"
                          ? "▲"
                          : "▼"
                        : ""}
                    </s-button>
                  </s-table-header>
                </s-table-header-row>
                <s-table-body>
                  {products.data.map((prod) => (
                    <s-table-row key={prod.id}>
                      <s-table-cell>
                        <div
                          style={{ display: "flex", alignItems: "center", gap: "10px" }}
                        >
                          {prod.image_url ? (
                            <img
                              src={prod.image_url}
                              alt={prod.title}
                              style={{
                                width: "36px",
                                height: "36px",
                                objectFit: "cover",
                                borderRadius: "4px",
                                border: "1px solid #e1e3e5",
                              }}
                            />
                          ) : (
                            <div
                              style={{
                                width: "36px",
                                height: "36px",
                                background: "#f1f2f3",
                                borderRadius: "4px",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: "16px",
                              }}
                            >
                              📦
                            </div>
                          )}
                          <div>
                            <Link
                              to={`/app/products/${prod.id}?preset=${currentPreset}`}
                              style={{
                                fontWeight: 600,
                                color: "#005bd3",
                                textDecoration: "none",
                              }}
                            >
                              {prod.title}
                            </Link>
                            <div
                              style={{
                                display: "flex",
                                alignItems: "center",
                                gap: "6px",
                                marginTop: "2px",
                              }}
                            >
                              <s-badge
                                tone={
                                  prod.status.toUpperCase() === "ACTIVE"
                                    ? "success"
                                    : prod.status.toUpperCase() === "DRAFT"
                                    ? "warning"
                                    : "info"
                                }
                              >
                                {prod.status}
                              </s-badge>
                              <span style={{ fontSize: "12px", color: "#616161" }}>
                                {prod.vendor || "No vendor"}{" "}
                                {prod.product_type ? `• ${prod.product_type}` : ""}
                              </span>
                            </div>
                          </div>
                        </div>
                      </s-table-cell>
                      <s-table-cell>
                        <strong>{formatMoney(prod.net_sales, currency)}</strong>
                      </s-table-cell>
                      <s-table-cell>
                        {formatMoney(prod.gross_sales, currency)}
                      </s-table-cell>
                      <s-table-cell>
                        {formatMoney(prod.discounts, currency)}
                      </s-table-cell>
                      <s-table-cell>
                        <span style={{ color: Number(prod.refunds) > 0 ? "#b00020" : undefined }}>
                          {formatMoney(prod.refunds, currency)}
                        </span>
                      </s-table-cell>
                      <s-table-cell>{prod.units_sold.toLocaleString()}</s-table-cell>
                      <s-table-cell>{prod.orders.toLocaleString()}</s-table-cell>
                    </s-table-row>
                  ))}
                </s-table-body>
              </s-table>
            ) : (
              <EmptyStateCard
                config={getEmptyStateConfig({
                  variant: "zero_filter_results",
                  searchQuery: searchParams.get("search") || undefined,
                  statusFilter: searchParams.get("status") || undefined,
                  dateRange,
                })}
                onAction={handleEmptyStateAction}
              />
            )}

            {/* Table Pagination */}
            {products && products.meta.last_page > 1 && (
              <div
                style={{
                  display: "flex",
                  justifyContent: "space-between",
                  alignItems: "center",
                  padding: "12px 16px",
                  borderTop: "1px solid #e1e3e5",
                  fontSize: "13px",
                }}
              >
                <div style={{ color: "#616161" }}>
                  Showing page <strong>{products.meta.current_page}</strong> of{" "}
                  <strong>{products.meta.last_page}</strong> ({products.meta.total}{" "}
                  products)
                </div>
                <div style={{ display: "flex", gap: "8px" }}>
                  <s-button
                    disabled={products.meta.current_page <= 1 || isLoading}
                    onClick={() =>
                      handleNavigate({ page: products.meta.current_page - 1 })
                    }
                  >
                    Previous
                  </s-button>
                  <s-button
                    disabled={
                      products.meta.current_page >= products.meta.last_page ||
                      isLoading
                    }
                    onClick={() =>
                      handleNavigate({ page: products.meta.current_page + 1 })
                    }
                  >
                    Next
                  </s-button>
                </div>
              </div>
            )}
          </div>
        )}
      </s-section>
    </s-page>
  );
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
