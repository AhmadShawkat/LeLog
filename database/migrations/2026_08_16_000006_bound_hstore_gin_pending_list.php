<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER INDEX logs_attributes_text_gin_idx SET (gin_pending_list_limit = 4096)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER INDEX logs_attributes_text_gin_idx RESET (gin_pending_list_limit)');
    }
};
