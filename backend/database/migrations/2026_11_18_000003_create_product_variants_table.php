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
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->uuid('product_id');
            $table->string('shopify_variant_gid', 128);
            $table->string('title', 255);
            $table->string('sku', 128)->nullable();
            $table->decimal('price', 20, 6)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->foreign(['shop_id', 'product_id'], 'variants_product_tenant_fk')
                ->references(['shop_id', 'id'])->on('products')->cascadeOnDelete();

            $table->unique(['shop_id', 'id'], 'variants_shop_id_unique');
            $table->unique(['shop_id', 'shopify_variant_gid'], 'variants_shop_gid_unique');
            $table->index(['shop_id', 'product_id'], 'variants_shop_product_idx');
            $table->index(['shop_id', 'sku'], 'variants_shop_sku_idx');
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT variants_price_non_negative CHECK (price IS NULL OR price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
