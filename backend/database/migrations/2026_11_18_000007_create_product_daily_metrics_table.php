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
        Schema::create('product_daily_metrics', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('product_id');
            $table->date('metric_date');
            $table->char('currency_code', 3);
            $table->decimal('gross_sales', 20, 6)->default(0);
            $table->decimal('discounts', 20, 6)->default(0);
            $table->decimal('refunds', 20, 6)->default(0);
            $table->decimal('net_sales', 20, 6)->default(0);
            $table->integer('units_sold')->default(0);
            $table->integer('order_count')->default(0);
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign(['shop_id', 'product_id'], 'metrics_product_tenant_fk')
                ->references(['shop_id', 'id'])->on('products')->noActionOnDelete();

            $table->unique(['shop_id', 'product_id', 'metric_date', 'currency_code'], 'metrics_shop_prod_date_curr_unique');
            $table->index(['shop_id', 'metric_date'], 'metrics_shop_date_idx');
        });

        DB::statement("ALTER TABLE product_daily_metrics ADD CONSTRAINT metrics_currency_code_format CHECK (currency_code ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_daily_metrics');
    }
};
