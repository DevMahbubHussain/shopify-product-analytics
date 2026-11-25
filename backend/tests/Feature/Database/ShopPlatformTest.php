<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function platformShopRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'shop_domain' => 'platform-test.myshopify.com',
        'currency_code' => 'USD',
        'timezone' => 'UTC',
        'status' => 'active',
        'installed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

test(
    'shops.platform is a non-null varchar(16) defaulting to shopify', function (): void {
        $column = DB::selectOne(
            "select data_type, character_maximum_length, is_nullable, column_default
         from information_schema.columns
         where table_name = 'shops' and column_name = 'platform'"
        );

        expect($column)->not->toBeNull()
            ->and($column->data_type)->toBe('character varying')
            ->and($column->character_maximum_length)->toBe(16)
            ->and($column->is_nullable)->toBe('NO')
            ->and($column->column_default)->toContain("'shopify'");
    }
);

test(
    'shop inserted without platform defaults to shopify', function (): void {
        $row = platformShopRow();
        DB::table('shops')->insert($row);

        expect(DB::table('shops')->where('id', $row['id'])->value('platform'))->toBe('shopify');
    }
);

test(
    'shops.platform rejects null', function (): void {
        try {
            DB::table('shops')->insert(platformShopRow(['platform' => null]));
            $this->fail('Expected NOT NULL violation.');
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('23502');
        }
    }
);

test(
    'shop model casts platform to the Platform enum', function (): void {
        $row = platformShopRow();
        DB::table('shops')->insert($row);

        expect(Shop::query()->findOrFail($row['id'])->platform)->toBe(Platform::Shopify);
    }
);
