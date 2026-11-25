import type { BackendClient } from "../../lib/backend/backendClient.server.ts";

export const MCP_CONSENT_POLICY_VERSION = "v1.0";

const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export class InvalidMcpTokenIdError extends Error {
  public constructor() {
    super("Invalid token id.");
    this.name = "InvalidMcpTokenIdError";
  }
}

export interface McpConsent {
  id?: string;
  platform: string;
  granted_by_staff_ref: string;
  policy_version: string;
  granted_at: string;
}

export interface McpPlan {
  tier: "free" | "pro";
  can_use_mcp: boolean;
  history_days: number;
  rate_limit_per_minute: number;
}

export interface McpStatusResponse {
  has_consent: boolean;
  consent: McpConsent | null;
  plan: McpPlan;
  counts: {
    active_tokens: number;
    active_oauth_grants: number;
  };
  store: McpStoreContext;
}

export interface McpStoreContext {
  timezone: string;
  currency_code: string;
  /** Store-local YYYY-MM-DD of the earliest imported data, or null when unknown. */
  history_start: string | null;
}

export interface McpToken {
  id: string;
  name: string;
  token_prefix: string;
  created_by_staff_ref: string;
  last_used_at: string | null;
  expires_at: string | null;
  created_at: string;
}

export interface McpConnectionsResponse {
  tokens: McpToken[];
}

export interface GeneratedMcpToken extends Omit<McpToken, "last_used_at"> {
  plaintext_token: string;
}

export interface CreateMcpTokenPayload {
  name: string;
  staff_ref: string;
  expires_at?: string | null;
}

export interface GrantMcpConsentPayload {
  staff_ref: string;
  policy_version: string;
}

export interface McpAuditLog {
  id: string;
  connection_type: string;
  method: string;
  target_name: string | null;
  status: string;
  duration_ms: number;
  client_name: string | null;
  protocol_version: string | null;
  correlation_id: string;
  created_at: string;
}

export interface McpAuditLogsResponse {
  data: McpAuditLog[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export async function getMcpStatus(
  backend: BackendClient,
  shopDomain: string,
): Promise<McpStatusResponse> {
  return backend.getInternal<McpStatusResponse>(
    "/internal/v1/mcp/status",
    shopDomain,
  );
}

export async function getMcpConnections(
  backend: BackendClient,
  shopDomain: string,
): Promise<McpConnectionsResponse> {
  return backend.getInternal<McpConnectionsResponse>(
    "/internal/v1/mcp/connections",
    shopDomain,
  );
}

export async function createMcpToken(
  backend: BackendClient,
  shopDomain: string,
  payload: CreateMcpTokenPayload,
): Promise<{ token: GeneratedMcpToken }> {
  return backend.postInternal<{ token: GeneratedMcpToken }>(
    "/internal/v1/mcp/tokens",
    shopDomain,
    payload,
  );
}

export async function revokeMcpToken(
  backend: BackendClient,
  shopDomain: string,
  tokenId: string,
): Promise<{ status: string }> {
  if (!UUID_PATTERN.test(tokenId)) {
    throw new InvalidMcpTokenIdError();
  }
  return backend.deleteInternal<{ status: string }>(
    `/internal/v1/mcp/connections/${encodeURIComponent(tokenId)}`,
    shopDomain,
  );
}

export async function grantMcpConsent(
  backend: BackendClient,
  shopDomain: string,
  payload: GrantMcpConsentPayload,
): Promise<{ status: string; consent: McpConsent }> {
  return backend.postInternal<{ status: string; consent: McpConsent }>(
    "/internal/v1/mcp/consent",
    shopDomain,
    payload,
  );
}

export async function withdrawMcpConsent(
  backend: BackendClient,
  shopDomain: string,
): Promise<{ status: string }> {
  return backend.deleteInternal<{ status: string }>(
    "/internal/v1/mcp/consent",
    shopDomain,
  );
}

export async function getMcpAuditLogs(
  backend: BackendClient,
  shopDomain: string,
  params?: { page?: number; per_page?: number },
): Promise<McpAuditLogsResponse> {
  return backend.getInternal<McpAuditLogsResponse>(
    "/internal/v1/mcp/audit-logs",
    shopDomain,
    undefined,
    params,
  );
}
