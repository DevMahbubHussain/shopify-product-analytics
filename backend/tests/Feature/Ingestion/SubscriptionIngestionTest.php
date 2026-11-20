<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->shopId = installShop('billing-store.myshopify.com');
});

it('upserts a new active subscription for the shop', function (): void {
    s2sPost('/internal/v1/ingestion/subscription', [
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/998877',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
        'trial_ends_at' => '2026-11-27T00:00:00Z',
        'current_period_end' => '2026-12-20T00:00:00Z',
    ], 'billing-store.myshopify.com')
        ->assertOk()
        ->assertJson([
            'status' => 'recorded',
            'plan_key' => 'pro',
            'subscription_status' => 'ACTIVE',
        ]);

    $sub = DB::table('subscriptions')
        ->where('shop_id', $this->shopId)
        ->where('shopify_subscription_gid', 'gid://shopify/AppSubscription/998877')
        ->first();

    expect($sub)->not->toBeNull()
        ->and($sub->plan_key)->toBe('pro')
        ->and($sub->status)->toBe('ACTIVE')
        ->and($sub->trial_ends_at)->not->toBeNull()
        ->and($sub->current_period_end)->not->toBeNull();
});

it('is idempotent and updates status without duplicate rows', function (): void {
    $payload = [
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/998877',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ];

    s2sPost('/internal/v1/ingestion/subscription', $payload, 'billing-store.myshopify.com')->assertOk();
    expect(DB::table('subscriptions')->where('shop_id', $this->shopId)->count())->toBe(1);

    // Cancel the subscription
    s2sPost('/internal/v1/ingestion/subscription', [
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/998877',
        'plan_key' => 'pro',
        'status' => 'CANCELLED',
    ], 'billing-store.myshopify.com')->assertOk();

    expect(DB::table('subscriptions')->where('shop_id', $this->shopId)->count())->toBe(1)
        ->and(DB::table('subscriptions')->where('shop_id', $this->shopId)->value('status'))->toBe('CANCELLED');
});

it('enforces tenant isolation across different shops', function (): void {
    $otherShopId = installShop('other-store.myshopify.com');

    s2sPost('/internal/v1/ingestion/subscription', [
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/111111',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ], 'billing-store.myshopify.com')->assertOk();

    s2sPost('/internal/v1/ingestion/subscription', [
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/222222',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ], 'other-store.myshopify.com')->assertOk();

    expect(DB::table('subscriptions')->where('shop_id', $this->shopId)->count())->toBe(1)
        ->and(DB::table('subscriptions')->where('shop_id', $otherShopId)->count())->toBe(1);
});

it('rejects invalid subscription payloads', function (array $body, string $field): void {
    s2sPost('/internal/v1/ingestion/subscription', $body, 'billing-store.myshopify.com')
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing plan_key' => [['status' => 'ACTIVE'], 'plan_key'],
    'missing status' => [['plan_key' => 'pro'], 'status'],
    'invalid gid format' => [['plan_key' => 'pro', 'status' => 'ACTIVE', 'shopify_subscription_gid' => 'not-a-gid'], 'shopify_subscription_gid'],
    'invalid date format' => [['plan_key' => 'pro', 'status' => 'ACTIVE', 'trial_ends_at' => 'not-a-date'], 'trial_ends_at'],
]);

it('requires a valid S2S signature', function (): void {
    $this->postJson('/internal/v1/ingestion/subscription', [
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ])->assertUnauthorized();
});
