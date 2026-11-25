<?php

declare(strict_types=1);

namespace App\Data\Ingestion;

final readonly class RefundBatch
{
    /** @param list<RefundLineData> $lines */
    public function __construct(public array $lines) {}

    /** @param array{refund_lines: list<array<string, mixed>>} $data */
    public static function fromArray(array $data): self
    {
        return new self(array_map(RefundLineData::fromArray(...), $data['refund_lines']));
    }
}
