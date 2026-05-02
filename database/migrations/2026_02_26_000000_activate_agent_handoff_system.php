<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Activate Agent Handoff System (N27)
     *
     * The original migration (2026_02_03_210000) was recorded as run but tables
     * were never created (or were dropped). This migration:
     * 1. Creates the 4 handoff tables if they don't exist
     * 2. Seeds real PLOS agent IDs (not the generic placeholders)
     * 3. Seeds routing rules mapped to actual agent capabilities
     * 4. Registers handoff tools in agent_tool_registry
     */
    public function up(): void
    {
        // 1. Create tables (idempotent - skip if they exist)
        if (!Schema::hasTable('agent_handoff_agents')) {
            Schema::create('agent_handoff_agents', function (Blueprint $table) {
                $table->id();
                $table->string('agent_id', 50)->unique();
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->json('capabilities');
                $table->tinyInteger('max_concurrent_handoffs')->default(5);
                $table->integer('timeout_seconds')->default(300);
                $table->boolean('is_active')->default(true)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['is_active', 'agent_id']);
            });
        }

        if (!Schema::hasTable('agent_handoff_routing_rules')) {
            Schema::create('agent_handoff_routing_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('task_pattern', 100)->index();
                $table->string('target_agent_id', 50)->index();
                $table->json('conditions')->nullable();
                $table->decimal('confidence', 3, 2)->default(0.90);
                $table->string('reason', 255)->nullable();
                $table->tinyInteger('priority')->default(0);
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->index(['is_active', 'priority']);
                $table->foreign('target_agent_id')
                    ->references('agent_id')
                    ->on('agent_handoff_agents')
                    ->onDelete('cascade');
            });
        }

        if (!Schema::hasTable('agent_handoffs')) {
            Schema::create('agent_handoffs', function (Blueprint $table) {
                $table->id();
                $table->string('handoff_id', 32)->unique();
                $table->string('source_agent_id', 50)->index();
                $table->string('target_agent_id', 50)->index();
                $table->string('reason', 500)->nullable();
                $table->string('context_summary', 255)->nullable();
                $table->enum('status', ['initiated', 'completed', 'failed', 'timeout', 'cancelled'])
                    ->default('initiated')->index();
                $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
                $table->integer('duration_ms')->nullable();
                $table->string('result_summary', 500)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
                $table->timestamp('completed_at')->nullable();

                $table->index(['created_at', 'status']);
                $table->index(['source_agent_id', 'created_at']);
                $table->index(['target_agent_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('agent_handoff_contexts')) {
            Schema::create('agent_handoff_contexts', function (Blueprint $table) {
                $table->id();
                $table->string('handoff_id', 32)->unique();
                $table->json('context_payload');
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('handoff_id')
                    ->references('handoff_id')
                    ->on('agent_handoffs')
                    ->onDelete('cascade');
            });
        }

        // 2. Seed real PLOS agents
        $this->seedAgents();

        // 3. Seed routing rules
        $this->seedRoutingRules();

        // 4. Register handoff tools in agent_tool_registry
        $this->registerTools();
    }

    private function seedAgents(): void
    {
        $now = now();
        $agents = [
            [
                'agent_id' => 'ai-ops',
                'name' => 'AI Operations',
                'description' => 'Manages AI service capacity, pipeline throughput, workload balancing',
                'capabilities' => ['ai_capacity', 'gpu_monitoring', 'pipeline_health', 'job_management', 'provider_status'],
            ],
            [
                'agent_id' => 'system-guardian',
                'name' => 'System Guardian',
                'description' => 'Infrastructure health monitoring, disk, network, service availability',
                'capabilities' => ['system_health', 'disk_monitoring', 'service_status', 'network_health', 'diagnostics'],
            ],
            [
                'agent_id' => 'email-ops',
                'name' => 'Email Operations',
                'description' => 'Email pipeline health, bounce management, thread analysis, sender profiling',
                'capabilities' => ['email_health', 'bounce_management', 'thread_analysis', 'sender_profiling', 'email_classify'],
            ],
            [
                'agent_id' => 'file-ops',
                'name' => 'File Operations',
                'description' => 'File registry health, enrichment pipeline, duplicate detection',
                'capabilities' => ['file_health', 'enrichment_pipeline', 'duplicate_detection', 'file_organize'],
            ],
            [
                'agent_id' => 'file-curator',
                'name' => 'File Curator',
                'description' => 'File metadata curation, AI tagging, content quality',
                'capabilities' => ['metadata_curation', 'ai_tagging', 'content_quality', 'file_classify'],
            ],
            [
                'agent_id' => 'research-ops',
                'name' => 'Research Operations',
                'description' => 'Research pipeline health, engine fallback, circuit breakers, topic scheduling',
                'capabilities' => ['research_health', 'engine_management', 'circuit_breakers', 'topic_scheduling', 'source_credibility'],
            ],
            [
                'agent_id' => 'research-analyst',
                'name' => 'Research Analyst',
                'description' => 'Deep research analysis, fact-checking, source evaluation, trend synthesis',
                'capabilities' => ['research_analysis', 'fact_check', 'source_evaluation', 'trend_synthesis', 'web_search'],
            ],
            [
                'agent_id' => 'genealogy-researcher',
                'name' => 'Genealogy Researcher',
                'description' => 'Family history research, record matching, DNA analysis, genealogy data quality',
                'capabilities' => ['genealogy_research', 'record_matching', 'dna_analysis', 'census_search', 'vital_records'],
            ],
            [
                'agent_id' => 'knowledge-curator',
                'name' => 'Knowledge Curator',
                'description' => 'RAG index maintenance, knowledge quality, embedding health',
                'capabilities' => ['rag_maintenance', 'knowledge_quality', 'embedding_health', 'content_indexing'],
            ],
            [
                'agent_id' => 'workflow-ops',
                'name' => 'Workflow Operations',
                'description' => 'Workflow health, dead letter queue, execution metrics, webhook reliability',
                'capabilities' => ['workflow_health', 'dlq_management', 'execution_metrics', 'webhook_monitoring', 'schedule_management'],
            ],
            [
                'agent_id' => 'youtube-ops',
                'name' => 'YouTube Operations',
                'description' => 'YouTube pipeline, transcript processing, playlist management',
                'capabilities' => ['youtube_health', 'transcript_processing', 'playlist_management'],
            ],
            [
                'agent_id' => 'factcheck-ops',
                'name' => 'Fact Check Operations',
                'description' => 'Fact-checking pipeline, claim verification, evidence quality',
                'capabilities' => ['factcheck_health', 'claim_verification', 'evidence_quality'],
            ],
            [
                'agent_id' => 'data-removal-ops',
                'name' => 'Data Removal Operations',
                'description' => 'Privacy data removal, broker monitoring, compliance tracking',
                'capabilities' => ['data_removal', 'broker_monitoring', 'compliance_tracking', 'privacy_scan'],
            ],
        ];

        foreach ($agents as $agent) {
            // Upsert: skip if already exists
            $existing = DB::selectOne("SELECT id FROM agent_handoff_agents WHERE agent_id = ?", [$agent['agent_id']]);
            if ($existing) {
                continue;
            }

            DB::insert("
                INSERT INTO agent_handoff_agents
                (agent_id, name, description, capabilities, max_concurrent_handoffs, timeout_seconds, is_active, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, 5, 600, 1, ?, ?, ?)
            ", [
                $agent['agent_id'],
                $agent['name'],
                $agent['description'],
                json_encode($agent['capabilities']),
                json_encode(['priority' => 'specialist']),
                $now,
                $now,
            ]);
        }
    }

    private function seedRoutingRules(): void
    {
        $now = now();
        $rules = [
            // Domain-specific routing
            ['name' => 'Route AI/GPU Tasks', 'task_pattern' => 'ai_*', 'target_agent_id' => 'ai-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'AI capacity and GPU tasks → ai-ops'],
            ['name' => 'Route GPU Tasks', 'task_pattern' => 'gpu_*', 'target_agent_id' => 'ai-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'GPU monitoring tasks → ai-ops'],
            ['name' => 'Route Pipeline Tasks', 'task_pattern' => 'pipeline_*', 'target_agent_id' => 'ai-ops', 'confidence' => 0.90, 'priority' => 40, 'reason' => 'Pipeline health → ai-ops'],
            ['name' => 'Route System Tasks', 'task_pattern' => 'system_*', 'target_agent_id' => 'system-guardian', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'System health → system-guardian'],
            ['name' => 'Route Email Tasks', 'task_pattern' => 'email_*', 'target_agent_id' => 'email-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Email tasks → email-ops'],
            ['name' => 'Route File Tasks', 'task_pattern' => 'file_*', 'target_agent_id' => 'file-ops', 'confidence' => 0.90, 'priority' => 40, 'reason' => 'File operations → file-ops'],
            ['name' => 'Route Metadata Tasks', 'task_pattern' => 'metadata_*', 'target_agent_id' => 'file-curator', 'confidence' => 0.90, 'priority' => 40, 'reason' => 'Metadata curation → file-curator'],
            ['name' => 'Route Research Tasks', 'task_pattern' => 'research_*', 'target_agent_id' => 'research-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Research pipeline → research-ops'],
            ['name' => 'Route Fact Check', 'task_pattern' => 'fact_check*', 'target_agent_id' => 'factcheck-ops', 'confidence' => 0.95, 'priority' => 60, 'reason' => 'Fact checking → factcheck-ops'],
            ['name' => 'Route Genealogy Tasks', 'task_pattern' => 'genealogy_*', 'target_agent_id' => 'genealogy-researcher', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Genealogy research → genealogy-researcher'],
            ['name' => 'Route RAG Tasks', 'task_pattern' => 'rag_*', 'target_agent_id' => 'knowledge-curator', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'RAG/knowledge tasks → knowledge-curator'],
            ['name' => 'Route Workflow Tasks', 'task_pattern' => 'workflow_*', 'target_agent_id' => 'workflow-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Workflow health → workflow-ops'],
            ['name' => 'Route YouTube Tasks', 'task_pattern' => 'youtube_*', 'target_agent_id' => 'youtube-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'YouTube pipeline → youtube-ops'],
            ['name' => 'Route Privacy Tasks', 'task_pattern' => 'privacy_*', 'target_agent_id' => 'data-removal-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Privacy/removal → data-removal-ops'],
            ['name' => 'Route Data Removal', 'task_pattern' => 'data_removal*', 'target_agent_id' => 'data-removal-ops', 'confidence' => 0.95, 'priority' => 50, 'reason' => 'Data removal → data-removal-ops'],
            ['name' => 'Route Web Search', 'task_pattern' => 'web_search', 'target_agent_id' => 'research-analyst', 'confidence' => 0.90, 'priority' => 45, 'reason' => 'Web search → research-analyst'],
        ];

        foreach ($rules as $rule) {
            // Skip if pattern+target already exists
            $existing = DB::selectOne(
                "SELECT id FROM agent_handoff_routing_rules WHERE task_pattern = ? AND target_agent_id = ?",
                [$rule['task_pattern'], $rule['target_agent_id']]
            );
            if ($existing) {
                continue;
            }

            DB::insert("
                INSERT INTO agent_handoff_routing_rules
                (name, task_pattern, target_agent_id, conditions, confidence, reason, priority, is_active, created_at, updated_at)
                VALUES (?, ?, ?, NULL, ?, ?, ?, 1, ?, ?)
            ", [
                $rule['name'],
                $rule['task_pattern'],
                $rule['target_agent_id'],
                $rule['confidence'],
                $rule['reason'],
                $rule['priority'],
                $now,
                $now,
            ]);
        }
    }

    private function registerTools(): void
    {
        $now = now();
        $tools = [
            [
                'name' => 'handoff_to_agent',
                'service_class' => 'App\\Services\\AgentHandoffService',
                'method' => 'handoff',
                'description' => 'Delegate a task to another specialist agent. Use when the current task requires capabilities outside your domain. Provide the target agent ID, context payload with goals, and reason for handoff.',
                'parameters' => json_encode([
                    'source_agent_id' => ['type' => 'string', 'description' => 'Your own agent ID (the agent initiating the handoff)', 'required' => true],
                    'target_agent_id' => ['type' => 'string', 'description' => 'Target agent ID to hand off to (e.g. research-ops, ai-ops, system-guardian)', 'required' => true],
                    'context_payload' => ['type' => 'object', 'description' => 'Context to transfer: {goals: [...], original_request: "...", intermediate_results: [...]}', 'required' => true],
                    'reason' => ['type' => 'string', 'description' => 'Why this handoff is needed', 'required' => true],
                ]),
                'returns_description' => 'Handoff result with handoff_id, status, and target agent response',
                'risk_level' => 'write',
                'category' => 'agent',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 3,
            ],
            [
                'name' => 'route_task',
                'service_class' => 'App\\Services\\AgentHandoffService',
                'method' => 'routeTask',
                'description' => 'Find the best agent to handle a task type. Returns the recommended agent ID with confidence score and alternatives. Does NOT execute the handoff — use handoff_to_agent for that.',
                'parameters' => json_encode([
                    'task_type' => ['type' => 'string', 'description' => 'Task type to route (e.g. research_engine_down, email_bounce_spike, file_duplicate_found)', 'required' => true],
                    'task_context' => ['type' => 'object', 'description' => 'Additional context for routing decisions', 'required' => false],
                    'current_agent_id' => ['type' => 'string', 'description' => 'Your own agent ID (excluded from routing candidates)', 'required' => false],
                ]),
                'returns_description' => 'Routing result with recommended agent_id, confidence, reason, and alternatives',
                'risk_level' => 'read',
                'category' => 'agent',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 5,
            ],
            [
                'name' => 'get_handoff_stats',
                'service_class' => 'App\\Services\\AgentHandoffService',
                'method' => 'getStats',
                'description' => 'Get handoff statistics: total handoffs, success rates, per-agent breakdown, routing rule counts. Useful for monitoring agent collaboration health.',
                'parameters' => json_encode([
                    'hours' => ['type' => 'integer', 'description' => 'Time window in hours (default 24)', 'required' => false],
                ]),
                'returns_description' => 'Handoff statistics with totals, per-agent breakdown, and success rates',
                'risk_level' => 'read',
                'category' => 'agent',
                'requires_confirmation' => 0,
                'max_calls_per_run' => 2,
            ],
        ];

        foreach ($tools as $tool) {
            // Skip if already exists
            $existing = DB::selectOne("SELECT id FROM agent_tool_registry WHERE name = ?", [$tool['name']]);
            if ($existing) {
                continue;
            }

            DB::insert("
                INSERT INTO agent_tool_registry
                (name, service_class, method, description, parameters, returns_description, permissions, enabled, risk_level, category, requires_confirmation, max_calls_per_run, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
            ", [
                $tool['name'],
                $tool['service_class'],
                $tool['method'],
                $tool['description'],
                $tool['parameters'],
                $tool['returns_description'],
                json_encode(['system:read', 'system:write']),
                $tool['risk_level'],
                $tool['category'],
                $tool['requires_confirmation'],
                $tool['max_calls_per_run'],
                $now,
                $now,
            ]);
        }
    }

    public function down(): void
    {
        // Remove tools
        DB::delete("DELETE FROM agent_tool_registry WHERE name IN ('handoff_to_agent', 'route_task', 'get_handoff_stats')");

        // Drop tables in dependency order
        Schema::dropIfExists('agent_handoff_contexts');
        Schema::dropIfExists('agent_handoffs');
        Schema::dropIfExists('agent_handoff_routing_rules');
        Schema::dropIfExists('agent_handoff_agents');
    }
};
