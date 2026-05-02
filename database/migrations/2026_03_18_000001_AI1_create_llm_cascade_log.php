<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI-1: Create llm_cascade_log table.
 *
 * Records every cascade evaluation (both escalated and non-escalated) so
 * operators can monitor escalation rates, tune thresholds, and identify
 * callers that should permanently target a stronger model.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS llm_cascade_log (
                id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                prompt_hash          VARCHAR(64)     NOT NULL COMMENT 'SHA-256 of prompt for dedup analysis',
                caller               VARCHAR(100)    NULL     COMMENT 'Agent name or service that called process()',
                initial_provider     VARCHAR(50)     NOT NULL,
                initial_model        VARCHAR(100)    NULL,
                escalated            TINYINT(1)      NOT NULL DEFAULT 0,
                escalation_reason    VARCHAR(255)    NULL,
                escalated_provider   VARCHAR(50)     NULL,
                escalated_model      VARCHAR(100)    NULL,
                quality_score        DECIMAL(5,4)    NULL     COMMENT 'Aggregate quality score 0.0–1.0',
                signals              JSON            NULL     COMMENT 'Per-signal scores',
                latency_initial_ms   INT UNSIGNED    NULL,
                latency_escalated_ms INT UNSIGNED    NULL,
                created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_lcl_escalated  (escalated),
                INDEX idx_lcl_caller     (caller),
                INDEX idx_lcl_created_at (created_at),
                INDEX idx_lcl_provider   (initial_provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_cascade_log');
    }
};
