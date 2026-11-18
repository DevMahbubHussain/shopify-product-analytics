import {
  parseCost,
  waitBeforeNext,
  type QueryCost,
} from "./graphqlCost.server.ts";
import { isRecord, sleep as realSleep } from "./runtime.server.ts";

export interface GraphqlAdmin {
  graphql(
    query: string,
    options?: { variables?: Record<string, unknown> },
  ): Promise<Response>;
}

export interface QueryResult<TData> {
  data: TData;
  cost: QueryCost | null;
}

export interface QueryOptions {
  sleep?: (ms: number) => Promise<void>;
  maxAttempts?: number;
}

interface GraphqlErrorDetail {
  message: string;
  code: string | null;
}

export class ShopifyGraphqlError extends Error {
  public readonly codes: string[];
  public readonly cost: QueryCost | null;

  public constructor(errors: GraphqlErrorDetail[], cost: QueryCost | null) {
    super(
      `Shopify GraphQL error: ${errors.map((e) => e.message).join("; ") || "unknown"}`,
    );
    this.name = "ShopifyGraphqlError";
    this.codes = errors.flatMap((e) => (e.code ? [e.code] : []));
    this.cost = cost;
  }
}

const THROTTLED = "THROTTLED";
const FALLBACK_THROTTLE_WAIT_MS = 1000;

/** The Shopify library throws GraphqlQueryError for GraphQL errors; matched by shape, not class. */
function toGraphqlError(error: unknown): ShopifyGraphqlError | null {
  if (
    !isRecord(error) ||
    !isRecord(error.body) ||
    !isRecord(error.body.errors)
  ) {
    return null;
  }
  const list = error.body.errors.graphQLErrors;
  if (!Array.isArray(list)) {
    return null;
  }
  const details = list.map((item: unknown): GraphqlErrorDetail => {
    const message =
      isRecord(item) && typeof item.message === "string"
        ? item.message
        : "unknown";
    const code =
      isRecord(item) &&
      isRecord(item.extensions) &&
      typeof item.extensions.code === "string"
        ? item.extensions.code
        : null;
    return { message, code };
  });
  return new ShopifyGraphqlError(details, parseCost(error.body.extensions));
}

async function attempt<TData>(
  admin: GraphqlAdmin,
  query: string,
  variables: Record<string, unknown>,
): Promise<QueryResult<TData>> {
  try {
    const body: unknown = await (
      await admin.graphql(query, { variables })
    ).json();
    if (!isRecord(body) || !isRecord(body.data)) {
      throw new ShopifyGraphqlError(
        [{ message: "Response contained no data.", code: null }],
        null,
      );
    }
    return { data: body.data as TData, cost: parseCost(body.extensions) };
  } catch (error) {
    throw toGraphqlError(error) ?? error;
  }
}

export async function shopifyQuery<TData>(
  admin: GraphqlAdmin,
  query: string,
  variables: Record<string, unknown> = {},
  { sleep = realSleep, maxAttempts = 3 }: QueryOptions = {},
): Promise<QueryResult<TData>> {
  for (let attemptNumber = 1; ; attemptNumber++) {
    try {
      return await attempt<TData>(admin, query, variables);
    } catch (error) {
      const throttled =
        error instanceof ShopifyGraphqlError && error.codes.includes(THROTTLED);
      if (!throttled || attemptNumber >= maxAttempts) {
        throw error;
      }
      const wait = error.cost
        ? waitBeforeNext(error.cost, error.cost.requestedQueryCost)
        : FALLBACK_THROTTLE_WAIT_MS;
      await sleep(wait);
    }
  }
}
