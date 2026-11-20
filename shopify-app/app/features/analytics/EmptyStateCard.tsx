import React from "react";
import type { EmptyStateConfig, EmptyStateAction } from "./emptyState.ts";

export interface EmptyStateCardProps {
  config: EmptyStateConfig;
  onAction?: (actionKey: EmptyStateAction["actionKey"]) => void;
}

export function EmptyStateCard({ config, onAction }: EmptyStateCardProps): React.JSX.Element {
  return (
    <div
      style={{
        background: "#ffffff",
        border: "1px solid #c9cccf",
        borderRadius: "12px",
        padding: "40px 24px",
        textAlign: "center",
        boxShadow: "0 1px 3px rgba(0,0,0,0.05)",
        margin: "12px 0",
      }}
    >
      <div style={{ fontSize: "40px", marginBottom: "12px" }}>{config.icon}</div>
      <div
        style={{
          fontSize: "16px",
          fontWeight: 600,
          color: "#202223",
          marginBottom: "8px",
        }}
      >
        {config.heading}
      </div>
      <div
        style={{
          fontSize: "13px",
          color: "#616161",
          maxWidth: "480px",
          margin: "0 auto 20px auto",
          lineHeight: "1.5",
        }}
      >
        {config.description}
      </div>

      {(config.primaryAction || config.secondaryAction) && (
        <div
          style={{
            display: "flex",
            gap: "10px",
            justifyContent: "center",
            alignItems: "center",
            flexWrap: "wrap",
            marginBottom: config.tip ? "16px" : "0",
          }}
        >
          {config.primaryAction && (
            <s-button
              variant="primary"
              onClick={() => onAction?.(config.primaryAction!.actionKey)}
            >
              {config.primaryAction.label}
            </s-button>
          )}

          {config.secondaryAction && (
            config.secondaryAction.url ? (
              <s-button
                variant="secondary"
                href={config.secondaryAction.url}
                target="_blank"
              >
                {config.secondaryAction.label} ↗
              </s-button>
            ) : (
              <s-button
                variant="secondary"
                onClick={() => onAction?.(config.secondaryAction!.actionKey)}
              >
                {config.secondaryAction.label}
              </s-button>
            )
          )}
        </div>
      )}

      {config.tip && (
        <div
          style={{
            fontSize: "12px",
            color: "#8c9196",
            borderTop: "1px solid #f1f2f3",
            paddingTop: "12px",
            marginTop: "16px",
            display: "inline-block",
          }}
        >
          💡 {config.tip}
        </div>
      )}
    </div>
  );
}
