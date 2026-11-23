<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ingestion\Services\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class PublishPendingOutboxEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'outbox:publish
                            {--age=30 : Minimum age in seconds for a pending event to be considered stranded}
                            {--limit=100 : Maximum number of events to process in one run}';

    /**
     * @var string
     */
    protected $description = 'Recovers and publishes stranded outbox events to the message queue';

    public function handle(OutboxService $outboxService): int
    {
        $age = max(1, (int) $this->option('age'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = CarbonImmutable::now()->subSeconds($age);

        $pending = $outboxService->getPendingStrandedEvents($cutoff, $limit);

        if ($pending->isEmpty()) {
            $this->info('No stranded outbox events found.');

            return self::SUCCESS;
        }

        $publishedCount = 0;

        foreach ($pending as $record) {
            try {
                $event = $outboxService->reconstitute($record);
                event($event);
                $outboxService->markPublished($record->id);
                $publishedCount++;
            } catch (Throwable $e) {
                $this->error("Failed to publish outbox event [{$record->id}]: {$e->getMessage()}");
            }
        }

        $this->info("Published {$publishedCount} stranded outbox events.");

        return self::SUCCESS;
    }
}
