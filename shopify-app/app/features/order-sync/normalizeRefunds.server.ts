import type { RefundLinePayload } from "../../lib/backend/ingestionContract.server.ts";
import { shopAmount } from "./normalizeOrder.server.ts";
import type {
  ShopifyOrderNode,
  ShopifyRefundNode,
} from "./ordersQuery.server.ts";

export interface NormalizedRefunds {
  lines: RefundLinePayload[];
  skippedWithoutId: number;
}

export class RefundsTruncatedError extends Error {
  public readonly orderGid: string;

  public constructor(orderGid: string) {
    super(
      `Order ${orderGid} has more refund lines than one query returned.`,
    );
    this.name = "RefundsTruncatedError";
    this.orderGid = orderGid;
  }
}

/** Refund lists are completed by completeOrderDetails; a truncated refund line page here is a harvester bug. */
function assertComplete(order: ShopifyOrderNode): void {
  if (order.refunds.some((refund) => refund.refundLineItems.pageInfo.hasNextPage)) {
    throw new RefundsTruncatedError(order.id);
  }
}

function refundLines(
  refund: ShopifyRefundNode,
  order: ShopifyOrderNode,
): RefundLinePayload[] {
  const processedAt = refund.createdAt ?? refund.updatedAt;
  const shopifyUpdatedAt = refund.updatedAt ?? refund.createdAt ?? processedAt;
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
            shopify_updated_at: shopifyUpdatedAt,
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
