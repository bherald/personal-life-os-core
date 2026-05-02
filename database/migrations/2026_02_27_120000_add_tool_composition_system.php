<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create composite_tool_usage tracking table
        $this->createUsageTable();

        // 2. Register tool_composition review type
        $this->registerReviewType();

        // 3. Seed agent tools for composition discovery/management
        $this->seedAgentTools();
    }

    private function createUsageTable(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS composite_tool_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tool_name VARCHAR(100) NOT NULL UNIQUE,
                times_executed INT UNSIGNED NOT NULL DEFAULT 0,
                times_succeeded INT UNSIGNED NOT NULL DEFAULT 0,
                times_failed INT UNSIGNED NOT NULL DEFAULT 0,
                avg_duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                last_executed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tool_name (tool_name),
                INDEX idx_last_executed (last_executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function registerReviewType(): void
    {
        $exists = DB::selectOne("SELECT id FROM review_type_registry WHERE name = 'tool_composition'");
        if ($exists) {
            return;
        }

        DB::insert("
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, ui_schema, vue_renderer, service_class, approve_method, reject_method, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            'tool_composition',
            'Tool Compositions',
            'layers',
            'agent',
            'agent_review_queue',
            'mysql',
            // Count
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE review_type = 'tool_composition' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())",
            // Fetch
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE review_type = 'tool_composition' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            // approve_sql: null — uses service handler
            null,
            // reject_sql: null — uses service handler
            null,
            // field_mapping
            json_encode([
                'unified_id_template' => 'tool_composition:{{token}}',
                'id' => 'id',
                'token' => 'token',
                'title' => 'title',
                'summary' => 'summary',
                'confidence' => 'confidence',
                'priority' => 'priority',
                'created_at' => 'created_at',
                'expires_at' => 'expires_at',
                'details_json' => 'details',
                'review_type' => 'review_type',
                'agent_id' => 'agent_id',
            ]),
            // ui_schema
            json_encode([
                'card' => [
                    'header' => [
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-gold'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                        ['type' => 'json', 'source' => 'details', 'label' => 'Composition Details', 'collapsible' => true],
                    ],
                    'footer' => [
                        ['type' => 'badge', 'source' => 'agent_id', 'label' => 'Discovered by', 'class' => 'bg-ops-plum'],
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Discovered'],
                        ['type' => 'timestamp', 'source' => 'expires_at', 'label' => 'Expires', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'source' => 'agent_id', 'label' => 'Discovered By'],
                    ['type' => 'json', 'source' => 'details', 'label' => 'Full Composition', 'expanded' => true],
                ],
            ]),
            // vue_renderer
            'AgentFindingRenderer',
            // service_class + approve_method + reject_method
            'App\\Services\\ToolCompositionService',
            'approveComposition',
            'rejectComposition',
            // requires_image, batch_enabled
            0,
            0,
            // color, display_order, enabled
            'ops-gold',
            16,
            1,
        ]);
    }

    private function seedAgentTools(): void
    {
        // discover_compositions — mine procedures for recurring patterns
        $this->insertTool(
            'discover_compositions',
            'App\\Services\\ToolCompositionService',
            'discoverCompositionsTool',
            'Discover recurring tool sequences from procedural memory that could become composite tools. Analyzes agent_procedures for patterns appearing 3+ times with 80%+ success rate. Returns candidates with composed names, tool pipelines, and usage statistics.',
            json_encode([
                'target_agent' => ['type' => 'string', 'required' => false, 'description' => 'Filter to specific agent ID (optional, default: all agents)'],
            ]),
            'Array with candidates list, each containing composed_name, tools pipeline, procedure_count, success_rate',
            json_encode(['system:read']),
            'read',
            'agent',
            2
        );

        // propose_composition — submit a composition for review
        $this->insertTool(
            'propose_composition',
            'App\\Services\\ToolCompositionService',
            'proposeCompositionTool',
            'Propose a tool composition for human review. Specify an ordered array of tool names that form a pipeline. Once approved, agents can call the composite tool as a single operation. Each tool output is piped as context to the next.',
            json_encode([
                'tools' => ['type' => 'array', 'required' => true, 'description' => 'Ordered array of tool names forming the pipeline'],
                'description' => ['type' => 'string', 'required' => false, 'description' => 'Human-readable description of what this composition does'],
            ]),
            'Array with success, composed_name, status, review_token',
            json_encode(['system:write']),
            'write',
            'agent',
            2
        );

        // composition_stats — view composition metrics
        $this->insertTool(
            'composition_stats',
            'App\\Services\\ToolCompositionService',
            'compositionStats',
            'View tool composition statistics including active compositions, pending proposals, and execution metrics (success rates, durations).',
            json_encode([]),
            'Array with active count, pending count, and usage statistics',
            json_encode(['system:read']),
            'read',
            'agent',
            null
        );

        // pending_compositions — list pending proposals
        $this->insertTool(
            'pending_compositions',
            'App\\Services\\ToolCompositionService',
            'pendingCompositions',
            'List pending tool composition proposals awaiting human review. Shows composed name, component tools pipeline, proposing agent, and creation date.',
            json_encode([]),
            'Array with count and proposals list',
            json_encode(['system:read']),
            'read',
            'agent',
            null
        );
    }

    private function insertTool(
        string $name,
        string $serviceClass,
        string $method,
        string $description,
        string $parameters,
        string $returnsDescription,
        string $permissions,
        string $riskLevel,
        string $category,
        ?int $maxCallsPerRun
    ): void {
        try {
            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, risk_level, category, max_calls_per_run, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'config')
            ", [
                $name,
                $serviceClass,
                $method,
                $description,
                $parameters,
                $returnsDescription,
                $permissions,
                $riskLevel,
                $category,
                $maxCallsPerRun,
            ]);
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate entry')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM review_type_registry WHERE name = 'tool_composition'");
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('discover_compositions', 'propose_composition', 'composition_stats', 'pending_compositions')");
        DB::delete("DELETE FROM agent_tool_registry WHERE source = 'composed'");
        DB::statement("DROP TABLE IF EXISTS composite_tool_usage");
    }
};
