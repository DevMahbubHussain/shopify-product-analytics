<?php

declare(strict_types=1);

namespace App\Ingestion\Pipes;

use App\Ingestion\IngestionContext;
use App\Ingestion\IngestionResponse;
use Closure;
use Illuminate\Support\Facades\DB;

final class DispatchIngestedEvents
{
    public function handle(IngestionContext $context, Closure $next): IngestionResponse
    {
        foreach ($context->events as $event) {
            event($event);

            DB::table('outbox_events')
                ->where('shop_id', $context->shop()->id)
                ->where('event_class', get_class($event))
                ->where('status', 'pending')
                ->update([
                    'status' => 'published',
                    'dispatched_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $next($context);
    }
}
