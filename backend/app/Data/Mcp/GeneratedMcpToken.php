<?php

declare(strict_types=1);

namespace App\Data\Mcp;

use App\Models\McpToken;

final readonly class GeneratedMcpToken
{
    public function __construct(
        public string $plaintextToken,
        public McpToken $token,
    ) {}
}
