import { backendClient, type BackendClient } from "./backendClient.server.ts";

const SHOP_QUERY = `#graphql
  query RegisterShop {
    shop {
      id
      currencyCode
      ianaTimezone
    }
  }`;

interface ShopQueryResponse {
  data?: {
    shop: {
      id: string;
      currencyCode: string;
      ianaTimezone: string;
    } | null;
  };
  errors?: { message: string }[];
}

export interface RegisteredShop {
  shop_domain: string;
  status: string;
}

export interface ShopQueryClient {
  graphql(query: string): Promise<Response>;
}

export async function registerShop(
  shopDomain: string,
  admin: ShopQueryClient,
  client: Pick<BackendClient, "postInternal"> = backendClient(),
): Promise<RegisteredShop> {
  const response = await admin.graphql(SHOP_QUERY);
  const { data, errors } = (await response.json()) as ShopQueryResponse;

  if (errors !== undefined && errors.length > 0) {
    throw new Error(
      `Shop query failed: ${errors.map((error) => error.message).join("; ")}`,
    );
  }

  const shop = data?.shop;
  if (!shop) {
    throw new Error("Shop query returned no shop.");
  }

  return client.postInternal<RegisteredShop>(
    "/internal/v1/ingestion/shop",
    shopDomain,
    {
      shopify_shop_gid: shop.id,
      currency_code: shop.currencyCode,
      timezone: shop.ianaTimezone,
    },
  );
}
