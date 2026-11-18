import { isRecord } from "./runtime.server.ts";

export interface ThrottleStatus {
  maximumAvailable: number;
  currentlyAvailable: number;
  restoreRate: number;
}

export interface QueryCost {
  requestedQueryCost: number;
  actualQueryCost: number | null;
  throttleStatus: ThrottleStatus;
}

const FALLBACK_WAIT_MS = 1000;

function isNumber(value: unknown): value is number {
  return typeof value === "number" && Number.isFinite(value);
}

function parseThrottleStatus(value: unknown): ThrottleStatus | null {
  if (
    !isRecord(value) ||
    !isNumber(value.maximumAvailable) ||
    !isNumber(value.currentlyAvailable) ||
    !isNumber(value.restoreRate)
  ) {
    return null;
  }
  return {
    maximumAvailable: value.maximumAvailable,
    currentlyAvailable: value.currentlyAvailable,
    restoreRate: value.restoreRate,
  };
}

/** Reads `extensions.cost` from an Admin GraphQL response; null when absent or malformed. */
export function parseCost(extensions: unknown): QueryCost | null {
  if (!isRecord(extensions) || !isRecord(extensions.cost)) {
    return null;
  }
  const { requestedQueryCost, actualQueryCost, throttleStatus } =
    extensions.cost;
  const status = parseThrottleStatus(throttleStatus);
  if (
    !isNumber(requestedQueryCost) ||
    (actualQueryCost !== null && !isNumber(actualQueryCost)) ||
    status === null
  ) {
    return null;
  }
  return { requestedQueryCost, actualQueryCost, throttleStatus: status };
}

/** Milliseconds until the cost bucket holds `nextCost` points (or is full, if it never can). */
export function waitBeforeNext(
  cost: QueryCost | null,
  nextCost: number,
): number {
  if (cost === null) {
    return 0;
  }
  const { maximumAvailable, currentlyAvailable, restoreRate } =
    cost.throttleStatus;
  const missing = Math.min(nextCost, maximumAvailable) - currentlyAvailable;
  if (missing <= 0) {
    return 0;
  }
  if (restoreRate <= 0) {
    return FALLBACK_WAIT_MS;
  }
  return Math.ceil((missing / restoreRate) * 1000);
}
