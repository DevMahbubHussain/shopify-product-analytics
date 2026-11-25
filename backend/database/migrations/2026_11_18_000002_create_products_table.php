<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('shopify_product_gid', 128);
            $table->string('title', 255);
            $table->string('handle', 255);
            $table->string('vendor', 128)->nullable();
            $table->string('product_type', 128)->nullable();
            $table->string('status', 32);
            $table->text('image_url')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('shopify_updated_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->unique(['shop_id', 'id'], 'products_shop_id_unique');
            $table->unique(['shop_id', 'shopify_product_gid'], 'products_shop_gid_unique');
            $table->index(['shop_id', 'status'], 'products_shop_status_idx');
            $table->index(['shop_id', 'vendor'], 'products_shop_vendor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
