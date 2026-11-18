<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const SQLSTATE_UNIQUE = '23505';
const SQLSTATE_FOREIGN_KEY = '23503';
const SQLSTATE_CHECK = '23514';
const SQLSTATE_NOT_NULL = '23502';

function expectSqlState(string $sqlState, Closure $statement): void
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        expect($exception->getCode())->toBe($sqlState);

        return;
    }

    test()->fail("Expected SQLSTATE {$sqlState}, statement succeeded.");
}

/** @param array<string, mixed> $overrides */
function insertRow(string $table, array $overrides): string
{
    $id = (string) Str::uuid();
    DB::table($table)->insert(array_merge(['id' => $id, 'created_at' => now(), 'updated_at' => now()], $overrides));

    return $id;
}

function seedShop(string $domain): string
{
    return insertRow('shops', [
        'shop_domain' => $domain,
        'currency_code' => 'USD',
        'timezone' => 'America/New_York',
        'status' => 'active',
        'installed_at' => now(),
    ]);
}

function seedProduct(string $shopId, string $gid = 'gid://shopify/Product/1'): string
{
    return insertRow('products', [
        'shop_id' => $shopId,
        'shopify_product_gid' => $gid,
        'title' => 'Tee',
        'handle' => 'tee',
        'status' => 'ACTIVE',
    ]);
}

function seedVariant(string $shopId, string $productId, string $gid = 'gid://shopify/ProductVariant/1'): string
{
    return insertRow('product_variants', [
        'shop_id' => $shopId,
        'product_id' => $productId,
        'shopify_variant_gid' => $gid,
        'title' => 'M',
    ]);
}

function seedOrder(string $shopId, string $gid = 'gid://shopify/Order/1'): string
{
    return insertRow('orders', [
        'shop_id' => $shopId,
        'shopify_order_gid' => $gid,
        'currency_code' => 'USD',
    ]);
}

