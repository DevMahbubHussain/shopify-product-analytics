<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analytics\Domain\FinancialMath;
use App\Analytics\Services\ProductDetailAnalyticsService;
use App\Mcp\Tools\Concerns\FormatsToolMetadata;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetProductAnalyticsTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'get_product_analytics';

    protected string $title = 'Get Product Analytics';

    public function description(): string
    {
        return 'Get deep-dive sales metrics, preceding period comparison, daily trend, and variant-level breakdown for a single product by UUID or handle.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->description('Internal application product UUID (either product_id or handle is required).'),
            'handle' => $schema->string()->description('Product slug/handle (either product_id or handle is required).'),
            'from' => $schema->string()->format('date')->description('Start date for analytics (YYYY-MM-DD, inclusive) in store timezone.'),
            'to' => $schema->string()->format('date')->description('End date for analytics (YYYY-MM-DD, inclusive) in store timezone.'),
            'include_variants' => $schema->boolean()->description('Whether to include variant-level sales breakdown. Defaults to true.'),
            'include_daily_trend' => $schema->boolean()->description('Whether to include daily time-series sales trend. Defaults to true.'),
            'compare_previous' => $schema->boolean()->description('Whether to calculate preceding period comparison. Defaults to true.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'product' => $schema->object([
                'id' => $schema->string()->description('Internal application product UUID'),
                'title' => $schema->string()->description('Product title'),
                'handle' => $schema->string()->description('Product URL slug/handle'),
                'vendor' => $schema->string()->nullable()->description('Product vendor'),
                'product_type' => $schema->string()->nullable()->description('Product category/type'),
                'status' => $schema->string()->description('Product status: active, draft, or archived'),
                'image_url' => $schema->string()->nullable()->description('Product primary image URL'),
            ]),
            'date_range' => $schema->object([
                'from' => $schema->string(),
                'to' => $schema->string(),
            ]),
            'previous_date_range' => $schema->object([
                'from' => $schema->string(),
                'to' => $schema->string(),
            ])->nullable(),
            'currency' => $schema->string()->description('Store currency code'),
            'summary' => $schema->object([
                'gross_sales' => $schema->string(),
                'discounts' => $schema->string(),
                'refunds' => $schema->string(),
                'net_sales' => $schema->string(),
                'units_sold' => $schema->integer(),
                'orders' => $schema->integer(),
                'average_order_revenue' => $schema->string(),
            ]),
            'comparison' => $schema->object([
                'gross_sales' => $schema->object([
                    'delta' => $schema->string(),
                    'percentage_change' => $schema->string()->nullable(),
                    'direction' => $schema->string(),
                ]),
                'net_sales' => $schema->object([
                    'delta' => $schema->string(),
                    'percentage_change' => $schema->string()->nullable(),
                    'direction' => $schema->string(),
                ]),
                'units_sold' => $schema->object([
                    'delta' => $schema->integer(),
                    'percentage_change' => $schema->string()->nullable(),
                    'direction' => $schema->string(),
                ]),
                'orders' => $schema->object([
                    'delta' => $schema->integer(),
                    'percentage_change' => $schema->string()->nullable(),
                    'direction' => $schema->string(),
                ]),
            ])->nullable(),
            'variants' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Internal application variant UUID'),
                'title' => $schema->string()->description('Variant title'),
                'sku' => $schema->string()->nullable()->description('Variant SKU'),
                'price' => $schema->string()->description('Variant price in store currency'),
                'gross_sales' => $schema->string()->description('Variant gross sales in store currency'),
                'discounts' => $schema->string()->description('Variant discounts in store currency'),
                'refunds' => $schema->string()->description('Variant refunds in store currency'),
                'net_sales' => $schema->string()->description('Variant net sales in store currency'),
                'units_sold' => $schema->integer()->description('Variant units sold minus returns'),
                'orders' => $schema->integer()->description('Variant order count'),
            ])),
            'daily_trend' => $schema->array()->items($schema->object([
                'date' => $schema->string(),
                'gross_sales' => $schema->string(),
                'discounts' => $schema->string(),
                'refunds' => $schema->string(),
                'net_sales' => $schema->string(),
                'units_sold' => $schema->integer(),
                'orders' => $schema->integer(),
            ]))->nullable(),
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
            'product_id' => ['required_without:handle', 'prohibits:handle', 'nullable', 'uuid'],
            'handle' => ['nullable', 'string', 'max:255'],
            'from' => ['required', 'string', 'date_format:Y-m-d'],
            'to' => ['required', 'string', 'date_format:Y-m-d'],
            'include_variants' => ['nullable', 'boolean'],
            'include_daily_trend' => ['nullable', 'boolean'],
            'compare_previous' => ['nullable', 'boolean'],
        ], [
            'product_id.required_without' => "The 'product_id' or 'handle' parameter is required.",
            'product_id.prohibits' => "Cannot provide both 'product_id' and 'handle'. Provide exactly one identifier.",
            'product_id.uuid' => "The 'product_id' parameter must be a valid UUID.",
            'from.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
            'to.date_format' => 'Invalid date format. Expected YYYY-MM-DD for both from and to dates.',
        ]);

        $shop = $this->resolveCurrentShop(request());

        $productId = (string) $request->get('product_id', '');
        $handle = (string) $request->get('handle', '');

        if ($handle !== '') {
            $productRow = DB::table('products')
                ->where('shop_id', $shop->id)
                ->where('handle', $handle)
                ->whereNull('deleted_at')
                ->first(['id']);

            if ($productRow === null) {
                return Response::error('Product not found in this store.');
            }

            $productId = (string) $productRow->id;
        }

        $from = (string) $request->get('from');
        $to = (string) $request->get('to');

        $dateRange = $this->validateDateRange($shop, $from, $to);
        if ($dateRange instanceof Response) {
            return $dateRange;
        }

        [$validFrom, $validTo] = $dateRange;

        $includeVariants = (bool) $request->get('include_variants', true);
        $includeDailyTrend = (bool) $request->get('include_daily_trend', true);
        $comparePrevious = (bool) $request->get('compare_previous', true);

        $service = app(ProductDetailAnalyticsService::class);

        try {
            $analytics = $service->getProductAnalytics($shop, $productId, $validFrom, $validTo);
        } catch (NotFoundHttpException) {
            return Response::error('Product not found in this store.');
        }

        // Platform neutrality: strip raw Shopify GIDs
        $productData = [
            'id' => (string) $analytics['product']['id'],
            'title' => (string) $analytics['product']['title'],
            'handle' => (string) $analytics['product']['handle'],
            'vendor' => $analytics['product']['vendor'] !== null ? (string) $analytics['product']['vendor'] : null,
            'product_type' => $analytics['product']['product_type'] !== null ? (string) $analytics['product']['product_type'] : null,
            'status' => (string) $analytics['product']['status'],
            'image_url' => $analytics['product']['image_url'] !== null ? (string) $analytics['product']['image_url'] : null,
        ];

        $variants = [];
        if ($includeVariants) {
            $variantData = $service->getVariantAnalytics($shop, $productId, $validFrom, $validTo);
            $variants = collect($variantData['variants'])->map(
                fn (array $v): array => [
                    'id' => (string) $v['id'],
                    'title' => (string) $v['title'],
                    'sku' => $v['sku'] !== null ? (string) $v['sku'] : null,
                    'price' => (string) $v['price'],
                    'gross_sales' => (string) $v['gross_sales'],
                    'discounts' => (string) $v['discounts'],
                    'refunds' => (string) $v['refunds'],
                    'net_sales' => (string) $v['net_sales'],
                    'units_sold' => (int) $v['units_sold'],
                    'orders' => (int) $v['orders'],
                ]
            )->all();
        }

        $dailyTrend = null;
        if ($includeDailyTrend) {
            $dailyMetrics = DB::table('product_daily_metrics')
                ->where('shop_id', $shop->id)
                ->where('product_id', $productId)
                ->where('metric_date', '>=', $validFrom)
                ->where('metric_date', '<=', $validTo)
                ->where('currency_code', $shop->currency_code)
                ->get()
                ->keyBy(fn ($r): string => (string) $r->metric_date);

            $start = CarbonImmutable::parse($validFrom);
            $end = CarbonImmutable::parse($validTo);
            $dailyTrend = [];

            for ($cur = $start; $cur->lte($end); $cur = $cur->addDay()) {
                $dateStr = $cur->toDateString();
                $rec = $dailyMetrics->get($dateStr);
                $dailyTrend[] = [
                    'date' => $dateStr,
                    'gross_sales' => FinancialMath::formatDecimal((string) ($rec?->gross_sales ?? '0')),
                    'discounts' => FinancialMath::formatDecimal((string) ($rec?->discounts ?? '0')),
                    'refunds' => FinancialMath::formatDecimal((string) ($rec?->refunds ?? '0')),
                    'net_sales' => FinancialMath::formatDecimal((string) ($rec?->net_sales ?? '0')),
                    'units_sold' => (int) ($rec?->units_sold ?? 0),
                    'orders' => (int) ($rec?->order_count ?? 0),
                ];
            }
        }

        $metadata = $this->buildMetadata($shop, $validFrom, $validTo);

        $payload = [
            'product' => $productData,
            'date_range' => $analytics['date_range'],
            'previous_date_range' => $comparePrevious ? $analytics['previous_date_range'] : null,
            'currency' => $analytics['currency'],
            'summary' => $analytics['summary'],
            'comparison' => $comparePrevious ? $analytics['comparison'] : null,
            'variants' => $variants,
            'daily_trend' => $dailyTrend,
            'metadata' => $metadata,
        ];

        return Response::structured($payload);
    }
}
