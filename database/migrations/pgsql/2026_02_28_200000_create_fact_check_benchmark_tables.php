<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * FC-4: Fact-check benchmark tables for AVeriTeC evaluation.
     */
    public function up(): void
    {
        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS fact_check_benchmark_runs (
                id BIGSERIAL PRIMARY KEY,
                run_id VARCHAR(64) NOT NULL UNIQUE,
                dataset VARCHAR(50) NOT NULL DEFAULT 'averitec',
                split VARCHAR(20) NOT NULL DEFAULT 'dev',
                claims_evaluated INT NOT NULL DEFAULT 0,
                accuracy DECIMAL(5,4) NULL,
                macro_f1 DECIMAL(5,4) NULL,
                weighted_f1 DECIMAL(5,4) NULL,
                confusion_matrix JSONB NULL,
                per_class_metrics JSONB NULL,
                config JSONB NULL,
                avg_confidence_correct DECIMAL(5,4) NULL,
                avg_confidence_incorrect DECIMAL(5,4) NULL,
                avg_duration_ms INT NULL,
                total_duration_ms INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_benchmark_runs_created ON fact_check_benchmark_runs (created_at)
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE TABLE IF NOT EXISTS fact_check_benchmark_claims (
                id BIGSERIAL PRIMARY KEY,
                run_id VARCHAR(64) NOT NULL,
                claim_index INT NOT NULL,
                claim_text TEXT NOT NULL,
                gold_label VARCHAR(30) NOT NULL,
                predicted_label VARCHAR(30) NULL,
                confidence DECIMAL(5,4) NULL,
                evidence_count INT NULL,
                duration_ms INT NULL,
                correct BOOLEAN NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_benchmark_claims_run ON fact_check_benchmark_claims (run_id)
        ");
        DB::connection('pgsql_rag')->statement("
            CREATE INDEX IF NOT EXISTS idx_benchmark_claims_correct ON fact_check_benchmark_claims (correct)
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS fact_check_benchmark_claims");
        DB::connection('pgsql_rag')->statement("DROP TABLE IF EXISTS fact_check_benchmark_runs");
    }
};
