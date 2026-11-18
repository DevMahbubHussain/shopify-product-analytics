<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('creates an active shop from the verified header domain', function (): void {
    s2sPost('/internal/v1/ingestion/shop', [
        'shopify_shop_gid' => 'gid://shopify/Shop/77',
        'currency_code' => 'EUR',
        'timezone' => 'Europe/Berlin',
    ], 'berlin.myshopify.com')
        ->assertOk()
        ->assertExactJson(['shop_domain' => 'berlin.myshopify.com', 'status' => 'active']);

    $shop = DB::table('shops')->where('shop_domain', 'berlin.myshopify.com')->first();

    expect($shop->status)->toBe('active')
        ->and($shop->currency_code)->toBe('EUR')
        ->and($shop->timezone)->toBe('Europe/Berlin')
        ->and($shop->shopify_shop_gid)->toBe('gid://shopify/Shop/77')
        ->and($shop->installed_at)->not->toBeNull();
});

it('is idempotent and applies metadata changes on repeat calls', function (): void {
    installShop();
    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'CAD', 'timezone' => 'America/Toronto'])->assertOk();

    expect(DB::table('shops')->count())->toBe(1)
        ->and(DB::table('shops')->value('currency_code'))->toBe('CAD')
        ->and(DB::table('shops')->value('timezone'))->toBe('America/Toronto');
});

it('reactivates an uninstalled shop and clears the uninstall time', function (): void {
    installShop();
    DB::table('shops')->update(['status' => 'uninstalled', 'uninstalled_at' => now()->subDay(), 'installed_at' => now()->subYear()]);

    installShop();

    $shop = DB::table('shops')->first();
    expect($shop->status)->toBe('active')
        ->and($shop->uninstalled_at)->toBeNull()
        ->and(now()->diffInMinutes($shop->installed_at, true))->toBeLessThan(1);
});

it('rejects invalid shop metadata', function (array $body, string $field): void {
    s2sPost('/internal/v1/ingestion/shop', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'lowercase currency' => [['currency_code' => 'usd', 'timezone' => 'UTC'], 'currency_code'],
    'unknown timezone' => [['currency_code' => 'USD', 'timezone' => 'Mars/Olympus'], 'timezone'],
    'wrong gid type' => [['currency_code' => 'USD', 'timezone' => 'UTC', 'shopify_shop_gid' => 'gid://shopify/Order/1'], 'shopify_shop_gid'],
]);

it('rejects a header domain that is not a myshopify domain', function (): void {
    s2sPost('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'], 'evil.example.com')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shop_domain');

    expect(DB::table('shops')->count())->toBe(0);
});

it('requires a valid S2S signature', function (): void {
    $this->postJson('/internal/v1/ingestion/shop', ['currency_code' => 'USD', 'timezone' => 'UTC'])
        ->assertUnauthorized();
});
