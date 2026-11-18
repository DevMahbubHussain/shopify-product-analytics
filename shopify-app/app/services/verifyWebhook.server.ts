import { createHmac, timingSafeEqual } from "node:crypto";

export interface VerifiedWebhook {
  shop: string;
  topic: string;
  webhookId: string | null;
  triggeredAt: string | null;
  rawBody: string;
}

const SHOP_DOMAIN = /^[a-z0-9][a-z0-9-]*\.myshopify\.com$/;

function signatureMatches(
  rawBody: string,
  hmacHeader: string,
  secret: string,
): boolean {
  const expected = createHmac("sha256", secret)
    .update(rawBody, "utf8")
    .digest();
  const received = Buffer.from(hmacHeader, "base64");
  return (
    received.length === expected.length && timingSafeEqual(received, expected)
  );
}

/**
 * HMAC-only verification for webhooks that need no access token
 * (app/uninstalled, privacy topics); authenticate.webhook would try to refresh an expired token.
 */
export async function verifyShopifyWebhook(
  request: Request,
  secret: string = process.env.SHOPIFY_API_SECRET ?? "",
): Promise<VerifiedWebhook | null> {
  if (secret === "") {
    throw new Error("SHOPIFY_API_SECRET environment variable is required.");
  }

  const hmac = request.headers.get("X-Shopify-Hmac-Sha256");
  const shop = request.headers.get("X-Shopify-Shop-Domain");
  const topic = request.headers.get("X-Shopify-Topic");
  if (!hmac || !shop || !topic || !SHOP_DOMAIN.test(shop)) {
    return null;
  }

  const rawBody = await request.text();
  if (!signatureMatches(rawBody, hmac, secret)) {
    return null;
  }

  return {
    shop,
    topic,
    webhookId: request.headers.get("X-Shopify-Webhook-Id"),
    triggeredAt: request.headers.get("X-Shopify-Triggered-At"),
    rawBody,
  };
}
