/** Same rule as Laravel's DecimalAmount: DECIMAL(20,6), 14 integer and up to 6 fractional digits. */
const DECIMAL_PATTERN = /^[0-9]{1,14}(\.[0-9]{1,6})?$/;
const SCALE = 6;
const UNITS_PER_WHOLE = 10n ** BigInt(SCALE);
const MAX_UNITS = 10n ** BigInt(14 + SCALE) - 1n;

function toUnits(value: string): bigint {
  if (!DECIMAL_PATTERN.test(value)) {
    throw new Error(`Not a plain decimal amount: [${value}].`);
  }
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole + fraction.padEnd(SCALE, "0"));
}

function fromUnits(units: bigint): string {
  const whole = units / UNITS_PER_WHOLE;
  const fraction = (units % UNITS_PER_WHOLE)
    .toString()
    .padStart(SCALE, "0")
    .replace(/0+$/, "");
  return fraction === "" ? whole.toString() : `${whole}.${fraction}`;
}

/** Exact sum of money strings, without floating point. */
export function sumDecimals(values: string[]): string {
  const total = values.reduce((sum, value) => sum + toUnits(value), 0n);
  if (total > MAX_UNITS) {
    throw new Error(`Decimal sum exceeds DECIMAL(20,6): ${fromUnits(total)}.`);
  }
  return fromUnits(total);
}
