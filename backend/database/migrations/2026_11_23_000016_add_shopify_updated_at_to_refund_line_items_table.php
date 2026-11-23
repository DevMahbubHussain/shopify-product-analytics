<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_line_items', function (Blueprint $table): void {
            $table->timestampTz('shopify_updated_at', 6)->nullable()->after('processed_at');
        });
    }

    public function down(): void
    {
        Schema::table('refund_line_items', function (Blueprint $table): void {
            $table->dropColumn('shopify_updated_at');
        });
    }
};
