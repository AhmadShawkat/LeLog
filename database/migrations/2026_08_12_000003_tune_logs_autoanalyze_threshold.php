<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE logs SET (autovacuum_analyze_scale_factor = 0.40)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE logs RESET (autovacuum_analyze_scale_factor)');
    }
};
