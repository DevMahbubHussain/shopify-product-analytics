<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use App\Jobs\AggregateProductDailyMetricsJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('implements ShouldBeUniqueUntilProcessing to allow dispatches while processing', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe("{$shopId}:{$productId}:{$metricDate}");
});

it('configures WithoutOverlapping with 120s expiration and 10s release delay', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);

    /** @var WithoutOverlapping $withoutOverlapping */
    $withoutOverlapping = $middleware[0];

    // Lock TTL must exceed worker timeout (90s in docker-compose.yml) to prevent split-brain execution
    expect($withoutOverlapping->expiresAfter)->toBe(120)
        // Overlapping jobs must be delayed and re-queued rather than dropped or failing
        ->and($withoutOverlapping->releaseAfter)->toBe(10);
});

it('defers execution when WithoutOverlapping lock is held and executes when released', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);
    /** @var WithoutOverlapping $middleware */
    $middleware = $job->middleware()[0];

    $lockKey = $middleware->getLockKey($job);

    // Manually acquire the lock to simulate Worker 1 currently running Job 1
    $lock = Cache::lock($lockKey, 120);
    expect($lock->get())->toBeTrue();

    // Create a mock queue job to observe release(10) when lock is held
    $mockJob = test()->mock(Job::class);
    $mockJob->shouldReceive('release')->once()->with(10);
    $job->setJob($mockJob);

    $executed = false;
    $middleware->handle($job, function () use (&$executed) {
        $executed = true;
    });

    // When lock is held, the pipeline should NOT execute the job handler; it must release with delay
    expect($executed)->toBeFalse();

    // Release the simulated lock (Worker 1 finishes Job 1)
    $lock->release();

    // Now delayed retry or Worker 2 runs: lock is free, so $next is called
    $executedAfterRelease = false;
    $middleware->handle($job, function () use (&$executedAfterRelease) {
        $executedAfterRelease = true;
    });

    expect($executedAfterRelease)->toBeTrue();
});

it('converges to correct aggregate when new orders arrive during execution sequence', function () {
    $shopId = (string) Str::uuid();
    $currency = 'USD';
    $timezone = 'America/New_York';

    DB::table('shops')->insert([
        'id' => $shopId,
        'shop_domain' => 'concurrency-shop.myshopify.com',
        'currency_code' => $currency,
        'timezone' => $timezone,
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = (string) Str::uuid();
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/888111',
        'title' => 'Concurrency Product',
        'handle' => 'concurrency-product',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $variantId = (string) Str::uuid();
    DB::table('product_variants')->insert([
        'id' => $variantId,
        'shop_id' => $shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/999111',
        'title' => 'Default Title',
        'price' => '100.000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Order 1 arrives at noon NYC time on 2026-05-15 (1 item at $100.00, $0 discount)
    $order1Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order1Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/101',
        'order_name' => '#1001',
        'processed_at' => CarbonImmutable::parse('2026-05-15 12:00:00', $timezone)->utc(),
        'currency_code' => $currency,
        'financial_status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $order1Id,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/1001',
        'product_id' => $productId,
        'variant_id' => $variantId,
        'title_snapshot' => 'Concurrency Product',
        'quantity' => 1,
        'original_unit_price' => '100.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Job 1 runs for 2026-05-15
    $job1 = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');
    $job1->handle();

    // Verify initial state after Job 1: $100.00 gross sales, 1 order
    $initialRow = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($initialRow)->not->toBeNull()
        ->and((float) $initialRow->gross_sales)->toEqualWithDelta(100.00, 0.001)
        ->and($initialRow->order_count)->toBe(1);

    // Now Order 2 arrives for the same day (1 item at $50.00, $0 discount)
    $order2Id = (string) Str::uuid();
    DB::table('orders')->insert([
        'id' => $order2Id,
        'shop_id' => $shopId,
        'shopify_order_gid' => 'gid://shopify/Order/102',
        'order_name' => '#1002',
        'processed_at' => CarbonImmutable::parse('2026-05-15 15:00:00', $timezone)->utc(),
        'currency_code' => $currency,
        'financial_status' => 'paid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => $shopId,
        'order_id' => $order2Id,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/1002',
        'product_id' => $productId,
        'variant_id' => $variantId,
        'title_snapshot' => 'Concurrency Product',
        'quantity' => 1,
        'original_unit_price' => '50.000000',
        'discount_amount' => '0.000000',
        'currency_code' => $currency,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Job 2 runs for the same date
    $job2 = new AggregateProductDailyMetricsJob($shopId, $productId, '2026-05-15');
    $job2->handle();

    // Verify updated state after Job 2: $150.00 gross sales, 2 orders (no lost updates)
    $finalRow = DB::table('product_daily_metrics')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('metric_date', '2026-05-15')
        ->first();

    expect($finalRow)->not->toBeNull()
        ->and((float) $finalRow->gross_sales)->toEqualWithDelta(150.00, 0.001)
        ->and($finalRow->order_count)->toBe(2);
});
