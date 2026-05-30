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
                'agent_id' => ['type' => 'string', 'required' => false, 'description' => 'Optional agent id filter; defaults from runtime context when available'],
                'session_id' => ['type' => 'string', 'required' => false, 'description' => 'Optional session id filter; defaults from runtime context when available'],
                'hours' => ['type' => 'integer', 'default' => 168, 'description' => 'Lookback window in hours, capped at 2160'],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Maximum normalized steps to return, capped at 200'],
                'include_reviews' => ['type' => 'boolean', 'default' => true, 'description' => 'Include review outcome rows from agent_review_queue'],
                'include_fixture' => ['type' => 'boolean', 'default' => false, 'description' => 'Also include sanitized regression fixture labels with raw text omitted'],
                'scenario' => ['type' => 'string', 'required' => false, 'description' => 'Optional eval fixture scenario label'],
            ],
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
            'agent_trajectory_build',
            'App\\Services\\AgentTrajectoryService',
            'build',
            'Build a redacted read-only agent trajectory from retained audit/tool/review evidence. Can include a sanitized eval fixture with raw private content omitted.',
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'Normalized redacted trajectory steps with tool names, gates, outcomes, duration, error class, review result, and optional no-raw-text eval fixture.',
            json_encode(['system:read'], JSON_UNESCAPED_SLASHES),
            'read',
            'memory',
            0,
            2,
            4000,
            1,
            'config',
            'HWR-007: read-only trajectory/eval scaffold. Private data stays inside PLOS; fixture mode omits raw prompts, summaries, paths, secrets, and review text.',
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
            'max_result_bytes' => 8000,
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
            ->where('name', 'agent_trajectory_build')
            ->delete();
    }
};
