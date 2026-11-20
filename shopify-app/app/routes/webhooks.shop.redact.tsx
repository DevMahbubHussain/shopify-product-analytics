import type { ActionFunctionArgs } from "react-router";
import db from "../db.server";
import { handleShopRedact, purgeShopBackend } from "../features/privacy/shopRedact.server.ts";
import { verifyShopifyWebhook } from "../lib/shopify/verifyWebhook.server.ts";

export const action = async ({ request }: ActionFunctionArgs) => {
  const webhook = await verifyShopifyWebhook(request);
  if (!webhook) {
    return new Response(null, { status: 401 });
  }

  const status = await handleShopRedact(webhook, {
    purgeBackend: purgeShopBackend,
    deleteSessions: async (shop) => {
      await db.session.deleteMany({ where: { shop } });
    },
  });

  return new Response(null, { status });
};
