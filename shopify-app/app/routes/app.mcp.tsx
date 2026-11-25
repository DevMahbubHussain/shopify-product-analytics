import { useState } from "react";
import type {
  ActionFunctionArgs,
  HeadersFunction,
  LoaderFunctionArgs,
} from "react-router";
import {
  useActionData,
  useLoaderData,
  useNavigation,
  useRouteError,
  useSearchParams,
  useSubmit,
} from "react-router";
import { boundary } from "@shopify/shopify-app-react-router/server";
import { authenticate } from "../shopify.server.ts";
import { backendClient } from "../lib/backend/backendClient.server.ts";
import type { McpLoadSection } from "./app.mcp.server.ts";
import { handleMcpAction, loadMcpData, parseAuditPage } from "./app.mcp.server.ts";
import type { McpAuditLog, McpToken } from "../features/mcp/mcpClient.server.ts";

const TOKEN_NAME_MAX_LENGTH = 100;
const CREATE_MODAL_ID = "mcp-create-token-modal";
const REVOKE_MODAL_ID = "mcp-revoke-token-modal";
const WITHDRAW_MODAL_ID = "mcp-withdraw-modal";

const SECTION_LABELS: Record<McpLoadSection, string> = {
  status: "plan and authorization status",
  connections: "connection tokens",
  auditLogs: "audit trail",
};

export const loader = async ({ request }: LoaderFunctionArgs) => {
  const { session } = await authenticate.admin(request);
  const page = parseAuditPage(new URL(request.url).searchParams.get("page"));

  return loadMcpData(backendClient(), session.shop, { page });
};

export const action = async ({ request }: ActionFunctionArgs) => {
  const { session, sessionToken } = await authenticate.admin(request);
  const formData = await request.formData();

  return handleMcpAction(backendClient(), session.shop, sessionToken?.sub, formData);
};

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString();
}

function auditTone(status: string): "success" | "warning" | "critical" {
  if (status === "success") return "success";
  if (status === "rejected") return "warning";
  return "critical";
}

function SectionHeader({ heading, children }: { heading: string; children?: React.ReactNode }) {
  return (
    <s-stack direction="inline" justifyContent="space-between" alignItems="center" gap="base">
      <s-heading>{heading}</s-heading>
      {children}
    </s-stack>
  );
}

function SetupStep({ done, label }: { done: boolean; label: string }) {
  return (
    <s-stack direction="inline" gap="small" alignItems="center">
      <s-icon type={done ? "check-circle-filled" : "circle-dashed"} tone={done ? "success" : "neutral"} />
      <s-text color={done ? "subdued" : "base"}>{label}</s-text>
    </s-stack>
  );
}

