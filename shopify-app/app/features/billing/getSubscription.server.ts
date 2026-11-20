import type { BackendClient } from "../../lib/backend/backendClient.server.ts";

export interface ShopSubscriptionStatus {
  plan_key: "free" | "pro";
  status: string | null;
  max_analytics_days: number;
  can_export_csv: boolean;
  trial_ends_at: string | null;
  current_period_end: string | null;
}

export async function getSubscriptionStatus(
  backend: BackendClient,
  shopDomain: string,
): Promise<ShopSubscriptionStatus> {
  try {
    return await backend.getInternal<ShopSubscriptionStatus>(
      "/internal/v1/subscription",
      shopDomain,
    );
  } catch (error) {
    console.error(`Failed to fetch subscription for ${shopDomain}:`, error);
    return {
      plan_key: "free",
      status: null,
      max_analytics_days: 30,
      can_export_csv: false,
      trial_ends_at: null,
      current_period_end: null,
    };
  }
}
