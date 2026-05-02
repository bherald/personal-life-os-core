<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Research Topics - stores topics for AI to research
        Schema::connection('pgsql_rag')->create('research_topics', function (Blueprint $table) {
            $table->id();
            $table->string('description', 255)->comment('Short description for UI display');
            $table->text('topic_content')->comment('Full topic paragraph/keywords for AI research');
            $table->string('frequency', 20)->default('monthly')->comment('daily, weekly, monthly, quarterly, biannually');
            $table->timestamp('last_ran_at')->nullable()->comment('Last time research was performed');
            $table->boolean('is_active')->default(true)->comment('Whether this topic is actively scheduled');
            $table->string('rag_category', 100)->nullable()->comment('Category name for RAG storage when approved');
            $table->timestamps();

            // Indexes
            $table->index('frequency');
            $table->index('is_active');
            $table->index('last_ran_at');
            $table->index(['is_active', 'frequency', 'last_ran_at'], 'idx_research_topics_scheduling');
        });

        // Research Results - stores pending AI research output awaiting human review
        Schema::connection('pgsql_rag')->create('research_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_topic_id')
                ->constrained('research_topics')
                ->onDelete('cascade')
                ->comment('FK to research_topics - cascade delete');
            $table->text('ai_output')->comment('AI-generated research content');
            $table->string('status', 20)->default('pending')->comment('pending, approved, skipped');
            $table->timestamp('reviewed_at')->nullable()->comment('When human reviewed this result');
            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index(['research_topic_id', 'status'], 'idx_research_results_topic_status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql_rag')->dropIfExists('research_results');
        Schema::connection('pgsql_rag')->dropIfExists('research_topics');
    }
};
