export type EmptyStateVariant = "zero_sales" | "zero_filter_results" | "initial_syncing";

export interface EmptyStateAction {
  label: string;
  url?: string;
  actionKey: "clear_filters" | "select_30d" | "select_90d" | "shopify_admin_orders";
}

export interface EmptyStateConfig {
  variant: EmptyStateVariant;
  icon: string;
  heading: string;
  description: string;
  primaryAction?: EmptyStateAction;
  secondaryAction?: EmptyStateAction;
  tip?: string;
}

export interface GetEmptyStateOptions {
  variant: EmptyStateVariant;
  dateRange?: { from: string; to: string };
  searchQuery?: string;
  statusFilter?: string;
  shopDomain?: string;
}

export function getEmptyStateConfig(options: GetEmptyStateOptions): EmptyStateConfig {
  const { variant, dateRange, searchQuery, statusFilter, shopDomain } = options;

  if (variant === "initial_syncing") {
    return {
      variant: "initial_syncing",
      icon: "⏳",
      heading: "Synchronizing your store data",
      description:
        "We are currently importing your catalogue and order history. Metrics will populate automatically once records arrive.",
      tip: "You can keep this tab open or navigate elsewhere in Shopify Admin.",
    };
  }

  if (variant === "zero_filter_results") {
    const filterParts: string[] = [];
    if (searchQuery && searchQuery.trim().length > 0) {
      filterParts.push(`search "${searchQuery}"`);
    }
    if (statusFilter && statusFilter !== "all") {
      filterParts.push(`status "${statusFilter}"`);
    }

    const filterSummary = filterParts.length > 0 ? ` for ${filterParts.join(" and ")}` : "";

    return {
      variant: "zero_filter_results",
      icon: "🔍",
      heading: "No products matched your filters",
      description: `We couldn't find any products${filterSummary} in the selected date range. Try broadening your criteria.`,
      primaryAction: {
        label: "Clear all filters",
        actionKey: "clear_filters",
      },
      tip: "Search matches product titles, and status filter matches active, draft, or archived items.",
    };
  }

  // Default: zero_sales
  const rangeText = dateRange ? `between ${dateRange.from} and ${dateRange.to}` : "in this period";
  const shopifyOrdersUrl = shopDomain
    ? `https://admin.shopify.com/store/${shopDomain.replace(".myshopify.com", "")}/orders`
    : undefined;

  return {
    variant: "zero_sales",
    icon: "📊",
    heading: "No sales recorded for this date range",
    description: `We found no eligible orders ${rangeText}. As soon as new orders are placed, they will appear here automatically within 5 minutes.`,
    primaryAction: {
      label: "View Last 30 Days",
      actionKey: "select_30d",
    },
    secondaryAction: shopifyOrdersUrl
      ? {
          label: "View Shopify Orders",
          url: shopifyOrdersUrl,
          actionKey: "shopify_admin_orders",
        }
      : {
          label: "View Last 90 Days",
          actionKey: "select_90d",
        },
    tip: "You can create a test order in Shopify Admin to test reporting immediately.",
  };
}
