<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('connection_type', 8);
            $table->uuid('connection_id')->nullable();
            $table->string('method', 64);
            $table->string('target_name', 128)->nullable();
            $table->string('status', 32);
            $table->integer('duration_ms');
            $table->string('client_name', 100)->nullable();
            $table->string('protocol_version', 16)->nullable();
            $table->uuid('correlation_id');
            $table->timestampTz('created_at');

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->index(['shop_id', 'created_at'], 'mcp_audit_logs_shop_created_idx');
            $table->index(['connection_id', 'created_at'], 'mcp_audit_logs_connection_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_audit_logs');
    }
};
