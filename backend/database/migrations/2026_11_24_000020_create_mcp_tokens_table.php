<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('name', 100);
            $table->string('token_prefix', 16);
            $table->char('token_hash', 64);
            $table->string('created_by_staff_ref', 64);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->unique('token_hash', 'mcp_tokens_token_hash_unique');
            $table->index(['shop_id', 'revoked_at'], 'mcp_tokens_shop_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
