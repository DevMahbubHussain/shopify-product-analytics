<?php

declare(strict_types=1);

namespace App\Http\Resources\Mcp;

use App\Models\McpConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property McpConsent $resource */
final class McpConsentResource extends JsonResource
{
    public function __construct(McpConsent $consent)
    {
        parent::__construct($consent);
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(Request $request): array
    {
        $consent = $this->resource;

        return [
            'id' => $consent->id,
            'platform' => $consent->platform->value,
            'granted_by_staff_ref' => $consent->granted_by_staff_ref,
            'policy_version' => $consent->policy_version,
            'granted_at' => $consent->granted_at->toIso8601String(),
        ];
    }
}
