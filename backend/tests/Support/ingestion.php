<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

function installShop(string $domain = 'acme.myshopify.com', string $timezone = 'America/New_York'): string
{
    s2sPost('/internal/v1/ingestion/shop', [
        'shopify_shop_gid' => 'gid://shopify/Shop/1',
        'currency_code' => 'USD',
        'timezone' => $timezone,
    ], $domain)->assertOk();

    return (string) DB::table('shops')->where('shop_domain', $domain)->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(int $id = 1, array $overrides = []): array
{
    return array_merge([
        'gid' => "gid://shopify/Product/{$id}",
        'title' => "Product {$id}",
        'handle' => "product-{$id}",
        'vendor' => 'Acme',
        'product_type' => 'Shirts',
        'status' => 'ACTIVE',
        'image_url' => null,
        'published_at' => '2026-10-01T10:00:00Z',
        'shopify_updated_at' => '2026-11-01T10:00:00Z',
        'variants' => [
            ['gid' => "gid://shopify/ProductVariant/{$id}1", 'title' => 'S', 'sku' => "SKU-{$id}-S", 'price' => '19.990000'],
            ['gid' => "gid://shopify/ProductVariant/{$id}2", 'title' => 'M', 'sku' => "SKU-{$id}-M", 'price' => '21.50'],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lineItemPayload(int $orderId, int $line, int $productId = 1, array $overrides = []): array
{
    return array_merge([
        'gid' => "gid://shopify/LineItem/{$orderId}{$line}",
        'product_gid' => "gid://shopify/Product/{$productId}",
        'variant_gid' => "gid://shopify/ProductVariant/{$productId}1",
        'title' => "Product {$productId}",
        'sku' => "SKU-{$productId}-S",
        'quantity' => 2,
        'original_unit_price' => '19.99',
        'discount_amount' => '1.00',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderPayload(int $id = 1, array $overrides = []): array
{
    return array_merge([
        'gid' => "gid://shopify/Order/{$id}",
        'name' => "#{$id}",
        'financial_status' => 'PAID',
        'fulfillment_status' => 'UNFULFILLED',
        'currency_code' => 'USD',
        'processed_at' => '2026-11-02T15:00:00Z',
        'cancelled_at' => null,
        'shopify_updated_at' => '2026-11-02T15:00:00Z',
        'line_items' => [lineItemPayload($id, 1)],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function refundLinePayload(int $id = 1, int $orderId = 1, array $overrides = []): array
{
    return array_merge([
        'refund_gid' => "gid://shopify/Refund/{$orderId}",
        'refund_line_item_gid' => "gid://shopify/RefundLineItem/{$id}",
        'order_gid' => "gid://shopify/Order/{$orderId}",
        'line_item_gid' => "gid://shopify/LineItem/{$orderId}1",
        'quantity' => 1,
        'subtotal' => '19.99',
        'currency_code' => 'USD',
        'processed_at' => '2026-11-05T09:00:00Z',
    ], $overrides);
}
