<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const UNINSTALL_PATH = '/internal/v1/ingestion/shop-uninstall';

function installedAt(string $timestamp, string $domain = 'acme.myshopify.com'): void
{
    DB::table('shops')->where('shop_domain', $domain)->update(['installed_at' => $timestamp]);
}

function shopRow(string $domain = 'acme.myshopify.com'): object
{
    return DB::table('shops')->where('shop_domain', $domain)->first();
}

it('marks an active shop uninstalled at the time Shopify triggered the event', function (): void {
    installShop();
    installedAt('2026-11-01T00:00:00Z');

    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T08:30:00+06:00'])
        ->assertOk()
        ->assertExactJson(['shop_domain' => 'acme.myshopify.com', 'status' => 'uninstalled']);

    $shop = shopRow();
    expect($shop->status)->toBe('uninstalled')
        ->and(CarbonImmutable::parse($shop->uninstalled_at)->equalTo(CarbonImmutable::parse('2026-11-18T02:30:00Z')))->toBeTrue();
});

it('is idempotent and keeps the first uninstall time on repeat deliveries', function (): void {
    installShop();
    installedAt('2026-11-01T00:00:00Z');

    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T02:30:00Z'])->assertOk();
    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T05:00:00Z'])
        ->assertOk()
        ->assertExactJson(['shop_domain' => 'acme.myshopify.com', 'status' => 'uninstalled']);

    expect(CarbonImmutable::parse(shopRow()->uninstalled_at)->equalTo(CarbonImmutable::parse('2026-11-18T02:30:00Z')))->toBeTrue()
        ->and(DB::table('shops')->count())->toBe(1);
});

it('ignores a late uninstall delivery triggered before the current installation', function (): void {
    installShop();
    installedAt('2026-11-18T10:00:00Z');

    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T09:59:59Z'])
        ->assertOk()
        ->assertExactJson(['shop_domain' => 'acme.myshopify.com', 'status' => 'active']);

    $shop = shopRow();
    expect($shop->status)->toBe('active')
        ->and($shop->uninstalled_at)->toBeNull();
});

it('returns 404 for a shop that was never installed', function (): void {
    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T02:30:00Z'])
        ->assertNotFound()
        ->assertExactJson(['error' => 'Unknown shop.']);

    expect(DB::table('shops')->count())->toBe(0);
});

it('keeps shop data and blocks further ingestion until reinstall', function (): void {
    installShop();
    installedAt('2026-11-01T00:00:00Z');
    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(1)]])->assertOk();

    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T02:30:00Z'])->assertOk();

    expect(DB::table('products')->count())->toBe(1)
        ->and(DB::table('product_variants')->count())->toBe(2);

    s2sPost('/internal/v1/ingestion/products', ['products' => [productPayload(2)]])
        ->assertForbidden()
        ->assertExactJson(['error' => 'Shop is not active.']);
});

it('only affects the shop from the verified header', function (): void {
    installShop('acme.myshopify.com');
    installShop('other.myshopify.com');
    installedAt('2026-11-01T00:00:00Z', 'acme.myshopify.com');

    s2sPost(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T02:30:00Z'], 'acme.myshopify.com')->assertOk();

    expect(shopRow('acme.myshopify.com')->status)->toBe('uninstalled')
        ->and(shopRow('other.myshopify.com')->status)->toBe('active')
        ->and(shopRow('other.myshopify.com')->uninstalled_at)->toBeNull();
});

it('rejects an invalid uninstall time', function (array $body): void {
    installShop();

    s2sPost(UNINSTALL_PATH, $body)->assertUnprocessable()->assertJsonValidationErrors('uninstalled_at');

    expect(shopRow()->status)->toBe('active');
})->with([
    'missing' => [[]],
    'not a date' => [['uninstalled_at' => 'yesterday']],
    'no timezone offset' => [['uninstalled_at' => '2026-11-18T02:30:00']],
]);

it('requires a valid S2S signature', function (): void {
    $this->postJson(UNINSTALL_PATH, ['uninstalled_at' => '2026-11-18T02:30:00Z'])->assertUnauthorized();
});
