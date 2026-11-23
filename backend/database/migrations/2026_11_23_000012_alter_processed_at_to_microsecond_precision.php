<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders ALTER COLUMN processed_at TYPE timestamptz(6)');
        DB::statement('ALTER TABLE refund_line_items ALTER COLUMN processed_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders ALTER COLUMN processed_at TYPE timestamptz(0)');
        DB::statement('ALTER TABLE refund_line_items ALTER COLUMN processed_at TYPE timestamptz(0)');
    }
};
