<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ollama Model Registry
     *
     * Tracks available models, their capabilities, and status.
     * Enables the framework to:
     * - Know which models are vetted and ready for production
     * - Detect new models that need human vetting
     * - Understand model capabilities and use cases
     * - Track model performance metrics
     */
    public function up(): void
    {
        Schema::create('ollama_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 100)->unique()->comment('Ollama model name (e.g., llama3.1:8b-instruct-q5_K_M)');
            $table->string('display_name', 100)->nullable()->comment('Human-friendly name');
            $table->string('profile', 50)->nullable()->comment('Profile: default, fast, creative, coding, vision, embedding');

            // Status tracking
            $table->enum('status', ['discovered', 'testing', 'vetted', 'deprecated', 'unavailable'])
                  ->default('discovered')
                  ->comment('discovered=new, testing=being evaluated, vetted=approved for production');
            $table->boolean('is_available')->default(true)->comment('Currently available on Ollama');

            // Capabilities
            $table->json('capabilities')->nullable()->comment('["text", "code", "vision", "embedding", "tool_use"]');
            $table->json('use_cases')->nullable()->comment('Recommended use cases');
            $table->text('description')->nullable();

            // Technical specs
            $table->decimal('size_gb', 5, 2)->nullable()->comment('Model size in GB');
            $table->integer('context_length')->nullable()->comment('Max context window');
            $table->integer('vram_required_mb')->nullable()->comment('Minimum VRAM needed');

            // Performance metrics (updated by monitoring)
            $table->decimal('avg_tokens_per_second', 8, 2)->nullable();
            $table->decimal('avg_response_time_ms', 10, 2)->nullable();
            $table->integer('total_requests')->default(0);
            $table->integer('total_failures')->default(0);
            $table->decimal('success_rate', 5, 2)->nullable()->comment('Calculated success percentage');

            // Quality assessment
            $table->tinyInteger('quality_rating')->nullable()->comment('1-10 human rating after vetting');
            $table->text('vetting_notes')->nullable()->comment('Human notes from vetting process');
            $table->timestamp('vetted_at')->nullable();
            $table->string('vetted_by', 100)->nullable();

            // Timestamps
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index('profile');
            $table->index('is_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ollama_models');
    }
};
