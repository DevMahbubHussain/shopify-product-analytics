<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

const DELETE_PATH = '/internal/v1/ingestion/products-delete';

/** @param list<int> $ids */
function ingestProducts(array $ids, string $domain = 'acme.myshopify.com'): void
{
    s2sPost('/internal/v1/ingestion/products', [
        'products' => array_map(fn (int $id): array => productPayload($id), $ids),
    ], $domain)->assertOk();
}

function productRow(int $id, string $domain = 'acme.myshopify.com'): object
{
    return DB::table('products')
        ->join('shops', 'shops.id', '=', 'products.shop_id')
        ->where('shops.shop_domain', $domain)
        ->where('products.shopify_product_gid', "gid://shopify/Product/{$id}")
        ->select('products.*')
        ->first();
}

it('soft deletes the listed products and their variants', function (): void {
    installShop();
    ingestProducts([1, 2]);

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 1, 'skipped_stale' => 0]);

    $deleted = productRow(1);
    expect($deleted->deleted_at)->not->toBeNull()
        ->and(DB::table('product_variants')->where('product_id', $deleted->id)->whereNull('deleted_at')->count())->toBe(0)
        ->and(DB::table('product_variants')->where('product_id', $deleted->id)->count())->toBe(2)
        ->and(productRow(2)->deleted_at)->toBeNull();
});

it('keeps sales history pointing at the deleted product', function (): void {
    installShop();
    ingestProducts([1]);
    s2sPost('/internal/v1/ingestion/orders', ['orders' => [orderPayload(1)]])->assertOk();
    $productId = productRow(1)->id;

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])->assertOk();

    expect(DB::table('products')->where('id', $productId)->exists())->toBeTrue()
        ->and(DB::table('order_line_items')->where('product_id', $productId)->count())->toBe(1);
});

it('is idempotent and keeps the first deletion time', function (): void {
    installShop();
    ingestProducts([1]);
    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])->assertOk();
    $firstDeletedAt = productRow(1)->deleted_at;

    $this->travel(5)->minutes();

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    expect(productRow(1)->deleted_at)->toBe($firstDeletedAt);
});

it('skips product GIDs that were never ingested', function (): void {
    installShop();
    ingestProducts([1]);

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1', 'gid://shopify/Product/999']])
        ->assertOk()
        ->assertExactJson(['received' => 2, 'written' => 1, 'skipped_stale' => 1]);

    expect(DB::table('products')->count())->toBe(1);
});

it('only deletes products of the shop from the verified header', function (): void {
    installShop('acme.myshopify.com');
    installShop('other.myshopify.com');
    ingestProducts([1], 'acme.myshopify.com');
    ingestProducts([1], 'other.myshopify.com');

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']], 'acme.myshopify.com')->assertOk();

    $other = productRow(1, 'other.myshopify.com');
    expect(productRow(1, 'acme.myshopify.com')->deleted_at)->not->toBeNull()
        ->and($other->deleted_at)->toBeNull()
        ->and(DB::table('product_variants')->where('product_id', $other->id)->whereNull('deleted_at')->count())->toBe(2);
});

it('does not restore a deleted product when a later product update arrives', function (): void {
    installShop();
    ingestProducts([1]);
    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])->assertOk();

    s2sPost('/internal/v1/ingestion/products', [
        'products' => [productPayload(1, ['title' => 'Renamed', 'shopify_updated_at' => '2026-11-10T10:00:00Z'])],
    ])->assertOk();

    expect(productRow(1)->deleted_at)->not->toBeNull()
        ->and(DB::table('product_variants')->where('product_id', productRow(1)->id)->whereNull('deleted_at')->count())->toBe(0);
});

it('rejects deletion for an uninstalled shop', function (): void {
    installShop();
    ingestProducts([1]);
    DB::table('shops')->update(['status' => 'uninstalled', 'uninstalled_at' => now()]);

    s2sPost(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])
        ->assertForbidden()
        ->assertExactJson(['error' => 'Shop is not active.']);

    expect(productRow(1)->deleted_at)->toBeNull();
});

it('rejects an invalid deletion payload', function (array $body, string $field): void {
    installShop();

    s2sPost(DELETE_PATH, $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing list' => [[], 'product_gids'],
    'empty list' => [['product_gids' => []], 'product_gids'],
    'wrong gid type' => [['product_gids' => ['gid://shopify/Order/1']], 'product_gids.0'],
    'more than 250' => [['product_gids' => array_map(fn (int $i): string => "gid://shopify/Product/{$i}", range(1, 251))], 'product_gids'],
]);

it('requires a valid S2S signature', function (): void {
    $this->postJson(DELETE_PATH, ['product_gids' => ['gid://shopify/Product/1']])->assertUnauthorized();
});
