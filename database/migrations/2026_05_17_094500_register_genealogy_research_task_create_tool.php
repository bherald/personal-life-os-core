<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $parameters = [
            'type' => 'object',
            'properties' => [
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID for the task'],
                'person_id' => ['type' => 'integer', 'description' => 'Optional person ID the task is about'],
                'task_type' => ['type' => 'string', 'description' => 'find_records, verify_facts, find_relatives, analyze_dna, suggest_sources, or transcribe_document'],
                'priority' => ['type' => 'string', 'description' => 'urgent, high, medium, or low'],
                'research_question' => ['type' => 'string', 'description' => 'Research question to queue'],
                'selection_reason' => ['type' => 'string', 'description' => 'Why this task should be queued'],
                'scope_reason' => ['type' => 'string', 'description' => 'Scope boundaries and evidence standard'],
                'related_people_used' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'sources_checked' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'evidence_summary' => ['type' => 'string'],
                'conflicts_found' => ['type' => 'string'],
                'outcome_state' => ['type' => 'string', 'default' => 'needs_research'],
                'outcome_reason' => ['type' => 'string'],
                'parameters' => ['type' => 'object'],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'confirm' => ['type' => 'boolean', 'default' => false],
                'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
            ],
            'required' => ['tree_id', 'task_type', 'priority', 'research_question'],
        ];

        DB::statement("
            INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description,
                 permissions, risk_level, category, requires_confirmation, max_calls_per_run,
                 mcp_server, mcp_tool, enabled, source, notes, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                service_class = VALUES(service_class),
                method = VALUES(method),
                description = VALUES(description),
                parameters = VALUES(parameters),
                returns_description = VALUES(returns_description),
                permissions = VALUES(permissions),
                risk_level = VALUES(risk_level),
                category = VALUES(category),
                requires_confirmation = VALUES(requires_confirmation),
                max_calls_per_run = VALUES(max_calls_per_run),
                mcp_server = VALUES(mcp_server),
                mcp_tool = VALUES(mcp_tool),
                enabled = VALUES(enabled),
                source = VALUES(source),
                notes = VALUES(notes),
                updated_at = NOW()
        ", [
            'research_task_create',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Dry-run-first creation of guarded genealogy research tasks.',
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'Returns the task creation dry-run plan or created genealogy_research_tasks id.',
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            20,
            'genealogy',
            'research_task_create',
            'MCP bridge registration for safe Genea research-task queueing; service enforces dry-run and confirm flags.',
        ]);
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'research_task_create')
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
