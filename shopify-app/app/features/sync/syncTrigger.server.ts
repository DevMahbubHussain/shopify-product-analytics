import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import { runHistoricalSync } from "./syncOrchestrator.server.ts";

export interface TriggerSyncOptions {
  admin: GraphqlAdmin;
  backend: BackendClient;
  shopDomain: string;
  getAdmin?: (shopDomain: string) => Promise<GraphqlAdmin>;
  syncRunner?: (options: {
    admin: GraphqlAdmin;
    backend: BackendClient;
    shopDomain: string;
  }) => Promise<unknown>;
  onError?: (error: Error) => void;
}

export type TriggerResult =
  | { started: true }
  | { started: false; reason: "already_running" };

const runningSyncs = new Set<string>();

/**
 * Checks if a historical sync is currently executing in-process for the shop.
 */
export function isSyncRunning(shopDomain: string): boolean {
  return runningSyncs.has(shopDomain);
}

/**
 * Initiates an asynchronous, non-blocking historical sync in the background.
 * Prevents concurrent runs for the same shop domain.
 * If getAdmin is provided, it is invoked inside the background runner to obtain
 * a fresh offline Admin client, ensuring tokens do not expire during long crawls.
 */
export function triggerHistoricalSync(options: TriggerSyncOptions): TriggerResult {
  const { admin, backend, shopDomain, getAdmin, syncRunner, onError } = options;

  if (runningSyncs.has(shopDomain)) {
    return { started: false, reason: "already_running" };
  }

  runningSyncs.add(shopDomain);

  const runner = syncRunner ?? runHistoricalSync;

  Promise.resolve()
    .then(async () => {
      const activeAdmin = getAdmin ? await getAdmin(shopDomain) : admin;
      await runner({ admin: activeAdmin, backend, shopDomain });
    })
    .catch((error: unknown) => {
      const err = error instanceof Error ? error : new Error(String(error));
      if (onError) {
        onError(err);
      } else {
        console.error(
          `Historical sync failed in background for ${shopDomain}:`,
          err.message,
        );
      }
    })
    .finally(() => {
      runningSyncs.delete(shopDomain);
    });

  return { started: true };
}

/**
 * Test helper to clear in-memory sync lock state between test runs.
 */
export function _resetRunningSyncsForTest(): void {
  runningSyncs.clear();
}
