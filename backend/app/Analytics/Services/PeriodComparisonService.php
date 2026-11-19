<?php

declare(strict_types=1);

namespace App\Analytics\Services;

use App\Analytics\Domain\FinancialMath;
use Carbon\CarbonImmutable;

final readonly class MetricComparisonResult
{
    /**
     * @param string|int $delta
     */
    public function __construct(
        public string|int $delta,
        public ?float $percentageChange,
        public string $direction,
        public bool $isNew = false
    ) {
    }
}

final class PeriodComparisonService
{
    /**
     * @return array{from: string, to: string}
     */
    public function calculatePreviousRange(string $from, string $to): array
    {
        $fromDate = CarbonImmutable::parse($from);
        $toDate = CarbonImmutable::parse($to);

        $days = (int) $fromDate->diffInDays($toDate) + 1;

        $prevTo = $fromDate->subDay();
        $prevFrom = $prevTo->subDays($days - 1);

        return [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
        ];
    }

    public function compareMonetary(string $current, string $previous): MetricComparisonResult
    {
        $delta = FinancialMath::subtract($current, $previous);
        $cmp = bccomp($delta, FinancialMath::ZERO, FinancialMath::SCALE);

        $direction = match (true) {
            $cmp > 0 => 'increase',
            $cmp < 0 => 'decrease',
            default => 'unchanged',
        };

        if (bccomp($previous, FinancialMath::ZERO, FinancialMath::SCALE) === 0) {
            $isNew = bccomp($current, FinancialMath::ZERO, FinancialMath::SCALE) > 0;

            return new MetricComparisonResult($delta, null, $direction, $isNew);
        }

        $absPrevious = bccomp($previous, FinancialMath::ZERO, FinancialMath::SCALE) < 0
            ? bcmul($previous, '-1', FinancialMath::SCALE)
            : $previous;

        $percentage = ((float) $delta / (float) $absPrevious) * 100;

        return new MetricComparisonResult($delta, round($percentage, 2), $direction, false);
    }

    public function compareCount(int $current, int $previous): MetricComparisonResult
    {
        $delta = $current - $previous;

        $direction = match (true) {
            $delta > 0 => 'increase',
            $delta < 0 => 'decrease',
            default => 'unchanged',
        };

        if ($previous === 0) {
            $isNew = $current > 0;

            return new MetricComparisonResult($delta, null, $direction, $isNew);
        }

        $percentage = ($delta / abs($previous)) * 100;

        return new MetricComparisonResult($delta, round($percentage, 2), $direction, false);
    }
}
