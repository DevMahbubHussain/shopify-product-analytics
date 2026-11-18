import type {
  LineItemPayload,
  OrderPayload,
} from "../../lib/backend/ingestionContract.server.ts";
import { sumDecimals } from "../../lib/money/decimal.server.ts";
import type {
  ShopMoney,
  ShopifyLineItemNode,
  ShopifyOrderNode,
} from "./ordersQuery.server.ts";

export type NormalizedOrder =
  { payload: OrderPayload } | { skipped: "test_order" };

export class LineItemsTruncatedError extends Error {
  public readonly orderGid: string;

  public constructor(orderGid: string) {
    super(
      `Order ${orderGid} has more line items than one query page returned.`,
    );
    this.name = "LineItemsTruncatedError";
    this.orderGid = orderGid;
  }
}

export class CurrencyMismatchError extends Error {
  public constructor(orderGid: string, expected: string, actual: string) {
    super(`Order ${orderGid} has a ${actual} amount in a ${expected} order.`);
    this.name = "CurrencyMismatchError";
  }
}

export function shopAmount(set: ShopMoney, order: ShopifyOrderNode): string {
  const { amount, currencyCode } = set.shopMoney;
  if (currencyCode !== order.currencyCode) {
    throw new CurrencyMismatchError(order.id, order.currencyCode, currencyCode);
  }
  return amount;
}

function normalizeLine(
  line: ShopifyLineItemNode,
  order: ShopifyOrderNode,
): LineItemPayload {
  return {
    gid: line.id,
    product_gid: line.product?.id ?? null,
    variant_gid: line.variant?.id ?? null,
    title: line.title,
    sku: line.sku,
    quantity: line.quantity,
    original_unit_price: shopAmount(line.originalUnitPriceSet, order),
    discount_amount: sumDecimals(
      line.discountAllocations.map((allocation) =>
        shopAmount(allocation.allocatedAmountSet, order),
      ),
    ),
  };
}

export function normalizeOrder(order: ShopifyOrderNode): NormalizedOrder {
  if (order.test) {
    return { skipped: "test_order" };
  }
  if (order.lineItems.pageInfo.hasNextPage) {
    throw new LineItemsTruncatedError(order.id);
  }

  return {
    payload: {
      gid: order.id,
      name: order.name,
      financial_status: order.displayFinancialStatus,
      fulfillment_status: order.displayFulfillmentStatus,
      currency_code: order.currencyCode,
      processed_at: order.processedAt,
      cancelled_at: order.cancelledAt,
      shopify_updated_at: order.updatedAt,
      line_items: order.lineItems.nodes.map((line) =>
        normalizeLine(line, order),
      ),
    },
  };
}
