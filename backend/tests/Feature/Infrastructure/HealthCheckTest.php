<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('returns 200 and healthy status on /healthz when all services are connected', function (): void {
    $response = $this->getJson('/healthz');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'healthy',
            'database' => 'connected',
            'redis' => 'connected',
        ])
        ->assertJsonStructure([
            'status',
            'database',
            'redis',
            'timestamp',
        ]);
});

it('returns 200 and healthy status on /api/v1/health when all services are connected', function (): void {
    $response = $this->getJson('/api/v1/health');

    $response->assertStatus(200)
        ->assertJson([
            'status' => 'healthy',
            'database' => 'connected',
            'redis' => 'connected',
        ]);
});

it('returns 503 and degraded status when database is unreachable', function (): void {
    // DB::partialMock prevents teardown exceptions when Laravel calls getConnections()
    DB::partialMock()
        ->shouldReceive('connection->getPdo')
        ->once()
        ->andThrow(new \PDOException('Database connection refused'));

    $response = $this->getJson('/healthz');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'database' => 'disconnected',
            'redis' => 'connected',
        ]);
});

it('returns 503 and degraded status when redis is unreachable', function (): void {
    Redis::shouldReceive('connection->ping')
        ->once()
        ->andThrow(new \RedisException('Redis server down'));

    $response = $this->getJson('/healthz');

    $response->assertStatus(503)
        ->assertJson([
            'status' => 'degraded',
            'database' => 'connected',
            'redis' => 'disconnected',
        ]);
});
