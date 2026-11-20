import type { ActionFunctionArgs } from "react-router";
import { handleAppSubscriptionUpdate } from "../features/billing/subscriptionWebhook.server.ts";
import { verifyShopifyWebhook } from "../lib/shopify/verifyWebhook.server.ts";

export const action = async ({ request }: ActionFunctionArgs) => {
  const webhook = await verifyShopifyWebhook(request);
  if (!webhook) {
    return new Response(null, { status: 401 });
  }

  const status = await handleAppSubscriptionUpdate(webhook);
  return new Response(null, { status });
};