export default function McpSettingsPage() {
  const { status, tokens, auditLogs, mcpEndpointUrl, loadErrors } =
    useLoaderData<typeof loader>();
  const actionData = useActionData<typeof action>();
  const navigation = useNavigation();
  const submit = useSubmit();
  const [searchParams, setSearchParams] = useSearchParams();

  const [tokenName, setTokenName] = useState("");
  const [revokeTarget, setRevokeTarget] = useState<McpToken | null>(null);
  const [copiedKey, setCopiedKey] = useState<string | null>(null);

  const isSubmitting = navigation.state === "submitting";
  const statusLoaded = status !== null;
  const canUseMcp = status?.plan.can_use_mcp ?? false;
  const hasConsent = status?.has_consent ?? false;
  const hasTokens = tokens.length > 0;
  const setupComplete = canUseMcp && hasConsent && hasTokens;
  const canCreateToken = canUseMcp && hasConsent && !loadErrors.includes("connections");

  const runIntent = (intent: string, fields: Record<string, string> = {}) => {
    submit({ intent, ...fields }, { method: "post" });
  };

  const goToPage = (page: number) => {
    const next = new URLSearchParams(searchParams);
    next.set("page", String(page));
    setSearchParams(next);
  };

  const copyToClipboard = async (text: string, key: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopiedKey(key);
      setTimeout(() => setCopiedKey(null), 2500);
    } catch {
      console.warn("Clipboard access failed");
    }
  };

  const clientConfig = JSON.stringify(
    {
      mcpServers: {
        "commerce-analytics": {
          url: mcpEndpointUrl,
          headers: {
            Authorization: `Bearer ${actionData?.generatedToken?.plaintext_token ?? "<YOUR_MCP_TOKEN>"}`,
          },
        },
      },
    },
    null,
    2,
  );

  const { current_page: currentPage, last_page: lastPage, total } = auditLogs.meta;
  const pastLastPage = auditLogs.data.length === 0 && total > 0;

  return (
    <s-page heading="AI & MCP">
      <s-link slot="breadcrumb-actions" href="/app">
        Dashboard
      </s-link>

      {/* Sidebar: current state at a glance */}
      <s-section slot="aside" heading="Status">
        <s-stack gap="base">
          <s-stack gap="small-200">
            <s-text color="subdued">Plan</s-text>
            {!statusLoaded ? (
              <s-text>Unavailable</s-text>
            ) : canUseMcp ? (
              <>
                <s-badge tone="success">Pro</s-badge>
                <s-text color="subdued">
                  {status.plan.rate_limit_per_minute} requests/min · {status.plan.history_days}-day
                  history
                </s-text>
              </>
            ) : (
              <>
                <s-badge>Free</s-badge>
                <s-button variant="primary" href="/app/pricing">
                  Upgrade to Pro
                </s-button>
              </>
            )}
          </s-stack>

          <s-divider />

          <s-stack gap="small-200">
            <s-text color="subdued">Data sharing</s-text>
            {statusLoaded && (
              <s-badge tone={hasConsent ? "success" : "warning"}>
                {hasConsent ? "Authorized" : "Not authorized"}
              </s-badge>
            )}
            {hasConsent && status?.consent && (
              <s-text color="subdued">
                Policy {status.consent.policy_version} · {formatDateTime(status.consent.granted_at)}
              </s-text>
            )}
            {hasConsent ? (
              <s-button
                tone="critical"
                variant="tertiary"
                commandFor={WITHDRAW_MODAL_ID}
                command="--show"
                disabled={isSubmitting}
              >
                Withdraw authorization
              </s-button>
            ) : (
              <s-button
                variant="primary"
                disabled={isSubmitting || !canUseMcp}
                loading={isSubmitting}
                onClick={() => runIntent("grant_consent")}
              >
                Authorize data sharing
              </s-button>
            )}
          </s-stack>

          <s-divider />

          <s-stack gap="small-200">
            <s-text color="subdued">Active tokens</s-text>
            <s-text>{tokens.length}</s-text>
          </s-stack>
        </s-stack>
      </s-section>

      {/* Page-level feedback */}
      {loadErrors.length > 0 && (
        <s-banner heading="Some data could not be loaded" tone="critical">
          Could not load the {loadErrors.map((s) => SECTION_LABELS[s]).join(", ")}. Actions are
          disabled until this loads. Refresh the page to try again.
        </s-banner>
      )}

      {actionData?.error && (
        <s-banner heading="Something went wrong" tone="critical">
          {actionData.error}
        </s-banner>
      )}

      {actionData?.message && !actionData.generatedToken && (
        <s-banner tone="success">{actionData.message}</s-banner>
      )}

      {actionData?.generatedToken && (
        <s-banner heading="Copy your new token now" tone="warning">
          <s-stack gap="small">
            <s-text>You will not be able to see it again after you leave or refresh this page.</s-text>
            <s-box background="subdued" padding="small" borderRadius="base">
              <code style={{ wordBreak: "break-all" }}>{actionData.generatedToken.plaintext_token}</code>
            </s-box>
          </s-stack>
          <s-button
            slot="secondary-actions"
            onClick={() => copyToClipboard(actionData.generatedToken?.plaintext_token ?? "", "token")}
          >
            {copiedKey === "token" ? "Copied" : "Copy token"}
          </s-button>
        </s-banner>
      )}

      {/* Onboarding checklist, hidden once everything is in place */}
      {statusLoaded && !setupComplete && (
        <s-section heading="Get started">
          <s-stack gap="small">
            <SetupStep done={canUseMcp} label="Subscribe to the Pro plan" />
            <SetupStep done={hasConsent} label="Authorize read-only data sharing" />
            <SetupStep done={hasTokens} label="Create a connection token" />
          </s-stack>
        </s-section>
      )}

      {/* Tokens */}
      <s-section>
        <s-stack gap="base">
          <SectionHeader heading="Connection tokens">
            <s-button
              variant="primary"
              icon="plus"
              commandFor={CREATE_MODAL_ID}
              command="--show"
              disabled={!canCreateToken || isSubmitting}
            >
              Generate token
            </s-button>
          </SectionHeader>

          {!hasTokens ? (
            <s-text color="subdued">
              {canCreateToken
                ? "No active tokens yet. Generate one to connect a client."
                : "Tokens can be created once the Pro plan is active and data sharing is authorized."}
            </s-text>
          ) : (
            <s-table>
              <s-table-header-row>
                <s-table-header>Name</s-table-header>
                <s-table-header>Token</s-table-header>
                <s-table-header>Created</s-table-header>
                <s-table-header>Last used</s-table-header>
                <s-table-header />
              </s-table-header-row>
              <s-table-body>
                {tokens.map((token) => (
                  <s-table-row key={token.id}>
                    <s-table-cell>{token.name}</s-table-cell>
                    <s-table-cell>
                      <code>{token.token_prefix}…</code>
                    </s-table-cell>
                    <s-table-cell>{new Date(token.created_at).toLocaleDateString()}</s-table-cell>
                    <s-table-cell>
                      {token.last_used_at ? formatDateTime(token.last_used_at) : "Never"}
                    </s-table-cell>
                    <s-table-cell>
                      <s-button
                        tone="critical"
                        variant="tertiary"
                        commandFor={REVOKE_MODAL_ID}
                        command="--show"
                        disabled={isSubmitting}
                        onClick={() => setRevokeTarget(token)}
                      >
                        Revoke
                      </s-button>
                    </s-table-cell>
                  </s-table-row>
                ))}
              </s-table-body>
            </s-table>
          )}
        </s-stack>
      </s-section>

      {/* Client setup */}
      <s-section>
        <s-stack gap="base">
          <SectionHeader heading="Connect a client">
            <s-button icon="text-block" href="/app/mcp/prompts">
              Prompt library
            </s-button>
          </SectionHeader>

          <s-stack gap="small-200">
            <s-text color="subdued">Endpoint</s-text>
            <s-stack direction="inline" gap="small" alignItems="center">
              <s-box background="subdued" padding="small" borderRadius="base">
                <code style={{ wordBreak: "break-all" }}>{mcpEndpointUrl}</code>
              </s-box>
              <s-button variant="tertiary" icon="clipboard" onClick={() => copyToClipboard(mcpEndpointUrl, "endpoint")}>
                {copiedKey === "endpoint" ? "Copied" : "Copy"}
              </s-button>
            </s-stack>
          </s-stack>

          <s-unordered-list>
            <s-list-item>
              <s-text type="strong">Config file clients:</s-text> paste the JSON below. Some
              clients also need <code>&quot;type&quot;: &quot;http&quot;</code> in the server entry.
            </s-list-item>
            <s-list-item>
              <s-text type="strong">Connector form clients:</s-text> enter the endpoint and add the
              request header <code>Authorization: Bearer &lt;your token&gt;</code>. The endpoint must
              be a public https address on port 443.
            </s-list-item>
          </s-unordered-list>

          <s-box background="subdued" padding="base" borderRadius="base">
            <s-stack gap="small">
              <s-stack direction="inline" justifyContent="space-between" alignItems="center">
                <s-text color="subdued">JSON configuration</s-text>
                <s-button variant="tertiary" icon="clipboard" onClick={() => copyToClipboard(clientConfig, "config")}>
                  {copiedKey === "config" ? "Copied" : "Copy"}
                </s-button>
              </s-stack>
              <pre style={{ overflowX: "auto", margin: 0 }}>
                <code>{clientConfig}</code>
              </pre>
            </s-stack>
          </s-box>
        </s-stack>
      </s-section>

      {/* Audit trail */}
      <s-section>
        <s-stack gap="base">
          <SectionHeader heading="Audit trail">
            <s-badge>{total} requests</s-badge>
          </SectionHeader>
          <s-text color="subdued">
            MCP request metadata from the last 90 days. No customer personal data is recorded.
          </s-text>

          {pastLastPage ? (
            <s-stack direction="inline" gap="small" alignItems="center">
              <s-text>This page is past the end of the audit trail.</s-text>
              <s-button variant="tertiary" onClick={() => goToPage(1)}>
                Go to first page
              </s-button>
            </s-stack>
          ) : auditLogs.data.length === 0 ? (
            <s-text color="subdued">
              No requests yet. Entries appear once a client calls a tool or reads a resource.
            </s-text>
          ) : (
            <AuditTable logs={auditLogs.data} />
          )}

          {lastPage > 1 && !pastLastPage && (
            <s-stack direction="inline" justifyContent="space-between" alignItems="center">
              <s-text color="subdued">
                Page {currentPage} of {lastPage}
              </s-text>
              <s-button-group>
                <s-button
                  slot="secondary-actions"
                  icon="chevron-left"
                  accessibilityLabel="Previous page"
                  disabled={currentPage <= 1}
                  onClick={() => goToPage(currentPage - 1)}
                />
                <s-button
                  slot="secondary-actions"
                  icon="chevron-right"
                  accessibilityLabel="Next page"
                  disabled={currentPage >= lastPage}
                  onClick={() => goToPage(currentPage + 1)}
                />
              </s-button-group>
            </s-stack>
          )}
        </s-stack>
      </s-section>

      {/* Dialogs */}
      <s-modal id={CREATE_MODAL_ID} heading="Generate connection token" onHide={() => setTokenName("")}>
        <s-text-field
          label="Token name"
          details="Helps you recognize where the token is used."
          placeholder="e.g. Analyst workstation"
          maxLength={TOKEN_NAME_MAX_LENGTH}
          value={tokenName}
          onInput={(e: Event) => {
            const target = e.currentTarget as HTMLInputElement | null;
            setTokenName(target?.value ?? "");
          }}
        />
        <s-button
          slot="primary-action"
          variant="primary"
          commandFor={CREATE_MODAL_ID}
          command="--hide"
          disabled={!tokenName.trim() || isSubmitting}
          onClick={() => runIntent("create_token", { name: tokenName.trim() })}
        >
          Generate
        </s-button>
        <s-button slot="secondary-actions" commandFor={CREATE_MODAL_ID} command="--hide">
          Cancel
        </s-button>
      </s-modal>

      <s-modal id={REVOKE_MODAL_ID} heading="Revoke token?" onHide={() => setRevokeTarget(null)}>
        <s-text>
          Clients using <s-text type="strong">{revokeTarget?.name ?? "this token"}</s-text> lose
          access immediately. This cannot be undone.
        </s-text>
        <s-button
          slot="primary-action"
          tone="critical"
          variant="primary"
          commandFor={REVOKE_MODAL_ID}
          command="--hide"
          disabled={!revokeTarget || isSubmitting}
          onClick={() => revokeTarget && runIntent("revoke_token", { tokenId: revokeTarget.id })}
        >
          Revoke token
        </s-button>
        <s-button slot="secondary-actions" commandFor={REVOKE_MODAL_ID} command="--hide">
          Cancel
        </s-button>
      </s-modal>

      <s-modal id={WITHDRAW_MODAL_ID} heading="Withdraw authorization?">
        <s-text>
          Every active connection token is revoked immediately. Clients lose access until you
          authorize again and create new tokens.
        </s-text>
        <s-button
          slot="primary-action"
          tone="critical"
          variant="primary"
          commandFor={WITHDRAW_MODAL_ID}
          command="--hide"
          disabled={isSubmitting}
          onClick={() => runIntent("withdraw_consent")}
        >
          Withdraw and revoke tokens
        </s-button>
        <s-button slot="secondary-actions" commandFor={WITHDRAW_MODAL_ID} command="--hide">
          Cancel
        </s-button>
      </s-modal>
    </s-page>
  );
}

