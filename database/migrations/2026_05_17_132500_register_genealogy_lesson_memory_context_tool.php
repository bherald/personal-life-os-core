<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
            'lesson_memory_context',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Read compact reusable Genea lessons for a tree/person/media/source/task context without exposing raw memory tables.',
            json_encode([
                'type' => 'object',
                'properties' => [
                    'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to search'],
                    'person_id' => ['type' => 'integer', 'description' => 'Optional same-tree person ID'],
                    'media_id' => ['type' => 'integer', 'description' => 'Optional same-tree media ID'],
                    'source_id' => ['type' => 'integer', 'description' => 'Optional same-tree source ID'],
                    'task_id' => ['type' => 'integer', 'description' => 'Optional same-tree research task ID'],
                    'query' => ['type' => 'string', 'description' => 'Optional extra text query'],
                    'lesson_type' => ['type' => 'string', 'description' => 'all or one lesson type', 'default' => 'all'],
                    'limit' => ['type' => 'integer', 'description' => 'Maximum lessons to return', 'default' => 8],
                ],
                'required' => ['tree_id'],
            ], JSON_UNESCAPED_SLASHES),
            'Returns compact lesson rows and a prompt-ready guardrail context_text for the requested tree/entity context.',
            json_encode(['genealogy:read'], JSON_UNESCAPED_SLASHES),
            'read',
            'genealogy',
            0,
            50,
            'genealogy',
            'lesson_memory_context',
            'MCP bridge registration for context-aware Genea lesson retrieval so agents can inject relevant local lessons without raw memory SQL.',
        ]);
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'lesson_memory_context')
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
