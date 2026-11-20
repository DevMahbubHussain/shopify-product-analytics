import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { handleShopRedact } from "./shopRedact.server.ts";

describe("handleShopRedact", () => {
  it("purges backend and deletes sessions on valid webhook returning 200", async () => {
    let backendPurged = false;
    let sessionsDeleted = false;

    const status = await handleShopRedact(
      { shop: "redact-test.myshopify.com" },
      {
        purgeBackend: async (shop) => {
          assert.equal(shop, "redact-test.myshopify.com");
          backendPurged = true;
          return { status: "purged", shop_domain: shop };
        },
        deleteSessions: async (shop) => {
          assert.equal(shop, "redact-test.myshopify.com");
          sessionsDeleted = true;
        },
      },
    );

    assert.equal(status, 200);
    assert.equal(backendPurged, true);
    assert.equal(sessionsDeleted, true);
  });

  it("returns 500 when backend purge fails so Shopify will retry", async () => {
    let sessionsDeleted = false;

    const status = await handleShopRedact(
      { shop: "redact-test.myshopify.com" },
      {
        purgeBackend: async () => {
          throw new Error("Backend connection error");
        },
        deleteSessions: async () => {
          sessionsDeleted = true;
        },
      },
    );

    assert.equal(status, 500);
    assert.equal(sessionsDeleted, false);
  });
});
