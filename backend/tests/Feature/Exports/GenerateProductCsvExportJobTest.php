<?php

declare(strict_types=1);

use App\Jobs\GenerateProductCsvExportJob;
use App\Models\Export;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Storage::fake('local');
    $this->shopId = installShop('export-store.myshopify.com');
});

it('implements ShouldQueue and defines queue connection', function (): void {
    $job = new GenerateProductCsvExportJob((string) Str::uuid());

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('exports')
        ->and($job->tries)->toBe(3);
});

it('generates a CSV export of product performance for the requested date range', function (): void {
    $productId1 = (string) Str::uuid7();
    $productId2 = (string) Str::uuid7();

    DB::table('products')->insert([
        [
            'id' => $productId1,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/1',
            'title' => 'Alpha T-Shirt',
            'handle' => 'alpha-t-shirt',
            'vendor' => 'Acme Apparel',
            'product_type' => 'Apparel',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $productId2,
            'shop_id' => $this->shopId,
            'shopify_product_gid' => 'gid://shopify/Product/2',
            'title' => 'Beta Hoodie',
            'handle' => 'beta-hoodie',
            'vendor' => 'Acme Apparel',
            'product_type' => 'Apparel',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    // Insert precomputed metrics for product 1
    DB::table('product_daily_metrics')->insert([
        [
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopId,
            'product_id' => $productId1,
            'metric_date' => '2026-11-05',
            'currency_code' => 'USD',
            'gross_sales' => '100.000000',
            'discounts' => '10.000000',
            'refunds' => '0.000000',
            'net_sales' => '90.000000',
            'units_sold' => 3,
            'order_count' => 2,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => (string) Str::uuid7(),
            'shop_id' => $this->shopId,
            'product_id' => $productId2,
            'metric_date' => '2026-11-06',
            'currency_code' => 'USD',
            'gross_sales' => '50.000000',
            'discounts' => '0.000000',
            'refunds' => '5.000000',
            'net_sales' => '45.000000',
            'units_sold' => 1,
            'order_count' => 1,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $exportId = (string) Str::uuid7();
    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'products_2026-11-01_2026-11-10.csv',
        'file_path' => null,
        'row_count' => 0,
        'status' => 'PENDING',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new GenerateProductCsvExportJob($exportId))->handle();

    $export = DB::table('exports')->where('id', $exportId)->first();

    expect($export->status)->toBe('COMPLETED')
        ->and($export->row_count)->toBe(2)
        ->and($export->file_path)->not->toBeNull();

    Storage::disk('local')->assertExists($export->file_path);
    $csvContent = Storage::disk('local')->get($export->file_path);

    expect($csvContent)->toContain('"Product Title",Handle,Vendor,"Product Type","Net Sales","Gross Sales",Discounts,Refunds,"Units Sold",Orders')
        ->and($csvContent)->toContain('"Alpha T-Shirt",alpha-t-shirt,"Acme Apparel",Apparel,90.00,100.00,10.00,0.00,3,2')
        ->and($csvContent)->toContain('"Beta Hoodie",beta-hoodie,"Acme Apparel",Apparel,45.00,50.00,0.00,5.00,1,1');
});

it('enforces tenant isolation and never exports another shop products', function (): void {
    $otherShopId = installShop('other-export.myshopify.com');

    $otherProductId = (string) Str::uuid7();
    DB::table('products')->insert([
        'id' => $otherProductId,
        'shop_id' => $otherShopId,
        'shopify_product_gid' => 'gid://shopify/Product/99',
        'title' => 'Secret Other Product',
        'handle' => 'secret-other',
        'vendor' => 'Other Vendor',
        'product_type' => 'Secret',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('product_daily_metrics')->insert([
        'id' => (string) Str::uuid7(),
        'shop_id' => $otherShopId,
        'product_id' => $otherProductId,
        'metric_date' => '2026-11-05',
        'currency_code' => 'USD',
        'gross_sales' => '999.000000',
        'discounts' => '0.000000',
        'refunds' => '0.000000',
        'net_sales' => '999.000000',
        'units_sold' => 10,
        'order_count' => 10,
        'calculated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $exportId = (string) Str::uuid7();
    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'products_isolated.csv',
        'file_path' => null,
        'row_count' => 0,
        'status' => 'PENDING',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-10',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new GenerateProductCsvExportJob($exportId))->handle();

    $export = DB::table('exports')->where('id', $exportId)->first();
    $csvContent = Storage::disk('local')->get($export->file_path);

    expect($csvContent)->not->toContain('Secret Other Product')
        ->and($export->row_count)->toBe(0);
});
