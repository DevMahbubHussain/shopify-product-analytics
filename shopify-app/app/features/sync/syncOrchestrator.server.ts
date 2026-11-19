import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import {
  BackendError,
  type BackendClient,
} from "../../lib/backend/backendClient.server.ts";
import { INGESTION_LIMITS } from "../../lib/backend/ingestionContract.server.ts";
import {
  harvestProducts,
  type HarvestProductsSummary,
} from "../catalog-sync/harvestProducts.server.ts";
import {
  defaultSixtyDayWindowStart,
  harvestOrders,
  type HarvestProgressCounts,
} from "../order-sync/harvestOrders.server.ts";

export interface SyncStatusBody {
  sync_run_id?: string | null;
  sync_type?: "initial" | "incremental" | "reconciliation";
  status: "pending" | "running" | "completed" | "failed";
  records_received?: number;
  records_processed?: number;
  cursor_metadata?: Record<string, unknown> | null;
  error_code?: string | null;
  error_summary?: string | null;
}

export interface SyncStatusResponse {
  sync_run_id: string;
  status: string;
}

export interface SyncProgress {
  stage: "products" | "orders" | "completed";
  recordsReceived: number;
  recordsProcessed: number;
}

export interface SyncOrchestratorOptions {
  admin: GraphqlAdmin;
  backend: BackendClient;
  shopDomain: string;
  orderQuery?: string;
  onProgress?: (progress: SyncProgress) => void;
  heartbeatMs?: number;
  now?: () => number;
}

/** Must stay well below STALE_AFTER_MS so a live sync is never reported as stalled. */
export const HEARTBEAT_MS = 60_000;

export interface SyncOrchestratorResult {
  syncRunId: string;
  products: HarvestProductsSummary;
  orders: HarvestProgressCounts;
  refunds: HarvestProgressCounts;
}

/**
 * Orchestrates full historical synchronization for a shop:
 * 1. Creates a sync run in Laravel (status: running).
 * 2. Harvests catalogue products and variants.
 * 3. Harvests historical orders and associated refunds (dependency order).
 * 4. Updates records_received / records_processed.
 * 5. Marks the sync run as completed (or failed upon error).
 */
