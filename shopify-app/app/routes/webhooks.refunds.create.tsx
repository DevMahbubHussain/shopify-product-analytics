import type { ActionFunctionArgs } from "react-router";
import { handleRefundWebhook } from "../features/order-sync/orderWebhook.server.ts";
import { verifyShopifyWebhook } from "../lib/shopify/verifyWebhook.server.ts";

export const action = async ({ request }: ActionFunctionArgs) => {
  const webhook = await verifyShopifyWebhook(request);
  if (!webhook) {
    return new Response(null, { status: 401 });
  }

  const status = await handleRefundWebhook(webhook);
  return new Response(null, { status });
};
