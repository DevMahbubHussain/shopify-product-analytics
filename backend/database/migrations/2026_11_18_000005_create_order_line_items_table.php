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
        Schema::create('order_line_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('order_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->string('shopify_line_item_gid', 128);
            $table->string('title_snapshot', 255);
            $table->string('sku_snapshot', 128)->nullable();
            $table->integer('quantity');
            $table->decimal('original_unit_price', 20, 6);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->char('currency_code', 3);
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign(['shop_id', 'order_id'], 'line_items_order_tenant_fk')
                ->references(['shop_id', 'id'])->on('orders')->cascadeOnDelete();
            $table->foreign(['shop_id', 'product_id'], 'line_items_product_tenant_fk')
                ->references(['shop_id', 'id'])->on('products')->noActionOnDelete();
            $table->foreign(['shop_id', 'variant_id'], 'line_items_variant_tenant_fk')
                ->references(['shop_id', 'id'])->on('product_variants')->noActionOnDelete();

            $table->unique(['shop_id', 'id'], 'line_items_shop_id_unique');
            $table->unique(['shop_id', 'shopify_line_item_gid'], 'line_items_shop_gid_unique');
            $table->index(['shop_id', 'product_id'], 'line_items_shop_product_idx');
            $table->index(['shop_id', 'variant_id'], 'line_items_shop_variant_idx');
            $table->index(['shop_id', 'order_id'], 'line_items_shop_order_idx');
        });

        DB::statement("ALTER TABLE order_line_items ADD CONSTRAINT line_items_currency_code_format CHECK (currency_code ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE order_line_items ADD CONSTRAINT line_items_quantity_positive CHECK (quantity > 0)');
        DB::statement('ALTER TABLE order_line_items ADD CONSTRAINT line_items_amounts_non_negative CHECK (original_unit_price >= 0 AND discount_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_items');
    }
};
