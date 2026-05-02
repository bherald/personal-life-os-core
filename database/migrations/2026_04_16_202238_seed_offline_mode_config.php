<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the routing.offline_mode kill switch in system_configs.
 *
 * When enabled, all external cloud LLM providers are blocked. Only local
 * Ollama instances handle traffic. Fail-closed: on any lookup error the code
 * treats the switch as enabled (safer to drop cloud than to leak personal data).
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::selectOne(
            'SELECT id FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
            ['routing', 'offline_mode']
        );

        if ($existing === null) {
            DB::insert(
                'INSERT INTO system_configs (section, config_key, config_value, data_type, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    'routing',
                    'offline_mode',
                    'disabled',
                    'string',
                    'Fail-closed offline kill switch for INTERNET-level cloud LLMs only. Values: disabled (default, cloud fallback active) | enabled (block external cloud LLM providers and APIs). LAN services (Nextcloud, MySQL, PostgreSQL, Redis, local Ollama instances, local MCP, queue workers, scheduled jobs) stay fully online. See docs/OLLAMA-COMPATIBILITY.md and docs/AIService-LLM-Gateway.md.',
                ]
            );
        }
    }

    public function down(): void
    {
        DB::delete(
            'DELETE FROM system_configs WHERE section = ? AND config_key = ?',
            ['routing', 'offline_mode']
        );
    }
};
