<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SyncRunStatus;
use App\Enums\SyncType;
use App\Models\Shop;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HistoryStartResolver
{
    /** Earliest store-local date with imported data (YYYY-MM-DD), or null when unknown. */
    public function startDate(Shop $shop): ?string
    {
        $timezone = $shop->timezone ?? 'UTC';
        $candidates = array_filter([
            $this->syncWindowStart($shop, $timezone),
            $this->earliestMetricDate($shop),
        ]);

        return $candidates === [] ? null : min($candidates);
    }

    private function syncWindowStart(Shop $shop, string $timezone): ?string
    {
        $syncRun = $this->latestCompletedSync($shop, SyncType::Initial) ?? $this->latestCompletedSync($shop);

        if ($syncRun !== null && is_array($syncRun->cursor_metadata) && ! empty($syncRun->cursor_metadata['window_start'])) {
            try {
                return CarbonImmutable::parse((string) $syncRun->cursor_metadata['window_start'])
                    ->setTimezone($timezone)
                    ->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        if ($shop->installed_at !== null) {
            return CarbonImmutable::instance($shop->installed_at)
                ->setTimezone($timezone)
                ->subDays(60)
                ->toDateString();
        }

        return null;
    }

    private function latestCompletedSync(Shop $shop, ?SyncType $type = null): ?SyncRun
    {
        /** @var SyncRun|null */
        return SyncRun::query()
            ->where('shop_id', $shop->id)
            ->where('status', SyncRunStatus::Completed)
            ->when($type !== null, fn (Builder $query): Builder => $query->where('sync_type', $type))
            ->orderByDesc('completed_at')
            ->first();
    }

    private function earliestMetricDate(Shop $shop): ?string
    {
        $date = DB::table('product_daily_metrics')
            ->where('shop_id', $shop->id)
            ->min('metric_date');

        return $date === null ? null : (string) $date;
    }
}
