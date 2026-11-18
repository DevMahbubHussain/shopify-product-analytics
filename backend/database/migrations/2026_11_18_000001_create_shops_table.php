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
        Schema::create('shops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('shop_domain', 255)->unique();
            $table->string('shopify_shop_gid', 128)->nullable();
            $table->char('currency_code', 3);
            $table->string('timezone', 64);
            $table->string('status', 32)->default('active');
            $table->timestampTz('installed_at');
            $table->timestampTz('uninstalled_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE shops ADD CONSTRAINT shops_currency_code_format CHECK (currency_code ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
