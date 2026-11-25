<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Mcp\McpAuditLogQueryData;

final class ListMcpAuditLogsRequest extends IngestionRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.McpAuditLogQueryData::MAX_PER_PAGE],
        ];
    }

    public function toDto(): McpAuditLogQueryData
    {
        /** @var array{page?: int|string, per_page?: int|string} $data */
        $data = $this->validated();

        return McpAuditLogQueryData::fromArray($data);
    }
}
