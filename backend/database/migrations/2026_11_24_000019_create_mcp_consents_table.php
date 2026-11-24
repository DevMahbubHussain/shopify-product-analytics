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
        Schema::create('mcp_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('platform', 16)->default('shopify');
            $table->string('granted_by_staff_ref', 64);
            $table->string('policy_version', 16);
            $table->timestampTz('granted_at');
            $table->timestampTz('withdrawn_at')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->index(['shop_id', 'withdrawn_at'], 'mcp_consents_shop_withdrawn_idx');
        });

        DB::statement('CREATE UNIQUE INDEX mcp_consents_shop_active_unique ON mcp_consents (shop_id) WHERE withdrawn_at IS NULL;');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mcp_consents_shop_active_unique;');
        Schema::dropIfExists('mcp_consents');
    }
};
