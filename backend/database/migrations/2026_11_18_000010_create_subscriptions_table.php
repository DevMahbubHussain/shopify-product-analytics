<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('shopify_subscription_gid', 128)->nullable();
            $table->string('plan_key', 32);
            $table->string('status', 32);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->unique(['shop_id', 'shopify_subscription_gid'], 'subscriptions_shop_gid_unique');
            $table->index(['shop_id', 'status'], 'subscriptions_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
