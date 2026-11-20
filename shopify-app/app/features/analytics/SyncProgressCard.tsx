import React from "react";
import type { SyncRunInfo } from "../sync/getSyncStatus.server.ts";
import { getSyncStageProgression } from "./syncProgress.ts";

export interface SyncProgressCardProps {
  syncRun: SyncRunInfo | null;
  isSyncing: boolean;
}

export function SyncProgressCard({ syncRun, isSyncing }: SyncProgressCardProps): React.JSX.Element {
  const stage = getSyncStageProgression(syncRun, isSyncing);

  return (
    <div
      style={{
        background: "#ffffff",
        border: "1px solid #c9cccf",
        borderRadius: "12px",
        padding: "20px",
        marginBottom: "16px",
        boxShadow: "0 1px 3px rgba(0,0,0,0.05)",
      }}
    >
      <div
        style={{
          display: "flex",
          justifyContent: "space-between",
          alignItems: "flex-start",
          marginBottom: "16px",
          flexWrap: "wrap",
          gap: "12px",
        }}
      >
        <div>
          <div style={{ fontSize: "16px", fontWeight: 600, color: "#202223" }}>
            {stage.headline}
          </div>
          <div style={{ fontSize: "13px", color: "#616161", marginTop: "4px" }}>
            {stage.subtext}
          </div>
        </div>

        <s-badge tone={stage.badgeTone}>{stage.badgeLabel}</s-badge>
      </div>

      {/* 4-Step Onboarding Stepper */}
      <div
        style={{
          display: "grid",
          gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))",
          gap: "12px",
          padding: "12px",
          background: "#f7f8f9",
          borderRadius: "8px",
          marginBottom: "14px",
        }}
      >
        {/* Step 1: Store Connected */}
        <div style={{ display: "flex", alignItems: "flex-start", gap: "8px" }}>
          <span style={{ fontSize: "16px", color: "#008060" }}>✓</span>
          <div>
            <div style={{ fontSize: "13px", fontWeight: 600, color: "#202223" }}>
              1. Store Connected
            </div>
            <div style={{ fontSize: "11px", color: "#616161" }}>
              Secure API handshake verified
            </div>
          </div>
        </div>

        {/* Step 2: Catalogue Import */}
        <div style={{ display: "flex", alignItems: "flex-start", gap: "8px" }}>
          <span style={{ fontSize: "16px", color: stage.catalogueDone ? "#008060" : "#8c9196" }}>
            {stage.catalogueDone ? "✓" : "○"}
          </span>
          <div>
            <div style={{ fontSize: "13px", fontWeight: 600, color: "#202223" }}>
              2. Catalogue Imported
            </div>
            <div style={{ fontSize: "11px", color: "#616161" }}>
              {stage.catalogueSummary}
            </div>
          </div>
        </div>

        {/* Step 3: Orders & Refunds */}
        <div style={{ display: "flex", alignItems: "flex-start", gap: "8px" }}>
          <span style={{ fontSize: "16px", color: stage.ordersDone ? "#008060" : "#8c9196" }}>
            {stage.ordersDone ? "✓" : "○"}
          </span>
          <div>
            <div style={{ fontSize: "13px", fontWeight: 600, color: "#202223" }}>
              3. Order History
            </div>
            <div style={{ fontSize: "11px", color: "#616161" }}>
              {stage.ordersSummary}
            </div>
          </div>
        </div>

        {/* Step 4: Analytics Precomputed */}
        <div style={{ display: "flex", alignItems: "flex-start", gap: "8px" }}>
          <span style={{ fontSize: "16px", color: stage.metricsDone ? "#008060" : "#8c9196" }}>
            {stage.metricsDone ? "✓" : "○"}
          </span>
          <div>
            <div style={{ fontSize: "13px", fontWeight: 600, color: "#202223" }}>
              4. Daily Analytics
            </div>
            <div style={{ fontSize: "11px", color: "#616161" }}>
              {stage.metricsDone ? "Precomputed & ready" : "Aggregating metrics"}
            </div>
          </div>
        </div>
      </div>

      {!stage.isCompleted && (
        <div style={{ fontSize: "12px", color: "#616161", display: "flex", alignItems: "center", gap: "6px" }}>
          <span>💡</span>
          <span>
            You can continue navigating inside Shopify Admin. This dashboard refreshes automatically as new records arrive.
          </span>
        </div>
      )}
    </div>
  );
}
