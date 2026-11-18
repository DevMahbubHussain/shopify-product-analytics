<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

it('runs against a dedicated PostgreSQL test database', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('shopify_analytics_test')
        ->and(DB::selectOne('select current_database() as name')->name)->toBe('shopify_analytics_test');
});

it('applies migrations inside the test database', function (): void {
    expect(DB::selectOne("select to_regclass('public.migrations') as t")->t)->toBe('migrations');
});

it('isolates redis cache keys from the development databases and prefixes', function (): void {
    expect((int) config('database.redis.default.database'))->toBe(2)
        ->and((int) config('database.redis.cache.database'))->toBe(3)
        ->and(config('database.redis.options.prefix'))->toBe('spa-test-database-')
        ->and(config('cache.prefix'))->toBe('spa-test-cache-');
});

it('writes redis cache entries into the isolated test database', function (): void {
    $key = 'isolation-probe:' . Str::uuid();

    Cache::store('redis')->put($key, 1, 30);

    $client = Redis::connection('cache')->client();

    expect((int) $client->getDbNum())->toBe(3)
        ->and((int) $client->rawCommand('EXISTS', 'spa-test-database-spa-test-cache-' . $key))->toBe(1);

    Cache::store('redis')->forget($key);
});
