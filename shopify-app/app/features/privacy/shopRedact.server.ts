import {
  backendClient,
  type BackendClient,
} from "../../lib/backend/backendClient.server.ts";
import type { VerifiedWebhook } from "../../lib/shopify/verifyWebhook.server.ts";

export interface ShopRedactResult {
  status: "purged";
  shop_domain: string;
}

export interface ShopRedactDeps {
  purgeBackend(shop: string): Promise<ShopRedactResult>;
  deleteSessions(shop: string): Promise<void>;
}

export async function purgeShopBackend(
  shop: string,
  client: Pick<BackendClient, "postInternal"> = backendClient(),
): Promise<ShopRedactResult> {
  return client.postInternal<ShopRedactResult>(
    "/internal/v1/ingestion/shop-redact",
    shop,
    { shop_domain: shop },
  );
}

export async function handleShopRedact(
  webhook: Pick<VerifiedWebhook, "shop">,
  deps: ShopRedactDeps = {
    purgeBackend: purgeShopBackend,
    deleteSessions: async () => {},
  },
): Promise<200 | 500> {
  try {
    await deps.purgeBackend(webhook.shop);
    await deps.deleteSessions(webhook.shop);
    return 200;
  } catch (error) {
    console.error(
      `shop/redact for ${webhook.shop} failed to purge; Shopify will retry.`,
      error instanceof Error ? error.message : error,
    );
    return 500;
  }
}
