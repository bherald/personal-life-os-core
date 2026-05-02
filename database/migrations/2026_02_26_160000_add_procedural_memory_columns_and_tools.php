<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add new columns to agent_procedures table
        $columns = DB::select("SHOW COLUMNS FROM agent_procedures LIKE 'procedure_type'");
        if (empty($columns)) {
            DB::statement("
                ALTER TABLE agent_procedures
                ADD COLUMN procedure_type ENUM('success', 'failure') NOT NULL DEFAULT 'success'
                    COMMENT 'success=do this, failure=avoid this' AFTER action_sequence,
                ADD COLUMN source_session_id VARCHAR(100) NULL
                    COMMENT 'Session that originated this procedure' AFTER procedure_type,
                ADD COLUMN is_canonical TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Promoted to canonical after proven reliability' AFTER source_session_id,
                ADD COLUMN is_retired TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT 'Retired: stale or low-performing' AFTER is_canonical,
                ADD INDEX idx_agent_procedures_type (procedure_type),
                ADD INDEX idx_agent_procedures_retired (is_retired),
                ADD INDEX idx_agent_procedures_canonical (is_canonical)
            ");
        }

        // 2. Register procedural memory tools in agent_tool_registry
        $tools = [
            [
                'name' => 'recall_procedures',
                'service_class' => 'App\\Services\\AgentProceduralMemoryService',
                'method' => 'recallProcedures',
                'description' => 'Search procedural memory for previously learned tool sequences that match the current task. Returns procedures ranked by relevance and success rate.',
                'parameters' => json_encode([
                    'query' => ['type' => 'string', 'required' => true, 'description' => 'Description of current task or situation to find matching procedures for'],
                ]),
                'returns_description' => 'List of matching procedures with tool sequences, success rates, and relevance scores',
                'risk_level' => 'read',
                'category' => 'memory',
            ],
            [
                'name' => 'save_procedure',
                'service_class' => 'App\\Services\\AgentProceduralMemoryService',
                'method' => 'saveProcedure',
                'description' => 'Save a learned procedure (tool sequence) to procedural memory. Use when you discover an effective (or ineffective) approach worth remembering for future tasks.',
                'parameters' => json_encode([
                    'name' => ['type' => 'string', 'required' => true, 'description' => 'Short descriptive name for this procedure'],
                    'trigger_pattern' => ['type' => 'string', 'required' => true, 'description' => 'When to use this procedure — describe the situation/task type'],
                    'tool_sequence' => ['type' => 'array', 'required' => true, 'description' => 'Array of tool names in execution order, e.g. ["check_health", "get_metrics"]'],
                    'type' => ['type' => 'string', 'required' => false, 'description' => 'success (recommended approach) or failure (approach to avoid). Default: success'],
                ]),
                'returns_description' => 'Confirmation with procedure ID',
                'risk_level' => 'write',
                'category' => 'memory',
            ],
            [
                'name' => 'procedure_stats',
                'service_class' => 'App\\Services\\AgentProceduralMemoryService',
                'method' => 'procedureStats',
                'description' => 'View procedural memory statistics — total procedures, success rates, per-agent breakdown. Use to understand what the system has learned.',
                'parameters' => json_encode([]),
                'returns_description' => 'Statistics about stored procedures across all agents',
                'risk_level' => 'read',
                'category' => 'memory',
            ],
            [
                'name' => 'consolidate_procedures',
                'service_class' => 'App\\Services\\AgentProceduralMemoryService',
                'method' => 'consolidateProcedures',
                'description' => 'Run procedure consolidation: merge similar procedures, retire stale/low-performers, promote proven procedures to canonical. Run periodically to keep memory clean.',
                'parameters' => json_encode([]),
                'returns_description' => 'Consolidation results with merge/retire/promote counts',
                'risk_level' => 'write',
                'category' => 'memory',
            ],
        ];

        foreach ($tools as $tool) {
            $existing = DB::select("SELECT id FROM agent_tool_registry WHERE name = ?", [$tool['name']]);
            if (empty($existing)) {
                DB::insert("
                    INSERT INTO agent_tool_registry
                        (name, service_class, method, description, parameters, returns_description,
                         risk_level, category, enabled, source, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'config', NOW(), NOW())
                ", [
                    $tool['name'],
                    $tool['service_class'],
                    $tool['method'],
                    $tool['description'],
                    $tool['parameters'],
                    $tool['returns_description'],
                    $tool['risk_level'],
                    $tool['category'],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove tools
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN (?, ?, ?, ?)", [
            'recall_procedures', 'save_procedure', 'procedure_stats', 'consolidate_procedures',
        ]);

        // Remove added columns (keep original table intact)
        try {
            DB::statement("ALTER TABLE agent_procedures DROP INDEX idx_agent_procedures_canonical");
            DB::statement("ALTER TABLE agent_procedures DROP INDEX idx_agent_procedures_retired");
            DB::statement("ALTER TABLE agent_procedures DROP INDEX idx_agent_procedures_type");
            DB::statement("ALTER TABLE agent_procedures DROP COLUMN is_retired");
            DB::statement("ALTER TABLE agent_procedures DROP COLUMN is_canonical");
            DB::statement("ALTER TABLE agent_procedures DROP COLUMN source_session_id");
            DB::statement("ALTER TABLE agent_procedures DROP COLUMN procedure_type");
        } catch (\Exception $e) {
            // Columns may not exist
        }
    }
};
