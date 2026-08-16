<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogQueryEndpointTest extends TestCase
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

    public function test_filters_are_freely_combined_and_query_values_remain_bound(): void
    {
        $this->insertLog('2026-08-12T10:00:00Z', 'checkout', 'error', 'Payment failed 100%_literal', [
            'request_id' => 'abc',
            'attempt' => 2,
        ]);
        $this->insertLog('2026-08-12T10:01:00Z', 'checkout', 'error', 'Payment failed 100x literal', [
            'request_id' => 'abc',
            'attempt' => 2,
        ]);
        $this->insertLog('2026-08-12T10:02:00Z', 'billing', 'warn', 'Payment failed 100%_literal', [
            'request_id' => 'abc',
            'attempt' => 2,
        ]);

        $select = null;
        DB::listen(static function (QueryExecuted $query) use (&$select): void {
            if (str_contains($query->sql, 'FROM logs') && str_contains($query->sql, 'ORDER BY event_timestamp')) {
                $select = $query;
            }
        });

        $response = $this->getJson('/logs?'.http_build_query([
            'service' => 'checkout',
            'level' => 'error',
            'since' => '2026-08-12T09:59:59Z',
            'until' => '2026-08-12T10:00:01Z',
            'attr.request_id' => 'abc',
            'attr.attempt' => '2',
            'q' => '100%_literal',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'logs')
            ->assertJsonPath('logs.0.service', 'checkout')
            ->assertJsonPath('logs.0.message', 'Payment failed 100%_literal')
            ->assertJsonPath('logs.0.attributes.attempt', 2)
            ->assertJsonPath('next_cursor', null);

        self::assertInstanceOf(QueryExecuted::class, $select);
        self::assertStringNotContainsString('OFFSET', strtoupper($select->sql));
        self::assertStringNotContainsString('checkout', $select->sql);
        self::assertContains('checkout', $select->bindings);
    }

    public function test_keyset_pagination_does_not_skip_logs_with_the_same_timestamp(): void
    {
        $timestamp = '2026-08-12T10:00:00.123456Z';
        $ids = [
            $this->insertLog($timestamp, 'api', 'info', 'first'),
            $this->insertLog($timestamp, 'api', 'info', 'second'),
            $this->insertLog($timestamp, 'api', 'info', 'third'),
        ];
        $olderId = $this->insertLog('2026-08-12T09:59:59Z', 'api', 'info', 'older');

        $first = $this->getJson('/logs?limit=2')->assertOk()->json();

        self::assertSame([$ids[2], $ids[1]], array_column($first['logs'], 'id'));
        self::assertIsString($first['next_cursor']);

        $second = $this->getJson('/logs?limit=2&cursor='.rawurlencode($first['next_cursor']))->assertOk()->json();

        self::assertSame([$ids[0], $olderId], array_column($second['logs'], 'id'));
        self::assertNull($second['next_cursor']);
    }

    public function test_default_and_maximum_limits_are_enforced(): void
    {
        for ($index = 0; $index < 101; $index++) {
            $this->insertLog('2026-08-12T10:00:00Z', 'api', 'debug', "log-$index");
        }

        $this->getJson('/logs')->assertOk()->assertJsonCount(100, 'logs')->assertJsonPath('next_cursor', fn ($value): bool => is_string($value));
        $this->getJson('/logs?limit=1000')->assertOk()->assertJsonCount(101, 'logs')->assertJsonPath('next_cursor', null);
        $this->getJson('/logs?limit=1001')->assertBadRequest()->assertJsonStructure(['error']);
    }

    public function test_invalid_and_injection_queries_return_safe_results(): void
    {
        $this->insertLog('2026-08-12T10:00:00Z', 'checkout', 'info', 'normal', ['tenant' => 'one']);

        $this->getJson('/logs?cursor=malformed')->assertBadRequest()->assertJsonStructure(['error']);
        $this->getJson('/logs?since=2026-08-13T00%3A00%3A00Z&until=2026-08-12T00%3A00%3A00Z')
            ->assertBadRequest()
            ->assertJsonStructure(['error']);
        $this->getJson('/logs?'.http_build_query(['service' => "checkout' OR 1=1 --"]))
            ->assertOk()
            ->assertExactJson(['logs' => [], 'next_cursor' => null]);
        $this->getJson('/logs?'.http_build_query(['attr.tenant' => "one' OR 1=1 --"]))
            ->assertOk()
            ->assertExactJson(['logs' => [], 'next_cursor' => null]);
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
    ): int {
        $attributesText = array_map(
            static fn (string|int|float|bool $value): string => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
            $attributes,
        );
        $row = DB::selectOne(<<<'SQL'
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
            RETURNING id
            SQL, [
            $timestamp,
            $service,
            $level,
            $message,
            json_encode((object) $attributes, JSON_THROW_ON_ERROR),
            json_encode((object) $attributesText, JSON_THROW_ON_ERROR),
        ]);

        self::assertNotNull($row);

        return (int) $row->id;
    }
}