export async function runHistoricalSync({
  admin,
  backend,
  shopDomain,
  orderQuery,
  onProgress,
  heartbeatMs = HEARTBEAT_MS,
  now = Date.now,
}: SyncOrchestratorOptions): Promise<SyncOrchestratorResult> {
  const windowStart = defaultSixtyDayWindowStart(new Date(now()));

  // Step 1: Create initial sync run
  const initialRun = await backend.postInternal<SyncStatusResponse>(
    "/internal/v1/ingestion/sync-status",
    shopDomain,
    {
      sync_run_id: null,
      sync_type: "initial",
      status: "running",
      records_received: 0,
      records_processed: 0,
      cursor_metadata: {
        window_start: windowStart,
      },
    },
  );
  const syncRunId = initialRun.sync_run_id;

  let totalReceived = 0;
  let totalProcessed = 0;

  const heartbeat = progressHeartbeat({
    intervalMs: heartbeatMs,
    now,
    send: (received, processed) =>
      backend.postInternal<SyncStatusResponse>(
        "/internal/v1/ingestion/sync-status",
        shopDomain,
        {
          sync_run_id: syncRunId,
          status: "running",
          records_received: received,
          records_processed: processed,
        },
      ),
    onError: (error) =>
      console.error(
        `Sync heartbeat failed for ${shopDomain} (sync_run ${syncRunId}):`,
        error instanceof Error ? error.message : String(error),
      ),
  });

  try {
    // Step 2: Harvest Products
    const productsSummary = await harvestProducts({
      admin,
      backend,
      shopDomain,
      onProgress: (p) => {
        totalReceived = p.received;
        totalProcessed = p.written;
        heartbeat.beat(totalReceived, totalProcessed);
        onProgress?.({
          stage: "products",
          recordsReceived: totalReceived,
          recordsProcessed: totalProcessed,
        });
      },
    });

    totalReceived = productsSummary.received;
    totalProcessed = productsSummary.written;

    await heartbeat.flush();
    await backend.postInternal<SyncStatusResponse>(
      "/internal/v1/ingestion/sync-status",
      shopDomain,
      {
        sync_run_id: syncRunId,
        status: "running",
        records_received: totalReceived,
        records_processed: totalProcessed,
      },
    );

    // Step 3: Harvest Orders & Refunds (strict dependency order)
    const ordersSummary = await harvestOrders({
      admin,
      backend,
      shopDomain,
      query: orderQuery,
      onProgress: (p) => {
        totalReceived =
          productsSummary.received + p.orders.received + p.refunds.received;
        totalProcessed =
          productsSummary.written + p.orders.written + p.refunds.written;
        heartbeat.beat(totalReceived, totalProcessed);
        onProgress?.({
          stage: "orders",
          recordsReceived: totalReceived,
          recordsProcessed: totalProcessed,
        });
      },
    });

    totalReceived =
      productsSummary.received +
      ordersSummary.orders.received +
      ordersSummary.refunds.received;
    totalProcessed =
      productsSummary.written +
      ordersSummary.orders.written +
      ordersSummary.refunds.written;

    // Step 4: Mark sync run completed
    await heartbeat.flush();
    await backend.postInternal<SyncStatusResponse>(
      "/internal/v1/ingestion/sync-status",
      shopDomain,
      {
        sync_run_id: syncRunId,
        status: "completed",
        records_received: totalReceived,
        records_processed: totalProcessed,
      },
    );

    onProgress?.({
      stage: "completed",
      recordsReceived: totalReceived,
      recordsProcessed: totalProcessed,
    });

    return {
      syncRunId,
      products: productsSummary,
      orders: ordersSummary.orders,
      refunds: ordersSummary.refunds,
    };
  } catch (error) {
    // Step 5: Mark sync run failed on error and rethrow
    await heartbeat.flush();
    await backend
      .postInternal<SyncStatusResponse>(
        "/internal/v1/ingestion/sync-status",
        shopDomain,
        {
          sync_run_id: syncRunId,
          status: "failed",
          error_code: "HARVEST_FAILED",
          error_summary: errorSummary(error),
          records_received: totalReceived,
          records_processed: totalProcessed,
        },
      )
      .catch((reportError: unknown) => {
        const status =
          reportError instanceof BackendError ? reportError.status : "n/a";
        console.error(
          `Failed to record sync failure for ${shopDomain} (sync_run ${syncRunId}, status ${status}):`,
          reportError instanceof Error ? reportError.message : String(reportError),
        );
      });

    throw error;
  }
}

/** Cut by code point: Laravel counts mb_strlen, and a split surrogate pair is invalid UTF-8. */
function errorSummary(error: unknown): string {
  const message = error instanceof Error ? error.message : String(error);
  return Array.from(message)
    .slice(0, INGESTION_LIMITS.errorSummaryLength)
    .join("");
}

interface HeartbeatOptions {
  intervalMs: number;
  now: () => number;
  send: (received: number, processed: number) => Promise<unknown>;
  onError: (error: unknown) => void;
}

/** Throttled, serialized "running" updates; serial order keeps Laravel's monotonic counter check satisfied. */
function progressHeartbeat({ intervalMs, now, send, onError }: HeartbeatOptions): {
  beat: (received: number, processed: number) => void;
  flush: () => Promise<void>;
} {
  let lastSentAt = now();
  let queue: Promise<void> = Promise.resolve();

  return {
    beat(received, processed) {
      if (now() - lastSentAt < intervalMs) {
        return;
      }
      lastSentAt = now();
      queue = queue.then(() => send(received, processed)).then(() => {}, onError);
    },
    flush: () => queue,
  };
}
