<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Distributed agents registry
        Schema::create('distributed_agents', function (Blueprint $table) {
            $table->id();
            $table->string('agent_id', 64)->unique()->comment('UUID for agent identification');
            $table->string('node_name', 100)->comment('Hostname or identifier of the node');
            $table->string('status', 20)->default('offline')->index()->comment('online, offline, busy, draining');
            $table->json('capabilities')->nullable()->comment('List of agent capabilities');
            $table->json('metadata')->nullable()->comment('Additional agent metadata');
            $table->integer('max_concurrent_tasks')->unsigned()->default(5);
            $table->integer('current_load')->unsigned()->default(0);
            $table->integer('total_tasks_completed')->unsigned()->default(0);
            $table->integer('total_tasks_failed')->unsigned()->default(0);
            $table->float('avg_task_duration_ms')->default(0);
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index(['status', 'current_load']);
        });

        // Distributed task queue
        Schema::create('distributed_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_id', 64)->unique()->comment('UUID for task identification');
            $table->string('task_type', 50)->index()->comment('Type of task to execute');
            $table->json('payload')->comment('Task payload data');
            $table->json('required_capabilities')->nullable()->comment('Capabilities required to execute');
            $table->unsignedBigInteger('assigned_agent_id')->nullable()->index();
            $table->string('status', 20)->default('pending')->index()->comment('pending, assigned, running, completed, failed, cancelled');
            $table->integer('priority')->default(0)->index()->comment('Higher = more important');
            $table->integer('retry_count')->unsigned()->default(0);
            $table->integer('max_retries')->unsigned()->default(3);
            $table->json('result')->nullable()->comment('Task execution result');
            $table->text('error_message')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('timeout_at')->nullable()->index();

            $table->foreign('assigned_agent_id')
                ->references('id')
                ->on('distributed_agents')
                ->onDelete('set null');

            $table->index(['status', 'priority', 'created_at']);
        });

        // Task result aggregations for batch operations
        Schema::create('distributed_task_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->unique()->comment('UUID for batch identification');
            $table->string('batch_name', 100)->nullable();
            $table->integer('total_tasks')->unsigned()->default(0);
            $table->integer('completed_tasks')->unsigned()->default(0);
            $table->integer('failed_tasks')->unsigned()->default(0);
            $table->string('status', 20)->default('pending')->index()->comment('pending, running, completed, failed, cancelled');
            $table->json('aggregated_results')->nullable();
            $table->json('options')->nullable()->comment('Batch configuration options');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
        });

        // Link tasks to batches
        Schema::create('distributed_task_batch_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('task_id');
            $table->integer('sequence_order')->unsigned()->default(0);

            $table->foreign('batch_id')
                ->references('id')
                ->on('distributed_task_batches')
                ->onDelete('cascade');

            $table->foreign('task_id')
                ->references('id')
                ->on('distributed_tasks')
                ->onDelete('cascade');

            $table->unique(['batch_id', 'task_id']);
        });

        // Agent health metrics history
        Schema::create('distributed_agent_health', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->float('cpu_usage')->nullable();
            $table->float('memory_usage')->nullable();
            $table->integer('active_tasks')->unsigned()->default(0);
            $table->float('avg_response_time_ms')->nullable();
            $table->integer('tasks_per_minute')->unsigned()->default(0);
            $table->json('custom_metrics')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->foreign('agent_id')
                ->references('id')
                ->on('distributed_agents')
                ->onDelete('cascade');

            $table->index(['agent_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributed_agent_health');
        Schema::dropIfExists('distributed_task_batch_items');
        Schema::dropIfExists('distributed_task_batches');
        Schema::dropIfExists('distributed_tasks');
        Schema::dropIfExists('distributed_agents');
    }
};
