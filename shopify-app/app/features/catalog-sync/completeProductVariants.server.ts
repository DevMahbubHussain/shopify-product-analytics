import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import {
  paginate,
  type Connection,
  type PaginateOptions,
} from "../../lib/shopify/graphql/paginate.server.ts";
import {
  VARIANT_FIELDS,
  type ShopifyProductNode,
  type ShopifyVariantNode,
} from "./productsQuery.server.ts";

const MAX_PAGE_SIZE = 250;

/** Admin API 2026-10. Root `product` query; allows up to 2048 variants per product. */
export const PRODUCT_VARIANTS_QUERY = `#graphql
  query ProductVariantsPage($id: ID!, $first: Int!, $after: String) {
    product(id: $id) {
      variants(first: $first, after: $after) {
        nodes {
          ${VARIANT_FIELDS}
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
  }`;

interface ProductVariantsPageData {
  product: { variants: Connection<ShopifyVariantNode> } | null;
}

export class ProductVanishedError extends Error {
  public readonly productGid: string;

  public constructor(productGid: string) {
    super(`Product ${productGid} was not found while fetching its remaining variants.`);
    this.name = "ProductVanishedError";
    this.productGid = productGid;
  }
}

/** Refetches all variants when the catalogue page truncated them; complete products pass through untouched. */
export async function completeProductVariants(
  admin: GraphqlAdmin,
  node: ShopifyProductNode,
  options: Omit<PaginateOptions, "pageSize"> = {},
): Promise<ShopifyProductNode> {
  if (!node.variants.pageInfo.hasNextPage) {
    return node;
  }

  const variants: ShopifyVariantNode[] = [];
  const pages = paginate<ProductVariantsPageData, ShopifyVariantNode>(
    admin,
    PRODUCT_VARIANTS_QUERY,
    { id: node.id },
    (data) => {
      if (data.product === null) {
        throw new ProductVanishedError(node.id);
      }
      return data.product.variants;
    },
    { ...options, pageSize: MAX_PAGE_SIZE },
  );

  for await (const page of pages) {
    variants.push(...page);
  }

  return { ...node, variants: { nodes: variants, pageInfo: { hasNextPage: false } } };
}
