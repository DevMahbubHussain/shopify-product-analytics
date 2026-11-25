<?php

declare(strict_types=1);

namespace App\Ingestion;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** PostgreSQL INSERT ... ON CONFLICT DO UPDATE that optionally skips rows whose freshness column is older than the stored row. */
final class GuardedUpsert
{
    private const MAX_BINDINGS = 60000;

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     * @return array<string, string> written rows keyed by the last unique column => id
     */
    public function __invoke(string $table, array $rows, array $uniqueBy, array $update, ?string $freshnessColumn = null): array
    {
        if ($rows === []) {
            return [];
        }

        $now = now()->utc();
        $rows = array_map(fn (array $row): array => array_map(
            $this->bindable(...),
            ['id' => (string) Str::uuid7(), ...$row, 'created_at' => $now, 'updated_at' => $now],
        ), $rows);

        $columns = array_keys($rows[0]);
        $written = [];

        foreach (array_chunk($rows, intdiv(self::MAX_BINDINGS, count($columns))) as $chunk) {
            $keyColumn = end($uniqueBy);
            foreach (DB::select($this->sql($table, $columns, count($chunk), $uniqueBy, [...$update, 'updated_at'], $freshnessColumn), $this->bindings($chunk)) as $row) {
                $written[$row->{$keyColumn}] = $row->id;
            }
        }

        return $written;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $update
     */
    private function sql(string $table, array $columns, int $rowCount, array $uniqueBy, array $update, ?string $freshnessColumn): string
    {
        $grammar = DB::getQueryGrammar();
        $wrap = fn (array $names): string => implode(', ', array_map($grammar->wrap(...), $names));
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $set = implode(', ', array_map(fn (string $c): string => $grammar->wrap($c).' = excluded.'.$grammar->wrap($c), $update));

        $sql = sprintf(
            'insert into %s (%s) values %s on conflict (%s) do update set %s',
            $grammar->wrapTable($table),
            $wrap($columns),
            implode(', ', array_fill(0, $rowCount, $rowPlaceholder)),
            $wrap($uniqueBy),
            $set,
        );

        if ($freshnessColumn !== null) {
            $current = $grammar->wrapTable($table).'.'.$grammar->wrap($freshnessColumn);
            $incoming = 'excluded.'.$grammar->wrap($freshnessColumn);
            $sql .= " where {$current} is null or ({$incoming} is not null and {$incoming} >= {$current})";
        }

        return $sql.' returning '.$wrap(['id', end($uniqueBy)]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<mixed>
     */
    private function bindings(array $rows): array
    {
        return array_merge(...array_map(array_values(...), $rows));
    }

    private function bindable(mixed $value): mixed
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->utc()->format('Y-m-d H:i:s.uP'),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \Stringable => (string) $value,
            default => $value,
        };
    }
}
