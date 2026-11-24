<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analytics\Services\OverviewAnalyticsService;
use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetAnalyticsOverviewTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'get_analytics_overview';

    protected string $title = 'Get Analytics Overview';

    public function description(): string
    {
        return 'Get high-level store sales overview metrics (gross sales, net sales, discounts, refunds, units sold, orders, and average order revenue) and preceding period comparison for a given date range.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date for overview analytics (YYYY-MM-DD, inclusive) in store timezone.'),
            'to' => $schema->string()->format('date')->description('End date for overview analytics (YYYY-MM-DD, inclusive) in store timezone.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date_range' => $schema->object(
                [
                    'from' => $schema->string(),
                    'to' => $schema->string(),
                ]
            ),
            'previous_date_range' => $schema->object(
                [
                    'from' => $schema->string(),
                    'to' => $schema->string(),
                ]
            ),
            'currency' => $schema->string()->description('Store currency code'),
            'summary' => $schema->object(
                [
                    'gross_sales' => $schema->string(),
                    'discounts' => $schema->string(),
                    'refunds' => $schema->string(),
                    'net_sales' => $schema->string(),
                    'units_sold' => $schema->integer(),
                    'orders' => $schema->integer(),
                    'average_order_revenue' => $schema->string(),
                ]
            ),
            'comparison' => $schema->object(
                [
                    'gross_sales' => $schema->object(
                        [
                            'delta' => $schema->string(),
                            'percentage_change' => $schema->string()->nullable(),
                            'direction' => $schema->string(),
                        ]
                    ),
                    'net_sales' => $schema->object(
                        [
                            'delta' => $schema->string(),
                            'percentage_change' => $schema->string()->nullable(),
                            'direction' => $schema->string(),
                        ]
                    ),
                    'units_sold' => $schema->object(
                        [
                            'delta' => $schema->integer(),
                            'percentage_change' => $schema->string()->nullable(),
                            'direction' => $schema->string(),
                        ]
                    ),
                    'orders' => $schema->object(
                        [
                            'delta' => $schema->integer(),
                            'percentage_change' => $schema->string()->nullable(),
                            'direction' => $schema->string(),
                        ]
                    ),
                ]
            ),
            'metadata' => $schema->object(
                [
                    'currency_code' => $schema->string(),
                    'timezone' => $schema->string(),
                    'from' => $schema->string(),
                    'to' => $schema->string(),
                    'last_synced_at' => $schema->string()->nullable(),
                    'data_complete' => $schema->boolean(),
                    'plan' => $schema->string(),
                ]
            ),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
        ], [
            'from.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
            'to.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
        ]);

        $shop = $this->resolveCurrentShop(request());

        $from = (string) $request->get('from');
        $to = (string) $request->get('to');

        $dateRange = $this->validateDateRange($shop, $from, $to);
        if ($dateRange instanceof Response) {
            return $dateRange;
        }

        [$validFrom, $validTo] = $dateRange;

        $overviewService = app(OverviewAnalyticsService::class);
        $overview = $overviewService->getOverview($shop, $validFrom, $validTo);
        $metadata = $this->buildMetadata($shop, $validFrom, $validTo);

        $payload = [
            ...$overview,
            'metadata' => $metadata,
        ];

        return Response::structured($payload);
    }
}
