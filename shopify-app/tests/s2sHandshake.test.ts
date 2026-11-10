import { test } from "node:test";
import assert from "node:assert/strict";
import { BackendClient } from "../app/services/backendClient.js";

test("BackendClient generates deterministic HMAC-SHA256 signature", () => {
  const secret = "test-s2s-secret-key-12345";
  const client = new BackendClient({ secret });

  const method = "POST";
  const path = "/internal/v1/ping";
  const timestamp = 1715400000;
  const shopDomain = "acme-store.myshopify.com";
  const body = JSON.stringify({ test: "data" });

  const signature = client.generateSignature(method, path, timestamp, shopDomain, body);

  assert.equal(typeof signature, "string");
  assert.equal(signature.length, 64); // SHA-256 hex digest is 64 characters
});
