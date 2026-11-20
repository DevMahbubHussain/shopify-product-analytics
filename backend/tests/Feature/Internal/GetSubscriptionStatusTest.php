<?php

declare(strict_types=1);

use App\Models\Subscription;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->shopId = installShop();
});

describe('GET /internal/v1/subscription', function (): void {
    it('returns free tier defaults when shop has no active subscription', function (): void {
        s2sGet('/internal/v1/subscription')
            ->assertOk()
            ->assertExactJson([
                'plan_key' => 'free',
                'status' => null,
                'max_analytics_days' => 30,
                'can_export_csv' => false,
                'trial_ends_at' => null,
                'current_period_end' => null,
            ]);
    });

    it('returns pro tier details when shop has an active subscription', function (): void {
        Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_subscription_gid' => 'gid://shopify/AppSubscription/12345',
            'plan_key' => 'pro',
            'status' => 'ACTIVE',
            'trial_ends_at' => '2026-11-25 00:00:00+00',
            'current_period_end' => '2026-12-20 00:00:00+00',
        ]);

        s2sGet('/internal/v1/subscription')
            ->assertOk()
            ->assertJson([
                'plan_key' => 'pro',
                'status' => 'ACTIVE',
                'max_analytics_days' => 365,
                'can_export_csv' => true,
            ]);
    });

    it('falls back to free tier when subscription is cancelled', function (): void {
        Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_subscription_gid' => 'gid://shopify/AppSubscription/12345',
            'plan_key' => 'pro',
            'status' => 'CANCELLED',
        ]);

        s2sGet('/internal/v1/subscription')
            ->assertOk()
            ->assertJson([
                'plan_key' => 'free',
                'status' => null,
                'max_analytics_days' => 30,
                'can_export_csv' => false,
            ]);
    });

    it('enforces tenant isolation across different shops', function (): void {
        Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'shop_id' => $this->shopId,
            'shopify_subscription_gid' => 'gid://shopify/AppSubscription/12345',
            'plan_key' => 'pro',
            'status' => 'ACTIVE',
        ]);

        installShop('other-shop.myshopify.com');

        s2sGet('/internal/v1/subscription', 'other-shop.myshopify.com')
            ->assertOk()
            ->assertJson([
                'plan_key' => 'free',
                'status' => null,
                'max_analytics_days' => 30,
                'can_export_csv' => false,
            ]);
    });

    it('requires a valid S2S signature', function (): void {
        $this->getJson('/internal/v1/subscription')
            ->assertStatus(401);
    });
});
