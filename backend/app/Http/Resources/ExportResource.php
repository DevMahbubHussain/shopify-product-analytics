<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Export
 */
final class ExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'export_type' => $this->export_type,
            'file_name' => $this->file_name,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'row_count' => $this->row_count,
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
