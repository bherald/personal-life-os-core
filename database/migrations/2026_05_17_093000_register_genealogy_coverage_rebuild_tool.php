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
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to rebuild'],
                'root_person_id' => ['type' => 'integer', 'description' => 'Optional root person override; defaults to genealogy_trees.root_person_id'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview status and write requirements only', 'default' => true],
                'confirm' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
            ],
            'required' => ['tree_id'],
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
            'coverage_rebuild',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Dry-run-first rebuild of genealogy ancestor paths and person coverage for one tree.',
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            'Returns before/after ancestor path and person coverage counts, stale-row counts, and rebuild timings.',
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            10,
            'genealogy',
            'coverage_rebuild',
            'MCP bridge registration for safe Genea coverage maintenance; service enforces dry-run and confirm flags.',
        ]);
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'coverage_rebuild')
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
