<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analytics\Services\SalesTrendAnalyticsService;
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
final class GetSalesTrendTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'get_sales_trend';

    protected string $title = 'Get Sales Trend';

    public function description(): string
    {
        return 'Get time-series sales trend metrics (gross sales, net sales, discounts, refunds, units sold, orders) grouped by day, week, or month for a specified date range.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date for sales trend (YYYY-MM-DD, inclusive) in store timezone.'),
            'to' => $schema->string()->format('date')->description('End date for sales trend (YYYY-MM-DD, inclusive) in store timezone.'),
            'interval' => $schema->string()->enum(['day', 'week', 'month'])->description('Time-series grouping interval: day, week, or month. Defaults to day.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'interval' => $schema->string()->description('Applied time-series grouping interval'),
            'currency' => $schema->string()->description('Store currency code'),
            'points' => $schema->array()->items(
                $schema->object(
                    [
                        'date' => $schema->string()->description('Bucket date or period identifier (YYYY-MM-DD, YYYY-Www, or YYYY-MM)'),
                        'gross_sales' => $schema->string()->description('Gross sales amount in store currency'),
                        'discounts' => $schema->string()->description('Discounts amount in store currency'),
                        'refunds' => $schema->string()->description('Refunds amount in store currency'),
                        'net_sales' => $schema->string()->description('Net sales amount in store currency'),
                        'units_sold' => $schema->integer()->description('Units sold minus returns for this period'),
                        'orders' => $schema->integer()->description('Order count for this period'),
                    ]
                )
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
            'interval' => ['nullable', 'string', 'in:day,week,month'],
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

        $interval = strtolower((string) $request->get('interval', 'day'));

        $service = app(SalesTrendAnalyticsService::class);
        $trend = $service->getSalesTrend($shop, $validFrom, $validTo, $interval);
        $metadata = $this->buildMetadata($shop, $validFrom, $validTo);

        $payload = [
            ...$trend,
            'metadata' => $metadata,
        ];

        return Response::structured($payload);
    }
}
