<?php

declare(strict_types=1);

use App\Events\OrdersIngested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1), productPayload(2)]])->assertOk();
});

it('inserts orders and line items linked to known products and variants', function (): void {
    Event::fake([OrdersIngested::class]);

    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1, ['line_items' => [lineItemPayload(1, 1, 1), lineItemPayload(1, 2, 2)]])]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    $order = DB::table('orders')->first();
    $line = DB::table('order_line_items')->where('shopify_line_item_gid', 'gid://shopify/LineItem/12')->first();

    expect($order->shop_id)->toBe($this->shopId)
        ->and($order->financial_status)->toBe('PAID')
        ->and(DB::table('order_line_items')->count())->toBe(2)
        ->and($line->product_id)->toBe(DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/2')->value('id'))
        ->and($line->variant_id)->toBe(DB::table('product_variants')->where('shopify_variant_gid', 'gid://shopify/ProductVariant/21')->value('id'))
        ->and($line->original_unit_price)->toBe('19.990000')
        ->and($line->discount_amount)->toBe('1.000000')
        ->and($line->currency_code)->toBe('USD');

    Event::assertDispatched(OrdersIngested::class, fn (OrdersIngested $e): bool => $e->shopId === $this->shopId && $e->orderIds === [$order->id]);
});

it('stores processed_at in UTC regardless of the incoming offset', function (): void {
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1, ['processed_at' => '2026-11-02T23:30:00-05:00'])]])->assertOk();

    expect(DB::selectOne("select to_char(processed_at at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as t from orders")->t)
        ->toBe('2026-11-03 04:30');
});

it('keeps custom line items without a product and unknown product gids as null references', function (): void {
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1, ['line_items' => [
        lineItemPayload(1, 1, 1, ['product_gid' => null, 'variant_gid' => null, 'title' => 'Gift wrap']),
        lineItemPayload(1, 2, 999),
    ]])]])->assertOk();

    expect(DB::table('order_line_items')->whereNull('product_id')->count())->toBe(2)
        ->and(DB::table('order_line_items')->whereNull('variant_id')->count())->toBe(2);
});

it('re-ingesting the same orders creates zero duplicate rows', function (): void {
    $batch = ['orders' => [orderPayload(1), orderPayload(2)]];

    s2sPost('/internal/v1/ingestion/orders', $batch)->assertOk();
    s2sPost('/internal/v1/ingestion/orders', $batch)->assertOk();

    expect(DB::table('orders')->count())->toBe(2)
        ->and(DB::table('order_line_items')->count())->toBe(2);
});

it('skips a stale order update together with its line items', function (): void {
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1, ['financial_status' => 'PARTIALLY_REFUNDED', 'shopify_updated_at' => '2026-11-10T00:00:00Z'])]])->assertOk();

    Event::fake([OrdersIngested::class]);
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1, [
        'financial_status' => 'PAID',
        'shopify_updated_at' => '2026-11-02T00:00:00Z',
        'line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 99])],
    ])]])->assertOk()->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    expect(DB::table('orders')->value('financial_status'))->toBe('PARTIALLY_REFUNDED')
        ->and(DB::table('order_line_items')->value('quantity'))->toBe(2);
    Event::assertNotDispatched(OrdersIngested::class);
});

it('rejects malformed orders', function (array $order, string $field): void {
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$order]])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(DB::table('orders')->count())->toBe(0);
})->with([
    'zero quantity' => [orderPayload(1, ['line_items' => [lineItemPayload(1, 1, 1, ['quantity' => 0])]]), 'orders.0.line_items.0.quantity'],
    'float unit price' => [orderPayload(1, ['line_items' => [lineItemPayload(1, 1, 1, ['original_unit_price' => 19.99])]]), 'orders.0.line_items.0.original_unit_price'],
    'negative discount' => [orderPayload(1, ['line_items' => [lineItemPayload(1, 1, 1, ['discount_amount' => '-1'])]]), 'orders.0.line_items.0.discount_amount'],
    'unknown financial status' => [orderPayload(1, ['financial_status' => 'paid']), 'orders.0.financial_status'],
    'bad currency' => [orderPayload(1, ['currency_code' => 'US']), 'orders.0.currency_code'],
    'line item gid of wrong type' => [orderPayload(1, ['line_items' => [lineItemPayload(1, 1, 1, ['gid' => 'gid://shopify/Product/5'])]]), 'orders.0.line_items.0.gid'],
]);

it('rejects the same line item gid twice in one batch', function (): void {
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [
        orderPayload(1),
        orderPayload(2, ['line_items' => [lineItemPayload(1, 1)]]),
    ]])->assertUnprocessable();
});

it('rejects a batch with more than 250 line items in total', function (): void {
    $orders = array_map(
        fn (int $o): array => orderPayload($o, ['line_items' => array_map(fn (int $l): array => lineItemPayload($o, $l), range(1, 26))]),
        range(1, 10),
    );

    s2sPost('/internal/v1/ingestion/orders', ['orders' => $orders])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['orders' => 'at most 250 line items']);

    expect(DB::table('orders')->count())->toBe(0);
});

it('accumulates line items when one large order arrives in slices with the same header', function (): void {
    $slice = fn (int $from, int $to): array => orderPayload(1, [
        'line_items' => array_map(fn (int $l): array => lineItemPayload(1, $l), range($from, $to)),
    ]);

    foreach ([$slice(1, 250), $slice(251, 500), $slice(501, 600)] as $payload) {
        s2sPost('/internal/v1/ingestion/orders', ['orders' => [$payload]])
            ->assertOk()
            ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);
    }
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [$slice(1, 250)]])->assertOk();

    $orderId = DB::table('orders')->value('id');

    expect(DB::table('orders')->count())->toBe(1)
        ->and(DB::table('order_line_items')->where('order_id', $orderId)->count())->toBe(600)
        ->and(DB::table('order_line_items')->distinct()->count('shopify_line_item_gid'))->toBe(600);
});

it('ingests a 25-order, 250-line batch with a bounded query count', function (): void {
    $orders = array_map(
        fn (int $o): array => orderPayload($o, ['line_items' => array_map(fn (int $l): array => lineItemPayload($o, $l, ($l % 2) + 1), range(1, 10))]),
        range(1, 25),
    );

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    s2sPost('/internal/v1/ingestion/orders', ['orders' => $orders])->assertOk()->assertJson(['written' => 25]);

    expect(DB::table('order_line_items')->count())->toBe(250)
        ->and($queries)->toBeLessThanOrEqual(12);
});
