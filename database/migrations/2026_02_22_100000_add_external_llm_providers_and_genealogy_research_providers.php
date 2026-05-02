<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════
        // PART 1: Seed free external LLM API providers into llm_instances
        // Human signs up for free API keys, sets env vars, providers activate automatically
        // ═══════════════════════════════════════════════════════════════

        $providers = [
            [
                'instance_id' => 'groq_free',
                'instance_name' => 'Groq Free Tier',
                'instance_type' => 'custom',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key_env' => 'GROQ_API_KEY',
                'priority' => 18,
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'max_concurrent' => 3,
                'rate_limit_rpm' => 30,
                'rate_limit_tpm' => 15000,
                'cost_per_1k_input' => 0,
                'cost_per_1k_output' => 0,
                'cost_tier' => 'free',
                'capabilities' => json_encode([
                    'text_generation' => true,
                    'chat' => true,
                    'sensitive_safe' => true,
                    'vision' => false,
                    'embedding' => false,
                    'tool_calling' => true,
                ]),
                'supported_models' => json_encode([
                    'llama-3.3-70b-versatile',
                    'llama-3.1-8b-instant',
                    'gemma2-9b-it',
                    'mixtral-8x7b-32768',
                ]),
                'config' => json_encode([
                    'default_model' => 'llama-3.3-70b-versatile',
                    'provider_type' => 'groq',
                    'api_format' => 'openai_compatible',
                    'free_tier' => true,
                    'signup_url' => 'https://console.groq.com',
                    'notes' => 'Ultra-fast inference via LPU. No training on user data.',
                ]),
                'notes' => 'Groq free tier - fast inference, no credit card required. Set GROQ_API_KEY in .env',
            ],
            [
                'instance_id' => 'openrouter_free',
                'instance_name' => 'OpenRouter Free Models',
                'instance_type' => 'custom',
                'base_url' => 'https://openrouter.ai/api/v1',
                'api_key_env' => 'OPENROUTER_API_KEY',
                'priority' => 19,
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'max_concurrent' => 3,
                'rate_limit_rpm' => 20,
                'rate_limit_tpm' => 10000,
                'cost_per_1k_input' => 0,
                'cost_per_1k_output' => 0,
                'cost_tier' => 'free',
                'capabilities' => json_encode([
                    'text_generation' => true,
                    'chat' => true,
                    'sensitive_safe' => false,
                    'vision' => true,
                    'embedding' => false,
                    'tool_calling' => true,
                ]),
                'supported_models' => json_encode([
                    'deepseek/deepseek-r1:free',
                    'meta-llama/llama-4-maverick:free',
                    'qwen/qwen3-235b-a22b:free',
                    'google/gemini-2.0-flash-exp:free',
                ]),
                'config' => json_encode([
                    'default_model' => 'deepseek/deepseek-r1:free',
                    'provider_type' => 'openrouter',
                    'api_format' => 'openai_compatible',
                    'free_tier' => true,
                    'signup_url' => 'https://openrouter.ai',
                    'extra_headers' => [
                        'HTTP-Referer' => config('app.url', 'http://localhost:8000'),
                        'X-Title' => 'PLOS',
                    ],
                    'notes' => 'Aggregates multiple providers. Free models rotate. Privacy varies by model.',
                ]),
                'notes' => 'OpenRouter free models - 20+ free models available. Set OPENROUTER_API_KEY in .env',
            ],
            [
                'instance_id' => 'mistral_free',
                'instance_name' => 'Mistral Experiment Tier',
                'instance_type' => 'custom',
                'base_url' => 'https://api.mistral.ai/v1',
                'api_key_env' => 'MISTRAL_API_KEY',
                'priority' => 22,
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'max_concurrent' => 1,
                'rate_limit_rpm' => 2,
                'rate_limit_tpm' => 500000,
                'cost_per_1k_input' => 0,
                'cost_per_1k_output' => 0,
                'cost_tier' => 'free',
                'capabilities' => json_encode([
                    'text_generation' => true,
                    'chat' => true,
                    'sensitive_safe' => false,
                    'vision' => true,
                    'embedding' => true,
                    'tool_calling' => true,
                ]),
                'supported_models' => json_encode([
                    'mistral-large-latest',
                    'mistral-small-latest',
                    'codestral-latest',
                    'pixtral-12b-2409',
                ]),
                'config' => json_encode([
                    'default_model' => 'mistral-small-latest',
                    'provider_type' => 'mistral',
                    'api_format' => 'openai_compatible',
                    'free_tier' => true,
                    'signup_url' => 'https://console.mistral.ai',
                    'notes' => 'WARNING: Free tier may train on requests. Do NOT send personal data.',
                ]),
                'notes' => 'Mistral experiment plan - 2 RPM limit, all models. WARNING: may train on data. Set MISTRAL_API_KEY in .env',
            ],
            [
                'instance_id' => 'gemini_free',
                'instance_name' => 'Google Gemini Free Tier',
                'instance_type' => 'google_gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'api_key_env' => 'GEMINI_API_KEY',
                'priority' => 21,
                'is_active' => 1,
                'is_healthy' => 1,
                'health_score' => 100,
                'max_concurrent' => 2,
                'rate_limit_rpm' => 10,
                'rate_limit_tpm' => 250000,
                'cost_per_1k_input' => 0,
                'cost_per_1k_output' => 0,
                'cost_tier' => 'free',
                'capabilities' => json_encode([
                    'text_generation' => true,
                    'chat' => true,
                    'sensitive_safe' => true,
                    'vision' => true,
                    'embedding' => true,
                    'tool_calling' => true,
                ]),
                'supported_models' => json_encode([
                    'gemini-2.5-flash',
                    'gemini-2.0-flash',
                    'gemini-2.0-flash-lite',
                ]),
                'config' => json_encode([
                    'default_model' => 'gemini-2.5-flash',
                    'provider_type' => 'gemini',
                    'api_format' => 'openai_compatible',
                    'free_tier' => true,
                    'signup_url' => 'https://ai.google.dev',
                    'notes' => 'Gemini OpenAI-compat endpoint. 1M context. Reduced quotas Dec 2025.',
                ]),
                'notes' => 'Google Gemini free tier - OpenAI compatible endpoint. Set GEMINI_API_KEY in .env',
            ],
        ];

        foreach ($providers as $p) {
            $existing = DB::selectOne('SELECT id FROM llm_instances WHERE instance_id = ?', [$p['instance_id']]);
            if (! $existing) {
                DB::insert('
                    INSERT INTO llm_instances (instance_id, instance_name, instance_type, base_url, api_key_env,
                        priority, is_active, is_healthy, health_score, max_concurrent, rate_limit_rpm, rate_limit_tpm,
                        cost_per_1k_input, cost_per_1k_output, cost_tier, capabilities, supported_models, config, notes,
                        created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ', [
                    $p['instance_id'], $p['instance_name'], $p['instance_type'], $p['base_url'], $p['api_key_env'],
                    $p['priority'], $p['is_active'], $p['is_healthy'], $p['health_score'], $p['max_concurrent'],
                    $p['rate_limit_rpm'], $p['rate_limit_tpm'], $p['cost_per_1k_input'], $p['cost_per_1k_output'],
                    $p['cost_tier'], $p['capabilities'], $p['supported_models'], $p['config'], $p['notes'],
                ]);
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // PART 2: Create genealogy_research_providers table
        // Makes genealogy research sources dynamic and table-driven
        // ═══════════════════════════════════════════════════════════════

        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_research_providers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider_id VARCHAR(50) NOT NULL UNIQUE,
                provider_name VARCHAR(100) NOT NULL,
                provider_class VARCHAR(255) DEFAULT NULL COMMENT 'PHP class path if framework-integrated',
                provider_type ENUM('api', 'scrape', 'oauth2', 'manual') NOT NULL DEFAULT 'api',
                base_url VARCHAR(500) DEFAULT NULL,
                api_key_env VARCHAR(100) DEFAULT NULL COMMENT 'Env var name holding API key/creds',
                auth_type ENUM('none', 'api_key', 'oauth2', 'cookie', 'session') NOT NULL DEFAULT 'none',
                capabilities JSON DEFAULT NULL COMMENT 'What this provider can do: search_persons, search_records, etc.',
                config JSON DEFAULT NULL COMMENT 'Provider-specific config: endpoints, rate limits, etc.',
                rate_limit_rpm INT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_authenticated TINYINT(1) NOT NULL DEFAULT 0,
                priority TINYINT NOT NULL DEFAULT 50,
                signup_url VARCHAR(500) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                last_used_at TIMESTAMP NULL DEFAULT NULL,
                last_error TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed with current hardcoded providers
        $genealogyProviders = [
            [
                'myheritage', 'MyHeritage', 'App\\Services\\Genealogy\\Providers\\MyHeritageProvider',
                'api', 'https://www.myheritage.com', null, 'api_key',
                json_encode(['search_persons' => true, 'search_records' => true, 'dna_matches' => true]),
                json_encode(['requires_subscription' => false]),
                30, 0, 30, 'https://www.myheritage.com',
            ],
            [
                'findagrave', 'FindAGrave', 'App\\Services\\Genealogy\\Providers\\FindAGraveProvider',
                'scrape', 'https://www.findagrave.com', null, 'none',
                json_encode(['search_persons' => true, 'search_records' => false, 'memorial_lookup' => true]),
                json_encode(['free_access' => true]),
                20, 1, 15, 'https://www.findagrave.com',
            ],
            [
                'billiongraves', 'BillionGraves', 'App\\Services\\Genealogy\\Providers\\BillionGravesProvider',
                'api', 'https://billiongraves.com', null, 'none',
                json_encode(['search_persons' => true, 'search_records' => false, 'gps_locations' => true]),
                json_encode(['free_access' => true]),
                20, 0, 40, 'https://billiongraves.com',
            ],
        ];

        foreach ($genealogyProviders as [$providerId, $name, $class, $type, $url, $keyEnv, $authType, $capabilities, $config, $rateLimit, $isActive, $priority, $signupUrl]) {
            $existing = DB::selectOne('SELECT id FROM genealogy_research_providers WHERE provider_id = ?', [$providerId]);
            if (! $existing) {
                DB::insert('
                    INSERT INTO genealogy_research_providers
                        (provider_id, provider_name, provider_class, provider_type, base_url, api_key_env, auth_type, capabilities, config, rate_limit_rpm, is_active, priority, signup_url, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ', [$providerId, $name, $class, $type, $url, $keyEnv, $authType, $capabilities, $config, $rateLimit, $isActive, $priority, $signupUrl]);
            }
        }
    }

    public function down(): void
    {
        DB::delete("DELETE FROM llm_instances WHERE instance_id IN ('groq_free', 'openrouter_free', 'mistral_free', 'gemini_free')");
        DB::statement('DROP TABLE IF EXISTS genealogy_research_providers');
    }
};
