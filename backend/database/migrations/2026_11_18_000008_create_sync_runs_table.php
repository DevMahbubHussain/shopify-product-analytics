<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('sync_type', 32);
            $table->string('status', 32)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('cursor_metadata')->nullable();
            $table->integer('records_received')->default(0);
            $table->integer('records_processed')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->index(['shop_id', 'status'], 'sync_runs_shop_status_idx');
            $table->index(['shop_id', 'created_at'], 'sync_runs_shop_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