/** @param array<string, mixed> $overrides */
function seedLineItem(string $shopId, string $orderId, array $overrides = []): string
{
    return insertRow('order_line_items', array_merge([
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'shopify_line_item_gid' => 'gid://shopify/LineItem/' . Str::random(8),
        'title_snapshot' => 'Tee',
        'quantity' => 2,
        'original_unit_price' => '10.000000',
        'discount_amount' => '0',
        'currency_code' => 'USD',
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function seedRefundLine(string $shopId, string $orderId, array $overrides = []): string
{
    return insertRow('refund_line_items', array_merge([
        'shop_id' => $shopId,
        'order_id' => $orderId,
        'shopify_refund_gid' => 'gid://shopify/Refund/1',
        'shopify_refund_line_item_gid' => 'gid://shopify/RefundLineItem/1',
        'refunded_quantity' => 1,
        'refunded_subtotal' => '10.000000',
        'currency_code' => 'USD',
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function seedMetric(string $shopId, string $productId, array $overrides = []): string
{
    return insertRow('product_daily_metrics', array_merge([
        'shop_id' => $shopId,
        'product_id' => $productId,
        'metric_date' => '2026-11-01',
        'currency_code' => 'USD',
        'calculated_at' => now(),
    ], $overrides));
}

/** @return list<string> */
function indexDefinitions(string $table): array
{
    return array_map(
        fn (object $row): string => $row->indexdef,
        DB::select('select indexdef from pg_indexes where tablename = ?', [$table]),
    );
}

beforeEach(function (): void {
    $this->shopA = seedShop('a.myshopify.com');
    $this->shopB = seedShop('b.myshopify.com');
});

describe('idempotency', function (): void {
    it('rejects re-ingesting the same refund line item', function (): void {
        $orderId = seedOrder($this->shopA);
        seedRefundLine($this->shopA, $orderId);

        expectSqlState(SQLSTATE_UNIQUE, fn () => seedRefundLine($this->shopA, $orderId));
    });

    it('allows the same refund line item gid in different shops', function (): void {
        seedRefundLine($this->shopA, seedOrder($this->shopA));
        seedRefundLine($this->shopB, seedOrder($this->shopB));

        expect(DB::table('refund_line_items')->count())->toBe(2);
    });

    it('rejects duplicate subscription snapshots for one shop', function (): void {
        $row = ['shop_id' => $this->shopA, 'shopify_subscription_gid' => 'gid://shopify/AppSubscription/1', 'plan_key' => 'starter', 'status' => 'ACTIVE'];
        insertRow('subscriptions', $row);

        expectSqlState(SQLSTATE_UNIQUE, fn () => insertRow('subscriptions', $row));
    });
});

describe('tenant isolation', function (): void {
    it('rejects a variant pointing at another shop product', function (): void {
        $foreignProduct = seedProduct($this->shopB);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedVariant($this->shopA, $foreignProduct));
    });

    it('rejects a line item pointing at another shop order', function (): void {
        $foreignOrder = seedOrder($this->shopB);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedLineItem($this->shopA, $foreignOrder));
    });

    it('rejects a line item pointing at another shop product', function (): void {
        $orderId = seedOrder($this->shopA);
        $foreignProduct = seedProduct($this->shopB);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedLineItem($this->shopA, $orderId, ['product_id' => $foreignProduct]));
    });

    it('rejects a line item pointing at another shop variant', function (): void {
        $orderId = seedOrder($this->shopA);
        $foreignVariant = seedVariant($this->shopB, seedProduct($this->shopB));

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedLineItem($this->shopA, $orderId, ['variant_id' => $foreignVariant]));
    });

    it('rejects a refund line pointing at another shop order or line item', function (): void {
        $foreignOrder = seedOrder($this->shopB);
        $foreignLine = seedLineItem($this->shopB, $foreignOrder);
        $ownOrder = seedOrder($this->shopA);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedRefundLine($this->shopA, $foreignOrder));
        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedRefundLine($this->shopA, $ownOrder, ['line_item_id' => $foreignLine]));
    });

    it('rejects a metric row pointing at another shop product', function (): void {
        $foreignProduct = seedProduct($this->shopB);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => seedMetric($this->shopA, $foreignProduct));
    });
});

describe('history preservation', function (): void {
    it('supports soft deletes on products and variants', function (): void {
        expect(DB::getSchemaBuilder()->hasColumn('products', 'deleted_at'))->toBeTrue()
            ->and(DB::getSchemaBuilder()->hasColumn('product_variants', 'deleted_at'))->toBeTrue();
    });

    it('blocks hard-deleting a product that has metrics or line items', function (): void {
        $productId = seedProduct($this->shopA);
        seedMetric($this->shopA, $productId);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => DB::table('products')->where('id', $productId)->delete());

        $other = seedProduct($this->shopA, 'gid://shopify/Product/2');
        seedLineItem($this->shopA, seedOrder($this->shopA), ['product_id' => $other]);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => DB::table('products')->where('id', $other)->delete());
    });

    it('blocks hard-deleting a variant referenced by a line item', function (): void {
        $productId = seedProduct($this->shopA);
        $variantId = seedVariant($this->shopA, $productId);
        seedLineItem($this->shopA, seedOrder($this->shopA), ['product_id' => $productId, 'variant_id' => $variantId]);

        expectSqlState(SQLSTATE_FOREIGN_KEY, fn () => DB::table('product_variants')->where('id', $variantId)->delete());
    });

    it('purges every tenant row when the shop itself is deleted', function (): void {
        $productId = seedProduct($this->shopA);
        $variantId = seedVariant($this->shopA, $productId);
        $orderId = seedOrder($this->shopA);
        $lineId = seedLineItem($this->shopA, $orderId, ['product_id' => $productId, 'variant_id' => $variantId]);
        seedRefundLine($this->shopA, $orderId, ['line_item_id' => $lineId]);
        seedMetric($this->shopA, $productId);
        insertRow('sync_runs', ['shop_id' => $this->shopA, 'sync_type' => 'initial', 'status' => 'pending']);
        insertRow('webhook_events', ['shop_id' => $this->shopA, 'webhook_id' => 'w1', 'topic' => 'orders/create', 'payload_hash' => str_repeat('a', 64), 'status' => 'received', 'received_at' => now()]);
        insertRow('subscriptions', ['shop_id' => $this->shopA, 'plan_key' => 'free', 'status' => 'ACTIVE']);

        DB::table('shops')->where('id', $this->shopA)->delete();

        foreach (['products', 'product_variants', 'orders', 'order_line_items', 'refund_line_items', 'product_daily_metrics', 'sync_runs', 'webhook_events', 'subscriptions'] as $table) {
            expect(DB::table($table)->where('shop_id', $this->shopA)->count())->toBe(0, $table);
        }
    });
});

