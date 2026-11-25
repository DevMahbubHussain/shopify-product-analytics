import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../lib/backend/backendClient.server.ts";
import { MCP_CONSENT_POLICY_VERSION } from "../features/mcp/mcpClient.server.ts";
import {
  handleMcpAction,
  loadMcpData,
  parseAuditPage,
  resolveMcpPublicUrl,
} from "./app.mcp.server.ts";

describe("app.mcp server handlers", () => {
  const secret = "test-secret-key-12345678901234567890";
  const shop = "acme-test.myshopify.com";
  const staffSub = "42";
  const tokenUuid = "0b6f3c1e-2a4d-4c8e-9f10-1234567890ab";
  process.env.MCP_PUBLIC_URL = "https://mcp.example.test/mcp";

  it("loadMcpData fetches status, connections, and audit logs in parallel", async () => {
    const urls: string[] = [];

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request) => {
      const url = String(input);
      urls.push(url);

      if (url.includes("/internal/v1/mcp/status")) {
        return new Response(
          JSON.stringify({
            has_consent: true,
            consent: null,
            plan: {
              tier: "pro",
              can_use_mcp: true,
              history_days: 365,
              rate_limit_per_minute: 60,
            },
            counts: { active_tokens: 1, active_oauth_grants: 0 },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        );
      }

      if (url.includes("/internal/v1/mcp/connections")) {
        return new Response(
          JSON.stringify({
            tokens: [
              {
                id: "tok-1",
                name: "Claude Desktop",
                token_prefix: "spa_mcp_abc",
                created_by_staff_ref: "gid://shopify/Staff/1",
                last_used_at: null,
                expires_at: null,
                created_at: "2026-11-24T12:00:00Z",
              },
            ],
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        );
      }

      if (url.includes("/internal/v1/mcp/audit-logs")) {
        return new Response(
          JSON.stringify({
            data: [],
            meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
          }),
          { status: 200, headers: { "Content-Type": "application/json" } },
        );
      }

      return new Response("Not Found", { status: 404 });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const data = await loadMcpData(backend, shop, { page: 1 });

      assert.equal(data.status?.has_consent, true);
      assert.equal(data.status?.plan.tier, "pro");
      assert.deepEqual(data.loadErrors, []);
      assert.equal(data.mcpEndpointUrl, "https://mcp.example.test/mcp");
      assert.equal(data.tokens.length, 1);
      assert.equal(data.tokens[0].name, "Claude Desktop");
      assert.equal(data.auditLogs.meta.total, 0);

      // Verify all 3 endpoints were hit
      assert.ok(urls.some((u) => u.includes("/internal/v1/mcp/status")));
      assert.ok(urls.some((u) => u.includes("/internal/v1/mcp/connections")));
      assert.ok(urls.some((u) => u.includes("/internal/v1/mcp/audit-logs")));
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("handleMcpAction processes create_token intent", async () => {
    let capturedBody = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (_input: string | URL | Request, init?: RequestInit) => {
      capturedBody = String(init?.body ?? "");
      return new Response(
        JSON.stringify({
          token: {
            id: "tok-created",
            name: "My AI Client",
            token_prefix: "spa_mcp_xyz",
            plaintext_token: "spa_mcp_xyz1234567890abcdef",
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
      const formData = new FormData();
      formData.set("intent", "create_token");
      formData.set("name", "My AI Client");
      formData.set("staff_ref", "gid://shopify/Staff/1");

      const result = await handleMcpAction(backend, shop, staffSub, formData);

      assert.equal(result.success, true);
      assert.ok(result.generatedToken);
      assert.equal(result.generatedToken?.plaintext_token, "spa_mcp_xyz1234567890abcdef");
      assert.match(capturedBody, /"name":"My AI Client"/);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("handleMcpAction rejects create_token with empty name", async () => {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const formData = new FormData();
    formData.set("intent", "create_token");
    formData.set("name", "");

    const result = await handleMcpAction(backend, shop, staffSub, formData);

    assert.equal(result.success, false);
    assert.match(result.error ?? "", /Token name is required/);
  });

  it("handleMcpAction processes revoke_token intent", async () => {
    let capturedUrl = "";

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request) => {
      capturedUrl = String(input);
      return new Response(JSON.stringify({ status: "revoked" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const formData = new FormData();
      formData.set("intent", "revoke_token");
      formData.set("tokenId", tokenUuid);

      const result = await handleMcpAction(backend, shop, staffSub, formData);

      assert.equal(result.success, true);
      assert.equal(result.message, "Token revoked successfully.");
      assert.match(capturedUrl, new RegExp(`/connections/${tokenUuid}$`));
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("handleMcpAction processes grant_consent and withdraw_consent", async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (_input: string | URL | Request, init?: RequestInit) => {
      const method = init?.method ?? "GET";
      if (method === "POST") {
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
      }
      return new Response(JSON.stringify({ status: "withdrawn" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      });
    }) as typeof globalThis.fetch;

    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });

      // Test grant
      const grantForm = new FormData();
      grantForm.set("intent", "grant_consent");
      grantForm.set("staff_ref", "gid://shopify/Staff/1");

      const grantResult = await handleMcpAction(backend, shop, staffSub, grantForm);
      assert.equal(grantResult.success, true);
      assert.equal(grantResult.message, "Data-sharing authorization granted.");

      // Test withdraw
      const withdrawForm = new FormData();
      withdrawForm.set("intent", "withdraw_consent");

      const withdrawResult = await handleMcpAction(backend, shop, staffSub, withdrawForm);
      assert.equal(withdrawResult.success, true);
      assert.equal(withdrawResult.message, "Authorization withdrawn and active connections revoked.");
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it("handleMcpAction returns error for unknown intent", async () => {
    const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
    const formData = new FormData();
    formData.set("intent", "unsupported_action");

    const result = await handleMcpAction(backend, shop, staffSub, formData);

    assert.equal(result.success, false);
    assert.match(result.error ?? "", /Unknown action/);
  });

  function stubFetch(
    handler: (url: string, init?: RequestInit) => Response,
  ): { restore: () => void; bodies: string[] } {
    const bodies: string[] = [];
    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: string | URL | Request, init?: RequestInit) => {
      bodies.push(String(init?.body ?? ""));
      return handler(String(input), init);
    }) as typeof globalThis.fetch;
    return { restore: () => (globalThis.fetch = originalFetch), bodies };
  }

  const createdToken = {
    token: {
      id: tokenUuid,
      name: "Client",
      token_prefix: "spa_mcp_xyz",
      plaintext_token: "spa_mcp_secret",
      created_by_staff_ref: staffSub,
      last_used_at: null,
      expires_at: null,
      created_at: "2026-11-24T12:00:00Z",
    },
  };

  it("create_token sends the session staff id and ignores a form-supplied staff_ref", async () => {
    const stub = stubFetch(() => Response.json(createdToken, { status: 201 }));
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "create_token");
      form.set("name", "Client");
      form.set("staff_ref", "gid://shopify/Staff/999");

      const result = await handleMcpAction(backend, shop, staffSub, form);

      assert.equal(result.success, true);
      const sent = JSON.parse(stub.bodies[0]) as { staff_ref: string };
      assert.equal(sent.staff_ref, staffSub);
    } finally {
      stub.restore();
    }
  });

  it("grant_consent uses the session staff id and the server policy version", async () => {
    const stub = stubFetch(() =>
      Response.json({ status: "granted", consent: {} }, { status: 201 }),
    );
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "grant_consent");
      form.set("staff_ref", "gid://shopify/Staff/999");
      form.set("policy_version", "v0-forged");

      await handleMcpAction(backend, shop, staffSub, form);

      const sent = JSON.parse(stub.bodies[0]) as { staff_ref: string; policy_version: string };
      assert.equal(sent.staff_ref, staffSub);
      assert.equal(sent.policy_version, MCP_CONSENT_POLICY_VERSION);
    } finally {
      stub.restore();
    }
  });

  it("refuses identity-bearing actions when the session has no staff id", async () => {
    const stub = stubFetch(() => Response.json(createdToken, { status: 201 }));
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      for (const intent of ["create_token", "grant_consent"]) {
        const form = new FormData();
        form.set("intent", intent);
        form.set("name", "Client");
        const result = await handleMcpAction(backend, shop, undefined, form);
        assert.equal(result.success, false);
        assert.match(result.error ?? "", /staff member/);
      }
      assert.equal(stub.bodies.length, 0);
    } finally {
      stub.restore();
    }
  });

  it("rejects a revoke with a token id that is not a UUID", async () => {
    const stub = stubFetch(() => Response.json({ status: "revoked" }));
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "revoke_token");
      form.set("tokenId", "../consent");

      const result = await handleMcpAction(backend, shop, staffSub, form);

      assert.equal(result.success, false);
      assert.equal(result.error, "Invalid token id.");
      assert.equal(stub.bodies.length, 0);
    } finally {
      stub.restore();
    }
  });

  it("hides backend 5xx bodies behind a generic message", async () => {
    const stub = stubFetch(
      () => new Response("<html>Stack trace: /var/www/app.php</html>", { status: 500 }),
    );
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "withdraw_consent");

      const result = await handleMcpAction(backend, shop, staffSub, form);

      assert.equal(result.success, false);
      assert.doesNotMatch(result.error ?? "", /Stack trace|var\/www|html/);
      assert.match(result.error ?? "", /unavailable/);
    } finally {
      stub.restore();
    }
  });

  it("shows the backend error_description for 4xx JSON responses", async () => {
    const stub = stubFetch(() =>
      Response.json(
        { error: "consent_required", error_description: "Cannot create tokens without consent." },
        { status: 403 },
      ),
    );
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "create_token");
      form.set("name", "Client");

      const result = await handleMcpAction(backend, shop, staffSub, form);

      assert.equal(result.error, "Cannot create tokens without consent.");
    } finally {
      stub.restore();
    }
  });

  it("does not echo raw non-JSON 4xx bodies", async () => {
    const stub = stubFetch(() => new Response("<html>nginx</html>", { status: 413 }));
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const form = new FormData();
      form.set("intent", "withdraw_consent");

      const result = await handleMcpAction(backend, shop, staffSub, form);

      assert.doesNotMatch(result.error ?? "", /nginx/);
    } finally {
      stub.restore();
    }
  });

  it("reports a failed status load instead of inventing a Free plan", async () => {
    const stub = stubFetch((url) => {
      if (url.includes("/mcp/status")) return new Response("down", { status: 503 });
      if (url.includes("/mcp/connections")) return Response.json({ tokens: [] });
      return Response.json({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
      });
    });
    try {
      const backend = new BackendClient({ baseUrl: "http://backend:8000", secret });
      const data = await loadMcpData(backend, shop, { page: 1 });

      assert.equal(data.status, null);
      assert.deepEqual(data.loadErrors, ["status"]);
    } finally {
      stub.restore();
    }
  });

  it("parseAuditPage falls back to 1 for missing, invalid or non-positive values", () => {
    assert.equal(parseAuditPage(null), 1);
    assert.equal(parseAuditPage("abc"), 1);
    assert.equal(parseAuditPage("0"), 1);
    assert.equal(parseAuditPage("-4"), 1);
    assert.equal(parseAuditPage("2.5"), 1);
    assert.equal(parseAuditPage("1e3"), 1);
    assert.equal(parseAuditPage("99999999999999999999"), 1);
    assert.equal(parseAuditPage("7"), 7);
  });

  it("resolveMcpPublicUrl requires a configured https URL", () => {
    const original = process.env.MCP_PUBLIC_URL;
    try {
      delete process.env.MCP_PUBLIC_URL;
      assert.throws(() => resolveMcpPublicUrl(), /MCP_PUBLIC_URL/);

      process.env.MCP_PUBLIC_URL = "not a url";
      assert.throws(() => resolveMcpPublicUrl(), /MCP_PUBLIC_URL/);

      process.env.MCP_PUBLIC_URL = "http://backend:8000/mcp";
      assert.throws(() => resolveMcpPublicUrl(), /https/);

      process.env.MCP_PUBLIC_URL = "http://localhost:8000/mcp";
      assert.equal(resolveMcpPublicUrl(), "http://localhost:8000/mcp");

      process.env.MCP_PUBLIC_URL = "https://mcp.example.test/mcp/";
      assert.equal(resolveMcpPublicUrl(), "https://mcp.example.test/mcp");
    } finally {
      process.env.MCP_PUBLIC_URL = original;
    }
  });
});
