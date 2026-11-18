import { test } from "node:test";
import assert from "node:assert/strict";
import { sumDecimals } from "./decimal.server.ts";

test("adds decimal strings exactly", () => {
  assert.equal(sumDecimals(["0.1", "4.9"]), "5");
  assert.equal(sumDecimals(["0.1", "0.2"]), "0.3");
  assert.equal(sumDecimals(["24.95", "49.95", "9.95"]), "84.85");
});

test("returns zero for an empty list", () => {
  assert.equal(sumDecimals([]), "0");
});

test("keeps up to six fractional digits and trims trailing zeros", () => {
  assert.equal(sumDecimals(["1.000001", "2.000002"]), "3.000003");
  assert.equal(sumDecimals(["5.0"]), "5");
  assert.equal(sumDecimals(["2.50", "0.25"]), "2.75");
});

test("handles the largest Laravel amount without float error", () => {
  assert.equal(
    sumDecimals(["99999999999998.999999", "0.000001"]),
    "99999999999999",
  );
});

test("rejects a sum larger than Laravel's DECIMAL(20,6)", () => {
  assert.throws(
    () => sumDecimals(["99999999999999.999999", "0.000001"]),
    /exceeds/,
  );
});

test("rejects values that are not plain non-negative decimals", () => {
  for (const value of ["", "abc", "-1", "1e3", "1.1234567", "1,5", " 1"]) {
    assert.throws(() => sumDecimals([value]), /decimal/, value);
  }
});
