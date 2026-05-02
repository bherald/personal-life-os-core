<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LLM Instances - Dynamic Multi-Provider LLM Pool
     *
     * Tracks all LLM providers (Ollama instances, Claude CLI, OpenAI, Anthropic API, etc.)
     * with health scoring, capabilities, and configuration for dynamic routing.
     *
     * Architecture:
     * - Each physical LLM endpoint is a row (2 Ollama servers = 2 rows)
     * - Health score drives routing decisions (0-100, higher = preferred)
     * - Circuit breaker state tracked per instance
     * - Capabilities determine which tasks can route to which instances
     */
    public function up(): void
    {
        Schema::create('llm_instances', function (Blueprint $table) {
            $table->id();

            // Instance identification
            $table->string('instance_id', 50)->unique()->comment('Unique identifier (e.g., ollama_primary, ollama_secondary_1, claude_cli)');
            $table->string('instance_name', 100)->comment('Human-friendly name (e.g., Primary GPU Server)');
            $table->enum('instance_type', [
                'ollama',           // Local Ollama instance
                'claude_cli',       // Claude CLI (optional, operator-configured)
                'anthropic_api',    // Anthropic API direct
                'openai',           // OpenAI API
                'azure_openai',     // Azure OpenAI
                'google_gemini',    // Google Gemini
                'local_llm',        // Other local LLM (llama.cpp, vllm, etc.)
                'custom',           // Custom provider
            ])->comment('Provider type for adapter selection');

            // Connection details
            $table->string('base_url', 255)->nullable()->comment('API endpoint URL (null for CLI-based)');
            $table->integer('port')->nullable()->comment('Port if separate from URL');
            $table->string('api_key_env', 100)->nullable()->comment('Env var name for API key (never store keys directly)');

            // Priority and routing
            $table->tinyInteger('priority')->default(50)->comment('Routing priority (1=highest, 100=lowest)');
            $table->boolean('is_active')->default(true)->comment('Manually enabled/disabled');
            $table->boolean('is_healthy')->default(true)->comment('Current health status');
            $table->tinyInteger('health_score')->default(100)->comment('Dynamic health score 0-100');

            // Capabilities (determines what tasks can route here)
            $table->json('capabilities')->comment('["text", "vision", "embedding", "tools", "streaming"]');
            $table->json('supported_models')->nullable()->comment('List of model names this instance supports');

            // Performance metrics (updated on each request)
            $table->decimal('avg_response_ms', 10, 2)->nullable()->comment('Moving average response time');
            $table->decimal('p95_response_ms', 10, 2)->nullable()->comment('95th percentile response time');
            $table->integer('total_requests')->default(0);
            $table->integer('total_failures')->default(0);
            $table->integer('consecutive_failures')->default(0)->comment('For circuit breaker');
            $table->decimal('success_rate', 5, 2)->nullable()->comment('Calculated success percentage');

            // Circuit breaker state
            $table->enum('circuit_state', ['closed', 'open', 'half_open'])->default('closed');
            $table->timestamp('circuit_opened_at')->nullable();
            $table->timestamp('circuit_retry_at')->nullable()->comment('When to attempt half-open');

            // Resource constraints
            $table->tinyInteger('max_concurrent')->default(1)->comment('Max concurrent requests (1 for single-GPU Ollama)');
            $table->integer('rate_limit_rpm')->nullable()->comment('Requests per minute limit');
            $table->integer('rate_limit_tpm')->nullable()->comment('Tokens per minute limit');

            // Cost tracking (for future cost-aware routing)
            $table->decimal('cost_per_1k_input', 8, 6)->nullable()->comment('Cost per 1K input tokens');
            $table->decimal('cost_per_1k_output', 8, 6)->nullable()->comment('Cost per 1K output tokens');
            $table->string('cost_tier', 20)->nullable()->comment('free, low, medium, high, premium');

            // Configuration
            $table->json('config')->nullable()->comment('Provider-specific config (timeouts, retries, etc.)');
            $table->text('notes')->nullable()->comment('Admin notes');

            // Timestamps
            $table->timestamp('last_health_check')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('instance_type');
            $table->index('is_active');
            $table->index('is_healthy');
            $table->index('health_score');
            $table->index('priority');
            $table->index('circuit_state');
        });

        // Seed default instances based on current config
        $this->seedDefaultInstances();
    }

    /**
     * Seed default instances from existing configuration
     */
    private function seedDefaultInstances(): void
    {
        $now = now();

        // Primary Ollama instance
        DB::table('llm_instances')->insert([
            'instance_id' => 'ollama_primary',
            'instance_name' => 'Primary GPU Server',
            'instance_type' => 'ollama',
            'base_url' => config('services.ollama.api_url', 'http://127.0.0.1:11434'),
            'priority' => 10,
            'is_active' => true,
            'is_healthy' => true,
            'health_score' => 100,
            'capabilities' => json_encode(['text', 'vision', 'embedding', 'streaming']),
            'max_concurrent' => 1, // Single GPU
            'config' => json_encode([
                'timeout_model_loaded' => 120,
                'timeout_model_loading' => 180,
                'timeout_model_swap' => 240,
                'busy_lock_ttl' => 300,
            ]),
            'cost_tier' => 'free',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Claude CLI instance
        DB::table('llm_instances')->insert([
            'instance_id' => 'claude_cli',
            'instance_name' => 'Claude CLI (optional)',
            'instance_type' => 'claude_cli',
            'base_url' => null, // CLI-based
            'priority' => 20, // Fallback after Ollama
            'is_active' => true,
            'is_healthy' => true,
            'health_score' => 100,
            'capabilities' => json_encode(['text', 'vision', 'tools', 'streaming']),
            'max_concurrent' => 7, // Current slot limit
            'config' => json_encode([
                'min_slots' => 1,
                'default_max_slots' => 5,
                'absolute_max_slots' => 10,
                'slot_ttl' => 600,
                'ollama_fallback_min_slots' => 3,
            ]),
            'cost_tier' => 'low', // Operator-configured Anthropic/Claude plan; adjust per provider pricing
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_instances');
    }
};
