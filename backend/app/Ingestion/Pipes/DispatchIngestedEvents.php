<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use App\Ingestion\Services\OutboxService;
use Closure;

final class DispatchIngestedEvents
{
    public function __construct(
        private readonly OutboxService $outboxService,
    ) {
    }

    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        foreach ($context->events as $event) {
            event($event);
        }

        foreach ($context->outboxIds as $outboxId) {
            $this->outboxService->markPublished($outboxId);
        }

        return $next($context);
    }
}
