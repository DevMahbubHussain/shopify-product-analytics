<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Services\OutboxService;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchIngestedEvents
{
    public function __construct(
        private readonly OutboxService $outboxService,
    ) {}

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        try {
            foreach ($context->events as $event) {
                event($event);
            }

            foreach ($context->outboxIds as $outboxId) {
                $this->outboxService->markPublished($outboxId);
            }
        } catch (Throwable $e) {
            Log::warning("Fast-path outbox dispatch failed; stranded records remain pending for recovery: {$e->getMessage()}", [
                'outbox_ids' => $context->outboxIds,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($context);
    }
}
