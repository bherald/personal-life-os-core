<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SC-3+SC-4: Promote remaining hardcoded service constants to system_configs table.
 *
 * These 13 values were the last hardcoded constants identified in the scalability audit.
 * Services will read via SystemConfigService (Redis-cached, DB-backed, hardcoded fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fix unique constraint: section+config_key compound instead of config_key alone.
        // This allows same key name in different sections (e.g., scraping.default_timeout vs workflow_defaults.default_timeout).
        try {
            DB::statement("ALTER TABLE system_configs DROP INDEX system_configs_config_key_unique");
            DB::statement("ALTER TABLE system_configs ADD UNIQUE INDEX system_configs_section_key_unique (section, config_key)");
        } catch (\Throwable $e) {
            // Index may already be correct
        }

        $configs = [
            // SafeScrapingService
            ['scraping', 'max_content_size', '5242880', 'int', 'Max content download size in bytes (5MB)'],
            ['scraping', 'max_response_time_ms', '30000', 'int', 'Max response time in milliseconds'],
            ['scraping', 'global_rate_limit_per_min', '100', 'int', 'Global requests per minute across all domains'],
            ['scraping', 'per_domain_rate_limit', '30', 'int', 'Max requests per minute per domain'],
            ['scraping', 'default_timeout', '30', 'int', 'Default HTTP timeout in seconds'],
            ['scraping', 'sandbox_timeout', '45', 'int', 'Sandbox/Puppeteer timeout in seconds'],

            // EmailRateLimitService
            ['email', 'daily_send_limit', '100', 'int', 'Max emails per mailbox per day'],
            ['email', 'hourly_send_limit', '20', 'int', 'Max emails per mailbox per hour'],
            ['email', 'cooldown_minutes', '30', 'int', 'Auto-cooldown duration when limit hit'],

            // EntityResolutionService
            ['entity_resolution', 'auto_merge_threshold', '0.95', 'float', 'Cosine similarity threshold for auto-merge (no LLM needed)'],
            ['entity_resolution', 'llm_compare_threshold', '0.75', 'float', 'Cosine similarity threshold to trigger LLM comparison'],
            ['entity_resolution', 'llm_merge_confidence', '0.85', 'float', 'LLM confidence threshold to approve merge'],
        ];

        $now = now()->toDateTimeString();

        foreach ($configs as [$section, $key, $value, $type, $desc]) {
            $exists = DB::selectOne(
                "SELECT id FROM system_configs WHERE section = ? AND config_key = ?",
                [$section, $key]
            );

            if (!$exists) {
                DB::insert(
                    "INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$section, $key, $value, $type, $desc, $now, $now]
                );
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM system_configs WHERE section IN ('scraping', 'entity_resolution')");
        DB::delete("DELETE FROM system_configs WHERE section = 'email' AND config_key IN ('daily_send_limit', 'hourly_send_limit', 'cooldown_minutes')");
    }
};