describe('value constraints', function (): void {
    it('rejects non-positive purchased quantity', function (): void {
        $orderId = seedOrder($this->shopA);

        expectSqlState(SQLSTATE_CHECK, fn () => seedLineItem($this->shopA, $orderId, ['quantity' => 0]));
        expectSqlState(SQLSTATE_CHECK, fn () => seedLineItem($this->shopA, $orderId, ['quantity' => -1]));
    });

    it('rejects negative unit price or discount', function (): void {
        $orderId = seedOrder($this->shopA);

        expectSqlState(SQLSTATE_CHECK, fn () => seedLineItem($this->shopA, $orderId, ['original_unit_price' => '-0.000001']));
        expectSqlState(SQLSTATE_CHECK, fn () => seedLineItem($this->shopA, $orderId, ['discount_amount' => '-1']));
    });

    it('rejects non-positive refunded quantity and negative refunded subtotal', function (): void {
        $orderId = seedOrder($this->shopA);

        expectSqlState(SQLSTATE_CHECK, fn () => seedRefundLine($this->shopA, $orderId, ['refunded_quantity' => 0]));
        expectSqlState(SQLSTATE_CHECK, fn () => seedRefundLine($this->shopA, $orderId, ['refunded_subtotal' => '-1']));
    });

    it('rejects currency codes that are not three uppercase letters', function (string $table): void {
        $orderId = seedOrder($this->shopA, 'gid://shopify/Order/' . Str::random(6));
        $productId = seedProduct($this->shopA, 'gid://shopify/Product/' . Str::random(6));

        $insert = match ($table) {
            'shops' => fn () => insertRow('shops', ['shop_domain' => 'c.myshopify.com', 'currency_code' => 'usd', 'timezone' => 'UTC', 'status' => 'active', 'installed_at' => now()]),
            'orders' => fn () => insertRow('orders', ['shop_id' => $this->shopA, 'shopify_order_gid' => 'gid://shopify/Order/x', 'currency_code' => 'us1']),
            'order_line_items' => fn () => seedLineItem($this->shopA, $orderId, ['currency_code' => 'us ']),
            'refund_line_items' => fn () => seedRefundLine($this->shopA, $orderId, ['currency_code' => 'Usd']),
            'product_daily_metrics' => fn () => seedMetric($this->shopA, $productId, ['currency_code' => 'usd']),
        };

        expectSqlState(SQLSTATE_CHECK, $insert);
    })->with(['shops', 'orders', 'order_line_items', 'refund_line_items', 'product_daily_metrics']);

    it('allows negative net sales and units on metric rows from refunds', function (): void {
        seedMetric($this->shopA, seedProduct($this->shopA), ['net_sales' => '-25.500000', 'units_sold' => -2, 'refunds' => '25.500000']);

        expect(DB::table('product_daily_metrics')->value('net_sales'))->toBe('-25.500000');
    });
});

describe('no silent defaults', function (): void {
    it('requires an explicit product status', function (): void {
        expectSqlState(SQLSTATE_NOT_NULL, fn () => insertRow('products', [
            'shop_id' => $this->shopA, 'shopify_product_gid' => 'gid://shopify/Product/9', 'title' => 't', 'handle' => 'h',
        ]));
    });

    it('requires an explicit subscription status and plan', function (): void {
        expectSqlState(SQLSTATE_NOT_NULL, fn () => insertRow('subscriptions', ['shop_id' => $this->shopA, 'plan_key' => 'free']));
        expectSqlState(SQLSTATE_NOT_NULL, fn () => insertRow('subscriptions', ['shop_id' => $this->shopA, 'status' => 'ACTIVE']));
    });
});

describe('indexes', function (): void {
    it('has no index that duplicates the leading columns of the metrics unique key', function (): void {
        expect(collect(indexDefinitions('product_daily_metrics'))->filter(fn (string $d): bool => str_contains($d, '(shop_id, product_id, metric_date)'))->all())
            ->toBe([]);
    });

    it('indexes every foreign key with shop_id leading', function (): void {
        $unindexed = DB::select(<<<'SQL'
            select c.conrelid::regclass::text as tbl, c.conname
            from pg_constraint c
            where c.contype = 'f'
              and c.conrelid::regclass::text in ('product_variants','orders','order_line_items','refund_line_items','product_daily_metrics','sync_runs','webhook_events','subscriptions','products')
              and not exists (
                select 1 from pg_index i
                where i.indrelid = c.conrelid
                  and (i.indkey::int2[])[0:array_length(c.conkey, 1) - 1] = c.conkey
              )
        SQL);

        expect($unindexed)->toBe([]);
    });

    it('serves dashboard range reads from the metrics range index', function (): void {
        DB::statement('set enable_seqscan = off');
        $plan = collect(DB::select("explain select * from product_daily_metrics where shop_id = ? and metric_date between '2026-10-01' and '2026-10-31'", [$this->shopA]))
            ->pluck('QUERY PLAN')->implode("\n");
        DB::statement('set enable_seqscan = on');

        expect($plan)->toContain('metrics_shop_date_idx');
    });
});
