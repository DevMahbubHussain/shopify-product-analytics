import type { RefundLinePayload } from "../../lib/backend/ingestionContract.server.ts";
import { shopAmount } from "./normalizeOrder.server.ts";
import {
  REFUNDS_PER_ORDER,
  type ShopifyOrderNode,
  type ShopifyRefundNode,
} from "./ordersQuery.server.ts";

export interface NormalizedRefunds {
  lines: RefundLinePayload[];
  skippedWithoutId: number;
}

export class RefundsTruncatedError extends Error {
  public readonly orderGid: string;

  public constructor(orderGid: string) {
    super(
      `Order ${orderGid} may have more refunds or refund lines than one query returned.`,
    );
    this.name = "RefundsTruncatedError";
    this.orderGid = orderGid;
  }
}

/** Order.refunds has no pageInfo, so a full page may hide more refunds. */
function assertComplete(order: ShopifyOrderNode): void {
  const truncated =
    order.refunds.length >= REFUNDS_PER_ORDER ||
    order.refunds.some((refund) => refund.refundLineItems.pageInfo.hasNextPage);
  if (truncated) {
    throw new RefundsTruncatedError(order.id);
  }
}

function refundLines(
  refund: ShopifyRefundNode,
  order: ShopifyOrderNode,
): RefundLinePayload[] {
  const processedAt = refund.createdAt ?? refund.updatedAt;
  return refund.refundLineItems.nodes.flatMap((line) =>
    line.id === null
      ? []
      : [
          {
            refund_gid: refund.id,
            refund_line_item_gid: line.id,
            order_gid: order.id,
            line_item_gid: line.lineItem.id,
            quantity: line.quantity,
            subtotal: shopAmount(line.subtotalSet, order),
            currency_code: order.currencyCode,
            processed_at: processedAt,
          },
        ],
  );
}

export function normalizeRefunds(order: ShopifyOrderNode): NormalizedRefunds {
  if (order.test || order.refunds.length === 0) {
    return { lines: [], skippedWithoutId: 0 };
  }
  assertComplete(order);

  const all = order.refunds.flatMap((refund) => refund.refundLineItems.nodes);
  return {
    lines: order.refunds.flatMap((refund) => refundLines(refund, order)),
    skippedWithoutId: all.filter((line) => line.id === null).length,
  };
}
