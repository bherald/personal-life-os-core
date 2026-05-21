<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tools = [
            [
                'name' => 'evidence_capture_plan',
                'description' => 'Plan capture-ready genealogy review evidence media for FT-local storage. Read-only; use before review or execution.',
                'mcp_tool' => 'evidence_capture_plan',
                'risk_level' => 'read',
                'max_calls_per_run' => 20,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Optional tree ID used to scope review packets'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum review rows to scan', 'default' => 50],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Return query posture without scanning rows', 'default' => false],
                        'compact' => ['type' => 'boolean', 'description' => 'Return count-only payload by default', 'default' => true],
                        'eligible_only' => ['type' => 'boolean', 'description' => 'Only count capture-ready candidates', 'default' => false],
                    ],
                ],
            ],
            [
                'name' => 'evidence_capture_review',
                'description' => 'Materialize tree-scoped approval rows for evidence media capture. No downloads or canonical media writes.',
                'mcp_tool' => 'evidence_capture_review',
                'risk_level' => 'write',
                'max_calls_per_run' => 10,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID to scope capture approval rows'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum review rows to scan', 'default' => 50],
                        'execute' => ['type' => 'boolean', 'description' => 'Create noncanonical approval rows when true', 'default' => false],
                        'confirm' => ['type' => 'boolean', 'description' => 'Required true when execute=true', 'default' => false],
                        'compact' => ['type' => 'boolean', 'description' => 'Return count-only payload by default', 'default' => true],
                        'eligible_only' => ['type' => 'boolean', 'description' => 'Only materialize capture-ready candidates', 'default' => false],
                    ],
                    'required' => ['tree_id'],
                ],
            ],
            [
                'name' => 'evidence_capture_execute',
                'description' => 'Preflight or execute already-approved tree-scoped evidence media capture into FT storage with optional genealogy linking.',
                'mcp_tool' => 'evidence_capture_execute',
                'risk_level' => 'write',
                'max_calls_per_run' => 10,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID stamped on approved capture review rows'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum approved capture rows to inspect', 'default' => 25],
                        'save_preflight' => ['type' => 'boolean', 'description' => 'Stamp noncanonical executor preflight details only', 'default' => false],
                        'execute_capture' => ['type' => 'boolean', 'description' => 'Download/save approved assets when confirmed', 'default' => false],
                        'confirm_noncanonical_write' => ['type' => 'boolean', 'description' => 'Required for save_preflight', 'default' => false],
                        'confirm_download' => ['type' => 'boolean', 'description' => 'Required for execute_capture', 'default' => false],
                        'confirm_storage_write' => ['type' => 'boolean', 'description' => 'Required for execute_capture', 'default' => false],
                        'confirm_genealogy_link' => ['type' => 'boolean', 'description' => 'Create person/family/source media links when executing', 'default' => false],
                        'max_bytes' => ['type' => 'integer', 'description' => 'Optional per-file download byte cap'],
                        'compact' => ['type' => 'boolean', 'description' => 'Return count-only payload by default', 'default' => true],
                    ],
                    'required' => ['tree_id'],
                ],
            ],
            [
                'name' => 'source_citation_link_apply',
                'description' => 'Dry-run-first bounded source citation plus person/family source link creation for already-vetted same-tree evidence.',
                'mcp_tool' => 'source_citation_link_apply',
                'risk_level' => 'write',
                'max_calls_per_run' => 10,
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns all targets'],
                        'source_id' => ['type' => 'integer', 'description' => 'Existing genealogy source ID in the same tree'],
                        'person_ids' => ['type' => 'array', 'description' => 'Optional person IDs to cite/link', 'items' => ['type' => 'integer']],
                        'family_ids' => ['type' => 'array', 'description' => 'Optional family IDs to cite/link', 'items' => ['type' => 'integer']],
                        'media_id' => ['type' => 'integer', 'description' => 'Optional cited media row in the same tree'],
                        'fact_type' => ['type' => 'string', 'description' => 'Citation fact type', 'default' => 'person_source_context'],
                        'page' => ['type' => 'string', 'description' => 'Optional page, URL section, or review locator'],
                        'quality' => ['type' => 'integer', 'description' => 'Optional citation quality 0-100'],
                        'text' => ['type' => 'string', 'description' => 'Required evidence text explaining exactly what the source supports'],
                        'evidence_type' => ['type' => 'string', 'description' => 'direct, indirect, or negative', 'default' => 'direct'],
                        'information_type' => ['type' => 'string', 'description' => 'primary, secondary, or indeterminate', 'default' => 'secondary'],
                        'dry_run' => ['type' => 'boolean', 'description' => 'Preview only when true', 'default' => true],
                        'confirm' => ['type' => 'boolean', 'description' => 'Required true when dry_run=false', 'default' => false],
                        'actor' => ['type' => 'string', 'description' => 'Audit actor label', 'default' => 'genea-agent'],
                    ],
                    'required' => ['tree_id', 'source_id', 'text'],
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
                'Returns the genealogy MCP tool payload, including success/error status and any dry-run or write-audit receipt.',
                json_encode($permissions, JSON_UNESCAPED_SLASHES),
                $tool['risk_level'],
                'genealogy',
                0,
                $tool['max_calls_per_run'],
                'genealogy',
                $tool['mcp_tool'],
                'MCP bridge registration for Genea agent intake/citation workflow; dry-run and confirmation are enforced by the MCP service and offline policy.',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', [
                'evidence_capture_plan',
                'evidence_capture_review',
                'evidence_capture_execute',
                'source_citation_link_apply',
            ])
            ->where('mcp_server', 'genealogy')
            ->delete();
    }
};
