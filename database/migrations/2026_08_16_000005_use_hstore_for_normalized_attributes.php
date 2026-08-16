<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE logs ADD COLUMN attributes_hstore HSTORE NOT NULL DEFAULT ''::hstore");
        DB::unprepared(<<<'SQL'
            UPDATE logs AS target
            SET attributes_hstore = COALESCE(
                (
                    SELECT hstore(array_agg(entry.key), array_agg(entry.value))
                    FROM jsonb_each_text(target.attributes_text) AS entry
                ),
                ''::hstore
            )
            SQL);
        DB::unprepared('DROP INDEX logs_attributes_text_gin_idx');
        DB::unprepared('ALTER TABLE logs DROP CONSTRAINT logs_attributes_text_object');
        DB::unprepared('ALTER TABLE logs DROP COLUMN attributes_text');
        DB::unprepared('ALTER TABLE logs RENAME COLUMN attributes_hstore TO attributes_text');
        DB::unprepared(<<<'SQL'
            CREATE INDEX logs_attributes_text_gin_idx
                ON logs USING GIN (attributes_text)
                WITH (fastupdate = on)
            SQL);
    }

    public function down(): void
    {
        DB::unprepared("ALTER TABLE logs ADD COLUMN attributes_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb");
        DB::unprepared('UPDATE logs SET attributes_jsonb = hstore_to_jsonb(attributes_text)');
        DB::unprepared('DROP INDEX logs_attributes_text_gin_idx');
        DB::unprepared('ALTER TABLE logs DROP COLUMN attributes_text');
        DB::unprepared('ALTER TABLE logs RENAME COLUMN attributes_jsonb TO attributes_text');
        DB::unprepared(<<<'SQL'
            ALTER TABLE logs
            ADD CONSTRAINT logs_attributes_text_object
            CHECK (jsonb_typeof(attributes_text) = 'object')
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE INDEX logs_attributes_text_gin_idx
                ON logs USING GIN (attributes_text jsonb_path_ops)
                WITH (fastupdate = on)
            SQL);
    }
};
