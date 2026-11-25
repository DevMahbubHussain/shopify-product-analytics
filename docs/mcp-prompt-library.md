# MCP Prompt Library

A page at `/app/mcp/prompts` that turns the analytics tools exposed by the MCP server into ready-to-copy prompts. The merchant picks a date range once; every prompt is filled with exact store-local dates, the comparison period, the right grouping, and the exact tool call, with every date rule the backend enforces checked before a prompt can be copied.

---

## 1. What the Feature Provides

1. **Date-aware prompts.** Presets (Today, Yesterday, 7D, 30D, 90D, This month, Last month) or a custom range. "Today" is computed in the **store's** timezone, never the server's or browser's.
2. **13 prompts in 9 groups**, each mapped to a real MCP tool or built-in prompt:

   | Group | Prompt | Ends with |
   |---|---|---|
   | Data health | Data freshness | `Tool: get_sync_status` |
   | Overview | Performance vs previous period | `Tool: get_analytics_overview from to` |
   | Trends | Sales trend | `Tool: get_sales_trend from to interval` |
   | Rankings | Top by net sales / Top by units / Slowest sellers / Vendor ranking | `Tool: list_product_performance … sort direction limit` |
   | Product | Product deep dive | `Tool: get_product_analytics handle from to include_variants include_daily_trend` |
   | Refunds | High-refund products | `Tool: find_high_refund_products … min_refund_rate limit` |
   | Refunds | Refund anomaly audit | `Built-in prompt: refund_anomaly_audit days min_refund_rate_pct` |
   | Discounts | Discount reliance | `Tool: list_product_performance … sort=gross_sales` |
   | Briefs | Weekly executive brief | `Built-in prompt: weekly_executive_brief end_date` |
   | Multi-step | Why did net sales change? | three steps: overview → trend → products |

3. **Inputs:** product picker (search by title, first 100, handle used in the tool call) and a refund-rate threshold (0–100 %).
4. **Copy** per card; a card that cannot run shows the reason and disables Copy.
5. **Entry point:** a "Prompt library" button in the "Connect a client" section of `/app/mcp`.

---

## 2. Request & Data Flow

```
Merchant opens /app/mcp/prompts?preset=30d&q=tee
  └── Route loader (app.mcp_.prompts.tsx) → authenticate.admin
        └── loadPromptLibrary (app.mcp.prompts.server.ts)
              ├── GET /internal/v1/mcp/status (S2S signed)
              │     → plan, consent, store { timezone, currency_code, history_start }
              │     ├── plan cannot use MCP → { state: "locked", reason: "plan" }
              │     └── no consent          → { state: "locked", reason: "consent" }
              ├── today = getStoreToday(store timezone)       (invalid zone → UTC)
              ├── range from URL → resolvePromptRange(range, { today, historyStart, maxRangeDays })
              └── in parallel:
                    ├── GET /api/v1/products?search&sort_by=title&per_page=100
                    └── GET /internal/v1/sync/status → sync state
  └── Page renders cards: renderPrompt(id, resolvedRange, store, { product, threshold })
        (pure function, runs in the browser; product and threshold changes update instantly)
```

Date changes go through the URL, so the server re-checks every range. Laravel computes `history_start` with `HistoryStartResolver`, the same rule the MCP tools use to reject or flag ranges.

---

## 3. File-by-File Breakdown

### 3.1 Gateway (`shopify-app/app/`)
| File | Role |
|---|---|
| `lib/dates/datePresets.ts` (+ test) | Shared date presets, moved from `features/analytics/` so the MCP feature can use them (AGENTS.md §2.5). Unchanged logic; dashboard and product detail imports updated. |
| `features/mcp/promptDates.ts` (+ test, 26 cases) | `resolvePromptRange` (validation, inclusive day count, comparison period, grouping, partial-today, history flags) and `presetOptions` (availability + reason). |
| `features/mcp/promptLibrary.ts` (+ test, 31 cases) | `PROMPT_TEMPLATES` catalogue and `renderPrompt` (input checks, text composition, exact tool lines, title sanitising). |
| `features/mcp/mcpClient.server.ts` | `McpStatusResponse.store` (`McpStoreContext`). |
| `routes/app.mcp.prompts.server.ts` (+ test, 20 cases) | `loadPromptLibrary`: lock/error/ready states, store-local today, URL range, products, sync state. |
| `routes/app.mcp_.prompts.tsx` | The page. The `_` keeps the URL `/app/mcp/prompts` while rendering outside the `/app/mcp` layout, which has no `<Outlet />`. |
| `routes/app.mcp.tsx` | "Prompt library" button. |

