<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE logs (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                event_timestamp TIMESTAMPTZ NOT NULL,
                received_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
                service TEXT NOT NULL,
                level TEXT NOT NULL,
                message TEXT NOT NULL,
                attributes JSONB NOT NULL DEFAULT '{}'::jsonb,
                attributes_text JSONB NOT NULL DEFAULT '{}'::jsonb,
                CONSTRAINT logs_attributes_object
                    CHECK (jsonb_typeof(attributes) = 'object'),
                CONSTRAINT logs_attributes_text_object
                    CHECK (jsonb_typeof(attributes_text) = 'object')
            )
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE INDEX logs_event_timestamp_id_idx
                ON logs (event_timestamp DESC, id DESC)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE INDEX logs_attributes_text_gin_idx
                ON logs USING GIN (attributes_text jsonb_path_ops)
                WITH (fastupdate = on)
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE INDEX logs_message_trgm_idx
                ON logs USING GIN (message gin_trgm_ops)
                WITH (fastupdate = on)
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS logs');
    }
};
