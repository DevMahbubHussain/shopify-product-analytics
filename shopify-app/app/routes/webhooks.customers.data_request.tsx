import type { ActionFunctionArgs } from "react-router";
import { verifyShopifyWebhook } from "../lib/shopify/verifyWebhook.server.ts";

/**
 * Shopify mandatory compliance webhook: customers/data_request
 * Since the app does not store any Protected Customer Data (PCD),
 * we acknowledge receipt with 200 OK.
 */
export const action = async ({ request }: ActionFunctionArgs) => {
  const webhook = await verifyShopifyWebhook(request);
  if (!webhook) {
    return new Response(null, { status: 401 });
  }

  return new Response(JSON.stringify({ message: "No customer personal data stored." }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
};
