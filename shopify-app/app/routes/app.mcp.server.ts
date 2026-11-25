import type { BackendClient } from "../lib/backend/backendClient.server.ts";
import { BackendError } from "../lib/backend/backendClient.server.ts";
import type {
  GeneratedMcpToken,
  McpAuditLogsResponse,
  McpStatusResponse,
  McpToken,
} from "../features/mcp/mcpClient.server.ts";
import {
  createMcpToken,
  getMcpAuditLogs,
  getMcpConnections,
  getMcpStatus,
  grantMcpConsent,
  InvalidMcpTokenIdError,
  MCP_CONSENT_POLICY_VERSION,
  revokeMcpToken,
  withdrawMcpConsent,
} from "../features/mcp/mcpClient.server.ts";

export const AUDIT_LOGS_PER_PAGE = 25;
const STAFF_REF_MAX_LENGTH = 64;
const BACKEND_UNAVAILABLE = "The analytics service is unavailable. Try again shortly.";
const REQUEST_REJECTED = "The request was rejected. Refresh the page and try again.";
const UNKNOWN_STAFF = "Could not identify the signed-in staff member. Reload the app and try again.";

export type McpLoadSection = "status" | "connections" | "auditLogs";

export interface McpPageData {
  shop: string;
  status: McpStatusResponse | null;
  tokens: McpToken[];
  auditLogs: McpAuditLogsResponse;
  mcpEndpointUrl: string;
  loadErrors: McpLoadSection[];
}

export interface McpActionResult {
  success: boolean;
  message?: string;
  error?: string;
  generatedToken?: GeneratedMcpToken;
}

export function parseAuditPage(raw: string | null): number {
  if (raw === null || !/^\d+$/.test(raw)) {
    return 1;
  }
  const page = Number(raw);
  return Number.isSafeInteger(page) && page >= 1 ? page : 1;
}

export function resolveMcpPublicUrl(): string {
  const raw = process.env.MCP_PUBLIC_URL;
  if (!raw) {
    throw new Error("MCP_PUBLIC_URL environment variable is required.");
  }

  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error("MCP_PUBLIC_URL must be an absolute URL.");
  }

  const isLocalhost = url.hostname === "localhost" || url.hostname === "127.0.0.1";
  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLocalhost)) {
    throw new Error("MCP_PUBLIC_URL must use https (http is allowed only for localhost).");
  }

  return url.toString().replace(/\/+$/, "");
}

function emptyAuditLogs(): McpAuditLogsResponse {
  return {
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: AUDIT_LOGS_PER_PAGE, total: 0 },
  };
}

export async function loadMcpData(
  backend: BackendClient,
  shop: string,
  params: { page: number },
): Promise<McpPageData> {
  const mcpEndpointUrl = resolveMcpPublicUrl();
  const loadErrors: McpLoadSection[] = [];

  const settle = async <T>(section: McpLoadSection, call: Promise<T>, fallback: T): Promise<T> => {
    try {
      return await call;
    } catch (error) {
      console.error(`Failed to load MCP ${section}:`, error);
      loadErrors.push(section);
      return fallback;
    }
  };

  const [status, connections, auditLogs] = await Promise.all([
    settle<McpStatusResponse | null>("status", getMcpStatus(backend, shop), null),
    settle("connections", getMcpConnections(backend, shop), { tokens: [] as McpToken[] }),
    settle(
      "auditLogs",
      getMcpAuditLogs(backend, shop, { page: params.page, per_page: AUDIT_LOGS_PER_PAGE }),
      emptyAuditLogs(),
    ),
  ]);

  return { shop, status, tokens: connections.tokens, auditLogs, mcpEndpointUrl, loadErrors };
}

function validStaffRef(staffSub: string | undefined): string | null {
  return staffSub && staffSub.length <= STAFF_REF_MAX_LENGTH ? staffSub : null;
}

function formString(formData: FormData, key: string): string {
  const value = formData.get(key);
  return typeof value === "string" ? value.trim() : "";
}

function backendErrorBody(error: BackendError): string {
  return error.message.replace(/^Backend S2S call failed \(\d+\): /, "");
}

function toUserError(error: unknown): string {
  if (error instanceof InvalidMcpTokenIdError) {
    return error.message;
  }

  console.error("MCP action failed:", error);

  if (!(error instanceof BackendError) || error.status >= 500) {
    return BACKEND_UNAVAILABLE;
  }

  try {
    const parsed = JSON.parse(backendErrorBody(error)) as {
      error_description?: unknown;
      message?: unknown;
    };
    for (const candidate of [parsed.error_description, parsed.message]) {
      if (typeof candidate === "string" && candidate !== "") {
        return candidate;
      }
    }
  } catch {
    // Non-JSON body (proxy page, HTML): never shown to the merchant.
  }

  return REQUEST_REJECTED;
}

export async function handleMcpAction(
  backend: BackendClient,
  shop: string,
  staffSub: string | undefined,
  formData: FormData,
): Promise<McpActionResult> {
  const intent = formString(formData, "intent");

  try {
    switch (intent) {
      case "create_token": {
        const name = formString(formData, "name");
        if (!name) {
          return { success: false, error: "Token name is required." };
        }
        const staffRef = validStaffRef(staffSub);
        if (!staffRef) {
          return { success: false, error: UNKNOWN_STAFF };
        }
        const result = await createMcpToken(backend, shop, { name, staff_ref: staffRef });
        return {
          success: true,
          message: "Connection token generated successfully.",
          generatedToken: result.token,
        };
      }

      case "revoke_token": {
        const tokenId = formString(formData, "tokenId");
        if (!tokenId) {
          return { success: false, error: "Token ID is required." };
        }
        await revokeMcpToken(backend, shop, tokenId);
        return { success: true, message: "Token revoked successfully." };
      }

      case "grant_consent": {
        const staffRef = validStaffRef(staffSub);
        if (!staffRef) {
          return { success: false, error: UNKNOWN_STAFF };
        }
        await grantMcpConsent(backend, shop, {
          staff_ref: staffRef,
          policy_version: MCP_CONSENT_POLICY_VERSION,
        });
        return { success: true, message: "Data-sharing authorization granted." };
      }

      case "withdraw_consent": {
        await withdrawMcpConsent(backend, shop);
        return {
          success: true,
          message: "Authorization withdrawn and active connections revoked.",
        };
      }

      default:
        return { success: false, error: "Unknown action." };
    }
  } catch (error) {
    return { success: false, error: toUserError(error) };
  }
}
