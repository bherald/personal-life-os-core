<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agent Benchmarks Table
     *
     * Stores benchmark results comparing agentic vs hybrid vs deterministic
     * workflow modes running identical tasks. Used for mode selection decisions.
     */
    public function up(): void
    {
        Schema::create('agent_benchmarks', function (Blueprint $table) {
            $table->id();

            // Run identification
            $table->string('run_id', 64)->index();         // Groups all modes for one task run
            $table->string('agent_id', 100)->index();       // Agent skill used
            $table->string('task_key', 100)->index();       // Test task identifier (e.g., "system_health_check")
            $table->text('task_description');                // Actual prompt sent

            // Mode under test
            $table->enum('workflow_mode', ['agentic', 'hybrid', 'deterministic'])->index();

            // Performance metrics
            $table->integer('tokens_used')->unsigned()->default(0);
            $table->integer('duration_ms')->unsigned()->default(0);
            $table->integer('iterations')->unsigned()->default(0);
            $table->integer('tool_calls_count')->unsigned()->default(0);
            $table->json('tool_calls_detail')->nullable();   // Array of tool names called

            // Quality metrics (scored after run)
            $table->tinyInteger('accuracy_score')->unsigned()->nullable();  // 1-5 human/LLM rating
            $table->tinyInteger('completeness_score')->unsigned()->nullable(); // 1-5
            $table->tinyInteger('relevance_score')->unsigned()->nullable();    // 1-5

            // Execution details
            $table->string('model', 100)->nullable();
            $table->text('response_summary')->nullable();    // First 1000 chars of response
            $table->json('metadata')->nullable();            // Extra data (phases, errors, etc.)
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Composite index for analysis queries
            $table->index(['task_key', 'workflow_mode']);
            $table->index(['run_id', 'workflow_mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_benchmarks');
    }
};
