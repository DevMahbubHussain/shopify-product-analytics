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
    public function record(string $shopId, object $event): string
    {
        $id = (string) Str::uuid7();
        $eventClass = get_class($event);
        $payload = $this->serializeEvent($event);

        DB::table('outbox_events')->insert([
            'id' => $id,
            'shop_id' => $shopId,
            'event_class' => $eventClass,
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
            ->update([
                'status' => 'published',
                'dispatched_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Retrieves stranded pending events that were not published within the expected cutoff.
     *
     * @return Collection<int, object>
     */
    public function getPendingStrandedEvents(DateTimeInterface $cutoff, int $limit = 100): Collection
    {
        return DB::table('outbox_events')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    /**
     * Reconstitutes a strongly-typed domain event instance from an outbox database record.
     */
    public function reconstitute(object $outboxRecord): object
    {
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
