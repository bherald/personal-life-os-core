<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates tables for Agent Handoff Service (Enhancement #33):
     * - agent_handoff_agents: Registered agents with capabilities
     * - agent_handoff_routing_rules: Task-to-agent routing rules
     * - agent_handoffs: Handoff log/history
     * - agent_handoff_contexts: Full context payloads (separate for size)
     */
    public function up(): void
    {
        // Agent registry - stores agent definitions and capabilities
        Schema::create('agent_handoff_agents', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 50)->unique()->comment('Unique agent identifier');
            $table->string('name', 100)->comment('Human-readable agent name');
            $table->text('description')->nullable()->comment('Agent description and purpose');
            $table->json('capabilities')->comment('Array of capability strings');
            $table->tinyInteger('max_concurrent_handoffs')->default(5)->comment('Max simultaneous handoffs');
            $table->integer('timeout_seconds')->default(300)->comment('Handoff timeout');
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable()->comment('Additional agent config');
            $table->timestamps();

            $table->index(['is_active', 'agent_id']);
        });

        // Routing rules - task type to agent mapping
        Schema::create('agent_handoff_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Rule name');
            $table->string('task_pattern', 100)->index()->comment('Task type pattern (supports wildcards)');
            $table->string('target_agent_id', 50)->index()->comment('Target agent for matching tasks');
            $table->json('conditions')->nullable()->comment('Additional conditions for matching');
            $table->decimal('confidence', 3, 2)->default(0.90)->comment('Routing confidence 0-1');
            $table->string('reason', 255)->nullable()->comment('Reason for this routing');
            $table->tinyInteger('priority')->default(0)->comment('Higher priority rules checked first');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->foreign('target_agent_id')
                ->references('agent_id')
                ->on('agent_handoff_agents')
                ->onDelete('cascade');
        });

        // Handoff log - tracks all handoffs for audit
        Schema::create('agent_handoffs', function (Blueprint $table) {
            $table->id();
            $table->string('handoff_id', 32)->unique()->comment('Unique handoff identifier');
            $table->string('source_agent_id', 50)->index()->comment('Agent initiating handoff');
            $table->string('target_agent_id', 50)->index()->comment('Agent receiving handoff');
            $table->string('reason', 500)->nullable()->comment('Reason for handoff');
            $table->string('context_summary', 255)->nullable()->comment('Brief context summary');
            $table->enum('status', ['initiated', 'completed', 'failed', 'timeout', 'cancelled'])
                ->default('initiated')->index();
            $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal');
            $table->integer('duration_ms')->nullable()->comment('Handoff duration in milliseconds');
            $table->string('result_summary', 500)->nullable()->comment('Brief result summary');
            $table->text('error')->nullable()->comment('Error message if failed');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->nullable()->useCurrentOnUpdate();
            $table->timestamp('completed_at')->nullable();

            $table->index(['created_at', 'status']);
            $table->index(['source_agent_id', 'created_at']);
            $table->index(['target_agent_id', 'created_at']);
        });

        // Context payloads - stored separately as they can be large
        Schema::create('agent_handoff_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('handoff_id', 32)->unique()->comment('Links to agent_handoffs');
            $table->json('context_payload')->comment('Full context including conversation history, state, goals');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('handoff_id')
                ->references('handoff_id')
                ->on('agent_handoffs')
                ->onDelete('cascade');
        });

        // Seed default agents
        $this->seedDefaultAgents();
    }

    /**
     * Seed default agent definitions
     */
    private function seedDefaultAgents(): void
    {
        $now = now();
        $agents = [
            [
                'agent_id' => 'general_assistant',
                'name' => 'General Assistant',
                'description' => 'General purpose assistant for common tasks and routing',
                'capabilities' => json_encode(['general', 'routing', 'conversation', 'clarification']),
                'max_concurrent_handoffs' => 10,
                'timeout_seconds' => 300,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'primary']),
            ],
            [
                'agent_id' => 'research_agent',
                'name' => 'Research Agent',
                'description' => 'Specialized in web research, fact-checking, and information gathering',
                'capabilities' => json_encode(['web_search', 'fact_check', 'summarize', 'research', 'source_evaluation']),
                'max_concurrent_handoffs' => 5,
                'timeout_seconds' => 600,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'specialist']),
            ],
            [
                'agent_id' => 'code_agent',
                'name' => 'Code Agent',
                'description' => 'Specialized in code analysis, review, and generation',
                'capabilities' => json_encode(['code_review', 'code_generation', 'debugging', 'refactoring', 'testing']),
                'max_concurrent_handoffs' => 3,
                'timeout_seconds' => 900,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'specialist']),
            ],
            [
                'agent_id' => 'data_agent',
                'name' => 'Data Agent',
                'description' => 'Specialized in data analysis, transformation, and visualization',
                'capabilities' => json_encode(['data_analysis', 'data_transformation', 'visualization', 'statistics', 'etl']),
                'max_concurrent_handoffs' => 5,
                'timeout_seconds' => 600,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'specialist']),
            ],
            [
                'agent_id' => 'file_agent',
                'name' => 'File Agent',
                'description' => 'Specialized in file operations, organization, and content extraction',
                'capabilities' => json_encode(['file_read', 'file_write', 'file_organize', 'content_extraction', 'ocr']),
                'max_concurrent_handoffs' => 5,
                'timeout_seconds' => 300,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'specialist']),
            ],
            [
                'agent_id' => 'email_agent',
                'name' => 'Email Agent',
                'description' => 'Specialized in email composition, classification, and management',
                'capabilities' => json_encode(['email_compose', 'email_classify', 'email_search', 'email_summarize']),
                'max_concurrent_handoffs' => 5,
                'timeout_seconds' => 300,
                'is_active' => true,
                'metadata' => json_encode(['priority' => 'specialist']),
            ],
        ];

        foreach ($agents as $agent) {
            DB::insert("
                INSERT INTO agent_handoff_agents
                (agent_id, name, description, capabilities, max_concurrent_handoffs, timeout_seconds, is_active, metadata, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $agent['agent_id'],
                $agent['name'],
                $agent['description'],
                $agent['capabilities'],
                $agent['max_concurrent_handoffs'],
                $agent['timeout_seconds'],
                $agent['is_active'],
                $agent['metadata'],
                $now,
                $now,
            ]);
        }

        // Seed default routing rules
        $rules = [
            [
                'name' => 'Route Research Tasks',
                'task_pattern' => 'research_*',
                'target_agent_id' => 'research_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Research tasks routed to specialist',
                'priority' => 50,
            ],
            [
                'name' => 'Route Code Tasks',
                'task_pattern' => 'code_*',
                'target_agent_id' => 'code_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Code tasks routed to specialist',
                'priority' => 50,
            ],
            [
                'name' => 'Route Data Tasks',
                'task_pattern' => 'data_*',
                'target_agent_id' => 'data_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Data tasks routed to specialist',
                'priority' => 50,
            ],
            [
                'name' => 'Route File Operations',
                'task_pattern' => 'file_*',
                'target_agent_id' => 'file_agent',
                'conditions' => null,
                'confidence' => 0.90,
                'reason' => 'File operations routed to file agent',
                'priority' => 40,
            ],
            [
                'name' => 'Route Email Tasks',
                'task_pattern' => 'email_*',
                'target_agent_id' => 'email_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Email tasks routed to specialist',
                'priority' => 50,
            ],
            [
                'name' => 'Route Fact Check',
                'task_pattern' => 'fact_check',
                'target_agent_id' => 'research_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Fact checking requires research capabilities',
                'priority' => 60,
            ],
            [
                'name' => 'Route Web Search',
                'task_pattern' => 'web_search',
                'target_agent_id' => 'research_agent',
                'conditions' => null,
                'confidence' => 0.95,
                'reason' => 'Web search routed to research agent',
                'priority' => 60,
            ],
        ];

        foreach ($rules as $rule) {
            DB::insert("
                INSERT INTO agent_handoff_routing_rules
                (name, task_pattern, target_agent_id, conditions, confidence, reason, priority, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
            ", [
                $rule['name'],
                $rule['task_pattern'],
                $rule['target_agent_id'],
                $rule['conditions'],
                $rule['confidence'],
                $rule['reason'],
                $rule['priority'],
                $now,
                $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_handoff_contexts');
        Schema::dropIfExists('agent_handoffs');
        Schema::dropIfExists('agent_handoff_routing_rules');
        Schema::dropIfExists('agent_handoff_agents');
    }
};
