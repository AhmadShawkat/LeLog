<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LogIngestionEndpointTest extends TestCase
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

    public function test_it_partially_accepts_and_durably_inserts_with_one_bound_statement(): void
    {
        $insertStatements = 0;

        DB::listen(static function (QueryExecuted $query) use (&$insertStatements): void {
            if (str_starts_with(ltrim($query->sql), 'INSERT INTO logs')) {
                $insertStatements++;
                self::assertCount(6, $query->bindings);
            }
        });

        $response = $this->withHeader('Authorization', 'Bearer ignored-by-design')->postJson('/logs', [
            'logs' => [
                $this->validEntry([
                    'attributes' => ['request_id' => 'a,b', 'attempt' => 2, 'cached' => false],
                ]),
                $this->validEntry(['level' => 'fatal']),
                $this->validEntry(['timestamp' => now()->addMinutes(6)->toIso8601String()]),
            ],
        ]);

        $response->assertOk()->assertExactJson([
            'accepted' => 1,
            'rejected' => [
                ['index' => 1, 'reason' => 'level must be one of debug, info, warn, or error'],
                ['index' => 2, 'reason' => 'timestamp must not be more than five minutes in the future'],
            ],
        ]);
        self::assertMatchesRegularExpression(
            '/json_decode;dur=\d+\.\d{3}, validation_transform;dur=\d+\.\d{3}, pg_array_encoding;dur=\d+\.\d{3}, connection_wait;dur=\d+\.\d{3}, sql_execution;dur=\d+\.\d{3}, complete_request;dur=\d+\.\d{3}/',
            (string) $response->headers->get('Server-Timing'),
        );

        self::assertSame(1, $insertStatements);
        self::assertSame(1, DB::table('logs')->count());

        $row = DB::selectOne('SELECT attributes, attributes_text FROM logs');
        self::assertNotNull($row);
        self::assertEquals(
            ['request_id' => 'a,b', 'attempt' => 2, 'cached' => false],
            json_decode($row->attributes, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertEquals(
            ['request_id' => 'a,b', 'attempt' => '2', 'cached' => 'false'],
            json_decode($row->attributes_text, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_all_invalid_entries_return_400_without_inserting(): void
    {
        $this->postJson('/logs', ['logs' => [
            $this->validEntry(['message' => '']),
            ['not' => 'a log'],
        ]])->assertBadRequest()->assertJsonPath('accepted', 0)->assertJsonCount(2, 'rejected');

        self::assertSame(0, DB::table('logs')->count());
    }

    public function test_malformed_and_invalid_top_level_bodies_return_400(): void
    {
        $this->call('POST', '/logs', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')
            ->assertBadRequest()
            ->assertExactJson(['error' => 'Malformed JSON.']);

        $this->postJson('/logs', ['logs' => [$this->validEntry()], 'extra' => true])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'The body must contain exactly one property: logs.']);
    }

    public function test_the_configured_batch_maximum_is_enforced(): void
    {
        config()->set('logs.ingestion.max_batch_size', 1);

        $this->postJson('/logs', ['logs' => [$this->validEntry(), $this->validEntry()]])
            ->assertBadRequest()
            ->assertExactJson(['error' => 'The logs array must contain at most 1 entries.']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validEntry(array $overrides = []): array
    {
        return array_replace([
            'timestamp' => now()->subSecond()->toIso8601String(),
            'level' => 'info',
            'service' => 'checkout',
            'message' => 'paid',
        ], $overrides);
    }
}
