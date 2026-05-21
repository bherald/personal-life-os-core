<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'lesson_memory_lookup',
                'description' => 'Read compact reusable Genea research, OCR/document, source-capture, identity, and offline workflow lessons for a tree.',
                'risk_level' => 'read',
                'max_calls_per_run' => 50,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to search'],
                        'lesson_type' => ['type' => 'string', 'description' => 'all or one lesson type', 'default' => 'all'],
                        'query' => ['type' => 'string', 'description' => 'Optional text search over lesson title/value'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum lessons to return', 'default' => 20],
                    ],
                    'required' => ['tree_id'],
                ],
            ],
            [
                'name' => 'lesson_memory_save',
                'description' => 'Dry-run-first Genea MCP tool to store reusable research, document/OCR, source-capture, identity, and offline workflow lessons in tree-scoped semantic memory.',
                'risk_level' => 'write',
                'max_calls_per_run' => 20,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID the lesson applies to'],
                        'lesson_type' => ['type' => 'string', 'description' => 'research_process_lesson, document_interpretation_lesson, source_capture_lesson, identity_decision_lesson, or offline_workflow_lesson'],
                        'title' => ['type' => 'string', 'description' => 'Short reusable lesson title'],
                        'lesson' => ['type' => 'string', 'description' => 'Reviewed lesson text with enough context to reuse safely'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'person_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'media_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'task_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'dry_run' => ['type' => 'boolean', 'default' => true],
                        'confirm' => ['type' => 'boolean', 'default' => false],
                        'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
                        'confidence' => ['type' => 'number', 'default' => 0.8],
                    ],
                    'required' => ['tree_id', 'lesson_type', 'title', 'lesson'],
                ],
            ],
        ];

        foreach ($tools as $tool) {
            $permissions = ['genealogy:read'];
            if ($tool['risk_level'] === 'write') {
                $permissions[] = 'genealogy:write';
            }

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
                $tool['name'],
                'App\\Engine\\MCPRouter',
                'callTool',
                $tool['description'],
                json_encode($tool['parameters'], JSON_UNESCAPED_SLASHES),
                'Returns the Genea lesson-memory MCP payload with compact lookup rows or dry-run/write-audit status.',
                json_encode($permissions, JSON_UNESCAPED_SLASHES),
                $tool['risk_level'],
                'genealogy',
                0,
                $tool['max_calls_per_run'],
                'genealogy',
                $tool['name'],
                'MCP bridge registration for reusable local Genea lessons so agents can reuse research, OCR/document, source-capture, identity, and offline workflow wisdom.',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['lesson_memory_lookup', 'lesson_memory_save'])
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
