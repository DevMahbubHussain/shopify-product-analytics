<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->integer('attempts')->default(0)->after('status');
            $table->timestampTz('retry_after')->nullable()->after('attempts');
            $table->text('last_error')->nullable()->after('retry_after');

            $table->index(['status', 'retry_after', 'created_at'], 'outbox_events_status_retry_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropIndex('outbox_events_status_retry_created_idx');
            $table->dropColumn(['attempts', 'retry_after', 'last_error']);
        });
    }
};
