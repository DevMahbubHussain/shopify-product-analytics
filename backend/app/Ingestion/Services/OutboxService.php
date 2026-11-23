<?php

declare(strict_types=1);

namespace App\Ingestion\Services;

use App\Events\OrdersIngested;
use App\Events\RefundsIngested;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OutboxService
{
    /**
     * Records a domain event inside the outbox table.
     * Can be invoked inside an active DB transaction to guarantee atomicity.
     */
    public function record(string $shopId, object $event, int $eventVersion = 1): string
    {
        $id = (string) Str::uuid7();
        $eventClass = get_class($event);
        $payload = $this->serializeEvent($event);

        DB::table('outbox_events')->insert([
            'id' => $id,
            'shop_id' => $shopId,
            'event_class' => $eventClass,
            'event_version' => $eventVersion,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'dispatched_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Marks an outbox event as successfully published to the message queue.
     */
    public function markPublished(string $outboxId): void
    {
        DB::table('outbox_events')
            ->where('id', $outboxId)
            ->where('status', 'pending')
            ->update([
                'status' => 'published',
                'dispatched_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Marks an unrecoverable outbox event as failed so it does not block future runs.
     */
    public function markFailed(string $outboxId, string $error = 'Failed permanently'): void
    {
        $this->markPermanentFailure($outboxId, $error);
    }

    /**
     * Marks an unrecoverable outbox event as failed immediately with error audit.
     */
    public function markPermanentFailure(string $outboxId, string $error): void
    {
        DB::table('outbox_events')
            ->where('id', $outboxId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'last_error' => Str::limit($error, 1000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Records a failed dispatch attempt with exponential backoff, failing only when maxAttempts is reached.
     */
    public function markAttemptFailed(string $outboxId, string $error, int $maxAttempts = 5): void
    {
        $record = DB::table('outbox_events')->where('id', $outboxId)->first(['id', 'attempts', 'status']);
        if ($record === null || $record->status !== 'pending') {
            return;
        }

        $newAttempts = (int) $record->attempts + 1;
        $isExhausted = $newAttempts >= $maxAttempts;
        $backoffSeconds = (int) (pow(2, min($newAttempts, 6)) * 5);

        DB::table('outbox_events')
            ->where('id', $outboxId)
            ->update([
                'attempts' => $newAttempts,
                'status' => $isExhausted ? 'failed' : 'pending',
                'retry_after' => $isExhausted ? null : now()->addSeconds($backoffSeconds),
                'last_error' => Str::limit($error, 1000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Retrieves stranded pending events that were not published within the expected cutoff
     * and whose retry_after backoff threshold (if any) has elapsed.
     * When $lock is true, acquires row locks using FOR UPDATE SKIP LOCKED for concurrent worker safety.
     *
     * @return Collection<int, object>
     */
    public function getPendingStrandedEvents(
        DateTimeInterface $cutoff,
        int $limit = 100,
        bool $lock = false
    ): Collection {
        $now = now();
        $query = DB::table('outbox_events')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->where(function ($q) use ($now): void {
                $q->whereNull('retry_after')
                    ->orWhere('retry_after', '<=', $now);
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit);

        if ($lock) {
            $query->lock('FOR UPDATE SKIP LOCKED');
        }

        return $query->get();
    }

    /**
     * Reconstitutes a strongly-typed domain event instance from an outbox database record.
     */
    public function reconstitute(object $outboxRecord): object
    {
        $version = (int) ($outboxRecord->event_version ?? 1);
        if ($version !== 1) {
            throw new InvalidArgumentException("Unsupported outbox event version: {$version}");
        }

        $payload = is_string($outboxRecord->payload)
            ? json_decode($outboxRecord->payload, true, 512, JSON_THROW_ON_ERROR)
            : (array) $outboxRecord->payload;

        $shopId = (string) ($payload['shop_id'] ?? $outboxRecord->shop_id);
        $affectedBuckets = (array) ($payload['affected_buckets'] ?? []);

        return match ($outboxRecord->event_class) {
            OrdersIngested::class => new OrdersIngested(
                $shopId,
                (array) ($payload['order_ids'] ?? []),
                $affectedBuckets
            ),
            RefundsIngested::class => new RefundsIngested(
                $shopId,
                (array) ($payload['order_ids'] ?? ($payload['affected_order_ids'] ?? [])),
                $affectedBuckets
            ),
            default => throw new InvalidArgumentException("Unsupported outbox event class: {$outboxRecord->event_class}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEvent(object $event): array
    {
        if ($event instanceof OrdersIngested) {
            return [
                'shop_id' => $event->shopId,
                'order_ids' => $event->orderIds,
                'affected_buckets' => $event->affectedBuckets,
            ];
        }

        if ($event instanceof RefundsIngested) {
            return [
                'shop_id' => $event->shopId,
                'order_ids' => $event->orderIds,
                'affected_buckets' => $event->affectedBuckets,
            ];
        }

        throw new InvalidArgumentException('Unsupported event class for outbox serialization: ' . get_class($event));
    }
}
