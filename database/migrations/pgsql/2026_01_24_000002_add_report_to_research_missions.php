<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Add report column to store the synthesized research report
        DB::connection($this->connection)->statement("
            ALTER TABLE research_missions
            ADD COLUMN IF NOT EXISTS report TEXT
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE research_missions
            DROP COLUMN IF EXISTS report
        ");
    }
};
