<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\ShopUninstallData;
use App\Support\IngestionRules;

final class UninstallShopRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uninstalled_at' => IngestionRules::timestamp(),
        ];
    }

    public function toDto(): ShopUninstallData
    {
        /** @var array{uninstalled_at: string} $data */
        $data = $this->validated();

        return ShopUninstallData::fromArray($this->shopDomain(), $data);
    }
}
