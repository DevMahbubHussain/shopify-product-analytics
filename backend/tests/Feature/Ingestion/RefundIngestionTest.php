<?php

declare(strict_types=1);

use App\Events\RefundsIngested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    $this->shopId = installShop();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1), orderPayload(2)]])->assertOk();
});

it('inserts refund lines linked to the order and line item', function (): void {
    Event::fake([RefundsIngested::class]);

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [refundLinePayload(1, 1)]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    $refund = DB::table('refund_line_items')->first();
    $orderId = DB::table('orders')->where('shopify_order_gid', 'gid://shopify/Order/1')->value('id');

    expect($refund->shop_id)->toBe($this->shopId)
        ->and($refund->order_id)->toBe($orderId)
        ->and($refund->line_item_id)->toBe(DB::table('order_line_items')->where('shopify_line_item_gid', 'gid://shopify/LineItem/11')->value('id'))
        ->and($refund->refunded_subtotal)->toBe('19.990000');

    Event::assertDispatched(RefundsIngested::class, fn (RefundsIngested $e): bool => $e->shopId === $this->shopId && $e->orderIds === [$orderId]);
});

it('re-ingesting the same refund lines creates zero duplicate rows', function (): void {
    $batch = ['refund_lines' => [refundLinePayload(1, 1), refundLinePayload(2, 2)]];

    s2sPost('/internal/v1/ingestion/refunds', $batch)->assertOk();
    s2sPost('/internal/v1/ingestion/refunds', $batch)->assertOk();

    expect(DB::table('refund_line_items')->count())->toBe(2);
});

it('returns 409 listing unknown orders and writes nothing from the batch', function (): void {
    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [refundLinePayload(1, 1), refundLinePayload(2, 404)]])
        ->assertConflict()
        ->assertExactJson(['error' => 'Referenced orders are not ingested yet.', 'missing_order_gids' => ['gid://shopify/Order/404']]);

    expect(DB::table('refund_line_items')->count())->toBe(0);
});

it('does not resolve orders belonging to another shop', function (): void {
    installShop('other.myshopify.com');

    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [refundLinePayload(1, 1)]], 'other.myshopify.com')
        ->assertConflict();
});

it('rejects malformed refund lines', function (array $line, string $field): void {
    s2sPost('/internal/v1/ingestion/refunds', ['refund_lines' => [$line]])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero quantity' => [refundLinePayload(1, 1, ['quantity' => 0]), 'refund_lines.0.quantity'],
    'float subtotal' => [refundLinePayload(1, 1, ['subtotal' => 5.5]), 'refund_lines.0.subtotal'],
    'missing refund line gid' => [refundLinePayload(1, 1, ['refund_line_item_gid' => null]), 'refund_lines.0.refund_line_item_gid'],
    'wrong refund gid type' => [refundLinePayload(1, 1, ['refund_gid' => 'gid://shopify/Order/1']), 'refund_lines.0.refund_gid'],
]);
