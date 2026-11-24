<?php

declare(strict_types=1);

use App\Events\TenantPurgeRequested;
use App\Services\TenantPurgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
    $this->shopDomain = 'redact-store.myshopify.com';
    $this->shopId = installShop($this->shopDomain);
});

it('completely purges all tenant records from database and storage when shop-redact is received', function (): void {
    $productId = (string) Str::uuid7();
    $orderId = (string) Str::uuid7();
    $exportId = (string) Str::uuid7();

    // 1. Products & Variants
    DB::table('products')->insert([
        'id' => $productId,
        'shop_id' => $this->shopId,
        'shopify_product_gid' => 'gid://shopify/Product/100',
        'title' => 'Purge Product',
        'handle' => 'purge-product',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_variants')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => 'gid://shopify/ProductVariant/1001',
        'title' => 'Default',
        'price' => '10.000000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Orders & Line Items
    DB::table('orders')->insert([
        'id' => $orderId,
        'shop_id' => $this->shopId,
        'shopify_order_gid' => 'gid://shopify/Order/200',
        'order_name' => '#1001',
        'financial_status' => 'PAID',
        'fulfillment_status' => 'FULFILLED',
        'currency_code' => 'USD',
        'processed_at' => now(),
        'shopify_updated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('order_line_items')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'order_id' => $orderId,
        'product_id' => $productId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/300',
        'title_snapshot' => 'Purge Product',
        'sku_snapshot' => 'SKU-PURGE',
        'quantity' => 1,
        'original_unit_price' => '10.000000',
        'discount_amount' => '0.000000',
        'currency_code' => 'USD',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. Daily Metrics
    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'product_id' => $productId,
        'metric_date' => '2026-11-01',
        'currency_code' => 'USD',
        'gross_sales' => '10.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '10.000000',
        'units_sold' => 1,
        'order_count' => 1,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 4. Sync Runs
    DB::table('sync_runs')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'sync_type' => 'HISTORICAL',
        'status' => 'COMPLETED',
        'started_at' => now(),
        'completed_at' => now(),
        'records_received' => 1,
        'records_processed' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 5. Subscriptions
    DB::table('subscriptions')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/400',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 6. Exports & Storage file
    $filePath = "exports/{$this->shopId}/test_export.csv";
    Storage::disk('local')->put($filePath, "test,content\n");

    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'test_export.csv',
        'file_path' => $filePath,
        'row_count' => 1,
        'status' => 'COMPLETED',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    s2sPost('/internal/v1/ingestion/shop-redact', [
        'shop_domain' => $this->shopDomain,
    ], $this->shopDomain)
        ->assertOk()
        ->assertJson([
            'status' => 'purged',
            'shop_domain' => $this->shopDomain,
        ]);

    expect(DB::table('shops')->where('id', $this->shopId)->exists())->toBeFalse()
        ->and(DB::table('products')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('product_variants')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('orders')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('order_line_items')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('product_daily_metrics')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('sync_runs')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('subscriptions')->where('shop_id', $this->shopId)->count())->toBe(0)
        ->and(DB::table('exports')->where('shop_id', $this->shopId)->count())->toBe(0);

    Storage::disk('local')->assertMissing($filePath);
});

it('enforces tenant isolation and leaves other shops data intact during purge', function (): void {
    $otherShopDomain = 'other-remain.myshopify.com';
    $otherShopId = installShop($otherShopDomain);

    DB::table('products')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $otherShopId,
        'shopify_product_gid' => 'gid://shopify/Product/999',
        'title' => 'Keep Product',
        'handle' => 'keep-product',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    s2sPost('/internal/v1/ingestion/shop-redact', [
        'shop_domain' => $this->shopDomain,
    ], $this->shopDomain)->assertOk();

    expect(DB::table('shops')->where('id', $otherShopId)->exists())->toBeTrue()
        ->and(DB::table('products')->where('shop_id', $otherShopId)->count())->toBe(1);
});

it('is idempotent when purging an already deleted or unknown shop', function (): void {
    s2sPost('/internal/v1/ingestion/shop-redact', [
        'shop_domain' => 'unknown-shop.myshopify.com',
    ], 'unknown-shop.myshopify.com')
        ->assertOk()
        ->assertJson([
            'status' => 'purged',
            'shop_domain' => 'unknown-shop.myshopify.com',
        ]);
});

it('requires a valid S2S signature', function (): void {
    $this->postJson('/internal/v1/ingestion/shop-redact', [
        'shop_domain' => $this->shopDomain,
    ])->assertUnauthorized();
});

function insertRedactProduct(string $shopId): void
{
    DB::table('products')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $shopId,
        'shopify_product_gid' => 'gid://shopify/Product/500',
        'title' => 'Event Product',
        'handle' => 'event-product',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('dispatches TenantPurgeRequested once while tenant rows still exist', function (): void {
    insertRedactProduct($this->shopId);
    $seen = [];

    Event::listen(TenantPurgeRequested::class, function (TenantPurgeRequested $event) use (&$seen): void {
        $seen[] = [
            'shop_id' => $event->shopId,
            'shop_exists' => DB::table('shops')->where('id', $event->shopId)->exists(),
            'products' => DB::table('products')->where('shop_id', $event->shopId)->count(),
        ];
    });

    s2sPost('/internal/v1/ingestion/shop-redact', ['shop_domain' => $this->shopDomain], $this->shopDomain)->assertOk();

    expect($seen)->toBe([['shop_id' => $this->shopId, 'shop_exists' => true, 'products' => 1]])
        ->and(DB::table('shops')->where('id', $this->shopId)->exists())->toBeFalse();
});

it('does not dispatch TenantPurgeRequested for an unknown or already purged shop', function (): void {
    s2sPost('/internal/v1/ingestion/shop-redact', ['shop_domain' => $this->shopDomain], $this->shopDomain)->assertOk();

    Event::fake([TenantPurgeRequested::class]);

    s2sPost('/internal/v1/ingestion/shop-redact', ['shop_domain' => $this->shopDomain], $this->shopDomain)->assertOk();
    s2sPost('/internal/v1/ingestion/shop-redact', ['shop_domain' => 'unknown-shop.myshopify.com'], 'unknown-shop.myshopify.com')->assertOk();

    Event::assertNotDispatched(TenantPurgeRequested::class);
});

it('rolls back the whole purge when a TenantPurgeRequested listener fails', function (): void {
    insertRedactProduct($this->shopId);
    $filePath = "exports/{$this->shopId}/keep.csv";
    Storage::disk('local')->put($filePath, "a,b\n");

    Event::listen(TenantPurgeRequested::class, function (): void {
        throw new RuntimeException('listener failed');
    });

    expect(fn () => app(TenantPurgeService::class)->purgeByDomain($this->shopDomain))
        ->toThrow(RuntimeException::class, 'listener failed');

    expect(DB::table('shops')->where('id', $this->shopId)->exists())->toBeTrue()
        ->and(DB::table('products')->where('shop_id', $this->shopId)->count())->toBe(1);

    Storage::disk('local')->assertExists($filePath);
});
