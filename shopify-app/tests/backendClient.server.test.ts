import { afterEach, mock, test } from "node:test";
import assert from "node:assert/strict";
import { createHash, createHmac } from "node:crypto";
import {
  BackendClient,
  BackendError,
} from "../app/services/backendClient.server.ts";

const secret = "test-s2s-secret";
const shop = "acme-store.myshopify.com";
const uuidPattern =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

function expectedSignature(
  path: string,
  timestamp: string,
  nonce: string,
  body: string,
): string {
  const bodyHash = createHash("sha256").update(body).digest("hex");
  return createHmac("sha256", secret)
    .update(`POST\n${path}\n${timestamp}\n${nonce}\n${shop}\n${bodyHash}`)
    .digest("hex");
}

function stubFetch(response: Response): {
  calls: () => { url: string; init: RequestInit }[];
} {
  const fetchMock = mock.method(
    globalThis,
    "fetch",
    async (): Promise<Response> => response,
  );
  return {
    calls: () =>
      fetchMock.mock.calls.map((call) => ({
        url: String(call.arguments[0]),
        init: call.arguments[1] ?? {},
      })),
  };
}

afterEach(() => {
  mock.restoreAll();
});

test("signs METHOD, PATH, TIMESTAMP, NONCE, SHOP and body hash", () => {
  const client = new BackendClient({ baseUrl: "http://backend:8000", secret });
  const body = JSON.stringify({ test: "data" });
  const nonce = "6f1c2a4e-3b5d-4e7f-8a9b-0c1d2e3f4a5b";

  const signature = client.generateSignature(
    "post",
    "internal/v1/ingestion/shop",
    1715400000,
    nonce,
    shop,
    body,
  );

  assert.equal(
    signature,
    expectedSignature("/internal/v1/ingestion/shop", "1715400000", nonce, body),
  );
});

test("refuses to start without a shared secret", () => {
  const original = process.env.S2S_SHARED_SECRET;
  delete process.env.S2S_SHARED_SECRET;

  try {
    assert.throws(
      () => new BackendClient({ baseUrl: "http://backend:8000" }),
      /S2S_SHARED_SECRET/,
    );
  } finally {
    if (original !== undefined) {
      process.env.S2S_SHARED_SECRET = original;
    }
  }
});

test("postInternal sends a signed request with all contract headers", async () => {
  const fetchStub = stubFetch(Response.json({ status: "active" }));
  const client = new BackendClient({ baseUrl: "http://backend:8000/", secret });
  const payload = { currency_code: "USD" };

  const result = await client.postInternal<{ status: string }>(
    "/internal/v1/ingestion/shop",
    shop,
    payload,
    "corr-123",
  );

  assert.deepEqual(result, { status: "active" });
  const [call] = fetchStub.calls();
  assert.ok(call);
  assert.equal(call.url, "http://backend:8000/internal/v1/ingestion/shop");
  assert.equal(call.init.method, "POST");
  assert.equal(call.init.body, JSON.stringify(payload));

  const headers = new Headers(call.init.headers);
  const timestamp = headers.get("X-App-Timestamp") ?? "";
  const nonce = headers.get("X-App-Nonce") ?? "";
  assert.equal(headers.get("X-App-Shop-Domain"), shop);
  assert.equal(headers.get("X-Correlation-ID"), "corr-123");
  assert.match(nonce, uuidPattern);
  assert.ok(Math.abs(Number(timestamp) - Date.now() / 1000) <= 5);
  assert.equal(
    headers.get("X-App-Signature"),
    expectedSignature(
      "/internal/v1/ingestion/shop",
      timestamp,
      nonce,
      JSON.stringify(payload),
    ),
  );
});

test("postInternal throws with the status code on a failed response", async () => {
  stubFetch(Response.json({ error: "Unknown shop." }, { status: 404 }));
  const client = new BackendClient({ baseUrl: "http://backend:8000", secret });

  await assert.rejects(
    client.postInternal("/internal/v1/ingestion/products", shop, {}),
    (error: unknown) => {
      assert.ok(error instanceof BackendError);
      assert.equal(error.status, 404);
      assert.match(error.message, /\(404\)/);
      return true;
    },
  );
});
