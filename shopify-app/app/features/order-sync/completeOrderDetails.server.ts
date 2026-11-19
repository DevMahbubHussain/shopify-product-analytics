import {
  shopifyQuery,
  type GraphqlAdmin,
} from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import {
  paginate,
  type Connection,
  type PaginateOptions,
} from "../../lib/shopify/graphql/paginate.server.ts";
import {
  LINE_ITEM_FIELDS,
  REFUND_FIELDS,
  REFUND_LINE_FIELDS,
  REFUNDS_PER_ORDER,
  type ShopifyLineItemNode,
  type ShopifyOrderNode,
  type ShopifyRefundLineNode,
  type ShopifyRefundNode,
} from "./ordersQuery.server.ts";

const MAX_PAGE_SIZE = 250;

/** Admin API 2026-10. Root `order` query, `Order.lineItems` connection. */
export const ORDER_LINE_ITEMS_QUERY = `#graphql
  query OrderLineItemsPage($id: ID!, $first: Int!, $after: String) {
    order(id: $id) {
      lineItems(first: $first, after: $after) {
        nodes {
          ${LINE_ITEM_FIELDS}
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
  }`;

/** Admin API 2026-10. `Order.refunds` is a plain list with no cursor; omitting `first` returns every refund. */
export const ORDER_REFUNDS_QUERY = `#graphql
  query OrderRefunds($id: ID!) {
    order(id: $id) {
      refunds {
        ${REFUND_FIELDS}
      }
    }
  }`;

/** Admin API 2026-10. Root `refund` query, `Refund.refundLineItems` connection. */
export const REFUND_LINE_ITEMS_QUERY = `#graphql
  query RefundLineItemsPage($id: ID!, $first: Int!, $after: String) {
    refund(id: $id) {
      refundLineItems(first: $first, after: $after) {
        nodes {
          ${REFUND_LINE_FIELDS}
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
  }`;

interface OrderLineItemsPageData {
  order: { lineItems: Connection<ShopifyLineItemNode> } | null;
}

interface OrderRefundsData {
  order: { refunds: ShopifyRefundNode[] } | null;
}

interface RefundLineItemsPageData {
  refund: { refundLineItems: Connection<ShopifyRefundLineNode> } | null;
}

type CompletionOptions = Omit<PaginateOptions, "pageSize">;

export class OrderDetailsVanishedError extends Error {
  public readonly resourceGid: string;

  public constructor(resourceGid: string) {
    super(`${resourceGid} was not found while fetching the rest of its order details.`);
    this.name = "OrderDetailsVanishedError";
    this.resourceGid = resourceGid;
  }
}

/** Fills in line items, refunds and refund lines that the orders page truncated; complete orders pass through untouched. */
export async function completeOrderDetails(
  admin: GraphqlAdmin,
  order: ShopifyOrderNode,
  options: CompletionOptions = {},
): Promise<ShopifyOrderNode> {
  if (order.test || isComplete(order)) {
    return order;
  }

  const lineItems = order.lineItems.pageInfo.hasNextPage
    ? { nodes: await fetchAllLineItems(admin, order.id, options), pageInfo: { hasNextPage: false } }
    : order.lineItems;

  const refunds = mayHaveMoreRefunds(order)
    ? await fetchAllRefunds(admin, order.id, options)
    : order.refunds;

  const completedRefunds: ShopifyRefundNode[] = [];
  for (const refund of refunds) {
    completedRefunds.push(await completeRefundLines(admin, refund, options));
  }

  return { ...order, lineItems, refunds: completedRefunds };
}

/** `Order.refunds` has no pageInfo, so a full page may hide more refunds. */
function mayHaveMoreRefunds(order: ShopifyOrderNode): boolean {
  return order.refunds.length >= REFUNDS_PER_ORDER;
}

function isComplete(order: ShopifyOrderNode): boolean {
  return (
    !order.lineItems.pageInfo.hasNextPage &&
    !mayHaveMoreRefunds(order) &&
    order.refunds.every((refund) => !refund.refundLineItems.pageInfo.hasNextPage)
  );
}

async function fetchAllLineItems(
  admin: GraphqlAdmin,
  orderGid: string,
  options: CompletionOptions,
): Promise<ShopifyLineItemNode[]> {
  const lines: ShopifyLineItemNode[] = [];
  const pages = paginate<OrderLineItemsPageData, ShopifyLineItemNode>(
    admin,
    ORDER_LINE_ITEMS_QUERY,
    { id: orderGid },
    (data) => {
      if (data.order === null) {
        throw new OrderDetailsVanishedError(orderGid);
      }
      return data.order.lineItems;
    },
    { ...options, pageSize: MAX_PAGE_SIZE },
  );
  for await (const page of pages) {
    lines.push(...page);
  }
  return lines;
}

async function fetchAllRefunds(
  admin: GraphqlAdmin,
  orderGid: string,
  options: CompletionOptions,
): Promise<ShopifyRefundNode[]> {
  const { data } = await shopifyQuery<OrderRefundsData>(
    admin,
    ORDER_REFUNDS_QUERY,
    { id: orderGid },
    options,
  );
  if (data.order === null) {
    throw new OrderDetailsVanishedError(orderGid);
  }
  return data.order.refunds;
}

async function completeRefundLines(
  admin: GraphqlAdmin,
  refund: ShopifyRefundNode,
  options: CompletionOptions,
): Promise<ShopifyRefundNode> {
  if (!refund.refundLineItems.pageInfo.hasNextPage) {
    return refund;
  }

  const lines: ShopifyRefundLineNode[] = [];
  const pages = paginate<RefundLineItemsPageData, ShopifyRefundLineNode>(
    admin,
    REFUND_LINE_ITEMS_QUERY,
    { id: refund.id },
    (data) => {
      if (data.refund === null) {
        throw new OrderDetailsVanishedError(refund.id);
      }
      return data.refund.refundLineItems;
    },
    { ...options, pageSize: MAX_PAGE_SIZE },
  );
  for await (const page of pages) {
    lines.push(...page);
  }
  return { ...refund, refundLineItems: { nodes: lines, pageInfo: { hasNextPage: false } } };
}
