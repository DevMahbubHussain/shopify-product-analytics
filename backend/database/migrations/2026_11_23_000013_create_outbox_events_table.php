<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('event_class', 255);
            $table->jsonb('payload');
            $table->string('status', 32)->default('pending');
            $table->timestampTz('dispatched_at')->nullable();
            $table->timestampsTz(6);

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();

            $table->index(['shop_id', 'status'], 'outbox_events_shop_status_idx');
            $table->index(['status', 'created_at'], 'outbox_events_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
