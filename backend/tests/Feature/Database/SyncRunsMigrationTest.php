<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('sync_runs table has expected schema definition and never stores tokens', function (): void {
    expect(Schema::hasTable('sync_runs'))->toBeTrue();

    $expectedColumns = [
        'id',
        'shop_id',
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'cursor_metadata',
        'records_received',
        'records_processed',
        'error_code',
        'error_summary',
        'created_at',
        'updated_at',
    ];

    foreach ($expectedColumns as $column) {
        expect(Schema::hasColumn('sync_runs', $column))->toBeTrue("Missing expected column [{$column}] on sync_runs table.");
    }

    $forbiddenColumns = ['access_token', 'token', 'shopify_token', 'secret'];
    foreach ($forbiddenColumns as $forbidden) {
        expect(Schema::hasColumn('sync_runs', $forbidden))->toBeFalse("Security breach: column [{$forbidden}] must not exist.");
    }
});

test('sync_runs enforces foreign key on shop_id', function (): void {
    expect(fn () => DB::table('sync_runs')->insert([
        'id' => (string) Str::uuid(),
        'shop_id' => (string) Str::uuid(), // non-existent shop
        'sync_type' => 'initial',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class, 'SQLSTATE[23503]');
});
