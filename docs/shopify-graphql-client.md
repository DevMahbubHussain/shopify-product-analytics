# Shopify GraphQL Client (Step 3, Group D)

How the Shopify app reads data from the Shopify Admin GraphQL API for background sync: one query wrapper with typed errors and throttle retry, a cost calculator that paces requests, and a cursor paginator. All files live in `shopify-app/app/lib/shopify/graphql/`. Nothing here writes to Shopify or calls Laravel; the harvester (group F) builds on it.

---

## 1. What This Phase Provides

- **Never hit the rate limit in normal use.** After every page, the client reads how many cost points remain and waits just long enough for the next request to fit.
- **Recover when Shopify throttles anyway.** A `THROTTLED` response is retried after the wait Shopify's own cost data implies, up to a limit.
- **Walk any connection page by page** (`first` / `after`), holding one page in memory at a time.
- **Clear failures.** GraphQL errors become `ShopifyGraphqlError` with Shopify's codes and messages; network and HTTP errors pass through unchanged.

---

## 2. Design Rules

1. **Shopify's numbers, not ours.** Bucket size and refill rate are read from each response (`extensions.cost.throttleStatus`), never hard-coded. The demo store reports 2000 points / 100 per second; the docs example shows 1000 / 50.
2. **Pace first, retry second.** Pacing avoids throttling; retry is the fallback.
3. **Retry only what can succeed later.** Only `THROTTLED` is retried. A broken query (`undefinedField`, missing data) fails immediately.
4. **No dependency on library internals by class.** `@shopify/shopify-api` is not a direct dependency, so its `GraphqlQueryError` is recognised by shape (`body.errors.graphQLErrors`), not by `instanceof`.
5. **No `any`.** Responses are `unknown` until checked; query result types are hand-written interfaces per query.
6. **Testable without network or clocks.** `admin` and `sleep` are injected.

Facts verified on shopify.dev (`/docs/apps/build/apis/graphql-admin/rate-limits`): cost-based leaky bucket per app and store; a single query may not exceed 1,000 points; `extensions.cost` carries `requestedQueryCost`, `actualQueryCost`, `throttleStatus { maximumAvailable, currentlyAvailable, restoreRate }`; bulk operations are recommended for very large datasets. The page does not name the throttle error code; `THROTTLED` is handled as the documented-in-practice value and the pacing makes it rare.

---

## 3. Request Flow

```
paginate(admin, query, variables, selectConnection, { pageSize = 50 })
  loop:
    shopifyQuery(admin, query, { ...variables, first, after })
      admin.graphql(...) ──► Shopify Admin API 2026-10
        ok     → { data, extensions.cost } → parseCost
        throws → GraphqlQueryError (shape) → ShopifyGraphqlError
                   code THROTTLED and attempts left → sleep(waitBeforeNext) → retry
                   otherwise → throw
    yield nodes
    hasNextPage false → stop
    endCursor null or unchanged → throw "cursor did not advance"
    sleep(waitBeforeNext(cost, requestedQueryCost)) if > 0
```

---

## 4. Files

| Step | File | Role |
|---|---|---|
| D-1 | `graphqlCost.server.test.ts` | 9 tests: parsing, zero wait, refill wait, rounding up, bucket cap, missing data, zero refill rate |
| D-2 | `graphqlCost.server.ts` | `parseCost(extensions)`, `waitBeforeNext(cost, nextCost)` |
| D-3 | `shopifyGraphql.server.test.ts` | 6 tests: data + cost, GraphQL error mapping, throttle retry with wait, give-up, non-GraphQL errors untouched, missing data |
| D-4 | `shopifyGraphql.server.ts` | `shopifyQuery<TData>()`, `ShopifyGraphqlError`, `GraphqlAdmin` |
| D-5 | `paginate.server.test.ts` | 6 tests: cursor sequence and variables, single page, page size, pacing wait, cursor not advancing (two cases) |
| D-6 | `paginate.server.ts` | `paginate<TData, TNode>()` async generator, `Connection<TNode>` |
| D-6a | `runtime.server.ts` | shared `isRecord`, `sleep` (removed duplicates) |

### Wait calculation

`missing = min(nextCost, maximumAvailable) − currentlyAvailable`

- `missing ≤ 0` → 0 ms
- otherwise → `ceil(missing ÷ restoreRate × 1000)` ms
- `restoreRate ≤ 0` → 1000 ms (guard against bad data)
- no cost data → 0 ms (pacing impossible; retry still protects)

### Usage

```ts
// from a file in app/features/<feature>/
import { unauthenticated } from "../../shopify.server";
import { paginate, type Connection } from "../../lib/shopify/graphql/paginate.server";

interface ProductsData { products: Connection<{ id: string; title: string }> }

const { admin } = await unauthenticated.admin(shopDomain);
for await (const products of paginate<ProductsData, { id: string; title: string }>(
  admin, PRODUCTS_QUERY, {}, (data) => data.products,
)) {
  // send this page to Laravel, then continue
}
```

Queries must select `nodes` and `pageInfo { hasNextPage endCursor }` and declare `$first: Int!`, `$after: String`.

---

## 5. Edge Cases Handled

| Case | Behavior |
|---|---|
| Bucket low after a page | Wait before the next page (computed, not fixed) |
| Next query larger than the bucket | Wait until the bucket is full, not forever |
| `THROTTLED` despite pacing | Retry after the computed wait, up to `maxAttempts` (default 3) |
| Throttled on every attempt | `ShopifyGraphqlError` with `codes: ["THROTTLED"]` |
| Invalid query | `ShopifyGraphqlError` immediately, no retry |
| Response without `data` | `ShopifyGraphqlError` |
| Network or HTTP failure | Original error rethrown (the library already retries HTTP 429/503 when `tries` is set) |
| Malformed or missing cost data | Treated as unknown: no pacing wait; throttle retry waits 1 s |
| `hasNextPage` with null or repeated cursor | Error instead of an endless loop |
| Large catalogs | One page in memory at a time |

---

## 6. Verification

### Automated

```bash
cd shopify-app && npm test   # 45 passed (21 for this group)
npm run typecheck && npm run lint
```

### Live (2026-11-18, dev store `product-analytics-demo`, read-only)

| Check | Result |
|---|---|
| `unauthenticated.admin(shop)` with `shopifyQuery` | 10 products; cost `requested 6, actual 6`, bucket `2000`, available `1994`, refill `100/s` |
| `paginate`, page size 10 | 17 products in 2 pages, 763 ms, no waits needed |
| `orders` connection | Access works; 0 orders (demo orders not created yet) |
| Invalid field | Real library error mapped to `ShopifyGraphqlError`, codes `["undefinedField"]` |

A real `THROTTLED` response was not provoked against the store; the retry path is covered by tests that use the library's error shape, confirmed by the invalid-field check.

---

## 7. Not in This Phase

- Bulk operations (`bulkOperationRunQuery`) for very large stores; paginated queries cover the 60-day window for small and medium stores.
- Generated query types (`graphql-codegen`); hand-written interfaces until queries multiply.
- Normalizers (group E) and the harvester (group F).
