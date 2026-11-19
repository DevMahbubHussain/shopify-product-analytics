import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import { paginate } from "../../lib/shopify/graphql/paginate.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import type {
  BatchResponse,
  OrderPayload,
  RefundLinePayload,
} from "../../lib/backend/ingestionContract.server.ts";
import { INGESTION_LIMITS } from "../../lib/backend/ingestionContract.server.ts";
import {
  ORDERS_QUERY,
  ORDER_PAGE_SIZE,
  type OrdersQueryData,
  type ShopifyOrderNode,
} from "./ordersQuery.server.ts";
import { normalizeOrder } from "./normalizeOrder.server.ts";
import { normalizeRefunds } from "./normalizeRefunds.server.ts";
import { completeOrderDetails } from "./completeOrderDetails.server.ts";

export interface HarvestProgressCounts {
  received: number;
  written: number;
  skippedStale: number;
}

export interface HarvestOrdersProgress {
  orders: HarvestProgressCounts;
  refunds: HarvestProgressCounts;
}

export interface HarvestOrdersOptions {
  admin: GraphqlAdmin;
  backend: BackendClient;
  shopDomain: string;
  query?: string;
  pageSize?: number;
  onProgress?: (progress: HarvestOrdersProgress) => void;
}

export interface HarvestOrdersSummary {
  orders: HarvestProgressCounts;
  refunds: HarvestProgressCounts;
}

/** Computes the default 60-day window start timestamp. */
export function defaultSixtyDayWindowStart(now = new Date()): string {
  return new Date(now.getTime() - 60 * 24 * 60 * 60 * 1000).toISOString();
}

/** Computes the default 60-day Shopify search filter for orders. */
export function defaultSixtyDayOrderQuery(now = new Date()): string {
  return `updated_at:>='${defaultSixtyDayWindowStart(now)}'`;
}

/**
 * Harvests orders and refunds for a shop via GraphQL cursor pagination.
 * Posts orders to /internal/v1/ingestion/orders first, then posts associated
 * refund lines to /internal/v1/ingestion/refunds, ensuring dependency order.
 */
export async function harvestOrders({
  admin,
  backend,
  shopDomain,
  query = defaultSixtyDayOrderQuery(),
  pageSize = ORDER_PAGE_SIZE,
  onProgress,
}: HarvestOrdersOptions): Promise<HarvestOrdersSummary> {
  const ordersSummary: HarvestProgressCounts = {
    received: 0,
    written: 0,
    skippedStale: 0,
  };
  const refundsSummary: HarvestProgressCounts = {
    received: 0,
    written: 0,
    skippedStale: 0,
  };

  const pager = paginate<OrdersQueryData, ShopifyOrderNode>(
    admin,
    ORDERS_QUERY,
    query ? { query } : {},
    (data) => data.orders,
    { pageSize },
  );

  for await (const page of pager) {
    if (page.length === 0) {
      continue;
    }

    const eligibleOrders: OrderPayload[] = [];
    const pendingRefundLines: RefundLinePayload[] = [];

    for (const pageNode of page) {
      const node = await completeOrderDetails(admin, pageNode);
      const normalizedOrder = normalizeOrder(node);
      if ("skipped" in normalizedOrder) {
        continue;
      }
      eligibleOrders.push(normalizedOrder.payload);

      const normalizedRefunds = normalizeRefunds(node);
      if (normalizedRefunds.lines.length > 0) {
        pendingRefundLines.push(...normalizedRefunds.lines);
      }
    }

    // 1. Post orders in chunks respecting ordersPerBatch (50) AND lineItemsPerOrderBatch (250)
    if (eligibleOrders.length > 0) {
      const orderChunks = chunkOrders(eligibleOrders);
      for (const chunk of orderChunks) {
        if (!chunk.continuation) {
          ordersSummary.received += chunk.orders.length;
          onProgress?.({ orders: ordersSummary, refunds: refundsSummary });
        }

        const res = await backend.postInternal<BatchResponse>(
          "/internal/v1/ingestion/orders",
          shopDomain,
          { orders: chunk.orders },
        );

        if (chunk.continuation) {
          continue;
        }

        ordersSummary.written += res.written;
        ordersSummary.skippedStale += res.skipped_stale;

        onProgress?.({ orders: ordersSummary, refunds: refundsSummary });
      }
    }

    // 2. Post refunds associated with the ingested orders
    if (pendingRefundLines.length > 0) {
      for (
        let i = 0;
        i < pendingRefundLines.length;
        i += INGESTION_LIMITS.refundLinesPerBatch
      ) {
        const chunk = pendingRefundLines.slice(
          i,
          i + INGESTION_LIMITS.refundLinesPerBatch,
        );
        refundsSummary.received += chunk.length;
        onProgress?.({ orders: ordersSummary, refunds: refundsSummary });

        const res = await backend.postInternal<BatchResponse>(
          "/internal/v1/ingestion/refunds",
          shopDomain,
          { refund_lines: chunk },
        );

        refundsSummary.written += res.written;
        refundsSummary.skippedStale += res.skipped_stale;

        onProgress?.({ orders: ordersSummary, refunds: refundsSummary });
      }
    }
  }

  return { orders: ordersSummary, refunds: refundsSummary };
}

interface OrderChunk {
  orders: OrderPayload[];
  /** Second or later slice of an oversized order: already counted with its first slice. */
  continuation: boolean;
}

/**
 * Splits orders into chunks that never exceed 50 orders OR 250 total line items.
 * An order above the line cap is sent as consecutive single-order slices with the same header;
 * Laravel upserts line items without deleting absent ones, so the slices accumulate.
 */
function chunkOrders(orders: OrderPayload[]): OrderChunk[] {
  const chunks: OrderChunk[] = [];
  let currentChunk: OrderPayload[] = [];
  let currentLineCount = 0;

  const flush = (): void => {
    if (currentChunk.length > 0) {
      chunks.push({ orders: currentChunk, continuation: false });
      currentChunk = [];
      currentLineCount = 0;
    }
  };

  for (const order of orders) {
    const orderLines = order.line_items.length;

    if (orderLines > INGESTION_LIMITS.lineItemsPerOrderBatch) {
      flush();
      sliceOrder(order).forEach((slice, index) => {
        chunks.push({ orders: [slice], continuation: index > 0 });
      });
      continue;
    }

    const wouldExceedOrders =
      currentChunk.length >= INGESTION_LIMITS.ordersPerBatch;
    const wouldExceedLines =
      currentLineCount + orderLines > INGESTION_LIMITS.lineItemsPerOrderBatch;

    if (wouldExceedOrders || wouldExceedLines) {
      flush();
    }

    currentChunk.push(order);
    currentLineCount += orderLines;
  }

  flush();

  return chunks;
}

function sliceOrder(order: OrderPayload): OrderPayload[] {
  const slices: OrderPayload[] = [];
  for (
    let i = 0;
    i < order.line_items.length;
    i += INGESTION_LIMITS.lineItemsPerOrderBatch
  ) {
    slices.push({
      ...order,
      line_items: order.line_items.slice(i, i + INGESTION_LIMITS.lineItemsPerOrderBatch),
    });
  }
  return slices;
}
