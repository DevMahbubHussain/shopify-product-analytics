<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Mcp\StoreMcpTokenData;

final class StoreMcpTokenRequest extends IngestionRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['shop_domain' => $this->shopDomain()]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'staff_ref' => ['required', 'string', 'max:64'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function toDto(): StoreMcpTokenData
    {
        /** @var array{shop_domain: string, name: string, staff_ref: string, expires_at?: string|null} $data */
        $data = $this->validated();

        return StoreMcpTokenData::fromArray($data);
    }
}
