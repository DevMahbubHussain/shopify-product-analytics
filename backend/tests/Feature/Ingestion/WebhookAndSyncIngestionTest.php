<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopId = installShop();
});

/** @return array<string, string> */
function webhookDelivery(string $webhookId = 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043'): array
{
    return [
        'webhook_id' => $webhookId,
        'topic' => 'orders/create',
        'payload_hash' => hash('sha256', 'raw-body'),
        'received_at' => '2026-11-18T08:00:00Z',
    ];
}

describe('webhook deliveries', function (): void {
    it('records the first delivery', function (): void {
        s2sPost('/internal/v1/ingestion/webhook-events', webhookDelivery())
            ->assertOk()
            ->assertExactJson(['duplicate' => false]);

        $event = DB::table('webhook_events')->first();
        expect($event->shop_id)->toBe($this->shopId)
            ->and($event->status)->toBe('received')
            ->and($event->topic)->toBe('orders/create');
    });

    it('flags a repeated delivery as duplicate without a second row', function (): void {
        s2sPost('/internal/v1/ingestion/webhook-events', webhookDelivery())->assertOk();

        s2sPost('/internal/v1/ingestion/webhook-events', webhookDelivery())
            ->assertOk()
            ->assertExactJson(['duplicate' => true]);

        expect(DB::table('webhook_events')->count())->toBe(1);
    });

    it('treats the same webhook id in another shop as a separate delivery', function (): void {
        installShop('other.myshopify.com');

        s2sPost('/internal/v1/ingestion/webhook-events', webhookDelivery())->assertExactJson(['duplicate' => false]);
        s2sPost('/internal/v1/ingestion/webhook-events', webhookDelivery(), 'other.myshopify.com')->assertExactJson(['duplicate' => false]);
    });

    it('rejects malformed deliveries', function (string $field, mixed $value): void {
        s2sPost('/internal/v1/ingestion/webhook-events', array_merge(webhookDelivery(), [$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        ['payload_hash', 'not-a-hash'],
        ['topic', 'Orders Create'],
        ['webhook_id', ''],
    ]);
});

describe('sync status', function (): void {
    it('creates a sync run and returns its id', function (): void {
        $response = s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running'])->assertCreated();

        $run = DB::table('sync_runs')->first();
        expect($response->json('sync_run_id'))->toBe($run->id)
            ->and($run->shop_id)->toBe($this->shopId)
            ->and($run->status)->toBe('running')
            ->and($run->started_at)->not->toBeNull();
    });

    it('records progress and completion', function (): void {
        $id = s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running'])->json('sync_run_id');

        s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_run_id' => $id, 'status' => 'running', 'records_received' => 50, 'records_processed' => 50,
            'cursor_metadata' => ['orders' => 'eyJsYXN0X2lkIjo1MH0='],
        ])->assertOk();
        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'completed', 'records_received' => 120, 'records_processed' => 120])->assertOk();

        $run = DB::table('sync_runs')->first();
        expect($run->status)->toBe('completed')
            ->and($run->records_processed)->toBe(120)
            ->and($run->completed_at)->not->toBeNull()
            ->and(json_decode($run->cursor_metadata, true))->toBe(['orders' => 'eyJsYXN0X2lkIjo1MH0=']);
    });

    it('rejects transitions out of a terminal state', function (): void {
        $id = s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running'])->json('sync_run_id');
        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'completed'])->assertOk();

        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'running'])
            ->assertConflict()
            ->assertExactJson(['error' => 'Illegal sync status transition.', 'from' => 'completed', 'to' => 'running']);
    });

    it('rejects counters going backwards', function (): void {
        $id = s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running', 'records_received' => 100])->json('sync_run_id');

        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'running', 'records_received' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('records_received');
    });

    it('requires an error code when a run fails', function (): void {
        s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'failed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('error_code');
    });

    it('cannot touch a sync run of another shop', function (): void {
        $id = s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running'])->json('sync_run_id');
        installShop('other.myshopify.com');

        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'completed'], 'other.myshopify.com')
            ->assertNotFound()
            ->assertExactJson(['error' => 'Unknown sync run.']);

        expect(DB::table('sync_runs')->value('status'))->toBe('running');
    });

    it('rejects starting a second sync run while one is already in progress', function (): void {
        s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial',
            'status' => 'running',
        ])->assertCreated();

        s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial',
            'status' => 'running',
        ])->assertConflict()
          ->assertExactJson(['error' => 'A sync run is already in progress for this shop.']);
    });

    it('allows starting a new sync run if previous running run is stalled past 10 minutes', function (): void {
        $oldRunId = s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial',
            'status' => 'running',
        ])->json('sync_run_id');

        DB::table('sync_runs')->where('id', $oldRunId)->update([
            'updated_at' => now()->subMinutes(11),
        ]);

        s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial',
            'status' => 'running',
        ])->assertCreated();

        $oldRun = DB::table('sync_runs')->where('id', $oldRunId)->first();
        expect($oldRun->status)->toBe('failed')
            ->and($oldRun->error_code)->toBe('STALLED');
    });

    it('stores the gateway history notice when a run starts', function (): void {
        s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial', 'status' => 'running', 'history_notice' => 'Order history limited to the last 60 days.',
        ])->assertCreated();

        expect(DB::table('sync_runs')->value('history_notice'))->toBe('Order history limited to the last 60 days.');
    });

    it('keeps the history notice when an update omits it and replaces it when one is sent', function (): void {
        $id = s2sPost('/internal/v1/ingestion/sync-status', [
            'sync_type' => 'initial', 'status' => 'running', 'history_notice' => 'First notice.',
        ])->json('sync_run_id');

        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'running', 'records_received' => 10])->assertOk();
        expect(DB::table('sync_runs')->value('history_notice'))->toBe('First notice.');

        s2sPost('/internal/v1/ingestion/sync-status', ['sync_run_id' => $id, 'status' => 'completed', 'history_notice' => 'Second notice.'])->assertOk();
        expect(DB::table('sync_runs')->value('history_notice'))->toBe('Second notice.');
    });

    it('leaves the history notice null when the gateway never sends one', function (): void {
        s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running'])->assertCreated();

        expect(DB::table('sync_runs')->value('history_notice'))->toBeNull();
    });

    it('rejects an invalid history notice', function (mixed $notice): void {
        s2sPost('/internal/v1/ingestion/sync-status', ['sync_type' => 'initial', 'status' => 'running', 'history_notice' => $notice])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('history_notice');

        expect(DB::table('sync_runs')->count())->toBe(0);
    })->with([
        'too long' => [str_repeat('a', 256)],
        'not a string' => [['nested' => 'array']],
    ]);
});

it('rate limits ingestion per shop', function (): void {
    config()->set('services.s2s.ingestion_per_minute', 2);
    $domain = 'limited-' . Str::lower(Str::random(6)) . '.myshopify.com';

    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'], $domain)->assertOk();
    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'], $domain)->assertOk();
    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'], $domain)->assertTooManyRequests();

    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'], 'unaffected.myshopify.com')->assertOk();
});
