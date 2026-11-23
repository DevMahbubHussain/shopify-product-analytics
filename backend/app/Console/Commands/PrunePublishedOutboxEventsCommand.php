<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PrunePublishedOutboxEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'outbox:prune
                            {--days=14 : Number of days of published events to retain}
                            {--chunk=500 : Maximum records to delete per chunk}';

    /**
     * @var string
     */
    protected $description = 'Safely prunes published outbox records older than the retention threshold';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $totalPruned = 0;

        do {
            $deleted = DB::table('outbox_events')
                ->where('status', 'published')
                ->where('created_at', '<=', $cutoff)
                ->limit($chunk)
                ->delete();

            $totalPruned += $deleted;
        } while ($deleted === $chunk);

        if ($totalPruned === 0) {
            $this->info('No expired published outbox events found to prune.');

            return self::SUCCESS;
        }

        $this->info("Pruned {$totalPruned} published outbox events.");

        return self::SUCCESS;
    }
}