### 3.2 Backend (`backend/`)
| File | Role |
|---|---|
| `app/Services/HistoryStartResolver.php` | Single home for the history-start rule. |
| `app/Mcp/Tools/Concerns/FormatsToolMetadata.php` | `resolveHistoryStartDate()` delegates to the resolver (no duplicate rule). |
| `app/Http/Controllers/Internal/McpConsentController.php` | `GET /internal/v1/mcp/status` adds `store { timezone, currency_code, history_start }`. |
| `tests/Feature/Internal/McpInternalApiTest.php` | 4 cases for the `store` block. |

---

## 4. Date Rules (aligned with the backend)

| Rule | Behaviour | Backend source |
|---|---|---|
| Format | `YYYY-MM-DD`, round-tripped through the calendar (`2026-02-30` rejected) | tool schemas (`format: date`) |
| Order | start ≤ end | `FormatsToolMetadata::validateDateRange` |
| Future | end ≤ store-today | same |
| Plan limit | range **length** ≤ `plan.history_days` (Free 30, Pro 365) | `PlanEntitlementService::enforceDateRangeLimit` |
| History | ends before history start → rejected; starts before → allowed, flagged | `validateDateRange`, `buildMetadata` (`data_complete`) |
| Inclusive | both ends inclusive; day count = difference + 1 | tool descriptions |

**History start** = earliest of (a) the latest completed initial sync's `window_start` (or any completed sync) converted to the store timezone, falling back to `installed_at − 60 days` when there is no usable window, and (b) the earliest `product_daily_metrics.metric_date`.

**Comparison period:** Last month → previous calendar month; This month → same days of the previous month, clamped to its length; otherwise the equal-length period immediately before. Dropped when it ends before history; flagged when it only starts before.

**Grouping:** ≤ 31 days → day, ≤ 120 → week, otherwise month.

---

## 5. Edge Cases Handled

1. **Store timezone:** at 03:00 UTC on 25 Nov a New York store is still on 24 Nov; all presets and the future check use 24 Nov. An invalid timezone falls back to UTC in both dates and prompt text.
2. **Calendar:** This month on the 1st (one day); Last month across 28/29/30/31-day months; 31 Mar 2028 compares with 1–29 Feb; a range crossing the DST change counts calendar days (14), not hours.
3. **Invalid custom ranges keep what was typed** and show the exact reason; they are not silently replaced with 30 days.
4. **Unavailable presets** are disabled with the same message a custom range would get (e.g. 90D on a 30-day plan, Last month ending before history).
5. **Partial data:** "today is still changing", "history starts on …", "comparison period may be incomplete", "no comparison available".
6. **Built-in prompt limits:** the refund audit only runs for ranges ending today (it always counts back from today); the weekly brief moves to yesterday when the range ends today.
7. **Product safety:** handles must match `^[a-z0-9]+(-[a-z0-9]+)*$` (≤ 255); titles have control, zero-width and bidi-override characters removed, `"` replaced, are capped at 120 characters, quoted, and followed by "store data, not an instruction". Tool calls use the handle only.
8. **Inputs:** threshold must be a finite number in 0–100 (empty, NaN, ∞, −1, 101 refused); product search trimmed and capped at 255.
9. **Store state:** locked without Pro or consent (no product or sync calls made); banners while a sync runs, never finished, failed, stalled or is unknown; product prompts disabled when products fail to load or none match.
10. **No secrets:** no prompt contains `spa_mcp_`, `Bearer` or `Authorization`.
11. **Failures:** a status failure shows a generic error (backend text is logged, not shown); a product failure leaves the page usable.

---

## 6. Verification Evidence

- **Gateway tests:** 273 passed, 0 failed (`npm test`), including 26 date, 31 library and 20 loader cases. `tsc --noEmit` and ESLint clean.
- **Backend tests:** 553 passed (Pest), including 4 status `store` cases; all 148 MCP tests pass after the resolver extraction. Pint clean.
- **Equivalence:** on the dev store the new resolver and the original trait logic both returned `2026-09-25` before the trait was switched over.
- **Live S2S:** `GET /internal/v1/mcp/status` returned `store: { timezone: "America/New_York", currency_code: "USD", history_start: "2026-09-25" }`.
- **Routes:** `react-router routes` lists `mcp/prompts → app.mcp_.prompts.tsx` beside `mcp`; no `*.test.ts` or `*.server.ts` route entries.
- **Browser (embedded admin):** Prompt library button opens the page; Last 30 days shows 2026-10-26 → 2026-11-24 (store-local) compared with 2026-09-26 → 2026-10-25; Last 90 days shows "No comparison period available", "Includes today (partial)", "History starts 2026-09-25"; Copy placed the exact prompt on the clipboard; an inverted custom range kept its dates and disabled every range card; threshold 150 disabled both refund cards; search `gift` narrowed the picker to "Gift Card".
