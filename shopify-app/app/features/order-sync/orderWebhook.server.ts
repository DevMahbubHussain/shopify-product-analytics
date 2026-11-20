import { backendClient } from "../../lib/backend/backendClient.server.ts";
import type {
  LineItemPayload,
  OrderBatchBody,
  OrderFinancialStatus,
  OrderFulfillmentStatus,
  OrderPayload,
  RefundBatchBody,
  RefundLinePayload,
} from "../../lib/backend/ingestionContract.server.ts";
import type { VerifiedWebhook } from "../../lib/shopify/verifyWebhook.server.ts";

export interface ShopifyWebhookLineItem {
  readonly id: number | string;
  readonly admin_graphql_api_id?: string;
  readonly product_id?: number | string | null;
  readonly variant_id?: number | string | null;
  readonly title?: string;
  readonly sku?: string | null;
  readonly quantity?: number;
  readonly price?: string | number;
  readonly total_discount?: string | number;
}

export interface ShopifyWebhookOrderPayload {
  readonly id?: number | string;
  readonly admin_graphql_api_id?: string;
  readonly name?: string | null;
  readonly currency?: string;
  readonly financial_status?: string | null;
  readonly fulfillment_status?: string | null;
  readonly processed_at?: string | null;
  readonly cancelled_at?: string | null;
  readonly updated_at?: string | null;
  readonly test?: boolean;
  readonly line_items?: readonly ShopifyWebhookLineItem[];
}

export interface ShopifyWebhookRefundLineItem {
  readonly id: number | string;
  readonly admin_graphql_api_id?: string;
  readonly line_item_id?: number | string | null;
  readonly quantity?: number;
  readonly subtotal?: string | number;
}

export interface ShopifyWebhookRefundPayload {
  readonly id?: number | string;
  readonly admin_graphql_api_id?: string;
  readonly order_id?: number | string;
  readonly currency?: string;
  readonly created_at?: string | null;
  readonly processed_at?: string | null;
  readonly refund_line_items?: readonly ShopifyWebhookRefundLineItem[];
}

function formatDecimal6(val: string | number | undefined | null): string {
  if (val === undefined || val === null || val === "") {
    return "0.000000";
  }
  const num = Number(val);
  return Number.isFinite(num) ? num.toFixed(6) : "0.000000";
}

export async function defaultPostInternal(
  endpoint: string,
  shop: string,
  payload: object,
): Promise<unknown> {
  return backendClient().postInternal(endpoint, shop, payload);
}

export async function handleOrderWebhook(
  webhook: Pick<VerifiedWebhook, "shop" | "rawBody">,
  postInternal: (
    endpoint: string,
    shop: string,
    payload: object,
  ) => Promise<unknown> = defaultPostInternal,
): Promise<200 | 500> {
  try {
    const data = JSON.parse(webhook.rawBody) as ShopifyWebhookOrderPayload;

    if (!data.id) {
      return 200;
    }

    if (data.test) {
      return 200;
    }

    const orderGid =
      data.admin_graphql_api_id || `gid://shopify/Order/${data.id}`;

    const rawLineItems = data.line_items || [];
    const lineItems: LineItemPayload[] = rawLineItems.map((line) => {
      const lineGid =
        line.admin_graphql_api_id || `gid://shopify/LineItem/${line.id}`;
      const productGid = line.product_id
        ? String(line.product_id).startsWith("gid://")
          ? String(line.product_id)
          : `gid://shopify/Product/${line.product_id}`
        : null;
      const variantGid = line.variant_id
        ? String(line.variant_id).startsWith("gid://")
          ? String(line.variant_id)
          : `gid://shopify/ProductVariant/${line.variant_id}`
        : null;

      return {
        gid: lineGid,
        product_gid: productGid,
        variant_gid: variantGid,
        title: line.title || "",
        sku: line.sku ?? null,
        quantity: Number(line.quantity || 1),
        original_unit_price: formatDecimal6(line.price),
        discount_amount: formatDecimal6(line.total_discount),
      };
    });

    const orderPayload: OrderPayload = {
      gid: orderGid,
      name: data.name ?? null,
      financial_status: data.financial_status
        ? (data.financial_status.toUpperCase() as OrderFinancialStatus)
        : null,
      fulfillment_status: data.fulfillment_status
        ? (data.fulfillment_status.toUpperCase() as OrderFulfillmentStatus)
        : null,
      currency_code: data.currency || "USD",
      processed_at: data.processed_at ?? null,
      cancelled_at: data.cancelled_at ?? null,
      shopify_updated_at:
        data.updated_at || data.processed_at || new Date().toISOString(),
      line_items: lineItems,
    };

    const batchBody: OrderBatchBody = {
      orders: [orderPayload],
    };

    await postInternal(
      "/internal/v1/ingestion/orders",
      webhook.shop,
      batchBody,
    );

    return 200;
  } catch (error) {
    console.error(
      `Order webhook processing error for ${webhook.shop}:`,
      error,
    );
    return 500;
  }
}

export async function handleRefundWebhook(
  webhook: Pick<VerifiedWebhook, "shop" | "rawBody">,
  postInternal: (
    endpoint: string,
    shop: string,
    payload: object,
  ) => Promise<unknown> = defaultPostInternal,
): Promise<200 | 500> {
  try {
    const data = JSON.parse(webhook.rawBody) as ShopifyWebhookRefundPayload;

    if (!data.id || !data.order_id) {
      return 200;
    }

    const refundGid =
      data.admin_graphql_api_id || `gid://shopify/Refund/${data.id}`;
    const orderGid = `gid://shopify/Order/${data.order_id}`;
    const processedAt =
      data.processed_at || data.created_at || new Date().toISOString();
    const currency = data.currency || "USD";

    const rawRefundLines = data.refund_line_items || [];
    const refundLines: RefundLinePayload[] = rawRefundLines.map((item) => {
      const refundLineItemGid =
        item.admin_graphql_api_id || `gid://shopify/RefundLineItem/${item.id}`;
      const lineItemGid = item.line_item_id
        ? `gid://shopify/LineItem/${item.line_item_id}`
        : null;

      return {
        refund_gid: refundGid,
        refund_line_item_gid: refundLineItemGid,
        order_gid: orderGid,
        line_item_gid: lineItemGid,
        quantity: Number(item.quantity || 1),
        subtotal: formatDecimal6(item.subtotal),
        currency_code: currency,
        processed_at: processedAt,
      };
    });

    const batchBody: RefundBatchBody = {
      refund_lines: refundLines,
    };

    await postInternal(
      "/internal/v1/ingestion/refunds",
      webhook.shop,
      batchBody,
    );

    return 200;
  } catch (error) {
    console.error(
      `Refund webhook processing error for ${webhook.shop}:`,
      error,
    );
    return 500;
  }
}
