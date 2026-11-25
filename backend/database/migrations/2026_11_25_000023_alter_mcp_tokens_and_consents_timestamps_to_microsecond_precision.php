<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $columns = [
        'mcp_tokens' => ['last_used_at', 'expires_at', 'revoked_at', 'created_at', 'updated_at'],
        'mcp_consents' => ['granted_at', 'withdrawn_at', 'created_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->alterPrecision(6);
    }

    public function down(): void
    {
        $this->alterPrecision(0);
    }

    private function alterPrecision(int $precision): void
    {
        foreach ($this->columns as $table => $columns) {
            $clauses = array_map(
                static fn (string $column): string => "ALTER COLUMN {$column} TYPE timestamptz({$precision})",
                $columns,
            );
            DB::statement("ALTER TABLE {$table} ".implode(', ', $clauses));
        }
    }
};
