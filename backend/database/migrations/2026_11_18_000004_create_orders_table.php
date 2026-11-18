<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('shopify_order_gid', 128);
            $table->string('order_name', 64)->nullable();
            $table->string('financial_status', 32)->nullable();
            $table->string('fulfillment_status', 32)->nullable();
            $table->char('currency_code', 3);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('shopify_updated_at')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->unique(['shop_id', 'id'], 'orders_shop_id_unique');
            $table->unique(['shop_id', 'shopify_order_gid'], 'orders_shop_gid_unique');
            $table->index(['shop_id', 'processed_at'], 'orders_shop_processed_idx');
            $table->index(['shop_id', 'financial_status'], 'orders_shop_financial_status_idx');
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_currency_code_format CHECK (currency_code ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
