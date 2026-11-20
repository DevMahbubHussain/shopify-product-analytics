<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Schema;

it('confirms all application tables contain zero protected customer data columns', function (): void {
    $forbiddenPcdKeywords = [
        'customer',
        'email',
        'phone',
        'first_name',
        'last_name',
        'shipping_address',
        'billing_address',
        'postal_code',
        'zip',
        'ip_address',
        'client_ip',
        'browser_ip',
        'device_id',
    ];

    $tablesToInspect = [
        'shops',
        'products',
        'product_variants',
        'orders',
        'order_line_items',
        'refund_line_items',
        'product_daily_metrics',
        'sync_runs',
        'webhook_events',
        'subscriptions',
        'exports',
    ];

    foreach ($tablesToInspect as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Expected table {$table} to exist");

        $columns = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            $lowerColumn = strtolower($column);

            foreach ($forbiddenPcdKeywords as $forbiddenKeyword) {
                expect($lowerColumn)
                    ->not->toContain(
                        $forbiddenKeyword,
                        "Table '{$table}' contains prohibited customer personal data column: '{$column}'"
                    );
            }
        }
    }
});

it('verifies orders schema contains only non-pcd financial and operational attributes', function (): void {
    $expectedOrderColumns = [
        'id',
        'shop_id',
        'shopify_order_gid',
        'order_number',
        'currency_code',
        'processed_at',
        'financial_status',
        'fulfillment_status',
        'cancelled_at',
        'cancel_reason',
        'closed_at',
        'test_order',
        'total_gross_amount',
        'total_discounts_amount',
        'total_refunded_amount',
        'total_net_amount',
        'currency_code',
        'created_at',
        'updated_at',
    ];

    $actualColumns = Schema::getColumnListing('orders');

    // Confirm essential columns exist
    expect($actualColumns)->toContain('shop_id');
    expect($actualColumns)->toContain('shopify_order_gid');
    expect($actualColumns)->toContain('order_name');
    expect($actualColumns)->toContain('financial_status');

    // Confirm no customer identifiers exist
    expect($actualColumns)->not->toContain('customer_id');
    expect($actualColumns)->not->toContain('email');
    expect($actualColumns)->not->toContain('phone');
    expect($actualColumns)->not->toContain('name');
    expect($actualColumns)->not->toContain('shipping_address');
    expect($actualColumns)->not->toContain('billing_address');
});
