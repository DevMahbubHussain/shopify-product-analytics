<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('webhook_id', 128);
            $table->string('topic', 128);
            $table->char('payload_hash', 64);
            $table->string('status', 32)->default('received');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->unique(['shop_id', 'webhook_id'], 'webhook_events_shop_webhook_unique');
            $table->index(['shop_id', 'status'], 'webhook_events_shop_status_idx');
            $table->index(['shop_id', 'received_at'], 'webhook_events_shop_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
