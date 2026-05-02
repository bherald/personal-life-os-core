<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Register tool_proposal review type in review_type_registry
        $this->registerReviewType();

        // 2. Seed propose_tool and pending_tool_proposals into agent_tool_registry
        $this->seedToolRegistryEntries();
    }

    private function registerReviewType(): void
    {
        // Check if already exists
        $exists = DB::selectOne("SELECT id FROM review_type_registry WHERE name = 'tool_proposal'");
        if ($exists) {
            return;
        }

        DB::insert("
            INSERT INTO review_type_registry
            (name, label, icon, category, source_table, source_connection, count_sql, fetch_sql, approve_sql, reject_sql, field_mapping, ui_schema, vue_renderer, service_class, approve_method, reject_method, requires_image, batch_enabled, color, display_order, enabled)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            'tool_proposal',
            'Tool Proposals',
            'wrench',
            'agent',
            'agent_review_queue',
            'mysql',
            // Count: pending tool_proposal entries in agent_review_queue
            "SELECT COUNT(*) as total FROM agent_review_queue WHERE review_type = 'tool_proposal' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW())",
            // Fetch: pending tool_proposal entries
            "SELECT id, agent_id, review_type, title, summary, details, confidence, priority, token, expires_at, created_at FROM agent_review_queue WHERE review_type = 'tool_proposal' AND status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY priority DESC, created_at ASC LIMIT 100",
            // approve_sql: null — uses service handler
            null,
            // reject_sql: null — uses service handler
            null,
            // field_mapping
            json_encode([
                'unified_id_template' => 'tool_proposal:{{token}}',
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
                        ['type' => 'badge', 'source' => 'review_type', 'class' => 'bg-ops-butterscotch'],
                        ['type' => 'text', 'source' => 'title', 'class' => 'font-semibold text-ops-peach flex-1'],
                        ['type' => 'confidence', 'source' => 'confidence'],
                    ],
                    'body' => [
                        ['type' => 'text', 'source' => 'summary', 'class' => 'text-sm text-ops-text-muted'],
                        ['type' => 'json', 'source' => 'details', 'label' => 'Tool Definition', 'collapsible' => true],
                    ],
                    'footer' => [
                        ['type' => 'badge', 'source' => 'agent_id', 'label' => 'Proposed by', 'class' => 'bg-ops-plum'],
                        ['type' => 'timestamp', 'source' => 'created_at', 'label' => 'Created'],
                        ['type' => 'timestamp', 'source' => 'expires_at', 'label' => 'Expires', 'warn_if_soon' => true],
                    ],
                ],
                'detail' => [
                    ['type' => 'text', 'source' => 'agent_id', 'label' => 'Proposed By'],
                    ['type' => 'json', 'source' => 'details', 'label' => 'Full Tool Definition', 'expanded' => true],
                ],
            ]),
            // vue_renderer
            'AgentFindingRenderer',
            // service_class + approve_method + reject_method
            'App\\Services\\ToolProposalService',
            'approveProposal',
            'rejectProposal',
            // requires_image, batch_enabled
            0,
            0,
            // color, display_order, enabled
            'ops-butterscotch',
            15,
            1,
        ]);
    }

    private function seedToolRegistryEntries(): void
    {
        // propose_tool — agents use this to propose new tools
        $this->insertTool(
            'propose_tool',
            'App\\Services\\ToolProposalService',
            'propose',
            'Propose a new tool for the agent framework. Creates a disabled tool entry and submits for human review. Requires: name (snake_case), service_class (fully qualified PHP class), method (method name), description (what the tool does). Optional: parameters (JSON schema), returns (description), reason (why needed), category, risk_level (read/write/destructive).',
            json_encode([
                'name' => ['type' => 'string', 'required' => true, 'description' => 'Tool name in snake_case (3-100 chars)'],
                'service_class' => ['type' => 'string', 'required' => true, 'description' => 'Fully qualified PHP class name'],
                'method' => ['type' => 'string', 'required' => true, 'description' => 'Method name on the service class'],
                'description' => ['type' => 'string', 'required' => true, 'description' => 'What the tool does'],
                'reason' => ['type' => 'string', 'required' => false, 'description' => 'Why this tool is needed'],
                'category' => ['type' => 'string', 'required' => false, 'description' => 'Tool category'],
                'risk_level' => ['type' => 'string', 'required' => false, 'description' => 'read, write, or destructive'],
            ]),
            'Array with success, tool name, status, review_id, message',
            json_encode(['system:write']),
            'write',
            'agent',
            1
        );

        // pending_tool_proposals — agents check status of proposals
        $this->insertTool(
            'pending_tool_proposals',
            'App\\Services\\ToolProposalService',
            'getPending',
            'List all pending tool proposals awaiting human approval. Returns count and list of proposals with name, service_class, method, description, proposed_by, created_at.',
            json_encode([]),
            'Array with success, count, and proposals list',
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
        DB::delete("DELETE FROM review_type_registry WHERE name = 'tool_proposal'");
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('propose_tool', 'pending_tool_proposals')");
    }
};
