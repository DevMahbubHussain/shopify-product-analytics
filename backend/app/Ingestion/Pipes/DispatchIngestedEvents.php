<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use Closure;

final class DispatchIngestedEvents
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        foreach ($context->events as $event) {
            event($event);
        }

        return $next($context);
    }
}
