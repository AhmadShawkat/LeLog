<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:rollback', ['--force' => true]);

        parent::tearDown();
    }

    public function test_fresh_postgresql_schema_has_the_exact_columns_constraints_and_indexes(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertTrue(DB::table('pg_extension')->where('extname', 'pg_trgm')->exists());
        self::assertTrue(DB::table('pg_extension')->where('extname', 'hstore')->exists());

        $columns = DB::select(<<<'SQL'
            SELECT column_name, data_type, is_nullable, column_default, identity_generation
            FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = 'logs'
            ORDER BY ordinal_position
            SQL);

        self::assertSame([
            ['id', 'bigint', 'NO', null, 'ALWAYS'],
            ['event_timestamp', 'timestamp with time zone', 'NO', null, null],
            ['received_at', 'timestamp with time zone', 'NO', 'clock_timestamp()', null],
            ['service', 'text', 'NO', null, null],
            ['level', 'text', 'NO', null, null],
            ['message', 'text', 'NO', null, null],
            ['attributes', 'jsonb', 'NO', "'{}'::jsonb", null],
            ['attributes_text', 'USER-DEFINED', 'NO', "''::hstore", null],
        ], array_map(static function (object $column): array {
            $values = (array) $column;

            return [
                $values['column_name'],
                $values['data_type'],
                $values['is_nullable'],
                $values['column_default'],
                $values['identity_generation'],
            ];
        }, $columns));

        $constraintRows = array_map(static fn (object $constraint): array => (array) $constraint, DB::select(<<<'SQL'
            SELECT conname, pg_get_constraintdef(oid) AS definition
            FROM pg_constraint
            WHERE conrelid = 'public.logs'::regclass
                AND contype IN ('c', 'p')
            ORDER BY conname
            SQL));
        $constraints = array_column($constraintRows, 'definition', 'conname');

        self::assertSame([
            'logs_attributes_object' => "CHECK ((jsonb_typeof(attributes) = 'object'::text))",
            'logs_pkey' => 'PRIMARY KEY (id)',
        ], $constraints);

        $indexRows = array_map(static fn (object $index): array => (array) $index, DB::select(<<<'SQL'
            SELECT
                index_class.relname AS name,
                pg_get_indexdef(index_class.oid) AS definition,
                index_class.reloptions,
                array_agg(operator_class.opcname ORDER BY key_position.ordinality) AS operator_classes
            FROM pg_index
            JOIN pg_class AS table_class ON table_class.oid = pg_index.indrelid
            JOIN pg_namespace ON pg_namespace.oid = table_class.relnamespace
            JOIN pg_class AS index_class ON index_class.oid = pg_index.indexrelid
            LEFT JOIN LATERAL unnest(pg_index.indclass) WITH ORDINALITY AS key_position(opclass_oid, ordinality) ON true
            LEFT JOIN pg_opclass AS operator_class ON operator_class.oid = key_position.opclass_oid
            WHERE pg_namespace.nspname = 'public' AND table_class.relname = 'logs'
            GROUP BY index_class.oid, index_class.relname, index_class.reloptions
            ORDER BY index_class.relname
            SQL));
        $indexes = array_column($indexRows, null, 'name');

        self::assertSame([
            'logs_attributes_text_gin_idx',
            'logs_event_timestamp_id_idx',
            'logs_message_trgm_idx',
            'logs_pkey',
        ], array_keys($indexes));
        self::assertStringContainsString('USING btree (event_timestamp DESC, id DESC)', (string) $indexes['logs_event_timestamp_id_idx']['definition']);
        self::assertSame('{gin_hstore_ops}', $indexes['logs_attributes_text_gin_idx']['operator_classes']);
        self::assertSame('{gin_trgm_ops}', $indexes['logs_message_trgm_idx']['operator_classes']);
        self::assertSame(
            ['fastupdate=on', 'gin_pending_list_limit=4096'],
            explode(',', trim((string) $indexes['logs_attributes_text_gin_idx']['reloptions'], '{}')),
        );
        self::assertStringContainsString('fastupdate=on', (string) $indexes['logs_message_trgm_idx']['reloptions']);

        $durability = (array) DB::selectOne(<<<'SQL'
            SELECT
                current_setting('fsync') AS fsync,
                current_setting('synchronous_commit') AS synchronous_commit,
                current_setting('full_page_writes') AS full_page_writes
            SQL);

        self::assertSame('on', $durability['fsync']);
        self::assertSame('on', $durability['synchronous_commit']);
        self::assertSame('on', $durability['full_page_writes']);
    }
}
