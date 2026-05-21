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
                'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns all link targets'],
                'url' => ['type' => 'string', 'description' => 'Vetted http/https evidence asset URL to capture'],
                'source_id' => ['type' => 'integer', 'description' => 'Optional same-tree genealogy source ID'],
                'person_id' => ['type' => 'integer', 'description' => 'Optional same-tree person ID'],
                'family_id' => ['type' => 'integer', 'description' => 'Optional same-tree family ID'],
                'label' => ['type' => 'string', 'description' => 'Optional media title/filename label'],
                'asset_type' => ['type' => 'string', 'description' => 'Optional asset type hint'],
                'content_type' => ['type' => 'string', 'description' => 'Optional MIME hint such as image/jp2'],
                'dry_run' => ['type' => 'boolean', 'description' => 'Preview only when true', 'default' => true],
                'confirm' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
                'confirm_download' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
                'confirm_storage_write' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
                'confirm_genealogy_link' => ['type' => 'boolean', 'description' => 'Create person/family/source media links when executing', 'default' => false],
                'max_bytes' => ['type' => 'integer', 'description' => 'Optional per-file download byte cap'],
                'actor' => ['type' => 'string', 'description' => 'Audit actor label', 'default' => 'genea-agent'],
            ],
            'required' => ['tree_id', 'url'],
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
            'evidence_capture_direct',
            'App\\Engine\\MCPRouter',
            'callTool',
            'Dry-run-first one-off capture of a vetted evidence URL into tree-scoped FT storage with optional source/person/family linking.',
            json_encode($parameters),
            'Returns a dry-run preview or an execution payload with saved media IDs, link scopes, and write-audit receipt.',
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            10,
            'genealogy',
            'evidence_capture_direct',
            'MCP bridge registration for direct vetted Genea evidence media capture; service enforces dry-run and download/storage confirmation flags.',
        ]);
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->where('name', 'evidence_capture_direct')
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
