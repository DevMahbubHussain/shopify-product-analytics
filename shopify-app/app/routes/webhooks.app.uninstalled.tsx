import type { ActionFunctionArgs } from "react-router";
import db from "../db.server";
import { handleAppUninstalled } from "../services/appUninstalled.server";
import { uninstallShop } from "../services/uninstallShop.server";
import { verifyShopifyWebhook } from "../services/verifyWebhook.server";

export const action = async ({ request }: ActionFunctionArgs) => {
  const webhook = await verifyShopifyWebhook(request);
  if (!webhook) {
    return new Response(null, { status: 401 });
  }

  const status = await handleAppUninstalled(webhook, {
    uninstall: uninstallShop,
    deleteSessions: async (shop) => {
      await db.session.deleteMany({ where: { shop } });
    },
  });

  return new Response(null, { status });
};
