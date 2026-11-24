<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Models\Shop;
use App\Services\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AnalyticsDateRangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function shopDomain(): string
    {
        return (string) $this->attributes->get('shop_domain');
    }

    public function resolveShop(): Shop
    {
        $shop = Shop::query()->where('shop_domain', $this->shopDomain())->first() ?? throw new UnknownShop();

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop();
        }

        return $shop;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has(['from', 'to']) && ! $validator->errors()->hasAny(['from', 'to'])) {
                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->query('from'));
                $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->query('to'));

                if ($from !== false && $to !== false) {
                    if ($from->diffInDays($to) + 1 > 365) {
                        $validator->errors()->add('to', 'The date range cannot exceed 365 days.');

                        return;
                    }

                    $shop = $this->resolveShop();
                    app(PlanEntitlementService::class)->enforceDateRangeLimit($shop, $from, $to);
                }
            }
        });
    }

    public function from(): string
    {
        return (string) $this->query('from');
    }

    public function to(): string
    {
        return (string) $this->query('to');
    }
}
