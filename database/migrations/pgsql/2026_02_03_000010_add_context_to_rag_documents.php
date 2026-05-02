<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Add context_prefix column for contextual retrieval
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS context_prefix TEXT NULL
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents
            ADD COLUMN IF NOT EXISTS contextualized_at TIMESTAMP NULL
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS context_prefix
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE rag_documents DROP COLUMN IF EXISTS contextualized_at
        ");
    }
};
