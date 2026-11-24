<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Name('metric_definitions')]
#[Title('Metric Definitions')]
#[Uri('analytics://docs/metric-definitions')]
#[MimeType('text/markdown')]
#[Description('Comprehensive Markdown reference document explaining financial metric formulas, half-open date boundary rules, refund rates, and comparison math.')]
final class MetricDefinitionsResource extends Resource
{
    public function handle(Request $request): Response
    {
        $markdown = <<<'MARKDOWN'
# Product Performance Analytics: Metric Definitions & Financial Formulas

This reference document defines the mathematical formulas, date boundary mechanics, currency constraints, and comparison rules implemented across all analytics endpoints and Model Context Protocol tools.

---

## 1. Core Financial Formulas

All monetary figures are calculated using arbitrary-precision arithmetic (`DECIMAL(20,6)` in PostgreSQL and `bcmath` in PHP). Floating-point arithmetic is strictly prohibited for money.

### Gross Sales
$$\text{Gross Sales} = \sum (\text{Quantity} \times \text{Original Unit Price})$$
- Calculated at line-item granularity prior to discounts, returns, shipping, or taxes.
- Reflects the gross list price value of ordered products.

### Discounts
$$\text{Discounts} = \sum (\text{Line Level Discounts})$$
- Aggregates all coupon codes, promotional markdowns, and line-level discount allocations in store currency.

### Returns & Refunds
$$\text{Refunds} = \sum (\text{Refund Subtotal})$$
- Attributed strictly to the calendar date on which the refund was **processed** (`processed_at`), rather than the date of the original order.
- This accounting convention prevents historical restatement of past order days and guarantees immutable historical reports.

### Net Sales
$$\text{Net Sales} = \text{Gross Sales} - \text{Discounts} - \text{Refunds}$$
- **Negative Net Sales Support:** If refunds on a given day or product exceed gross sales minus discounts, Net Sales will naturally be negative. AI clients must handle negative net sales as valid financial states.

### Units Sold (Net Volume)
$$\text{Units Sold} = \sum (\text{Purchased Quantity}) - \sum (\text{Refunded Quantity})$$
- Represents net physical product volume retained by customers.

### Orders Count
$$\text{Orders Count} = \text{COUNT}(\text{DISTINCT } \text{Order ID})$$
- Count of unique orders containing at least one line item of the product or store during the time interval.

### Average Order Revenue (AOV)
$$\text{Average Order Revenue} = \frac{\text{Net Sales}}{\text{Orders Count}}$$
- If $\text{Orders Count} = 0$, Average Order Revenue evaluates safely to `0.000000` without division-by-zero errors.

### Refund Rate Percentage
$$\text{Refund Rate} = \left(\frac{\text{Refunds}}{\text{Gross Sales}}\right) \times 100$$
- Expressed as a percentage rounded to 2 decimal places.
- Returned as a decimal string computed in PostgreSQL `NUMERIC` (for example `"12.50"`).
- Products with zero gross sales in the window have no defined refund rate and are excluded from `find_high_refund_products`; `list_product_performance` reports `0.00` for them.

---

## 2. Temporal Precision & Day Boundaries

### Half-Open Day Boundaries
All daily metric aggregations and analytics queries enforce half-open intervals:
$$[\text{startOfDayUtc}, \text{startOfNextDayUtc})$$
- Evaluated as `>= startOfDayUtc` and `< startOfNextDayUtc`.
- Closed intervals (`<= endOfDay` or `23:59:59`) are strictly forbidden to eliminate double-counting or data loss at microsecond boundaries (`timestamptz(6)`).

### Store Timezone Grounding
- All input date parameters (`from`, `to`) are interpreted in the store's configured IANA timezone (e.g. `America/New_York`, `Europe/London`).
- Midnight in store local time is converted to the exact corresponding UTC microsecond before querying PostgreSQL aggregates.
- Inquiries for dates beyond the store's current calendar day are rejected as invalid future dates.

### Weekly and Monthly Buckets (`get_sales_trend`)
- `interval: "week"` groups days into ISO weeks starting on **Monday**; each bucket is keyed by its Monday date (`YYYY-MM-DD`).
- `interval: "month"` groups days by calendar month, keyed `YYYY-MM`.
- The first and last buckets are partial when `from`/`to` fall mid-week or mid-month: they contain only days inside the requested range.

---

## 3. Currency Isolation (ISO 4217)

- Every financial amount is accompanied by its ISO 4217 three-letter currency code (e.g. `USD`, `EUR`, `CAD`).
- Monetary amounts from differing currencies are **never summed together**. Multi-currency transactions are partitioned by currency code in `product_daily_metrics`.

---

## 4. Preceding Period Comparison Math

`get_analytics_overview` always includes a preceding-period comparison; `get_product_analytics` includes it unless its `compare_previous` argument is `false`. The math is identical in both:
1. **Date Range Sizing:** If the primary period spans $N$ calendar days ($[\text{from}, \text{to}]$ inclusive, where $N = \text{to} - \text{from} + 1$), the preceding period spans the immediately preceding $N$ calendar days:
   $$[\text{from} - N \text{ days}, \text{from} - 1 \text{ day}]$$
2. **Absolute Delta:**
   $$\Delta = \text{Current Metric} - \text{Preceding Metric}$$
3. **Percentage Change:**
   $$\text{Percentage Change} = \frac{\text{Current Metric} - \text{Preceding Metric}}{|\text{Preceding Metric}|} \times 100$$
   - Using the absolute value of the preceding metric in the denominator guarantees mathematical correctness when the baseline is negative (e.g., negative net sales from refunds).
   - If $\text{Preceding Metric} = 0$, percentage change is returned as `null` to indicate an undefined growth rate from zero baseline.
MARKDOWN;

        return Response::text($markdown);
    }
}
