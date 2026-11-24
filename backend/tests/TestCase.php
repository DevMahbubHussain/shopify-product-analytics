<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestRedis();
    }

    /** Throttle counters (throttleWithRedis) live on the default connection and would otherwise leak between tests and runs. */
    private function flushTestRedis(): void
    {
        $isTestDatabase = (int) config('database.redis.default.database') === 2
            && config('database.redis.options.prefix') === 'spa-test-database-';

        if (! $isTestDatabase) {
            throw new RuntimeException('Refusing to flush a non-test Redis database.');
        }

        Redis::connection()->flushdb();
    }
}
