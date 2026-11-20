<?php

declare(strict_types=1);

use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopAId = installShop('shop-a.myshopify.com');
    $this->shopBId = installShop('shop-b.myshopify.com');
});

it('dispatches aggregation jobs for all distinct product dates across order line items', function (): void {
    Queue::fake();

    $productId = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopAId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'title' => 'Sample Snowboard',
        'handle' => 'sample-snowboard',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid7();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopAId,
        'shopify_order_gid' => 'gid://shopify/Order/100',
        'order_name' => '#100',
        'currency_code' => 'USD',
        'processed_at' => '2026-11-18 10:00:00+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopAId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/1',
        'title_snapshot' => 'Sample Snowboard',
        'quantity' => 2,
        'original_unit_price' => '100.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('analytics:aggregate', ['--shop' => 'shop-a.myshopify.com'])
        ->expectsOutputToContain('Completed metrics aggregation')
        ->assertSuccessful();

    Queue::assertPushed(AggregateProductDailyMetricsJob::class, 1);
    Queue::assertPushed(AggregateProductDailyMetricsJob::class, function (AggregateProductDailyMetricsJob $job) use ($productId): bool {
        return $job->shopId === $this->shopAId
            && $job->productId === $productId
            && $job->metricDate === '2026-11-18';
    });
});

it('supports --now flag to process synchronously without queue', function (): void {
    $productId = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopAId,
        'shopify_product_gid' => 'gid://shopify/Product/1',
        'title' => 'Direct Ski Wax',
        'handle' => 'direct-ski-wax',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderId = (string) Str::uuid7();
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopAId,
        'shopify_order_gid' => 'gid://shopify/Order/101',
        'order_name' => '#101',
        'currency_code' => 'USD',
        'processed_at' => '2026-11-18 10:00:00+00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopAId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/2',
        'title_snapshot' => 'Direct Ski Wax',
        'quantity' => 1,
        'original_unit_price' => '50.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('analytics:aggregate', [
        '--shop' => 'shop-a.myshopify.com',
        '--now' => true,
    ])->assertSuccessful();

    $metric = DB::table('product_daily_metrics')
        ->where('shop_id', $this->shopAId)
        ->where('product_id', $productId)
        ->first();

    expect($metric)->not->toBeNull()
        ->and($metric->net_sales)->toBe('50.000000')
        ->and($metric->units_sold)->toBe(1);
});

it('warns when the requested shop is not found', function (): void {
    $this->artisan('analytics:aggregate', ['--shop' => 'non-existent.myshopify.com'])
        ->expectsOutputToContain('Shop not found')
        ->assertFailed();
});
