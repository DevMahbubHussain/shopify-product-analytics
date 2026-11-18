import { waitBeforeNext } from "./graphqlCost.server.ts";
import { sleep as realSleep } from "./runtime.server.ts";
import {
  shopifyQuery,
  type GraphqlAdmin,
  type QueryOptions,
} from "./shopifyGraphql.server.ts";

export interface Connection<TNode> {
  nodes: TNode[];
  pageInfo: { hasNextPage: boolean; endCursor: string | null };
}

export interface PaginateOptions extends QueryOptions {
  pageSize?: number;
}

/** Yields one page of nodes at a time, pacing requests by the cost bucket Shopify reports. */
export async function* paginate<TData, TNode>(
  admin: GraphqlAdmin,
  query: string,
  variables: Record<string, unknown>,
  selectConnection: (data: TData) => Connection<TNode>,
  { pageSize = 50, sleep = realSleep, maxAttempts }: PaginateOptions = {},
): AsyncGenerator<TNode[]> {
  let after: string | null = null;

  for (;;) {
    const { data, cost } = await shopifyQuery<TData>(
      admin,
      query,
      { ...variables, first: pageSize, after },
      { sleep, maxAttempts },
    );
    const { nodes, pageInfo } = selectConnection(data);
    yield nodes;

    if (!pageInfo.hasNextPage) {
      return;
    }
    if (pageInfo.endCursor === null || pageInfo.endCursor === after) {
      throw new Error(
        `Shopify pagination cursor did not advance (after: ${after ?? "start"}).`,
      );
    }
    after = pageInfo.endCursor;

    const wait = cost ? waitBeforeNext(cost, cost.requestedQueryCost) : 0;
    if (wait > 0) {
      await sleep(wait);
    }
  }
}
