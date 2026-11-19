import type { Connection } from "../../lib/shopify/graphql/paginate.server.ts";
import type { ProductStatus } from "../../lib/backend/ingestionContract.server.ts";

export const VARIANTS_PER_PRODUCT = 100;

export const VARIANT_FIELDS = `id
            title
            sku
            price`;

/** Admin API 2026-10. `$query` takes Shopify search syntax, e.g. "updated_at:>'2026-11-01T00:00:00Z'". */
export const PRODUCTS_QUERY = `#graphql
  query CatalogProducts($first: Int!, $after: String, $query: String) {
    products(first: $first, after: $after, query: $query, sortKey: ID) {
      nodes {
        id
        title
        handle
        vendor
        productType
        status
        publishedAt
        updatedAt
        featuredMedia {
          preview {
            image {
              url
            }
          }
        }
        variants(first: ${VARIANTS_PER_PRODUCT}) {
          nodes {
            ${VARIANT_FIELDS}
          }
          pageInfo {
            hasNextPage
          }
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }`;

export interface ShopifyVariantNode {
  id: string;
  title: string;
  sku: string | null;
  price: string;
}

export interface ShopifyProductNode {
  id: string;
  title: string;
  handle: string;
  vendor: string;
  productType: string;
  status: ProductStatus;
  publishedAt: string | null;
  updatedAt: string;
  featuredMedia: { preview: { image: { url: string } | null } | null } | null;
  variants: {
    nodes: ShopifyVariantNode[];
    pageInfo: { hasNextPage: boolean };
  };
}

export interface ProductsQueryData {
  products: Connection<ShopifyProductNode>;
}
