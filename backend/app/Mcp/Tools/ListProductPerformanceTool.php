<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Analytics\Domain\FinancialMath;
use App\Analytics\Services\ProductPerformanceService;
use App\Http\Requests\Analytics\ProductListRequest;
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
final class ListProductPerformanceTool extends Tool
{
    use FormatsToolMetadata;

    protected string $name = 'list_product_performance';

    protected string $title = 'List Product Performance';

    public function description(): string
    {
        return 'List and rank store products by sales performance metrics (gross sales, net sales, units sold, orders) for a specified date range with sorting, search, and pagination.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date for product analytics (YYYY-MM-DD, inclusive) in store timezone.'),
            'to' => $schema->string()->format('date')->description('End date for product analytics (YYYY-MM-DD, inclusive) in store timezone.'),
            'sort' => $schema->string()->enum(['net_sales', 'gross_sales', 'units_sold', 'orders', 'title', 'refund_rate', 'refunds'])->description('Sort metric. Defaults to net_sales.'),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction. Defaults to desc.'),
            'search' => $schema->string()->nullable()->description('Search keyword matching product title.'),
            'vendor' => $schema->string()->nullable()->description('Filter products by vendor name.'),
            'product_type' => $schema->string()->nullable()->description('Filter products by category/product_type.'),
            'status' => $schema->string()->enum(['active', 'archived', 'draft'])->nullable()->description('Filter by product status.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Number of products per page (1-50). Defaults to 20.'),
            'page' => $schema->integer()->min(1)->description('Page number for pagination. Defaults to 1.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items(
                $schema->object(
                    [
                        'id' => $schema->string()->description('Internal application product UUID'),
                        'title' => $schema->string()->description('Product title'),
                        'handle' => $schema->string()->description('Product URL slug/handle'),
                        'vendor' => $schema->string()->nullable()->description('Product vendor/brand'),
                        'product_type' => $schema->string()->nullable()->description('Product category/type'),
                        'status' => $schema->string()->description('Product status: active, draft, or archived'),
                        'gross_sales' => $schema->string()->description('Gross sales amount in store currency'),
                        'discounts' => $schema->string()->description('Discounts amount in store currency'),
                        'refunds' => $schema->string()->description('Refunds amount in store currency'),
                        'net_sales' => $schema->string()->description('Net sales amount in store currency'),
                        'units_sold' => $schema->integer()->description('Total units sold minus returns'),
                        'orders' => $schema->integer()->description('Count of orders containing this product'),
                    ]
                )
            ),
            'pagination' => $schema->object(
                [
                    'current_page' => $schema->integer(),
                    'per_page' => $schema->integer(),
                    'total' => $schema->integer(),
                    'last_page' => $schema->integer(),
                    'has_more' => $schema->boolean(),
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
            'sort' => ['nullable', 'string', 'in:net_sales,gross_sales,units_sold,orders,title,refund_rate,refunds'],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'search' => ['nullable', 'string', 'max:255'],
            'vendor' => ['nullable', 'string', 'max:255'],
            'product_type' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:active,archived,draft'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
            'page' => ['nullable', 'integer', 'min:1'],
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

        $sort = (string) $request->get('sort', 'net_sales');
        $direction = (string) $request->get('direction', 'desc');
        $search = $request->get('search');
        $vendor = $request->get('vendor');
        $productType = $request->get('product_type');
        $status = $request->get('status');
        $limit = (int) $request->get('limit', 20);
        $page = (int) $request->get('page', 1);

        $formRequest = ProductListRequest::create(
            '/api/v1/products', 'GET', array_filter(
                [
                    'from' => $validFrom,
                    'to' => $validTo,
                    'sort' => $sort,
                    'direction' => $direction,
                    'search' => is_string($search) ? $search : null,
                    'vendor' => is_string($vendor) ? $vendor : null,
                    'product_type' => is_string($productType) ? $productType : null,
                    'status' => is_string($status) ? $status : null,
                    'per_page' => $limit,
                    'page' => $page,
                ], fn ($val) => $val !== null
            )
        );

        $service = app(ProductPerformanceService::class);
        $paginator = $service->getProducts($shop, $formRequest);

        $items = collect($paginator->items())->map(
            fn (object $row): array => [
                'id' => (string) $row->id,
                'title' => (string) $row->title,
                'handle' => (string) $row->handle,
                'vendor' => $row->vendor !== null ? (string) $row->vendor : null,
                'product_type' => $row->product_type !== null ? (string) $row->product_type : null,
                'status' => (string) $row->status,
                'gross_sales' => FinancialMath::formatDecimal((string) $row->gross_sales),
                'discounts' => FinancialMath::formatDecimal((string) $row->discounts),
                'refunds' => FinancialMath::formatDecimal((string) $row->refunds),
                'net_sales' => FinancialMath::formatDecimal((string) $row->net_sales),
                'units_sold' => (int) $row->units_sold,
                'orders' => (int) $row->orders,
            ]
        )->all();

        $pagination = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'has_more' => $paginator->hasMorePages(),
        ];

        $metadata = $this->buildMetadata($shop, $validFrom, $validTo);

        $payload = [
            'items' => $items,
            'pagination' => $pagination,
            'metadata' => $metadata,
        ];

        return Response::structured($payload);
    }
}
