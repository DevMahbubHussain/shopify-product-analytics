<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

final class SalesTrendRequest extends AnalyticsDateRangeRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'interval' => ['nullable', 'string', 'in:day,week,month'],
        ]);
    }

    public function trendInterval(): string
    {
        $interval = (string) $this->query('interval');

        return $interval !== '' ? $interval : 'day';
    }
}
