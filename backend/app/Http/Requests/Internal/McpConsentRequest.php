<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Mcp\McpConsentData;

final class McpConsentRequest extends IngestionRequest
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
            'staff_ref' => ['required', 'string', 'max:64'],
            'policy_version' => ['required', 'string', 'max:16'],
        ];
    }

    public function toDto(): McpConsentData
    {
        /** @var array{shop_domain: string, staff_ref: string, policy_version: string} $data */
        $data = $this->validated();

        return McpConsentData::fromArray($data);
    }
}
