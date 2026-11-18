import { backendClient, type BackendClient } from "./backendClient.server.ts";

export interface UninstalledShop {
  shop_domain: string;
  status: string;
}

/** X-Shopify-Triggered-At has no documented format; anything unparseable falls back to now. */
function toIsoUtc(triggeredAt: string | null): string {
  const parsed = triggeredAt ? Date.parse(triggeredAt) : Number.NaN;
  return new Date(Number.isNaN(parsed) ? Date.now() : parsed).toISOString();
}

export async function uninstallShop(
  shopDomain: string,
  triggeredAt: string | null,
  client: Pick<BackendClient, "postInternal"> = backendClient(),
): Promise<UninstalledShop> {
  return client.postInternal<UninstalledShop>(
    "/internal/v1/ingestion/shop-uninstall",
    shopDomain,
    { uninstalled_at: toIsoUtc(triggeredAt) },
  );
}
