<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates tables for Human Review Manager Service (Enhancement #28):
     * - human_review_tasks: Review task queue with priority
     * - human_review_outcomes: Records of review decisions
     * - human_reviewers: Reviewer assignments and workload tracking
     */
    public function up(): void
    {
        // Human Reviewers - reviewer assignments and workload tracking
        Schema::create('human_reviewers', function (Blueprint $table) {
            $table->id();
            $table->string('user_id', 100)->unique()->comment('User identifier');
            $table->string('name', 255);
            $table->json('expertise_areas')->nullable()->comment('Areas of expertise for task routing');
            $table->integer('max_concurrent_tasks')->default(5);
            $table->integer('current_task_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });

        // Human Review Tasks - review task queue
        Schema::create('human_review_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_type', 50)->index()->comment('verdict_review, evidence_review, contradiction_resolution');
            $table->string('reference_type', 50)->comment('claim, verdict, evidence, pipeline');
            $table->string('reference_id', 100)->index()->comment('ID of the item to review');
            $table->string('pipeline_id', 100)->nullable()->index()->comment('Associated fact-check pipeline');

            // Priority and confidence
            $table->decimal('confidence_score', 5, 4)->comment('Original AI confidence score');
            $table->integer('priority')->default(50)->index()->comment('1-100, higher = more urgent');

            // Task content
            $table->text('title');
            $table->text('description')->nullable();
            $table->json('context')->nullable()->comment('Full context for review');
            $table->json('ai_recommendation')->nullable()->comment('AI suggested action');

            // Status and assignment
            $table->enum('status', ['pending', 'assigned', 'in_review', 'completed', 'escalated', 'expired'])->default('pending')->index();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('due_at')->nullable();

            // Timestamps
            $table->timestamps();

            $table->foreign('assigned_to')->references('id')->on('human_reviewers')->onDelete('set null');
            $table->index(['status', 'priority']);
            $table->index(['status', 'created_at']);
            $table->index(['task_type', 'status']);
        });

        // Human Review Outcomes - records of review decisions
        Schema::create('human_review_outcomes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id')->index();
            $table->unsignedBigInteger('reviewer_id')->nullable();

            // Decision
            $table->enum('decision', ['approve', 'reject', 'modify'])->index();
            $table->text('notes')->nullable();
            $table->json('modifications')->nullable()->comment('Changes made if decision=modify');

            // Original vs final values
            $table->json('original_values')->nullable();
            $table->json('final_values')->nullable();

            // Review metrics
            $table->integer('review_duration_seconds')->nullable()->comment('Time spent reviewing');
            $table->decimal('confidence_after', 5, 4)->nullable()->comment('Confidence after human review');

            $table->timestamps();

            $table->foreign('task_id')->references('id')->on('human_review_tasks')->onDelete('cascade');
            $table->foreign('reviewer_id')->references('id')->on('human_reviewers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('human_review_outcomes');
        Schema::dropIfExists('human_review_tasks');
        Schema::dropIfExists('human_reviewers');
    }
};
