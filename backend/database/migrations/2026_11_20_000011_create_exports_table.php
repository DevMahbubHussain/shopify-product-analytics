<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shop_id');
            $table->string('export_type', 32)->default('products');
            $table->string('file_name', 255);
            $table->string('file_path', 512)->nullable();
            $table->integer('row_count')->default(0);
            $table->string('status', 32)->default('PENDING');
            $table->date('date_from');
            $table->date('date_to');
            $table->text('error_message')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->index(['shop_id', 'status'], 'exports_shop_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
