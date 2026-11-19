import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import { isSyncRunning } from "./syncTrigger.server.ts";

/** A run with no status update for this long, and not running here, is treated as abandoned. */
export const STALE_AFTER_MS = 10 * 60 * 1000;

export type SyncRunStatus = "pending" | "running" | "completed" | "failed";

export interface SyncRunInfo {
  id: string;
  syncType: string;
  status: SyncRunStatus;
  recordsReceived: number;
  recordsProcessed: number;
  startedAt: string | null;
  completedAt: string | null;
  errorCode: string | null;
  lastActivityAt: string | null;
}

export type ShopSyncStatus =
  | { state: "never_synced"; syncRun: null }
  | { state: "syncing"; syncRun: SyncRunInfo | null }
  | { state: "completed"; syncRun: SyncRunInfo }
  | { state: "failed"; syncRun: SyncRunInfo }
  | { state: "stalled"; syncRun: SyncRunInfo }
  | { state: "backend_error"; syncRun: null; error?: string };

export interface GetSyncStatusOptions {
  backend: BackendClient;
  shopDomain: string;
  isLocalRunning?: (shopDomain: string) => boolean;
  now?: () => number;
}

interface RawSyncRunResponse {
  id: string;
  sync_type: string;
  status: SyncRunStatus;
  records_received: number;
  records_processed: number;
  started_at: string | null;
  completed_at: string | null;
  error_code: string | null;
  last_activity_at: string | null;
}

interface SyncStatusApiResponse {
  sync_run: RawSyncRunResponse | null;
}

/**
 * Queries the backend for the latest sync run for a shop, combining it with
 * in-process local sync execution state.
 */
export async function getSyncStatus(
  options: GetSyncStatusOptions,
): Promise<ShopSyncStatus> {
  const { backend, shopDomain, isLocalRunning, now = Date.now } = options;

  const response = await backend.getInternal<SyncStatusApiResponse>(
    "/internal/v1/sync/status",
    shopDomain,
  );

  const isLocallyActive = (isLocalRunning ?? isSyncRunning)(shopDomain);
  const raw = response.sync_run;

  if (raw === null || raw === undefined) {
    if (isLocallyActive) {
      return { state: "syncing", syncRun: null };
    }
    return { state: "never_synced", syncRun: null };
  }

  const syncRun: SyncRunInfo = {
    id: raw.id,
    syncType: raw.sync_type,
    status: raw.status,
    recordsReceived: raw.records_received,
    recordsProcessed: raw.records_processed,
    startedAt: raw.started_at,
    completedAt: raw.completed_at,
    errorCode: raw.error_code,
    lastActivityAt: raw.last_activity_at ?? null,
  };

  if (isLocallyActive) {
    return { state: "syncing", syncRun };
  }

  if (syncRun.status === "running" || syncRun.status === "pending") {
    return isStale(syncRun, now())
      ? { state: "stalled", syncRun }
      : { state: "syncing", syncRun };
  }

  if (syncRun.status === "completed") {
    return { state: "completed", syncRun };
  }

  if (syncRun.status === "failed") {
    return { state: "failed", syncRun };
  }

  return { state: "syncing", syncRun };
}

function isStale(syncRun: SyncRunInfo, nowMs: number): boolean {
  if (syncRun.lastActivityAt === null) {
    return false;
  }
  const lastActivityMs = Date.parse(syncRun.lastActivityAt);
  return !Number.isNaN(lastActivityMs) && nowMs - lastActivityMs > STALE_AFTER_MS;
}
