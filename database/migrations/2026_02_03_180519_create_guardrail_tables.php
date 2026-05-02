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
     * Creates tables for Agent Guardrail Service (Enhancement #17):
     * - guardrail_rules: Configurable validation rules
     * - guardrail_events: Audit log of guardrail decisions
     * - guardrail_confirmations: Pending confirmation requests
     */
    public function up(): void
    {
        // Guardrail Rules - configurable validation rules
        Schema::create('guardrail_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Human-readable rule name');
            $table->string('operation_pattern', 100)->index()->comment('Operation type pattern (supports wildcards)');
            $table->enum('action', ['block', 'confirm', 'log', 'allow'])->default('log');
            $table->json('conditions')->nullable()->comment('JSON conditions to match context');
            $table->string('reason', 255)->nullable()->comment('Message shown when rule triggers');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('agent_scope', 50)->nullable()->index()->comment('Limit rule to specific agent');
            $table->tinyInteger('priority')->default(0)->comment('Higher priority rules checked first');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });

        // Guardrail Events - audit log
        Schema::create('guardrail_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 30)->index()->comment('blocked, allowed, confirm_required, confirmed, denied');
            $table->string('operation', 100)->index();
            $table->json('context')->nullable()->comment('Operation context at time of validation');
            $table->string('reason', 255)->nullable();
            $table->string('agent_id', 50)->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['created_at', 'event_type']);
            $table->index(['operation', 'created_at']);
        });

        // Guardrail Confirmations - pending confirmation requests
        Schema::create('guardrail_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique()->comment('Confirmation token');
            $table->string('operation', 100)->index();
            $table->json('context')->nullable();
            $table->string('agent_id', 50)->nullable()->index();
            $table->enum('status', ['pending', 'approved', 'denied', 'expired'])->default('pending')->index();
            $table->string('confirmed_by', 100)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();

            $table->index(['status', 'created_at']);
        });

        // Seed default rules
        $this->seedDefaultRules();
    }

    /**
     * Seed default guardrail rules
     */
    private function seedDefaultRules(): void
    {
        $now = now();
        $rules = [
            // Critical blocks
            [
                'name' => 'Block System Commands',
                'operation_pattern' => 'system_command',
                'action' => 'block',
                'conditions' => null,
                'reason' => 'System command execution is not permitted',
                'severity' => 'critical',
                'agent_scope' => null,
                'priority' => 100,
                'is_active' => true,
            ],
            [
                'name' => 'Block Shell Exec',
                'operation_pattern' => 'shell_exec',
                'action' => 'block',
                'conditions' => null,
                'reason' => 'Shell execution is not permitted',
                'severity' => 'critical',
                'agent_scope' => null,
                'priority' => 100,
                'is_active' => true,
            ],
            [
                'name' => 'Block Process Kill',
                'operation_pattern' => 'process_kill',
                'action' => 'block',
                'conditions' => null,
                'reason' => 'Process termination is not permitted',
                'severity' => 'critical',
                'agent_scope' => null,
                'priority' => 100,
                'is_active' => true,
            ],

            // Confirmation required
            [
                'name' => 'Confirm File Deletion',
                'operation_pattern' => 'file_delete',
                'action' => 'confirm',
                'conditions' => null,
                'reason' => 'File deletion requires confirmation',
                'severity' => 'high',
                'agent_scope' => null,
                'priority' => 50,
                'is_active' => true,
            ],
            [
                'name' => 'Confirm Database Drop',
                'operation_pattern' => 'database_drop',
                'action' => 'confirm',
                'conditions' => null,
                'reason' => 'Database drop operations require confirmation',
                'severity' => 'critical',
                'agent_scope' => null,
                'priority' => 90,
                'is_active' => true,
            ],
            [
                'name' => 'Confirm Bulk Email',
                'operation_pattern' => 'email_send_bulk',
                'action' => 'confirm',
                'conditions' => null,
                'reason' => 'Bulk email operations require confirmation',
                'severity' => 'high',
                'agent_scope' => null,
                'priority' => 50,
                'is_active' => true,
            ],
            [
                'name' => 'Confirm Workflow Deletion',
                'operation_pattern' => 'workflow_delete',
                'action' => 'confirm',
                'conditions' => null,
                'reason' => 'Workflow deletion requires confirmation',
                'severity' => 'high',
                'agent_scope' => null,
                'priority' => 50,
                'is_active' => true,
            ],

            // Logging for audit
            [
                'name' => 'Log AI Operations',
                'operation_pattern' => 'ai_*',
                'action' => 'log',
                'conditions' => null,
                'reason' => null,
                'severity' => 'low',
                'agent_scope' => null,
                'priority' => 10,
                'is_active' => true,
            ],
            [
                'name' => 'Log External API Calls',
                'operation_pattern' => 'api_call_*',
                'action' => 'log',
                'conditions' => null,
                'reason' => null,
                'severity' => 'low',
                'agent_scope' => null,
                'priority' => 10,
                'is_active' => true,
            ],
        ];

        foreach ($rules as $rule) {
            DB::insert("
                INSERT INTO guardrail_rules
                (name, operation_pattern, action, conditions, reason, severity, agent_scope, priority, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [
                $rule['name'],
                $rule['operation_pattern'],
                $rule['action'],
                $rule['conditions'],
                $rule['reason'],
                $rule['severity'],
                $rule['agent_scope'],
                $rule['priority'],
                $rule['is_active'],
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
        Schema::dropIfExists('guardrail_confirmations');
        Schema::dropIfExists('guardrail_events');
        Schema::dropIfExists('guardrail_rules');
    }
};
