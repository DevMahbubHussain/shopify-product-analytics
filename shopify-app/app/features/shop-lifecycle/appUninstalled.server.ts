import { BackendError } from "../../lib/backend/backendClient.server.ts";
import type { UninstalledShop } from "./uninstallShop.server.ts";
import type { VerifiedWebhook } from "../../lib/shopify/verifyWebhook.server.ts";

export interface AppUninstalledDeps {
  uninstall(shop: string, triggeredAt: string | null): Promise<UninstalledShop>;
  deleteSessions(shop: string): Promise<void>;
}

/**
 * Laravel decides first: a late delivery after reinstall leaves the shop active,
 * and its current access token must survive.
 */
export async function handleAppUninstalled(
  webhook: Pick<VerifiedWebhook, "shop" | "triggeredAt">,
  deps: AppUninstalledDeps,
): Promise<200 | 500> {
  try {
    const result = await deps.uninstall(webhook.shop, webhook.triggeredAt);
    if (result.status === "uninstalled") {
      await deps.deleteSessions(webhook.shop);
    }
    return 200;
  } catch (error) {
    if (error instanceof BackendError && error.status === 404) {
      await deps.deleteSessions(webhook.shop);
      return 200;
    }
    console.error(
      `app/uninstalled for ${webhook.shop} not recorded in backend; Shopify will retry.`,
      error instanceof Error ? error.message : error,
    );
    return 500;
  }
}
