import type { Connection } from "../../lib/shopify/graphql/paginate.server.ts";
import type {
  OrderFinancialStatus,
  OrderFulfillmentStatus,
} from "../../lib/backend/ingestionContract.server.ts";

/** Nested page sizes; Shopify multiplies them into the requested cost (single query max 1,000). */
export const ORDER_PAGE_SIZE = 25;
export const LINE_ITEMS_PER_ORDER = 50;
export const REFUNDS_PER_ORDER = 5;
export const REFUND_LINES_PER_REFUND = 20;

/** Admin API 2026-10. `$query` takes Shopify search syntax, e.g. "updated_at:>'2026-11-01T00:00:00Z'". */
export const ORDERS_QUERY = `#graphql
  query OrderSync($first: Int!, $after: String, $query: String) {
    orders(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
      nodes {
        id
        name
        test
        displayFinancialStatus
        displayFulfillmentStatus
        currencyCode
        processedAt
        cancelledAt
        updatedAt
        lineItems(first: ${LINE_ITEMS_PER_ORDER}) {
          nodes {
            id
            title
            sku
            quantity
            product {
              id
            }
            variant {
              id
            }
            originalUnitPriceSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            discountAllocations {
              allocatedAmountSet {
                shopMoney {
                  amount
                  currencyCode
                }
              }
            }
          }
          pageInfo {
            hasNextPage
          }
        }
        refunds(first: ${REFUNDS_PER_ORDER}) {
          id
          createdAt
          updatedAt
          refundLineItems(first: ${REFUND_LINES_PER_REFUND}) {
            nodes {
              id
              quantity
              lineItem {
                id
              }
              subtotalSet {
                shopMoney {
                  amount
                  currencyCode
                }
              }
            }
            pageInfo {
              hasNextPage
            }
          }
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }`;

export interface ShopMoney {
  shopMoney: { amount: string; currencyCode: string };
}

export interface ShopifyLineItemNode {
  id: string;
  title: string;
  sku: string | null;
  quantity: number;
  product: { id: string } | null;
  variant: { id: string } | null;
  originalUnitPriceSet: ShopMoney;
  /** Includes order-level discounts spread across lines; `totalDiscountSet` omits those. */
  discountAllocations: { allocatedAmountSet: ShopMoney }[];
}

export interface ShopifyRefundLineNode {
  id: string | null;
  quantity: number;
  lineItem: { id: string };
  subtotalSet: ShopMoney;
}

export interface ShopifyRefundNode {
  id: string;
  createdAt: string | null;
  updatedAt: string;
  refundLineItems: {
    nodes: ShopifyRefundLineNode[];
    pageInfo: { hasNextPage: boolean };
  };
}

export interface ShopifyOrderNode {
  id: string;
  name: string;
  test: boolean;
  displayFinancialStatus: OrderFinancialStatus | null;
  displayFulfillmentStatus: OrderFulfillmentStatus;
  currencyCode: string;
  processedAt: string;
  cancelledAt: string | null;
  updatedAt: string;
  lineItems: {
    nodes: ShopifyLineItemNode[];
    pageInfo: { hasNextPage: boolean };
  };
  refunds: ShopifyRefundNode[];
}

export interface OrdersQueryData {
  orders: Connection<ShopifyOrderNode>;
}
