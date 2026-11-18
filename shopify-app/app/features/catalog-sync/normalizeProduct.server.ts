import type {
  ProductPayload,
  VariantPayload,
} from "../../lib/backend/ingestionContract.server.ts";
import type {
  ShopifyProductNode,
  ShopifyVariantNode,
} from "./productsQuery.server.ts";

export class VariantsTruncatedError extends Error {
  public readonly productGid: string;

  public constructor(productGid: string) {
    super(
      `Product ${productGid} has more variants than one query page returned.`,
    );
    this.name = "VariantsTruncatedError";
    this.productGid = productGid;
  }
}

/** Shopify returns "" for an unset vendor or product type; Laravel stores null. */
function emptyToNull(value: string): string | null {
  return value === "" ? null : value;
}

function normalizeVariant(variant: ShopifyVariantNode): VariantPayload {
  return {
    gid: variant.id,
    title: variant.title,
    sku: variant.sku,
    price: variant.price,
  };
}

export function normalizeProduct(node: ShopifyProductNode): ProductPayload {
  if (node.variants.pageInfo.hasNextPage) {
    throw new VariantsTruncatedError(node.id);
  }

  return {
    gid: node.id,
    title: node.title,
    handle: node.handle,
    vendor: emptyToNull(node.vendor),
    product_type: emptyToNull(node.productType),
    status: node.status,
    image_url: node.featuredMedia?.preview?.image?.url ?? null,
    published_at: node.publishedAt,
    shopify_updated_at: node.updatedAt,
    variants: node.variants.nodes.map(normalizeVariant),
  };
}
