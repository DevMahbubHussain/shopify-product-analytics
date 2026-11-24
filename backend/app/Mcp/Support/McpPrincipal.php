<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Enums\Platform;
use Carbon\CarbonImmutable;

final readonly class McpPrincipal
{
    public function __construct(
        public string $shopId,
        public Platform $platform,
        public string $staffRef,
        public string $connectionId,
        public string $connectionType = 'token',
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt->isPast();
    }

    public function isToken(): bool
    {
        return $this->connectionType === 'token';
    }

    public function isOAuth(): bool
    {
        return $this->connectionType === 'oauth';
    }
}
