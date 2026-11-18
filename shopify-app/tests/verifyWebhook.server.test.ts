import { test } from "node:test";
import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { verifyShopifyWebhook } from "../app/services/verifyWebhook.server.ts";

const secret = "test-app-secret";
const shop = "acme-store.myshopify.com";
const body = JSON.stringify({ id: 1, myshopify_domain: shop });

function sign(rawBody: string, key: string = secret): string {
  return createHmac("sha256", key).update(rawBody, "utf8").digest("base64");
}

function webhookRequest(
  rawBody: string,
  headers: Record<string, string | undefined>,
): Request {
  const defined = Object.entries(headers).filter(
    (entry): entry is [string, string] => entry[1] !== undefined,
  );
  return new Request("http://localhost/webhooks/app/uninstalled", {
    method: "POST",
    body: rawBody,
    headers: new Headers([["Content-Type", "application/json"], ...defined]),
  });
}

function validHeaders(): Record<string, string> {
  return {
    "X-Shopify-Hmac-Sha256": sign(body),
    "X-Shopify-Shop-Domain": shop,
    "X-Shopify-Topic": "app/uninstalled",
    "X-Shopify-Webhook-Id": "b54557e4-0000-4000-8000-000000000001",
    "X-Shopify-Triggered-At": "2026-11-18T09:51:31.386207777Z",
  };
}

test("returns the verified shop, topic and trigger time for a valid signature", async () => {
  const result = await verifyShopifyWebhook(
    webhookRequest(body, validHeaders()),
    secret,
  );

  assert.deepEqual(result, {
    shop,
    topic: "app/uninstalled",
    webhookId: "b54557e4-0000-4000-8000-000000000001",
    triggeredAt: "2026-11-18T09:51:31.386207777Z",
    rawBody: body,
  });
});

test("returns a null trigger time when the header is absent", async () => {
  const headers = { ...validHeaders(), "X-Shopify-Triggered-At": undefined };

  const result = await verifyShopifyWebhook(
    webhookRequest(body, headers),
    secret,
  );

  assert.equal(result?.triggeredAt, null);
});

test("rejects a signature made with another secret", async () => {
  const headers = {
    ...validHeaders(),
    "X-Shopify-Hmac-Sha256": sign(body, "wrong"),
  };

  assert.equal(
    await verifyShopifyWebhook(webhookRequest(body, headers), secret),
    null,
  );
});

test("rejects a body that was changed after signing", async () => {
  assert.equal(
    await verifyShopifyWebhook(
      webhookRequest(body.replace("1", "2"), validHeaders()),
      secret,
    ),
    null,
  );
});

test("rejects a malformed signature without throwing", async () => {
  for (const hmac of ["", "AAAA", "not base64 !!"]) {
    const headers = { ...validHeaders(), "X-Shopify-Hmac-Sha256": hmac };
    assert.equal(
      await verifyShopifyWebhook(webhookRequest(body, headers), secret),
      null,
    );
  }
});

test("rejects deliveries missing required headers", async () => {
  for (const missing of [
    "X-Shopify-Hmac-Sha256",
    "X-Shopify-Shop-Domain",
    "X-Shopify-Topic",
  ]) {
    const headers = { ...validHeaders(), [missing]: undefined };
    assert.equal(
      await verifyShopifyWebhook(webhookRequest(body, headers), secret),
      null,
      `expected rejection without ${missing}`,
    );
  }
});

test("rejects a shop domain outside myshopify.com", async () => {
  const headers = {
    ...validHeaders(),
    "X-Shopify-Shop-Domain": "evil.example.com",
  };

  assert.equal(
    await verifyShopifyWebhook(webhookRequest(body, headers), secret),
    null,
  );
});

test("refuses to verify without an app secret", async () => {
  await assert.rejects(
    verifyShopifyWebhook(webhookRequest(body, validHeaders()), ""),
    /SHOPIFY_API_SECRET/,
  );
});
