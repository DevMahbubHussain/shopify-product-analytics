import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { loader } from "./healthz.ts";

describe("healthz loader", () => {
  it("returns 200 OK with healthy JSON payload", async () => {
    const response = await loader();

    assert.equal(response.status, 200);
    assert.equal(response.headers.get("Content-Type"), "application/json");
    assert.equal(
      response.headers.get("Cache-Control"),
      "no-store, no-cache, must-revalidate",
    );

    const data = await response.json();
    assert.equal(data.status, "ok");
    assert.equal(data.app, "product-analytics-gateway");
    assert.ok(typeof data.timestamp === "string");
    assert.ok(!isNaN(Date.parse(data.timestamp)));
  });
});
