<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        $parameters = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search terms for historical agent/session/job trace excerpts'],
                'limit' => ['type' => 'integer', 'default' => 8, 'description' => 'Maximum results to return, capped at 20'],
                'hours' => ['type' => 'integer', 'default' => 168, 'description' => 'Lookback window in hours, capped at 2160'],
                'sources' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional source filters: session_messages, agent_episodes, agent_episode_summaries, agent_messages, agent_execution_log, scheduled_job_runs'],
                'agent_id' => ['type' => 'string', 'required' => false, 'description' => 'Optional agent id filter; defaults from runtime context when available'],
                'session_id' => ['type' => 'string', 'required' => false, 'description' => 'Optional session id filter; defaults from runtime context when available'],
                'context_chars' => ['type' => 'integer', 'default' => 90, 'description' => 'Characters before/after the match in each excerpt, capped at 240'],
            ],
            'required' => ['query'],
        ];

        $columns = [
            'name',
            'service_class',
            'method',
            'description',
            'parameters',
            'returns_description',
            'permissions',
            'risk_level',
            'category',
            'requires_confirmation',
            'max_calls_per_run',
            'max_tokens_per_call',
            'enabled',
            'source',
            'notes',
            'created_at',
            'updated_at',
        ];
        $values = [
            'agent_session_search',
            'App\\Services\\AgentSessionSearchService',
            'search',
            'Search recent agent session, episode, inter-agent message, audit, and scheduled-job history. Returns bounded redacted excerpts labeled as historical traces, not authoritative facts.',
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'Bounded redacted historical trace excerpts with source, timestamp, agent, session, and field metadata.',
            json_encode(['system:read'], JSON_UNESCAPED_SLASHES),
            'read',
            'memory',
            0,
            3,
            3000,
            1,
            'config',
            'HWR-006: read-only session recall search. Private data stays inside PLOS; excerpts are bounded, redacted, and explicitly non-authoritative.',
            now(),
            now(),
        ];
        $updates = [
            'service_class = VALUES(service_class)',
            'method = VALUES(method)',
            'description = VALUES(description)',
            'parameters = VALUES(parameters)',
            'returns_description = VALUES(returns_description)',
            'permissions = VALUES(permissions)',
            'risk_level = VALUES(risk_level)',
            'category = VALUES(category)',
            'requires_confirmation = VALUES(requires_confirmation)',
            'max_calls_per_run = VALUES(max_calls_per_run)',
            'max_tokens_per_call = VALUES(max_tokens_per_call)',
            'enabled = VALUES(enabled)',
            'source = VALUES(source)',
            'notes = VALUES(notes)',
            'updated_at = VALUES(updated_at)',
        ];

        foreach ([
            'max_result_bytes' => 6000,
            'availability_status' => 'available',
            'schema_generation' => 2,
            'privacy_class' => 'internal_private',
            'allows_private_data' => 1,
        ] as $column => $value) {
            if (! Schema::hasColumn('agent_tool_registry', $column)) {
                continue;
            }

            $columns[] = $column;
            $values[] = $value;
            $updates[] = "{$column} = VALUES({$column})";
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        DB::statement(
            'INSERT INTO agent_tool_registry ('.implode(', ', $columns).") VALUES ({$placeholders}) ".
            'ON DUPLICATE KEY UPDATE '.implode(', ', $updates),
            $values
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return;
        }

        DB::table('agent_tool_registry')
            ->where('name', 'agent_session_search')
            ->delete();
    }
};
