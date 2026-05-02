<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dead Letter Queue (DLQ) Table
     *
     * Routes non-retryable failures for manual review with full execution context.
     * Supports workflow nodes, email jobs, research tasks, and other job types.
     *
     * Pattern from n8n DLQ: https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.errorTrigger/
     */
    public function up(): void
    {
        Schema::create('dead_letter_queue', function (Blueprint $table) {
            $table->id();

            // Job identification
            $table->string('job_type', 50)->index();  // workflow_node, email, research, queue_job, etc.
            $table->string('job_id', 255)->index();   // workflow_run:123:node:456, email_draft:789, etc.

            // Execution context
            $table->json('execution_context')->nullable();  // workflow_id, run_id, node_type, node_order, etc.

            // Error details
            $table->text('error_message');
            $table->text('error_trace')->nullable();
            $table->string('error_class', 255)->nullable();  // Exception class name

            // Original payload for retry
            $table->json('original_payload')->nullable();

            // Retry tracking
            $table->tinyInteger('retry_count')->unsigned()->default(0);
            $table->boolean('max_retries_reached')->default(true);

            // Status workflow
            $table->enum('status', ['pending_review', 'retried', 'resolved', 'dismissed'])->default('pending_review');
            $table->text('resolution_notes')->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 100)->nullable();  // user identifier or 'system'
            $table->timestamp('updated_at')->nullable();

            // Indexes for common queries
            $table->index('status');
            $table->index('created_at');
            $table->index(['job_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letter_queue');
    }
};
