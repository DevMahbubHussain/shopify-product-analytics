<?php

declare(strict_types=1);

use App\Models\Subscription;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopDomain = 'entitlement-store.myshopify.com';
    $this->shopId = installShop($this->shopDomain);
});

it('restricts free plan to a maximum 30-day analytics query returning 403 Forbidden', function (): void {
    // 45 days query on free tier (no active subscription)
    s2sGet('/api/v1/analytics/overview?from=2026-10-01&to=2026-11-15', $this->shopDomain)
        ->assertForbidden()
        ->assertJson([
            'error' => 'plan_limit_exceeded',
            'plan' => 'free',
            'max_days' => 30,
        ]);
});

it('allows free plan to query within 30 days', function (): void {
    s2sGet('/api/v1/analytics/overview?from=2026-11-01&to=2026-11-20', $this->shopDomain)
        ->assertOk();
});

it('allows pro plan to query up to 365 days', function (): void {
    Subscription::query()->create([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/123456',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ]);

    s2sGet('/api/v1/analytics/overview?from=2026-01-01&to=2026-11-15', $this->shopDomain)
        ->assertOk();
});

it('rejects pro plan query exceeding 365 days with 422', function (): void {
    Subscription::query()->create([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/123456',
        'plan_key' => 'pro',
        'status' => 'ACTIVE',
    ]);

    s2sGet('/api/v1/analytics/overview?from=2025-01-01&to=2026-11-15', $this->shopDomain)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

it('falls back to free plan when subscription is cancelled', function (): void {
    Subscription::query()->create([
        'id' => (string) Str::uuid7(),
        'shop_id' => $this->shopId,
        'shopify_subscription_gid' => 'gid://shopify/AppSubscription/123456',
        'plan_key' => 'pro',
        'status' => 'CANCELLED',
    ]);

    s2sGet('/api/v1/analytics/overview?from=2026-10-01&to=2026-11-15', $this->shopDomain)
        ->assertForbidden()
        ->assertJson([
            'error' => 'plan_limit_exceeded',
            'plan' => 'free',
            'max_days' => 30,
        ]);
});

it('enforces plan gating across sales-trend and products list endpoints', function (): void {
    // Sales trend endpoint
    s2sGet('/api/v1/analytics/sales-trend?from=2026-10-01&to=2026-11-15&interval=day', $this->shopDomain)
        ->assertForbidden()
        ->assertJson([
            'error' => 'plan_limit_exceeded',
            'plan' => 'free',
            'max_days' => 30,
        ]);

    // Products performance list endpoint
    s2sGet('/api/v1/products?from=2026-10-01&to=2026-11-15', $this->shopDomain)
        ->assertForbidden()
        ->assertJson([
            'error' => 'plan_limit_exceeded',
            'plan' => 'free',
            'max_days' => 30,
        ]);
});
