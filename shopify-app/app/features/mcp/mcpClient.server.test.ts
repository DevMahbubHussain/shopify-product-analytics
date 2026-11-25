import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../../lib/backend/backendClient.server.ts";
import {
  createMcpToken,
  getMcpAuditLogs,
  getMcpConnections,
  getMcpStatus,
  grantMcpConsent,
  InvalidMcpTokenIdError,
  revokeMcpToken,
  withdrawMcpConsent,
} from "./mcpClient.server.ts";

describe("mcpClient server helpers", () => {
  const secret = "test-secret-key-12345678901234567890";
  const shop = "acme-test.myshopify.com";

  it("getMcpStatus fetches /internal/v1/mcp/status", async () => {
    let capturedUrl = "";
    let capturedMethod = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      capturedMethod = init?.method ?? "GET";
      return new Response(
        JSON.stringify({
          has_consent: true,
          consent: {
            id: "consent-1",
            platform: "shopify",
            granted_by_staff_ref: "gid://shopify/Staff/1",
            policy_version: "v1.0",
            granted_at: "2026-11-24T12:00:00Z",
          },
          plan: {
            tier: "pro",
            can_use_mcp: true,
            history_days: 365,
            rate_limit_per_minute: 60,
          },
          counts: {
            active_tokens: 1,
            active_oauth_grants: 0,
          },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const status = await getMcpStatus(backend, shop);

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/status$/);
      assert.equal(capturedMethod, "GET");
      assert.equal(status.has_consent, true);
      assert.equal(status.plan.tier, "pro");
      assert.equal(status.counts.active_tokens, 1);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("getMcpConnections fetches /internal/v1/mcp/connections", async () => {
    let capturedUrl = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request) => {
      capturedUrl = String(input);
      return new Response(
        JSON.stringify({
          tokens: [
            {
              id: "tok-1",
              name: "Claude Desktop",
              token_prefix: "spa_mcp_abc",
              created_by_staff_ref: "gid://shopify/Staff/1",
              last_used_at: "2026-11-24T12:00:00Z",
              expires_at: null,
              created_at: "2026-11-24T10:00:00Z",
            },
          ],
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const connections = await getMcpConnections(backend, shop);

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/connections$/);
      assert.equal(connections.tokens.length, 1);
      assert.equal(connections.tokens[0].name, "Claude Desktop");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("createMcpToken posts to /internal/v1/mcp/tokens", async () => {
    let capturedUrl = "";
    let capturedBody = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      capturedBody = String(init?.body ?? "");
      return new Response(
        JSON.stringify({
          token: {
            id: "tok-new",
            name: "New Token",
            token_prefix: "spa_mcp_xyz",
            plaintext_token: "spa_mcp_xyz1234567890",
            created_by_staff_ref: "gid://shopify/Staff/1",
            expires_at: null,
            created_at: "2026-11-24T12:00:00Z",
          },
        }),
        { status: 201, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const created = await createMcpToken(backend, shop, {
        name: "New Token",
        staff_ref: "gid://shopify/Staff/1",
      });

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/tokens$/);
      assert.match(capturedBody, /"New Token"/);
      assert.equal(created.token.plaintext_token, "spa_mcp_xyz1234567890");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("revokeMcpToken sends delete to /internal/v1/mcp/connections/:id", async () => {
    let capturedUrl = "";
    let capturedMethod = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      capturedMethod = init?.method ?? "";
      return new Response(JSON.stringify({ status: "revoked" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const result = await revokeMcpToken(backend, shop, "0b6f3c1e-2a4d-4c8e-9f10-1234567890ab");

      assert.match(
        capturedUrl,
        /\/internal\/v1\/mcp\/connections\/0b6f3c1e-2a4d-4c8e-9f10-1234567890ab$/,
      );
      assert.equal(capturedMethod, "DELETE");
      assert.equal(result.status, "revoked");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("grantMcpConsent posts to /internal/v1/mcp/consent", async () => {
    let capturedUrl = "";
    let capturedBody = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      capturedBody = String(init?.body ?? "");
      return new Response(
        JSON.stringify({
          status: "granted",
          consent: {
            platform: "shopify",
            granted_by_staff_ref: "gid://shopify/Staff/1",
            policy_version: "v1.0",
            granted_at: "2026-11-24T12:00:00Z",
          },
        }),
        { status: 201, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const result = await grantMcpConsent(backend, shop, {
        staff_ref: "gid://shopify/Staff/1",
        policy_version: "v1.0",
      });

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/consent$/);
      assert.match(capturedBody, /"policy_version":"v1.0"/);
      assert.equal(result.status, "granted");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("withdrawMcpConsent sends delete to /internal/v1/mcp/consent", async () => {
    let capturedUrl = "";
    let capturedMethod = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      capturedUrl = String(input);
      capturedMethod = init?.method ?? "";
      return new Response(JSON.stringify({ status: "withdrawn" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const result = await withdrawMcpConsent(backend, shop);

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/consent$/);
      assert.equal(capturedMethod, "DELETE");
      assert.equal(result.status, "withdrawn");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("getMcpAuditLogs fetches /internal/v1/mcp/audit-logs with query params", async () => {
    let capturedUrl = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request) => {
      capturedUrl = String(input);
      return new Response(
        JSON.stringify({
          data: [
            {
              id: "audit-1",
              connection_type: "token",
              method: "tools/call",
              target_name: "get_sync_status",
              status: "success",
              duration_ms: 15,
              client_name: "Claude-Desktop/1.0",
              protocol_version: "2026-07-28",
              correlation_id: "corr-1",
              created_at: "2026-11-24T12:00:00Z",
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            per_page: 25,
            total: 1,
          },
        }),
        { status: 200, headers: { "Content-Type": "application/json" } },
      );
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const logs = await getMcpAuditLogs(backend, shop, { page: 1, per_page: 25 });

      assert.match(capturedUrl, /\/internal\/v1\/mcp\/audit-logs/);
      assert.match(capturedUrl, /page=1/);
      assert.match(capturedUrl, /per_page=25/);
      assert.equal(logs.data.length, 1);
      assert.equal(logs.data[0].method, "tools/call");
      assert.equal(logs.meta.total, 1);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("revokeMcpToken rejects ids that are not UUIDs without calling the backend", async () => {
    let called = false;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async () => {
      called = true;
      return new Response("{}", { status: 200 });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      for (const bad of ["../consent", "tok-123", "x?y=1", "", "0b6f3c1e-2a4d-4c8e-9f10-1234567890ab/../x"]) {
        await assert.rejects(() => revokeMcpToken(backend, shop, bad), InvalidMcpTokenIdError);
      }
      assert.equal(called, false);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});
