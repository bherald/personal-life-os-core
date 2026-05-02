<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create consensus_verdicts table for multi-LLM consensus verification (FC-1).
 *
 * Tracks individual provider verdicts and consensus decisions for claims.
 * Based on LoCal (ACM Web 2025) multi-agent fact-checking pattern.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS consensus_verdicts (
                id BIGSERIAL PRIMARY KEY,
                claim_id BIGINT NOT NULL REFERENCES claims(id) ON DELETE CASCADE,
                provider_count INTEGER NOT NULL DEFAULT 0,
                agreement_ratio NUMERIC(4,3),
                consensus_verdict VARCHAR(20),
                consensus_confidence NUMERIC(5,3),
                devil_advocate_verdict VARCHAR(20),
                devil_advocate_confidence NUMERIC(5,3),
                provider_details JSONB NOT NULL DEFAULT '[]'::jsonb,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_consensus_verdicts_claim_id ON consensus_verdicts(claim_id)
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_consensus_verdicts_consensus_verdict ON consensus_verdicts(consensus_verdict)
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("DROP TABLE IF EXISTS consensus_verdicts");
    }
};
