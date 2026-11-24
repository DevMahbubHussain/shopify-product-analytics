<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analytics\Services\ProductPerformanceService;
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
final class FindHighRefundProductsTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'find_high_refund_products';

    protected string $title = 'Find High Refund Products';

    public function description(): string
    {
        return 'Find and rank products with high refund amounts or elevated refund rates for a specified date range to identify product quality or customer expectation issues.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date for refund analysis (YYYY-MM-DD, inclusive) in store timezone.'),
            'to' => $schema->string()->format('date')->description('End date for refund analysis (YYYY-MM-DD, inclusive) in store timezone.'),
            'sort_by' => $schema->string()->enum(['refund_rate', 'refund_amount'])->description('Sort metric: refund_rate or refund_amount. Defaults to refund_rate.'),
            'direction' => $schema->string()->enum(['desc', 'asc'])->description('Sort direction: desc or asc. Defaults to desc.'),
            'min_refund_rate' => $schema->number()->min(0)->max(100)->nullable()->description('Minimum refund rate percentage threshold (0-100).'),
            'min_refund_amount' => $schema->string()->nullable()->description('Minimum monetary refund amount threshold in store currency.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum number of products to return (1-50). Defaults to 20.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Internal application product UUID'),
                'title' => $schema->string()->description('Product title'),
                'handle' => $schema->string()->description('Product slug/handle'),
                'vendor' => $schema->string()->nullable()->description('Product vendor'),
                'product_type' => $schema->string()->nullable()->description('Product category/type'),
                'status' => $schema->string()->description('Product status: active, draft, or archived'),
                'gross_sales' => $schema->string()->description('Gross sales amount in store currency'),
                'refund_amount' => $schema->string()->description('Total refunds amount in store currency'),
                'net_sales' => $schema->string()->description('Net sales amount in store currency'),
                'units_sold' => $schema->integer()->description('Net units sold minus returns'),
                'refund_rate' => $schema->string()->description('Refund rate percentage formatted as decimal string (refunds / gross_sales * 100)'),
            ])),
            'metadata' => $schema->object([
                'currency_code' => $schema->string(),
                'timezone' => $schema->string(),
                'from' => $schema->string(),
                'to' => $schema->string(),
                'last_synced_at' => $schema->string()->nullable(),
                'data_complete' => $schema->boolean(),
                'plan' => $schema->string(),
            ]),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
            'sort_by' => ['nullable', 'string', 'in:refund_rate,refund_amount'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'min_refund_rate' => ['nullable', 'numeric', 'between:0,100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'min_refund_amount' => ['nullable', 'regex:/^\d+(\.\d{1,6})?$/'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ], [
            'from.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
            'to.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
            'min_refund_amount.regex' => 'The min refund amount field must be a valid non-negative number with up to 6 decimal places.',
            'min_refund_rate.regex' => 'The min refund rate field must be a valid number with up to 2 decimal places.',
        ]);

        $shop = $this->resolveCurrentShop(request());

        $from = (string) $request->get('from');
        $to = (string) $request->get('to');

        $dateRange = $this->validateDateRange($shop, $from, $to);
        if ($dateRange instanceof Response) {
            return $dateRange;
        }

        [$validFrom, $validTo] = $dateRange;

        $sortBy = (string) $request->get('sort_by', 'refund_rate');
        $direction = strtolower((string) $request->get('direction', 'desc'));
        $minRefundRate = $request->get('min_refund_rate');
        $minRefundAmount = $request->get('min_refund_amount') !== null ? (string) $request->get('min_refund_amount') : null;
        $limit = (int) $request->get('limit', 20);

        $service = app(ProductPerformanceService::class);
        $items = $service->getHighRefundProducts(
            shop: $shop,
            from: $validFrom,
            to: $validTo,
            sortBy: $sortBy,
            direction: $direction,
            minRefundRatePct: $minRefundRate,
            minRefundAmount: $minRefundAmount,
            limit: $limit,
        );

        $metadata = $this->buildMetadata($shop, $validFrom, $validTo);

        return Response::structured([
            'items' => $items,
            'metadata' => $metadata,
        ]);
    }
}
