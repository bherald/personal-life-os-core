<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'source_gap_decision_lookup',
                'description' => 'Read compact genealogy source-gap decision memory so agents avoid repeating weak or collateral evidence reviews.',
                'mcp_tool' => 'source_gap_decision_lookup',
                'risk_level' => 'read',
                'max_calls_per_run' => 30,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to search'],
                        'person_id' => ['type' => 'integer', 'description' => 'Optional person ID to inspect'],
                        'decision' => ['type' => 'string', 'description' => 'Optional decision filter or all', 'default' => 'all'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum matches to return', 'default' => 50],
                    ],
                    'required' => ['tree_id'],
                ],
            ],
            [
                'name' => 'source_gap_decision_add',
                'description' => 'Dry-run-first source-gap review memory writer for collateral-only, weak evidence, deferred, and external-research decisions.',
                'mcp_tool' => 'source_gap_decision_add',
                'risk_level' => 'write',
                'max_calls_per_run' => 20,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID this decision applies to'],
                        'person_id' => ['type' => 'integer', 'description' => 'Person ID this source-gap decision applies to'],
                        'decision' => ['type' => 'string', 'description' => 'Decision code'],
                        'reason' => ['type' => 'string', 'description' => 'Evidence review reason'],
                        'source_ids' => ['type' => 'array', 'description' => 'Optional related source IDs reviewed', 'items' => ['type' => 'integer']],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Preview only when true', 'default' => true],
                        'confirm' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
                        'actor' => ['type' => 'string', 'description' => 'Audit actor label', 'default' => 'genea-agent'],
                        'confidence' => ['type' => 'number', 'description' => 'Memory confidence 0..1', 'default' => 0.8],
                    ],
                    'required' => ['tree_id', 'person_id', 'decision', 'reason'],
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
                json_encode($tool['parameters']),
                'Returns the genealogy MCP tool payload with dry-run/write-audit status where applicable.',
                json_encode($permissions, JSON_UNESCAPED_SLASHES),
                $tool['risk_level'],
                'genealogy',
                0,
                $tool['max_calls_per_run'],
                'genealogy',
                $tool['mcp_tool'],
                'MCP bridge registration for Genea source-gap memory so agents can avoid repeated weak/collateral checks.',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', [
                'source_gap_decision_lookup',
                'source_gap_decision_add',
            ])
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
