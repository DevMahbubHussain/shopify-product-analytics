<?php

declare(strict_types=1);

use App\Jobs\GenerateProductCsvExportJob;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopDomain = 'export-api.myshopify.com';
    $this->shopId = installShop($this->shopDomain);
});

it('rejects product export requests on the free tier with 403 Forbidden', function (): void {
    s2sPost('/api/v1/exports/products', [
        'from' => '2026-11-01',
        'to' => '2026-11-15',
    ], $this->shopDomain)
        ->assertForbidden()
        ->assertJson([
            'error' => 'plan_limit_exceeded',
        ]);
});

it('creates a pending export and dispatches the queue job on pro tier with 202 Accepted', function (): void {
    Queue::fake([GenerateProductCsvExportJob::class]);

    Subscription::query()->create([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/777',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ]);

    $response = s2sPost('/api/v1/exports/products', [
        'from' => '2026-11-01',
        'to' => '2026-11-15',
    ], $this->shopDomain)
        ->assertStatus(202)
        ->assertJsonStructure([
            'data' => [
                'id',
                'status',
                'export_type',
                'file_name',
                'date_from',
                'date_to',
                'row_count',
            ],
        ]);

    $exportId = $response->json('data.id');
    expect($response->json('data.status'))->toBe('PENDING')
        ->and($response->json('data.export_type'))->toBe('products')
        ->and(DB::table('exports')->where('id', $exportId)->where('shop_id', $this->shopId)->exists())->toBeTrue();

    Queue::assertPushed(GenerateProductCsvExportJob::class, function ($job) use ($exportId): bool {
        return $job->exportId === $exportId;
    });
});

it('validates required date parameters with 422', function (): void {
    Subscription::query()->create([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/777',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ]);

    s2sPost('/api/v1/exports/products', [
        'to' => '2026-11-15',
    ], $this->shopDomain)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from');
});

it('returns export status via GET /api/v1/exports/{id}', function (): void {
    $exportId = (string) Str::uuid7();
    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'products_test.csv',
        'file_path' => null,
        'row_count' => 0,
        'status' => 'PENDING',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    s2sGet("/api/v1/exports/{$exportId}", $this->shopDomain)
        ->assertOk()
        ->assertJson([
            'data' => [
                'id' => $exportId,
                'status' => 'PENDING',
                'export_type' => 'products',
                'file_name' => 'products_test.csv',
                'row_count' => 0,
            ],
        ]);
});

it('enforces tenant isolation on export status check returning 404', function (): void {
    $otherShopId = installShop('other-api.myshopify.com');
    $otherExportId = (string) Str::uuid7();

    DB::table('exports')->insert([
        'id' => $otherExportId,
        'shop_id' => $otherShopId,
        'export_type' => 'products',
        'file_name' => 'products_secret.csv',
        'file_path' => null,
        'row_count' => 0,
        'status' => 'PENDING',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    s2sGet("/api/v1/exports/{$otherExportId}", $this->shopDomain)
        ->assertNotFound();
});

it('downloads the completed CSV file via GET /api/v1/exports/{id}/download', function (): void {
    Storage::fake('local');
    $filePath = "exports/{$this->shopId}/products_complete.csv";
    Storage::disk('local')->put($filePath, "Header 1,Header 2\nValue 1,Value 2\n");

    $exportId = (string) Str::uuid7();
    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'products_complete.csv',
        'file_path' => $filePath,
        'row_count' => 1,
        'status' => 'COMPLETED',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = s2sGet("/api/v1/exports/{$exportId}/download", $this->shopDomain);

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->assertHeader('content-disposition', 'attachment; filename=products_complete.csv');

    expect($response->streamedContent())->toContain("Header 1,Header 2\nValue 1,Value 2\n");
});

it('rejects download when export is still pending or processing with 409 Conflict', function (): void {
    $exportId = (string) Str::uuid7();
    DB::table('exports')->insert([
        'id' => $exportId,
        'shop_id' => $this->shopId,
        'export_type' => 'products',
        'file_name' => 'products_in_progress.csv',
        'file_path' => null,
        'row_count' => 0,
        'status' => 'PROCESSING',
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-15',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    s2sGet("/api/v1/exports/{$exportId}/download", $this->shopDomain)
        ->assertStatus(409)
        ->assertJson([
            'error' => 'export_not_ready',
        ]);
});
