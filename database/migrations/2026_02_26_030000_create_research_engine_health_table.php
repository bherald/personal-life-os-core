<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Create research_engine_health table (MySQL) ─────────────────
        DB::statement("
            CREATE TABLE IF NOT EXISTS research_engine_health (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                engine_name VARCHAR(50) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                status ENUM('healthy', 'degraded', 'failed', 'unknown') NOT NULL DEFAULT 'unknown',
                last_check_at TIMESTAMP NULL,
                last_success_at TIMESTAMP NULL,
                last_failure_at TIMESTAMP NULL,
                consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
                consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
                total_checks INT UNSIGNED NOT NULL DEFAULT 0,
                total_successes INT UNSIGNED NOT NULL DEFAULT 0,
                total_failures INT UNSIGNED NOT NULL DEFAULT 0,
                total_timeouts INT UNSIGNED NOT NULL DEFAULT 0,
                avg_response_time_ms INT UNSIGNED NULL,
                last_error_message TEXT NULL,
                last_error_type VARCHAR(30) NULL,
                circuit_breaker_state ENUM('closed', 'open', 'half_open') NOT NULL DEFAULT 'closed',
                circuit_breaker_opened_at TIMESTAMP NULL,
                is_api_key_configured TINYINT(1) NOT NULL DEFAULT 1,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                chain_position TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Position in fallback chain (1=first)',
                alert_sent TINYINT(1) NOT NULL DEFAULT 0,
                alert_sent_at TIMESTAMP NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_engine_name (engine_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed the 6 engines in fallback chain order
        $engines = [
            ['newsapi',       'NewsAPI',        1],
            ['gnews',         'GNews API',      2],
            ['wikipedia',     'Wikipedia API',  3],
            ['searxng',       'SearXNG',        4],
            ['curl_scraper',  'Curl Scraper',   5],
            ['puppeteer',     'Puppeteer',      6],
        ];

        foreach ($engines as [$name, $display, $pos]) {
            DB::insert("
                INSERT IGNORE INTO research_engine_health (engine_name, display_name, chain_position)
                VALUES (?, ?, ?)
            ", [$name, $display, $pos]);
        }

        // ── Register agent tool ─────────────────────────────────────────
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, enabled, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config')
            ", [
                'research_update_engine_health',
                'App\\Services\\ResearchEngineHealthService',
                'updateAllEngineHealth',
                'Snapshot current health of all research engines into research_engine_health table. Polls engine status, circuit breaker state, and API key config. Returns per-engine health summary with chain-level assessment. Other agents (genealogy-researcher) can read this passively to skip known-dead engines.',
                '[]',
                'Array with per-engine health records and chain_summary (healthy/degraded/failed count, overall status)',
                '["research:read", "system:write"]',
                'write',
                'research',
            ]);
        } catch (\Exception $e) {
            // Skip if already exists
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS research_engine_health");
        DB::delete("DELETE FROM agent_tool_registry WHERE name = 'research_update_engine_health'");
    }
};
