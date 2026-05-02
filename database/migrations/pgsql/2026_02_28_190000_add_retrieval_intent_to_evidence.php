<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE evidence
            ADD COLUMN IF NOT EXISTS retrieval_intent VARCHAR(20) DEFAULT 'general'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE evidence DROP COLUMN IF EXISTS retrieval_intent
        ");
    }
};
