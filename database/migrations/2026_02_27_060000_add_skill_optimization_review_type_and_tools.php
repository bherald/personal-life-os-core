<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Register skill_optimization review type in review_type_registry
        $this->registerReviewType();

        // 2. Seed optimization tools into agent_tool_registry
        $this->seedToolRegistryEntries();
    }

    private function registerReviewType(): void
    {
        $exists = DB::selectOne("SELECT id FROM review_type_registry WHERE name = 'skill_optimization'");
        if ($exists) {
            return;
        }

        DB::insert("
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, ui_schema, vue_renderer, service_class, approve_method, reject_method, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            'skill_optimization',
            'Skill Optimizations',
            'cog',
            'agent',
            'agent_review_queue',
            'mysql',
            // Count: pending skill_optimization entries
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE review_type = 'skill_optimization' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())",
            // Fetch: pending skill_optimization entries
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE review_type = 'skill_optimization' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            // approve_sql: null — uses service handler
            null,
            // reject_sql: null — uses service handler
            null,
            // field_mapping
            json_encode([
                'unified_id_template' => 'skill_optimization:{{token}}',
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
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-sky'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                        ['type' => 'json', 'source' => 'details', 'label' => 'Amendment Details', 'collapsible' => true],
                    ],
                    'footer' => [
                        ['type' => 'badge', 'source' => 'agent_id', 'label' => 'Agent', 'class' => 'bg-ops-plum'],
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Created'],
                        ['type' => 'timestamp', 'source' => 'expires_at', 'label' => 'Expires', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'source' => 'agent_id', 'label' => 'Target Agent'],
                    ['type' => 'json', 'source' => 'details', 'label' => 'Full Amendment Details', 'expanded' => true],
                ],
            ]),
            // vue_renderer
            'AgentFindingRenderer',
            // service_class + approve_method + reject_method
            'App\\Services\\SkillOptimizationService',
            'onApprove',
            'onReject',
            // requires_image, batch_enabled
            0,
            0,
            // color, display_order, enabled
            'ops-sky',
            16,
            1,
        ]);
    }

    private function seedToolRegistryEntries(): void
    {
        // analyze_skill_performance — comprehensive skill analysis
        $this->insertTool(
            'analyze_skill_performance',
            'App\\Services\\SkillOptimizationService',
            'analyzeSkillPerformance',
            'Analyze an agent\'s skill performance: benchmark scores, tool usage heatmap, iteration waste, failure rates, mode recommendations, and tool gaps. Provide target_agent parameter with the agent ID to analyze (e.g. "system-guardian"). Returns structured analysis report.',
            json_encode([
                'target_agent' => ['type' => 'string', 'required' => true, 'description' => 'Agent ID to analyze (e.g. "system-guardian", "ai-ops")'],
            ]),
            'Structured analysis report with performance, tool_usage, iteration_waste, failure_rates, mode_recommendation, tool_gaps',
            json_encode(['system:read']),
            'read',
            'agent',
            null
        );

        // propose_skill_changes — generate and submit optimization proposals
        $this->insertTool(
            'propose_skill_changes',
            'App\\Services\\SkillOptimizationService',
            'proposeSkillChanges',
            'Analyze an agent\'s skill and propose SKILL.md amendments for human review. Generates proposals for: unused tool removal, phase rebalancing, temperature tuning, iteration limit optimization, and workflow mode switches. Set dry_run=true to preview without submitting.',
            json_encode([
                'target_agent' => ['type' => 'string', 'required' => true, 'description' => 'Agent ID to optimize'],
                'dry_run' => ['type' => 'boolean', 'required' => false, 'description' => 'Preview proposals without submitting (default: false)'],
            ]),
            'Array with submitted amendments count and details',
            json_encode(['system:write']),
            'write',
            'agent',
            1
        );

        // optimization_stats — dashboard overview
        $this->insertTool(
            'optimization_stats',
            'App\\Services\\SkillOptimizationService',
            'getOptimizationStats',
            'Get skill optimization dashboard: pending proposals by agent, approval/rejection counts, and recent proposal history.',
            json_encode([]),
            'Dashboard with pending_by_agent, total_pending, total_approved, total_rejected, recent_proposals',
            json_encode(['system:read']),
            'read',
            'agent',
            null
        );

        // pending_skill_proposals — list pending proposals
        $this->insertTool(
            'pending_skill_proposals',
            'App\\Services\\SkillOptimizationService',
            'getPendingProposals',
            'List pending skill optimization proposals awaiting human approval. Optionally filter by target_agent.',
            json_encode([
                'target_agent' => ['type' => 'string', 'required' => false, 'description' => 'Filter proposals by agent ID'],
            ]),
            'Array with count and list of pending proposals',
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
        DB::delete("DELETE FROM review_type_registry WHERE name = 'skill_optimization'");
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('analyze_skill_performance', 'propose_skill_changes', 'optimization_stats', 'pending_skill_proposals')");
    }
};
