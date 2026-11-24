<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('product_deep_dive')]
#[Title('Product Deep Dive')]
#[Description('Deep dive into sales, variants, refund rates, and daily trends for a specific product.')]
final class ProductDeepDivePrompt extends Prompt
{
    use FormatsToolMetadata;

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'product_query',
                description: 'Product title, keyword, or handle to identify and analyze.',
                required: true,
            ),
            new Argument(
                name: 'days',
                description: 'Number of historical days to analyze (1-365, default: 30).',
                required: false,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'product_query' => ['required', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'between:1,365'],
        ]);

        $shop = $this->resolveCurrentShop(request());
        $storeTimezone = $shop->timezone ?? 'UTC';

        $productQuery = (string) $request->get('product_query');
        $quotedQuery = (string) json_encode($productQuery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $days = (int) ($request->get('days') ?? 30);

        $endDate = CarbonImmutable::yesterday($storeTimezone)->toDateString();
        $startDate = CarbonImmutable::parse($endDate, $storeTimezone)->subDays($days - 1)->toDateString();

        $promptText = <<<PROMPT
Please perform a detailed Product Deep Dive for the product matching {$quotedQuery} over the past {$days} days ({$startDate} to {$endDate}, store timezone: `{$storeTimezone}`).

Execute the following analytical workflow using the available tools:

1. **Verify Ingestion Status & Completeness:**
   - Call tool `get_sync_status`.
   - Confirm background sync is completed (`data_complete: true`).

2. **Locate and Identify the Product:**
   - Call tool `list_product_performance` with:
     - `search`: {$quotedQuery}
     - `from`: "{$startDate}"
     - `to`: "{$endDate}"
     - `limit`: 5
   - Select the matching product and note its `product_id`, `title`, and `vendor`.

3. **Retrieve Comprehensive Product & Variant Analytics:**
   - Call tool `get_product_analytics` with:
     - `product_id`: {product.id}
     - `from`: "{$startDate}"
     - `to`: "{$endDate}"
     - `include_variants`: true
     - `include_daily_trend`: true
   - Note overall net sales, gross sales, refunds, returns count, and variant-level sales distributions.

4. **Analyze Historical Sales Trajectory:**
   - Use the `daily_trend` array returned by `get_product_analytics` in step 3 (one entry per day for this product only).
   - Inspect daily sales velocity, identifying spikes, consistent demand, or sudden drop-offs.

5. **Synthesize the Deep Dive Report:**
   - **Product Overview:** Title, Vendor, Product Status, Units Sold, Net Sales, Refund Rate (%).
   - **Variant Performance Breakdown:** Detailed breakdown of performance by SKU/variant (gross units, net revenue, return rate). Highlight top-selling variants vs lagging variants.
   - **Sales Trajectory & Momentum:** Analysis of day-by-day sales pattern, peak sales days, and demand trends.
   - **Return & Risk Evaluation:** Detailed assessment of return rates, identifying if issues stem from specific variants or overall product fit.
   - **Strategic Recommendations:** Concrete next steps for the merchant (inventory reordering, marketing promotions, pricing strategy, or listing revisions).
PROMPT;

        return Response::text($promptText);
    }
}
