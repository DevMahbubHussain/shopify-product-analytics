import type { GraphqlAdmin } from "../../lib/shopify/graphql/shopifyGraphql.server.ts";
import { paginate } from "../../lib/shopify/graphql/paginate.server.ts";
import type { BackendClient } from "../../lib/backend/backendClient.server.ts";
import type {
  BatchResponse,
  ProductPayload,
} from "../../lib/backend/ingestionContract.server.ts";
import { INGESTION_LIMITS } from "../../lib/backend/ingestionContract.server.ts";
import {
  PRODUCTS_QUERY,
  type ProductsQueryData,
  type ShopifyProductNode,
} from "./productsQuery.server.ts";
import { normalizeProduct } from "./normalizeProduct.server.ts";
import { completeProductVariants } from "./completeProductVariants.server.ts";

export interface HarvestProgress {
  received: number;
  written: number;
  skippedStale: number;
}

export interface HarvestProductsOptions {
  admin: GraphqlAdmin;
  backend: BackendClient;
  shopDomain: string;
  query?: string;
  pageSize?: number;
  onProgress?: (progress: HarvestProgress) => void;
}

export interface HarvestProductsSummary {
  received: number;
  written: number;
  skippedStale: number;
}

/**
 * Harvests all products and their variants for a shop via GraphQL cursor pagination,
 * normalizes them into Laravel ingestion DTOs, and posts them to the ingestion endpoint.
 */
export async function harvestProducts({
  admin,
  backend,
  shopDomain,
  query,
  pageSize = 50,
  onProgress,
}: HarvestProductsOptions): Promise<HarvestProductsSummary> {
  let received = 0;
  let written = 0;
  let skippedStale = 0;

  const pager = paginate<ProductsQueryData, ShopifyProductNode>(
    admin,
    PRODUCTS_QUERY,
    query ? { query } : {},
    (data) => data.products,
    { pageSize },
  );

  for await (const page of pager) {
    if (page.length === 0) {
      continue;
    }

    const normalizedBatch: ProductPayload[] = [];
    for (const node of page) {
      normalizedBatch.push(normalizeProduct(await completeProductVariants(admin, node)));
    }

    for (
      let i = 0;
      i < normalizedBatch.length;
      i += INGESTION_LIMITS.productsPerBatch
    ) {
      const chunk = normalizedBatch.slice(
        i,
        i + INGESTION_LIMITS.productsPerBatch,
      );
      const res = await backend.postInternal<BatchResponse>(
        "/internal/v1/ingestion/products",
        shopDomain,
        { products: chunk },
      );

      received += res.received;
      written += res.written;
      skippedStale += res.skipped_stale;

      onProgress?.({ received, written, skippedStale });
    }
  }

  return { received, written, skippedStale };
}
