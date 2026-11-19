/**
 * Payloads accepted by Laravel's /internal/v1/ingestion/* endpoints.
 * Mirrors backend/app/Http/Requests/Internal/*Request.php and docs/ingestion-api.md.
 * Money is a decimal string (up to 14 integer and 6 fractional digits); times are ISO 8601 with Z or an offset.
 */

export const INGESTION_LIMITS = {
  productsPerBatch: 100,
  ordersPerBatch: 50,
  lineItemsPerOrderBatch: 250,
  refundLinesPerBatch: 250,
  productGidsPerDelete: 250,
  errorSummaryLength: 2000,
} as const;

export type ProductStatus = "ACTIVE" | "ARCHIVED" | "DRAFT" | "UNLISTED";

export type OrderFinancialStatus =
  | "AUTHORIZED"
  | "EXPIRED"
  | "PAID"
  | "PARTIALLY_PAID"
  | "PARTIALLY_REFUNDED"
  | "PENDING"
  | "REFUNDED"
  | "VOIDED";

export type OrderFulfillmentStatus =
  | "FULFILLED"
  | "FULFILLMENT_NOT_REQUIRED"
  | "IN_PROGRESS"
  | "ON_HOLD"
  | "OPEN"
  | "PARTIALLY_FULFILLED"
  | "PENDING_FULFILLMENT"
  | "REQUEST_DECLINED"
  | "RESTOCKED"
  | "SCHEDULED"
  | "UNFULFILLED";

export interface VariantPayload {
  gid: string;
  title: string;
  sku: string | null;
  price: string | null;
}

export interface ProductPayload {
  gid: string;
  title: string;
  handle: string;
  vendor: string | null;
  product_type: string | null;
  status: ProductStatus;
  image_url: string | null;
  published_at: string | null;
  shopify_updated_at: string;
  variants: VariantPayload[];
}

export interface LineItemPayload {
  gid: string;
  product_gid: string | null;
  variant_gid: string | null;
  title: string;
  sku: string | null;
  quantity: number;
  original_unit_price: string;
  discount_amount: string;
}

export interface OrderPayload {
  gid: string;
  name: string | null;
  financial_status: OrderFinancialStatus | null;
  fulfillment_status: OrderFulfillmentStatus | null;
  currency_code: string;
  processed_at: string | null;
  cancelled_at: string | null;
  shopify_updated_at: string;
  line_items: LineItemPayload[];
}

export interface RefundLinePayload {
  refund_gid: string;
  refund_line_item_gid: string;
  order_gid: string;
  line_item_gid: string | null;
  quantity: number;
  subtotal: string;
  currency_code: string;
  processed_at: string | null;
}

export interface ProductBatchBody {
  products: ProductPayload[];
}

export interface OrderBatchBody {
  orders: OrderPayload[];
}

export interface RefundBatchBody {
  refund_lines: RefundLinePayload[];
}

export interface BatchResponse {
  received: number;
  written: number;
  skipped_stale: number;
}
