<?php

declare(strict_types=1);

namespace App\Data\Mcp;

final readonly class McpAuditLogQueryData
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page,
        public int $perPage,
    ) {}

    /**
     * @param  array{page?: int|string, per_page?: int|string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? self::DEFAULT_PER_PAGE),
        );
    }
}
