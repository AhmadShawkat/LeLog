<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogAggregationEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        DB::purge();
        Artisan::call('migrate:rollback', ['--force' => true]);

        parent::tearDown();
    }

    public function test_it_aggregates_combined_filters_in_utc_with_bound_values(): void
    {
        $this->insertLog('2026-08-12T10:01:00Z', 'checkout', 'error', 'Payment failed 100%_literal', ['tenant' => 'one']);
        $this->insertLog('2026-08-12T10:04:59Z', 'checkout', 'error', 'Payment failed 100%_literal', ['tenant' => 'one']);
        $this->insertLog('2026-08-12T10:06:00Z', 'checkout', 'error', 'Payment failed 100%_literal', ['tenant' => 'one']);
        $this->insertLog('2026-08-12T10:03:00Z', 'billing', 'error', 'Payment failed 100%_literal', ['tenant' => 'one']);
        $this->insertLog('2026-08-12T10:03:00Z', 'checkout', 'warn', 'Payment failed 100%_literal', ['tenant' => 'one']);

        $select = null;
        DB::listen(static function (QueryExecuted $query) use (&$select): void {
            if (str_contains($query->sql, 'date_bin')) {
                $select = $query;
            }
        });

        $response = $this->getJson('/logs/aggregate?'.http_build_query([
            'since' => '2026-08-12T12:00:00+02:00',
            'until' => '2026-08-12T10:10:00Z',
            'bucket' => '5m',
            'group_by' => 'service',
            'service' => 'checkout',
            'level' => 'error',
            'attr.tenant' => 'one',
            'q' => '100%_literal',
        ]));

        $response->assertOk()->assertExactJson(['buckets' => [
            ['bucket' => '2026-08-12T10:00:00.000000Z', 'group' => 'checkout', 'count' => 2],
            ['bucket' => '2026-08-12T10:05:00.000000Z', 'group' => 'checkout', 'count' => 1],
        ]]);

        self::assertInstanceOf(QueryExecuted::class, $select);
        self::assertStringContainsString("date_bin(INTERVAL '5 minutes'", $select->sql);
        self::assertStringContainsString("TIMESTAMPTZ '2001-01-01 00:00:00+00'", $select->sql);
        self::assertStringNotContainsString('checkout', $select->sql);
        self::assertContains('checkout', $select->bindings);
    }

    public function test_grouped_rows_have_exact_order_and_ungrouped_rows_use_null(): void
    {
        $this->insertLog('2026-08-12T10:01:00Z', 'api', 'warn', 'one');
        $this->insertLog('2026-08-12T10:02:00Z', 'api', 'error', 'two');
        $this->insertLog('2026-08-12T11:01:00Z', 'api', 'warn', 'three');

        $range = 'since=2026-08-12T10%3A00%3A00Z&until=2026-08-12T12%3A00%3A00Z&bucket=1h';

        $this->getJson("/logs/aggregate?$range&group_by=level")
            ->assertOk()
            ->assertExactJson(['buckets' => [
                ['bucket' => '2026-08-12T10:00:00.000000Z', 'group' => 'error', 'count' => 1],
                ['bucket' => '2026-08-12T10:00:00.000000Z', 'group' => 'warn', 'count' => 1],
                ['bucket' => '2026-08-12T11:00:00.000000Z', 'group' => 'warn', 'count' => 1],
            ]]);

        $this->getJson("/logs/aggregate?$range")
            ->assertOk()
            ->assertExactJson(['buckets' => [
                ['bucket' => '2026-08-12T10:00:00.000000Z', 'group' => null, 'count' => 2],
                ['bucket' => '2026-08-12T11:00:00.000000Z', 'group' => null, 'count' => 1],
            ]]);
    }

    public function test_empty_ranges_and_invalid_queries_return_the_exact_contract(): void
    {
        $valid = 'since=2026-08-12T10%3A00%3A00Z&until=2026-08-12T11%3A00%3A00Z&bucket=1m';

        $this->getJson("/logs/aggregate?$valid")->assertOk()->assertExactJson(['buckets' => []]);
        $this->getJson('/logs/aggregate?bucket=1h')->assertBadRequest()->assertJsonStructure(['error']);
        $this->getJson("/logs/aggregate?$valid&group_by=service%2Cmessage")
            ->assertBadRequest()
            ->assertJsonStructure(['error']);
    }

    /**
     * @param  array<string, string|int|float|bool>  $attributes
     */
    private function insertLog(
        string $timestamp,
        string $service,
        string $level,
        string $message,
        array $attributes = [],
    ): void {
        $attributesText = array_map(
            static fn (string|int|float|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $attributes,
        );

        DB::insert(<<<'SQL'
            INSERT INTO logs (event_timestamp, service, level, message, attributes, attributes_text)
            VALUES (
                CAST(? AS timestamptz),
                ?,
                ?,
                ?,
                CAST(? AS jsonb),
                COALESCE(
                    (SELECT hstore(array_agg(entry.key), array_agg(entry.value)) FROM jsonb_each_text(CAST(? AS jsonb)) AS entry),
                    ''::hstore
                )
            )
            SQL, [
            $timestamp,
            $service,
            $level,
            $message,
            json_encode((object) $attributes, JSON_THROW_ON_ERROR),
            json_encode((object) $attributesText, JSON_THROW_ON_ERROR),
        ]);
    }
}
