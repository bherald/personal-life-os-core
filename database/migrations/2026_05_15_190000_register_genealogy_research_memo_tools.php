<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->upsertTool(
            'research_memo_save',
            'Dry-run-first Genea MCP tool to save a reviewed research memo inside the FT tree root, append target notes, and optionally record source-gap memory.',
            [
                'type' => 'object',
                'properties' => [
                    'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the memo'],
                    'title' => ['type' => 'string', 'description' => 'Short memo title'],
                    'body' => ['type' => 'string', 'description' => 'Reviewed research memo body'],
                    'person_id' => ['type' => 'integer', 'description' => 'Optional same-tree person ID'],
                    'family_id' => ['type' => 'integer', 'description' => 'Optional same-tree family ID'],
                    'relative_path' => ['type' => 'string', 'description' => 'Optional safe .md path below the tree media root'],
                    'notes_append' => ['type' => 'string', 'description' => 'Optional note appended to the target person/family record'],
                    'source_gap_decision' => ['type' => 'string', 'description' => 'Optional source-gap decision code for person_id'],
                    'source_gap_reason' => ['type' => 'string', 'description' => 'Required when source_gap_decision is provided'],
                    'source_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                    'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
                    'overwrite' => ['type' => 'boolean', 'default' => false],
                    'confidence' => ['type' => 'number', 'default' => 0.8],
                ],
                'required' => ['tree_id', 'title', 'body'],
            ],
            'Returns the planned or applied FT-local memo path, note update counts, and optional source-gap memory result.',
            'MCP bridge registration for safe Genea research memo persistence without ad hoc tinker filesystem writes.',
            20
        );

        $this->upsertTool(
            'family_duplicate_retire',
            'Dry-run-first Genea MCP tool to retire an isolated duplicate family row after strict same-spouse and no-reference checks.',
            [
                'type' => 'object',
                'properties' => [
                    'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns both families'],
                    'keep_family_id' => ['type' => 'integer', 'description' => 'Canonical family ID to keep'],
                    'duplicate_family_id' => ['type' => 'integer', 'description' => 'Isolated duplicate family ID to delete'],
                    'reason' => ['type' => 'string', 'description' => 'Evidence/review reason for retiring the duplicate'],
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                    'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
                ],
                'required' => ['tree_id', 'keep_family_id', 'duplicate_family_id', 'reason'],
            ],
            'Returns the planned or applied duplicate-family cleanup with dependent row counts.',
            'MCP bridge registration for safe duplicate-family cleanup without raw DELETE commands.',
            20
        );

        $this->upsertTool(
            'person_source_link_retire',
            'Dry-run-first Genea MCP tool to retire invalid uncited person-source link rows after strict tree and citation checks.',
            [
                'type' => 'object',
                'properties' => [
                    'tree_id' => ['type' => 'integer', 'description' => 'Tree ID that owns the person-source links'],
                    'person_source_ids' => ['type' => 'array', 'description' => 'genealogy_person_sources IDs to retire, max 50', 'items' => ['type' => 'integer']],
                    'reason' => ['type' => 'string', 'description' => 'Evidence/review reason for retiring these source links'],
                    'dry_run' => ['type' => 'boolean', 'default' => true],
                    'confirm' => ['type' => 'boolean', 'default' => false],
                    'actor' => ['type' => 'string', 'default' => 'genea-mcp'],
                ],
                'required' => ['tree_id', 'person_source_ids', 'reason'],
            ],
            'Returns a planned or applied uncited person-source link cleanup with citation blocking checks.',
            'MCP bridge registration for safe person-source link cleanup without raw DELETE commands.',
            20
        );
    }

    public function down(): void
    {
        DB::table('agent_tool_registry')
            ->whereIn('name', ['research_memo_save', 'family_duplicate_retire', 'person_source_link_retire'])
            ->where('mcp_server', 'genealogy')
            ->delete();
    }

    private function upsertTool(
        string $name,
        string $description,
        array $parameters,
        string $returnsDescription,
        string $notes,
        int $maxCallsPerRun
    ): void {
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
            $name,
            'App\\Engine\\MCPRouter',
            'callTool',
            $description,
            json_encode($parameters, JSON_UNESCAPED_SLASHES),
            $returnsDescription,
            json_encode(['genealogy:read', 'genealogy:write'], JSON_UNESCAPED_SLASHES),
            'write',
            'genealogy',
            0,
            $maxCallsPerRun,
            'genealogy',
            $name,
            $notes,
        ]);
    }
};
