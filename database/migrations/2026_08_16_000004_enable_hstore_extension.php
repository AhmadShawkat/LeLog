<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS hstore');
    }

    public function down(): void
    {
        DB::unprepared('DROP EXTENSION IF EXISTS hstore');
    }
};
