import { test } from "node:test";
import assert from "node:assert/strict";
import {
  parseCost,
  waitBeforeNext,
  type QueryCost,
} from "./graphqlCost.server.ts";

function cost(
  currentlyAvailable: number,
  restoreRate = 50,
  maximumAvailable = 1000,
): QueryCost {
  return {
    requestedQueryCost: 52,
    actualQueryCost: 52,
    throttleStatus: { maximumAvailable, currentlyAvailable, restoreRate },
  };
}

test("parses the cost extension Shopify returns", () => {
  const parsed = parseCost({
    cost: {
      requestedQueryCost: 101,
      actualQueryCost: 46,
      throttleStatus: {
        maximumAvailable: 1000,
        currentlyAvailable: 954,
        restoreRate: 50,
      },
    },
  });

  assert.deepEqual(parsed, {
    requestedQueryCost: 101,
    actualQueryCost: 46,
    throttleStatus: {
      maximumAvailable: 1000,
      currentlyAvailable: 954,
      restoreRate: 50,
    },
  });
});

test("accepts a null actual cost (query rejected before execution)", () => {
  const parsed = parseCost({
    cost: {
      requestedQueryCost: 1200,
      actualQueryCost: null,
      throttleStatus: {
        maximumAvailable: 1000,
        currentlyAvailable: 1000,
        restoreRate: 50,
      },
    },
  });

  assert.equal(parsed?.actualQueryCost, null);
});

test("returns null for missing or malformed cost data", () => {
  for (const extensions of [
    undefined,
    null,
    {},
    { cost: null },
    { cost: { requestedQueryCost: "52" } },
    {
      cost: {
        requestedQueryCost: 52,
        actualQueryCost: 52,
        throttleStatus: { maximumAvailable: 1000, restoreRate: 50 },
      },
    },
  ]) {
    assert.equal(parseCost(extensions), null, JSON.stringify(extensions));
  }
});

test("does not wait when enough points are available", () => {
  assert.equal(waitBeforeNext(cost(500), 52), 0);
  assert.equal(waitBeforeNext(cost(52), 52), 0);
});

test("waits for the missing points to restore", () => {
  assert.equal(waitBeforeNext(cost(2), 52), 1000);
});

test("rounds the wait up to whole milliseconds", () => {
  assert.equal(waitBeforeNext(cost(0, 3), 1), 334);
});

test("waits only until the bucket is full when the next cost exceeds its size", () => {
  assert.equal(waitBeforeNext(cost(900, 50, 1000), 1200), 2000);
});

test("does not wait without cost data", () => {
  assert.equal(waitBeforeNext(null, 52), 0);
});

test("falls back to one second when the restore rate is unusable", () => {
  assert.equal(waitBeforeNext(cost(0, 0), 52), 1000);
});
