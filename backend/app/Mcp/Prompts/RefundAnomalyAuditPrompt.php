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

#[Name('refund_anomaly_audit')]
#[Title('Refund Anomaly Audit')]
#[Description('Audit refund anomalies and return rate spikes across store products.')]
final class RefundAnomalyAuditPrompt extends Prompt
{
    use FormatsToolMetadata;

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'days',
                description: 'Number of historical days to audit for refund anomalies (1-365, default: 30).',
                required: false,
            ),
            new Argument(
                name: 'min_refund_rate_pct',
                description: 'Minimum refund rate percentage threshold (0-100, default: 5).',
                required: false,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'between:1,365'],
            'min_refund_rate_pct' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $shop = $this->resolveCurrentShop(request());
        $storeTimezone = $shop->timezone ?? 'UTC';

        $days = (int) ($request->get('days') ?? 30);
        $minRefundRate = $request->get('min_refund_rate_pct') !== null ? (float) $request->get('min_refund_rate_pct') : 5.0;

        $endDate = CarbonImmutable::yesterday($storeTimezone)->toDateString();
        $startDate = CarbonImmutable::parse($endDate, $storeTimezone)->subDays($days - 1)->toDateString();

        $promptText = <<<PROMPT
Please conduct a comprehensive Refund Anomaly Audit for this store over the past {$days} days ({$startDate} to {$endDate}, store timezone: `{$storeTimezone}`) filtering for products with a refund rate of at least {$minRefundRate}%.

Execute the following analytical workflow using the available tools:

1. **Verify Ingestion Status & Completeness:**
   - Call tool `get_sync_status`.
   - Confirm that background sync is completed and data is fresh.

2. **Identify Products with Elevated Refund Rates:**
   - Call tool `find_high_refund_products` with:
     - `from`: "{$startDate}"
     - `to`: "{$endDate}"
     - `sort_by`: "refund_rate"
     - `direction`: "desc"
     - `min_refund_rate`: {$minRefundRate}
     - `limit`: 10
   - Review gross sales, refund amounts, and return rates.

3. **Variant-Level Drill-Down on Top Offending Products:**
   - For the top 3 products with the highest return rates or highest monetary return values:
     - Call tool `get_product_analytics` with:
       - `product_id`: {product.id}
       - `from`: "{$startDate}"
       - `to`: "{$endDate}"
       - `include_variants`: true
       - `include_daily_trend`: true
     - Isolate whether returns are concentrated in a specific SKU, size, or color variant, or if returns are distributed uniformly across all variants.

4. **Compile the Refund Anomaly Audit Report:**
   - **Executive Summary:** Overall return rate assessment, total refund exposure, and risk level.
   - **High-Risk Products Breakdown:** Table showing Title, Vendor, Units Sold, Gross Sales, Refund Amount, and Refund Rate (%).
   - **Variant Return Concentration:** Analysis of specific SKUs driving returns and potential root causes (sizing mismatches, product descriptions, quality defects).
   - **Remediation Action Plan:** Prioritized recommendations for listing updates, supplier reviews, and merchant policy improvements.
PROMPT;

        return Response::text($promptText);
    }
}
