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
        Schema::create('refund_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('order_id');
            $table->uuid('line_item_id')->nullable();
            $table->string('shopify_refund_gid', 128);
            $table->string('shopify_refund_line_item_gid', 128);
            $table->integer('refunded_quantity');
            $table->decimal('refunded_subtotal', 20, 6);
            $table->char('currency_code', 3);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign(['shop_id', 'order_id'], 'refund_lines_order_tenant_fk')
                ->references(['shop_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['shop_id', 'line_item_id'], 'refund_lines_line_item_tenant_fk')
                ->references(['shop_id', 'id'])->on('order_line_items')->cascadeOnDelete();

            $table->unique(['shop_id', 'shopify_refund_line_item_gid'], 'refund_lines_shop_gid_unique');
            $table->index(['shop_id', 'order_id'], 'refund_lines_shop_order_idx');
            $table->index(['shop_id', 'line_item_id'], 'refund_lines_shop_line_idx');
            $table->index(['shop_id', 'processed_at'], 'refund_lines_shop_processed_idx');
        });

        DB::statement("ALTER TABLE refund_line_items ADD CONSTRAINT refund_lines_currency_code_format CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE refund_line_items ADD CONSTRAINT refund_lines_quantity_positive CHECK (refunded_quantity > 0)');
        DB::statement('ALTER TABLE refund_line_items ADD CONSTRAINT refund_lines_subtotal_non_negative CHECK (refunded_subtotal >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_line_items');
    }
};
