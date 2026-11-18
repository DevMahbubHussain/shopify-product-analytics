<?php

declare(strict_types=1);

namespace App\Http\Requests\Internal;

use App\Data\Ingestion\OrderBatch;
use App\Enums\OrderFinancialStatus;
use App\Enums\OrderFulfillmentStatus;
use App\Support\IngestionRules;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IngestOrdersRequest extends IngestionRequest
{
    public const MAX_LINE_ITEMS = 250;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'orders' => ['required', 'array', 'min:1', 'max:50'],
            'orders.*.gid' => [...IngestionRules::gid('Order'), 'distinct'],
            'orders.*.name' => ['nullable', 'string', 'max:64'],
            'orders.*.financial_status' => ['nullable', Rule::enum(OrderFinancialStatus::class)],
            'orders.*.fulfillment_status' => ['nullable', Rule::enum(OrderFulfillmentStatus::class)],
            'orders.*.currency_code' => IngestionRules::currency(),
            'orders.*.processed_at' => IngestionRules::timestamp(required: false),
            'orders.*.cancelled_at' => IngestionRules::timestamp(required: false),
            'orders.*.shopify_updated_at' => IngestionRules::timestamp(),
            'orders.*.line_items' => ['required', 'array', 'min:1', 'max:' . self::MAX_LINE_ITEMS],
            'orders.*.line_items.*.gid' => [...IngestionRules::gid('LineItem'), 'distinct'],
            'orders.*.line_items.*.product_gid' => IngestionRules::gid('Product', required: false),
            'orders.*.line_items.*.variant_gid' => IngestionRules::gid('ProductVariant', required: false),
            'orders.*.line_items.*.title' => ['required', 'string', 'max:255'],
            'orders.*.line_items.*.sku' => ['nullable', 'string', 'max:128'],
            'orders.*.line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'orders.*.line_items.*.original_unit_price' => IngestionRules::amount(),
            'orders.*.line_items.*.discount_amount' => IngestionRules::amount(),
        ];
    }

    /**
     * Caps per-request work so the endpoint stays within its latency budget; split larger pages client-side.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $orders = $this->input('orders');
            $lineCount = is_array($orders) ? array_sum(array_map(fn (mixed $o): int => is_array($o['line_items'] ?? null) ? count($o['line_items']) : 0, $orders)) : 0;

            if ($lineCount > self::MAX_LINE_ITEMS) {
                $validator->errors()->add('orders', 'A batch may contain at most ' . self::MAX_LINE_ITEMS . " line items in total; got {$lineCount}.");
            }
        }];
    }

    public function toDto(): OrderBatch
    {
        /** @var array{orders: list<array<string, mixed>>} $data */
        $data = $this->validated();

        return OrderBatch::fromArray($data);
    }
}
