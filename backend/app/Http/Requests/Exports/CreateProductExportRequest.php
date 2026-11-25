<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Enums\ShopStatus;
use App\Ingestion\Exceptions\InactiveShop;
use App\Ingestion\Exceptions\UnknownShop;
use App\Models\Shop;
use App\Services\PlanEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CreateProductExportRequest extends FormRequest
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
        $shop = Shop::query()->where('shop_domain', $this->shopDomain())->first() ?? throw new UnknownShop;

        if ($shop->status !== ShopStatus::Active) {
            throw new InactiveShop;
        }

        return $shop;
    }

    /** @return array<string, list<string>> */
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
                $shop = $this->resolveShop();
                app(PlanEntitlementService::class)->enforceCsvExport($shop);

                $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('from'));
                $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $this->input('to'));

                if ($from !== false && $to !== false) {
                    app(PlanEntitlementService::class)->enforceDateRangeLimit($shop, $from, $to);
                }
            }
        });
    }

    public function from(): string
    {
        return (string) $this->input('from');
    }

    public function to(): string
    {
        return (string) $this->input('to');
    }
}
