<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS rag_evaluations (
                id BIGSERIAL PRIMARY KEY,
                query TEXT NOT NULL,
                answer TEXT NOT NULL,
                metrics JSONB NOT NULL DEFAULT '{}',
                overall_score DECIMAL(5,4) NOT NULL DEFAULT 0,
                evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_evaluations_score ON rag_evaluations(overall_score)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_rag_evaluations_date ON rag_evaluations(evaluated_at)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS rag_evaluations");
    }
};
