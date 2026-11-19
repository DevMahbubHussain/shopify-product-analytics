<?php

declare(strict_types=1);

use App\Jobs\AggregateProductDailyMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('enforces retry parameters, exponential backoff, and redis connection on aggregation job', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);

    // C9.3: Queued jobs must define explicit $tries = 5, exponential backoff [5, 15, 60]
    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([5, 15, 60])
        ->and($job->uniqueFor)->toBe(300)
        ->and($job->connection)->toBe('redis')
        ->and($job->queue)->toBe('default');
});

it('logs structured error details when aggregation job fails permanently', function () {
    Log::spy();

    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);
    $exception = new RuntimeException('PostgreSQL lock acquisition timeout');

    $job->failed($exception);

    Log::shouldHaveReceived('error')
        ->once()
        ->with('AggregateProductDailyMetricsJob failed permanently', [
            'shop_id' => $shopId,
            'product_id' => $productId,
            'metric_date' => $metricDate,
            'error' => 'PostgreSQL lock acquisition timeout',
        ]);
});

it('records unrecoverable failed jobs in the postgres failed_jobs table', function () {
    $shopId = (string) Str::uuid();
    $productId = (string) Str::uuid();
    $metricDate = '2026-05-15';

    $job = new AggregateProductDailyMetricsJob($shopId, $productId, $metricDate);

    /** @var FailedJobProviderInterface $failer */
    $failer = app('queue.failer');

    $payload = json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => AggregateProductDailyMetricsJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => AggregateProductDailyMetricsJob::class,
            'command' => serialize($job),
        ],
    ], JSON_THROW_ON_ERROR);

    $exception = new Exception('Deadlock detected during batch upsert');

    $failerId = $failer->log('redis', 'default', $payload, $exception);

    expect($failerId)->not->toBeNull();

    $failedRecord = DB::table('failed_jobs')->where('connection', 'redis')->first();

    expect($failedRecord)->not->toBeNull()
        ->and($failedRecord->queue)->toBe('default')
        ->and($failedRecord->payload)->toContain('AggregateProductDailyMetricsJob')
        ->and($failedRecord->exception)->toContain('Deadlock detected during batch upsert');
});
