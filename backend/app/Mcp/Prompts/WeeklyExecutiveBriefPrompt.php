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

#[Name('weekly_executive_brief')]
#[Title('Weekly Executive Brief')]
#[Description('Draft an executive weekly performance brief analyzing revenue KPIs, preceding period deltas, and top/bottom products.')]
final class WeeklyExecutiveBriefPrompt extends Prompt
{
    use FormatsToolMetadata;

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'end_date',
                description: 'End date for the weekly brief (YYYY-MM-DD, inclusive, defaults to store yesterday).',
                required: false,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $shop = $this->resolveCurrentShop(request());
        $storeTimezone = $shop->timezone ?? 'UTC';
        $today = CarbonImmutable::now($storeTimezone)->toDateString();

        $request->validate([
            'end_date' => ['nullable', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ]);

        $endDateParam = $request->get('end_date');
        $endDate = is_string($endDateParam) && $endDateParam !== ''
            ? $endDateParam
            : CarbonImmutable::yesterday($storeTimezone)->toDateString();

        $startDate = CarbonImmutable::parse($endDate, $storeTimezone)->subDays(6)->toDateString();

        $promptText = <<<PROMPT
Please generate an executive weekly product performance brief for this store covering the 7-day period from `{$startDate}` to `{$endDate}` (inclusive, store timezone: `{$storeTimezone}`).

Execute the following analytical workflow using the available tools:

1. **Verify Ingestion Freshness & Sync Integrity:**
   - Call tool `get_sync_status`.
   - Confirm whether `data_complete` is true and check `last_synced_at`. If sync is pending or incomplete, prominently highlight data caveats.

2. **Retrieve High-Level Store KPIs & Growth Deltas:**
   - Call tool `get_analytics_overview` with:
     - `from`: "{$startDate}"
     - `to`: "{$endDate}"
   - Analyze Gross Sales, Net Sales, Discounts, Refunds, and Average Order Revenue compared against the immediately preceding 7-day period.

3. **Identify Product Winners and Underperformers:**
   - Call tool `list_product_performance` for top volume/revenue drivers:
     - `from`: "{$startDate}", `to`: "{$endDate}", `sort`: "net_sales", `direction`: "desc", `limit`: 5
   - Call tool `list_product_performance` for lowest performers:
     - `from`: "{$startDate}", `to`: "{$endDate}", `sort`: "net_sales", `direction`: "asc", `limit`: 5

4. **Structure the Executive Briefing:**
   - **Executive Summary:** Bulleted overview of revenue momentum and period-over-period percentage growth.
   - **Financial Health & KPI Breakdown:** Table or concise bullets of Gross vs Net Sales, Discount Rate, Return Volume, and AOV.
   - **Top 5 Performers vs Bottom 5 Underperformers:** Key SKU/product names, net sales, and units sold.
   - **Strategic Recommendations:** Actionable merchandising and inventory guidance based on performance data.
   - **Data Completeness Notice:** Explicitly state if any data was incomplete or if transactions fall prior to available sync history.
PROMPT;

        return Response::text($promptText);
    }
}
