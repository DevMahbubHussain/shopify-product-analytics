import type { SyncRunInfo } from "../sync/getSyncStatus.server.ts";

export interface SyncStageProgression {
  isCompleted: boolean;
  isSyncing: boolean;
  catalogueDone: boolean;
  ordersDone: boolean;
  metricsDone: boolean;
  badgeTone: "success" | "info" | "warning";
  badgeLabel: string;
  headline: string;
  subtext: string;
  catalogueSummary: string;
  ordersSummary: string;
}

export function getSyncStageProgression(
  syncRun: SyncRunInfo | null,
  isSyncing: boolean,
): SyncStageProgression {
  const isCompleted = syncRun?.status === "completed";
  const processed = syncRun?.recordsProcessed ?? 0;
  const received = syncRun?.recordsReceived ?? 0;

  const catalogueDone = isCompleted || processed > 0 || received > 0;
  const ordersDone = isCompleted || processed >= 10;
  const metricsDone = isCompleted;

  const badgeTone: "success" | "info" | "warning" = isCompleted
    ? "success"
    : isSyncing
    ? "info"
    : "warning";

  const badgeLabel = isCompleted
    ? "Ready"
    : isSyncing
    ? "Sync in progress"
    : "Pending";

  const headline = isCompleted
    ? "Initial Store Synchronization Complete"
    : "Setting Up Your Product Analytics";

  const subtext = isCompleted
    ? "Your products and order history are fully imported and ready for analysis."
    : "We are importing your catalogue and recent order history in the background.";

  const catalogueSummary =
    received > 0 ? `${received.toLocaleString()} records fetched` : "Products & variants";

  const ordersSummary =
    processed > 0
      ? `${processed.toLocaleString()} orders processed`
      : "60-day historical window";

  return {
    isCompleted,
    isSyncing,
    catalogueDone,
    ordersDone,
    metricsDone,
    badgeTone,
    badgeLabel,
    headline,
    subtext,
    catalogueSummary,
    ordersSummary,
  };
}
