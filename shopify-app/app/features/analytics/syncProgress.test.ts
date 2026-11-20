import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { getSyncStageProgression } from "./syncProgress.ts";
import type { SyncRunInfo } from "../sync/getSyncStatus.server.ts";

describe("getSyncStageProgression", () => {
  it("returns pending state when syncRun is null", () => {
    const stage = getSyncStageProgression(null, true);

    assert.equal(stage.isCompleted, false);
    assert.equal(stage.isSyncing, true);
    assert.equal(stage.catalogueDone, false);
    assert.equal(stage.ordersDone, false);
    assert.equal(stage.metricsDone, false);
    assert.equal(stage.badgeTone, "info");
    assert.equal(stage.badgeLabel, "Sync in progress");
    assert.equal(stage.headline, "Setting Up Your Product Analytics");
  });

  it("advances catalogue and order stages as records arrive", () => {
    const mockRun: SyncRunInfo = {
      id: "run-1",
      syncType: "historical",
      status: "running",
      recordsReceived: 50,
      recordsProcessed: 20,
      startedAt: "2026-11-20T10:00:00Z",
      completedAt: null,
      errorCode: null,
      lastActivityAt: "2026-11-20T10:05:00Z",
    };

    const stage = getSyncStageProgression(mockRun, true);

    assert.equal(stage.isCompleted, false);
    assert.equal(stage.catalogueDone, true);
    assert.equal(stage.ordersDone, true);
    assert.equal(stage.metricsDone, false);
    assert.equal(stage.catalogueSummary, "50 records fetched");
    assert.equal(stage.ordersSummary, "20 orders processed");
  });

  it("marks all stages complete when status is completed", () => {
    const mockRun: SyncRunInfo = {
      id: "run-2",
      syncType: "historical",
      status: "completed",
      recordsReceived: 120,
      recordsProcessed: 45,
      startedAt: "2026-11-20T10:00:00Z",
      completedAt: "2026-11-20T10:15:00Z",
      errorCode: null,
      lastActivityAt: "2026-11-20T10:15:00Z",
    };

    const stage = getSyncStageProgression(mockRun, false);

    assert.equal(stage.isCompleted, true);
    assert.equal(stage.catalogueDone, true);
    assert.equal(stage.ordersDone, true);
    assert.equal(stage.metricsDone, true);
    assert.equal(stage.badgeTone, "success");
    assert.equal(stage.badgeLabel, "Ready");
    assert.equal(stage.headline, "Initial Store Synchronization Complete");
  });
});
