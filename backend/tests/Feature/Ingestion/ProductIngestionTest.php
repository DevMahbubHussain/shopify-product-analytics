<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->shopId = installShop();
});

it('inserts products and their variants', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1), productPayload(2)]])
        ->assertOk()
        ->assertExactJson(['received' => 2, 'written' => 2, 'skipped_stale' => 0]);

    $product = DB::table('products')->where('shopify_product_gid', 'gid://shopify/Product/1')->first();

    expect(DB::table('products')->count())->toBe(2)
        ->and(DB::table('product_variants')->count())->toBe(4)
        ->and($product->shop_id)->toBe($this->shopId)
        ->and($product->status)->toBe('ACTIVE')
        ->and(DB::table('product_variants')->where('shopify_variant_gid', 'gid://shopify/ProductVariant/12')->value('price'))->toBe('21.500000')
        ->and(DB::table('product_variants')->where('product_id', $product->id)->count())->toBe(2);
});

it('re-ingesting the same batch creates zero duplicate rows', function (): void {
    $batch = ['products' => [productPayload(1), productPayload(2)]];

    s2sPost('/internal/v1/ingestion/products', $batch)->assertOk();
    $ids = DB::table('products')->orderBy('id')->pluck('id')->all();
    s2sPost('/internal/v1/ingestion/products', $batch)->assertOk();

    expect(DB::table('products')->count())->toBe(2)
        ->and(DB::table('product_variants')->count())->toBe(4)
        ->and(DB::table('products')->orderBy('id')->pluck('id')->all())->toBe($ids);
});

it('skips stale updates that arrive out of order', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1, ['title' => 'New', 'shopify_updated_at' => '2026-11-10T00:00:00Z'])]])->assertOk();

    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1, [
        'title' => 'Old',
        'shopify_updated_at' => '2026-11-01T00:00:00Z',
        'variants' => [['gid' => 'gid://shopify/ProductVariant/11', 'title' => 'Old S', 'sku' => null, 'price' => '1.00']],
    ])]])
        ->assertOk()
        ->assertExactJson(['received' => 1, 'written' => 0, 'skipped_stale' => 1]);

    expect(DB::table('products')->value('title'))->toBe('New')
        ->and(DB::table('product_variants')->where('shopify_variant_gid', 'gid://shopify/ProductVariant/11')->value('title'))->toBe('S');
});

it('applies newer updates to product and variant fields', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1, [
        'title' => 'Renamed',
        'status' => 'ARCHIVED',
        'shopify_updated_at' => '2026-11-20T00:00:00Z',
        'variants' => [['gid' => 'gid://shopify/ProductVariant/11', 'title' => 'Small', 'sku' => 'NEW', 'price' => '9.99']],
    ])]])->assertOk();

    expect(DB::table('products')->value('title'))->toBe('Renamed')
        ->and(DB::table('products')->value('status'))->toBe('ARCHIVED')
        ->and(DB::table('product_variants')->where('shopify_variant_gid', 'gid://shopify/ProductVariant/11')->value('price'))->toBe('9.990000');
});

it('scopes rows to the shop in the signed header, not to anything in the body', function (): void {
    $otherShopId = installShop('other.myshopify.com');

    s2sPost('/internal/v1/ingestion/products', ['shop_id' => $otherShopId, 'products' => [productPayload(1)]])->assertOk();
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]], 'other.myshopify.com')->assertOk();

    expect(DB::table('products')->where('shop_id', $this->shopId)->count())->toBe(1)
        ->and(DB::table('products')->where('shop_id', $otherShopId)->count())->toBe(1);
});

it('returns 404 for a shop that was never installed', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]], 'ghost.myshopify.com')
        ->assertNotFound()
        ->assertExactJson(['error' => 'Unknown shop.']);
});

it('returns 403 for an uninstalled shop and writes nothing', function (): void {
    DB::table('shops')->update(['status' => 'uninstalled']);

    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])
        ->assertForbidden()
        ->assertExactJson(['error' => 'Shop is not active.']);

    expect(DB::table('products')->count())->toBe(0);
});

it('rejects malformed product batches', function (array $product, string $field): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [$product]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(DB::table('products')->count())->toBe(0);
})->with([
    'wrong gid type' => [productPayload(1, ['gid' => 'gid://shopify/Order/1']), 'products.0.gid'],
    'unknown status' => [productPayload(1, ['status' => 'active']), 'products.0.status'],
    'float price' => [productPayload(1, ['variants' => [['gid' => 'gid://shopify/ProductVariant/1', 'title' => 'S', 'sku' => null, 'price' => 19.99]]]), 'products.0.variants.0.price'],
    'seven decimals' => [productPayload(1, ['variants' => [['gid' => 'gid://shopify/ProductVariant/1', 'title' => 'S', 'sku' => null, 'price' => '1.0000001']]]), 'products.0.variants.0.price'],
    'negative price' => [productPayload(1, ['variants' => [['gid' => 'gid://shopify/ProductVariant/1', 'title' => 'S', 'sku' => null, 'price' => '-1.00']]]), 'products.0.variants.0.price'],
    'timestamp without zone' => [productPayload(1, ['shopify_updated_at' => '2026-11-01 10:00:00']), 'products.0.shopify_updated_at'],
]);

it('rejects duplicate gids inside one batch', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1), productPayload(1)]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('products.0.gid');
});

it('rejects empty and oversized batches', function (): void {
    s2sPost('/internal/v1/ingestion/products', ['products' => []])->assertUnprocessable();

    $tooMany = array_map(fn (int $i): array => productPayload($i, ['variants' => []]), range(1, 101));
    s2sPost('/internal/v1/ingestion/products', ['products' => $tooMany])->assertUnprocessable()->assertJsonValidationErrors('products');
});