function AuditTable({ logs }: { logs: McpAuditLog[] }) {
  return (
    <s-table>
      <s-table-header-row>
        <s-table-header>Time</s-table-header>
        <s-table-header>Method</s-table-header>
        <s-table-header>Target</s-table-header>
        <s-table-header>Client</s-table-header>
        <s-table-header>Latency</s-table-header>
        <s-table-header>Status</s-table-header>
      </s-table-header-row>
      <s-table-body>
        {logs.map((log) => (
          <s-table-row key={log.id}>
            <s-table-cell>{formatDateTime(log.created_at)}</s-table-cell>
            <s-table-cell>
              <code>{log.method}</code>
            </s-table-cell>
            <s-table-cell>
              <code>{log.target_name ?? "—"}</code>
            </s-table-cell>
            <s-table-cell>{log.client_name ?? "Unknown"}</s-table-cell>
            <s-table-cell>{log.duration_ms} ms</s-table-cell>
            <s-table-cell>
              <s-badge tone={auditTone(log.status)}>{log.status}</s-badge>
            </s-table-cell>
          </s-table-row>
        ))}
      </s-table-body>
    </s-table>
  );
}

export function ErrorBoundary() {
  return boundary.error(useRouteError());
}

export const headers: HeadersFunction = (headersArgs) => {
  return boundary.headers(headersArgs);
};
