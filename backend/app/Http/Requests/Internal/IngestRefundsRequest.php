<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\RefundBatch;
use App\Support\IngestionRules;

final class IngestRefundsRequest extends IngestionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refund_lines' => ['required', 'array', 'min:1', 'max:250'],
            'refund_lines.*.refund_gid' => IngestionRules::gid('Refund'),
            'refund_lines.*.refund_line_item_gid' => [...IngestionRules::gid('RefundLineItem'), 'distinct'],
            'refund_lines.*.order_gid' => IngestionRules::gid('Order'),
            'refund_lines.*.line_item_gid' => IngestionRules::gid('LineItem', required: false),
            'refund_lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'refund_lines.*.subtotal' => IngestionRules::amount(),
            'refund_lines.*.currency_code' => IngestionRules::currency(),
            'refund_lines.*.processed_at' => IngestionRules::timestamp(required: false),
        ];
    }

    public function toDto(): RefundBatch
    {
        /** @var array{refund_lines: list<array<string, mixed>>} $data */
        $data = $this->validated();

        return RefundBatch::fromArray($data);
    }
}
