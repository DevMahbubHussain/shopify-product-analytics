import {
  backendClient,
  type BackendClient,
} from "../../lib/backend/backendClient.server.ts";
import type { VerifiedWebhook } from "../../lib/shopify/verifyWebhook.server.ts";

export interface AppSubscriptionWebhookPayload {
  app_subscription?: {
    admin_graphql_api_id?: string;
    name?: string;
    status?: string;
    trial_days?: number;
    current_period_end?: string | null;
  };
}

export interface IngestSubscriptionResult {
  status: string;
  plan_key: string;
  subscription_status: string;
}

export async function ingestSubscriptionUpdate(
  shop: string,
  payload: {
    shopify_subscription_gid?: string | null;
    plan_key: string;
    status: string;
    current_period_end?: string | null;
  },
  client: Pick<BackendClient, "postInternal"> = backendClient(),
): Promise<IngestSubscriptionResult> {
  return client.postInternal<IngestSubscriptionResult>(
    "/internal/v1/ingestion/subscription",
    shop,
    payload,
  );
}

export async function handleAppSubscriptionUpdate(
  webhook: Pick<VerifiedWebhook, "shop" | "rawBody">,
  ingest = ingestSubscriptionUpdate,
): Promise<200 | 500> {
  try {
    const data = JSON.parse(webhook.rawBody) as AppSubscriptionWebhookPayload;
    const sub = data.app_subscription;

    if (!sub || !sub.admin_graphql_api_id || !sub.status) {
      console.warn(
        `app_subscriptions/update for ${webhook.shop} missing subscription data.`,
      );
      return 200;
    }

    const planKey = (sub.name ?? "").toLowerCase().includes("pro")
      ? "pro"
      : "pro";

    await ingest(webhook.shop, {
      shopify_subscription_gid: sub.admin_graphql_api_id,
      plan_key: planKey,
      status: sub.status.toUpperCase(),
      current_period_end: sub.current_period_end ?? null,
    });

    return 200;
  } catch (error) {
    console.error(
      `app_subscriptions/update for ${webhook.shop} failed to ingest.`,
      error instanceof Error ? error.message : error,
    );
    return 500;
  }
}
