import { test } from "node:test";
import assert from "node:assert/strict";
import { createHash, createHmac } from "node:crypto";
import { BackendClient } from "../app/services/backendClient.js";

const secret = "test-s2s-secret-key-12345";

test("BackendClient signs METHOD, PATH, TIMESTAMP, NONCE, SHOP and body hash", () => {
  const client = new BackendClient({ baseUrl: "http://backend:8000", secret });
  const body = JSON.stringify({ test: "data" });
  const nonce = "6f1c2a4e-3b5d-4e7f-8a9b-0c1d2e3f4a5b";

  const signature = client.generateSignature(
    "post",
    "internal/v1/ping",
    1715400000,
    nonce,
    "acme-store.myshopify.com",
    body
  );

  const bodyHash = createHash("sha256").update(body).digest("hex");
  const expected = createHmac("sha256", secret)
    .update(`POST\n/internal/v1/ping\n1715400000\n${nonce}\nacme-store.myshopify.com\n${bodyHash}`)
    .digest("hex");

  assert.equal(signature, expected);
});

test("BackendClient refuses to start without a shared secret", () => {
  const original = process.env["S2S_SHARED_SECRET"];
  delete process.env["S2S_SHARED_SECRET"];

  try {
    assert.throws(() => new BackendClient({ baseUrl: "http://backend:8000" }), /S2S_SHARED_SECRET/);
  } finally {
    if (original !== undefined) {
      process.env["S2S_SHARED_SECRET"] = original;
    }
  }
});
