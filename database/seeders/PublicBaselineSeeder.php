<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PublicBaselineSeeder extends Seeder
{
    private array $columnCache = [];

    public function run(): void
    {
        $this->seedSystemConfigs();
        $this->seedLocalAiRows();
        $this->seedModelProfiles();
        $this->seedOptionalCloudProviders();
        $this->seedPublicGenealogyProviders();

        Cache::forget('llm_model_profiles');
        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
    }

    private function seedSystemConfigs(): void
    {
        $this->seedSystemConfig('setup', 'public_baseline_version', '2026-04-27', 'string', 'Public baseline seed version for fresh PLOS installs.');
        $this->seedSystemConfig('setup', 'optional_addons', json_encode([
            'pushover',
            'nextcloud',
            'joplin',
            'thunderbird',
            'ollama',
            'tika',
            'searxng',
            'media',
            'genealogy',
        ], JSON_UNESCAPED_SLASHES), 'json', 'Optional public add-ons. Core PLOS does not require these services.');

        $this->seedSystemConfig('routing', 'offline_mode', 'disabled', 'string', 'Cloud fallback kill switch. disabled allows configured cloud fallback; enabled blocks external LLM providers.');
        $this->seedSystemConfig('routing', 'active_profile', 'default', 'string', 'Active routing profile. default uses normal guardrails.');
        $this->seedSystemConfig('routing', 'profile.offline_review', json_encode([
            'allowed_instance_types' => ['ollama'],
            'allowed_capabilities' => ['text'],
            'description' => 'Read-only local review profile. Local Ollama only.',
        ], JSON_UNESCAPED_SLASHES), 'json', 'Offline review routing profile.');
        $this->seedSystemConfig('routing', 'profile.offline_dev_assist', json_encode([
            'allowed_instance_types' => ['ollama'],
            'allowed_capabilities' => ['text', 'tools'],
            'description' => 'Local development-assist profile. Local Ollama only.',
        ], JSON_UNESCAPED_SLASHES), 'json', 'Offline development-assist routing profile.');

        $this->seedSystemConfig('notifications', 'pushover_enabled', 'false', 'boolean', 'Optional Pushover notifications. Disabled by default for public installs.');
        $this->seedSystemConfig('notifications', 'email_enabled', 'false', 'boolean', 'Optional email notifications. Disabled by default for public installs.');
        $this->seedSystemConfig('notifications', 'email_on_failure', 'false', 'boolean', 'Send email on workflow failure when email notifications are configured.');
        $this->seedSystemConfig('notifications', 'email_on_success', 'false', 'boolean', 'Send email on workflow success when email notifications are configured.');
        $this->seedSystemConfig('notifications', 'notification_email', '', 'string', 'Optional notification email address.');

        $this->seedSystemConfig('integrations', 'ollama_base_url', $this->configString('services.ollama.api_url', 'http://127.0.0.1:11434'), 'string', 'Local Ollama-compatible endpoint.');
        $this->seedSystemConfig('integrations', 'nextcloud_enabled', 'false', 'boolean', 'Optional same-host or LAN Nextcloud file layer.');
        $this->seedSystemConfig('integrations', 'joplin_enabled', 'false', 'boolean', 'Optional Joplin interoperability through operator-managed sync files.');
        $this->seedSystemConfig('integrations', 'thunderbird_enabled', 'false', 'boolean', 'Optional local Thunderbird bridge. Not a cloud mail sync.');

        $this->seedSystemConfig('ai_settings', 'max_tokens', '2000', 'int', 'Default maximum tokens per AI request.');
        $this->seedSystemConfig('ai_settings', 'temperature', '0.3', 'float', 'Default AI temperature.');
        $this->seedSystemConfig('recursion', 'master_enabled', 'false', 'boolean', 'Master kill switch for RLM recursion framework.');

        foreach ([
            ['scraping', 'max_content_size', '5242880', 'int', 'Max content download size in bytes.'],
            ['scraping', 'max_response_time_ms', '30000', 'int', 'Max response time in milliseconds.'],
            ['scraping', 'global_rate_limit_per_min', '100', 'int', 'Global requests per minute across all domains.'],
            ['scraping', 'per_domain_rate_limit', '30', 'int', 'Max requests per minute per domain.'],
            ['scraping', 'default_timeout', '30', 'int', 'Default HTTP timeout in seconds.'],
            ['scraping', 'sandbox_timeout', '45', 'int', 'Browser sandbox timeout in seconds.'],
            ['entity_resolution', 'auto_merge_threshold', '0.95', 'float', 'Similarity threshold for automatic merge.'],
            ['entity_resolution', 'llm_compare_threshold', '0.75', 'float', 'Similarity threshold to trigger LLM comparison.'],
            ['entity_resolution', 'llm_merge_confidence', '0.85', 'float', 'LLM confidence threshold to approve merge.'],
            ['email', 'daily_send_limit', '100', 'int', 'Max emails per mailbox per day.'],
            ['email', 'hourly_send_limit', '20', 'int', 'Max emails per mailbox per hour.'],
            ['email', 'cooldown_minutes', '30', 'int', 'Email cooldown duration when a limit is hit.'],
        ] as [$section, $key, $value, $type, $description]) {
            $this->seedSystemConfig($section, $key, $value, $type, $description);
        }
    }

    private function seedLocalAiRows(): void
    {
        $defaultModel = $this->defaultModel();
        $embeddingModel = $this->embeddingModel();
        $visionModel = $this->visionModel();

        $supportedModels = array_values(array_unique(array_filter([
            $defaultModel,
            $embeddingModel,
            $visionModel,
        ])));

        $this->insertIfMissing('llm_instances', ['instance_id' => 'ollama_primary'], [
            'instance_name' => 'Local Ollama',
            'instance_type' => 'ollama',
            'base_url' => $this->configString('services.ollama.api_url', 'http://127.0.0.1:11434'),
            'api_key_env' => null,
            'priority' => 10,
            'is_active' => true,
            'routability' => 'allowed',
            'gpu_target' => 'any',
            'host_affinity' => 'local',
            'compat_runtime_family' => 'ollama',
            'compat_backend' => 'ollama_api',
            'compat_status' => 'provisional',
            'is_healthy' => true,
            'health_score' => 100,
            'capabilities' => json_encode(['text', 'vision', 'embedding', 'streaming'], JSON_UNESCAPED_SLASHES),
            'is_censored' => true,
            'allows_private_data' => true,
            'data_privacy_scope' => 'local_private',
            'privacy_notes' => 'Local Ollama provider on approved PLOS infrastructure; private data allowed.',
            'supported_models' => json_encode($supportedModels, JSON_UNESCAPED_SLASHES),
            'context_length' => 8192,
            'embedding_context_length' => 8192,
            'success_rate' => 100,
            'circuit_state' => 'closed',
            'max_concurrent' => 1,
            'cost_tier' => 'free',
            'config' => json_encode([
                'default_model' => $defaultModel,
                'models' => [
                    'fast' => $defaultModel,
                    'standard' => $defaultModel,
                    'quality' => $defaultModel,
                    'coding' => $defaultModel,
                    'creative' => $defaultModel,
                    'vision' => $visionModel,
                    'embedding' => $embeddingModel,
                ],
                'timeout_model_loaded' => 120,
                'timeout_model_loading' => 180,
                'timeout_model_swap' => 240,
                'busy_lock_ttl' => 300,
            ], JSON_UNESCAPED_SLASHES),
            'notes' => 'Public baseline local AI row. Pull the configured Ollama models or edit this row for your host.',
        ]);
    }

    private function seedModelProfiles(): void
    {
        $defaultModel = $this->defaultModel();

        foreach ([
            ['default', $defaultModel, 'Default local model', ['general', 'summarization', 'analysis']],
            ['standard', $defaultModel, 'Standard local model', ['chat', 'agents', 'general']],
            ['fast', $defaultModel, 'Fast local model', ['classification', 'extraction', 'cleanup']],
            ['quality', $defaultModel, 'Higher-quality local model', ['research', 'reasoning', 'synthesis']],
            ['coding', $defaultModel, 'Local coding model', ['code_generation', 'code_review', 'debugging']],
            ['creative', $defaultModel, 'Creative local model', ['drafting', 'rewriting']],
            ['vision', $this->visionModel(), 'Local vision model', ['image_analysis', 'ocr', 'visual_qa']],
            ['embedding', $this->embeddingModel(), 'Local embedding model', ['embedding', 'rag', 'similarity']],
        ] as [$profile, $model, $description, $useCases]) {
            $this->insertIfMissing('llm_model_profiles', ['profile_name' => $profile], [
                'model_name' => $model,
                'description' => $description,
                'use_cases' => json_encode($useCases, JSON_UNESCAPED_SLASHES),
                'enabled' => true,
                'notes' => 'Seeded by PublicBaselineSeeder. Tune after pulling local models.',
            ]);
        }
    }

    private function seedOptionalCloudProviders(): void
    {
        foreach ([
            [
                'instance_id' => 'groq_free',
                'instance_name' => 'Groq API',
                'instance_type' => 'custom',
                'base_url' => 'https://api.groq.com/openai/v1',
                'api_key_env' => 'GROQ_API_KEY',
                'priority' => 60,
                'rate_limit_rpm' => 30,
                'rate_limit_tpm' => 15000,
                'capabilities' => ['text_generation' => true, 'chat' => true, 'vision' => false, 'embedding' => false, 'tool_calling' => true],
                'models' => ['llama-3.3-70b-versatile', 'llama-3.1-8b-instant'],
                'default_model' => 'llama-3.3-70b-versatile',
                'provider_type' => 'groq',
            ],
            [
                'instance_id' => 'openrouter_free',
                'instance_name' => 'OpenRouter',
                'instance_type' => 'custom',
                'base_url' => 'https://openrouter.ai/api/v1',
                'api_key_env' => 'OPENROUTER_API_KEY',
                'priority' => 70,
                'rate_limit_rpm' => 20,
                'rate_limit_tpm' => 10000,
                'capabilities' => ['text_generation' => true, 'chat' => true, 'vision' => true, 'embedding' => false, 'tool_calling' => true],
                'models' => ['deepseek/deepseek-r1:free', 'google/gemini-2.0-flash-exp:free'],
                'default_model' => 'deepseek/deepseek-r1:free',
                'provider_type' => 'openrouter',
            ],
            [
                'instance_id' => 'gemini_free',
                'instance_name' => 'Google Gemini API',
                'instance_type' => 'google_gemini',
                'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'api_key_env' => 'GEMINI_API_KEY',
                'priority' => 75,
                'rate_limit_rpm' => 10,
                'rate_limit_tpm' => 250000,
                'capabilities' => ['text_generation' => true, 'chat' => true, 'vision' => true, 'embedding' => true, 'tool_calling' => true],
                'models' => ['gemini-2.5-flash', 'gemini-2.0-flash'],
                'default_model' => 'gemini-2.5-flash',
                'provider_type' => 'gemini',
            ],
            [
                'instance_id' => 'mistral_free',
                'instance_name' => 'Mistral API',
                'instance_type' => 'custom',
                'base_url' => 'https://api.mistral.ai/v1',
                'api_key_env' => 'MISTRAL_API_KEY',
                'priority' => 85,
                'rate_limit_rpm' => 2,
                'rate_limit_tpm' => 500000,
                'capabilities' => ['text_generation' => true, 'chat' => true, 'vision' => true, 'embedding' => true, 'tool_calling' => true],
                'models' => ['mistral-small-latest', 'mistral-large-latest'],
                'default_model' => 'mistral-small-latest',
                'provider_type' => 'mistral',
            ],
        ] as $provider) {
            $this->insertIfMissing('llm_instances', ['instance_id' => $provider['instance_id']], [
                'instance_name' => $provider['instance_name'],
                'instance_type' => $provider['instance_type'],
                'base_url' => $provider['base_url'],
                'api_key_env' => $provider['api_key_env'],
                'priority' => $provider['priority'],
                'is_active' => false,
                'routability' => 'bench_only',
                'gpu_target' => 'none',
                'compat_runtime_family' => $provider['provider_type'],
                'compat_backend' => 'openai_compat',
                'compat_status' => 'provisional',
                'is_healthy' => false,
                'health_score' => 0,
                'capabilities' => json_encode($provider['capabilities'], JSON_UNESCAPED_SLASHES),
                'is_censored' => true,
                'allows_private_data' => false,
                'data_privacy_scope' => 'public_only',
                'privacy_notes' => 'Optional public/free cloud provider. Do not send private or sensitive PLOS data.',
                'supported_models' => json_encode($provider['models'], JSON_UNESCAPED_SLASHES),
                'context_length' => 128000,
                'rate_limit_rpm' => $provider['rate_limit_rpm'],
                'rate_limit_tpm' => $provider['rate_limit_tpm'],
                'success_rate' => null,
                'circuit_state' => 'closed',
                'max_concurrent' => 1,
                'cost_tier' => 'external',
                'config' => json_encode([
                    'provider_type' => $provider['provider_type'],
                    'api_format' => 'openai_compatible',
                    'default_model' => $provider['default_model'],
                    'models' => [
                        'standard' => $provider['default_model'],
                        'quality' => $provider['default_model'],
                        'fast' => $provider['models'][0],
                    ],
                    'privacy_scope' => 'public_only',
                    'notes' => 'Optional public add-on. Disabled until the operator adds a key and intentionally enables the row. Do not send private data.',
                ], JSON_UNESCAPED_SLASHES),
                'notes' => 'Optional cloud provider. Public baseline leaves this disabled. Public-only until intentionally enabled.',
            ]);
        }
    }

    private function seedPublicGenealogyProviders(): void
    {
        foreach ([
            [
                'provider_id' => 'wikitree',
                'provider_name' => 'WikiTree',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\WikiTreeProvider',
                'provider_type' => 'api',
                'base_url' => 'https://api.wikitree.com/api.php',
                'auth_type' => 'none',
                'capabilities' => ['search_persons' => true, 'get_person' => true, 'get_ancestors' => true],
                'config' => ['free_access' => true, 'api_key_required' => false],
                'rate_limit_rpm' => 30,
                'is_active' => true,
                'is_authenticated' => true,
                'priority' => 10,
                'signup_url' => 'https://www.wikitree.com/wiki/Help:API_Documentation',
                'notes' => 'Free public genealogy API. No key required for public profile lookups.',
            ],
            [
                'provider_id' => 'findagrave',
                'provider_name' => 'Find a Grave',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\FindAGraveProvider',
                'provider_type' => 'scrape',
                'base_url' => 'https://www.findagrave.com',
                'auth_type' => 'none',
                'capabilities' => ['search_persons' => true, 'memorial_lookup' => true],
                'config' => ['free_access' => true, 'respect_rate_limits' => true],
                'rate_limit_rpm' => 20,
                'is_active' => true,
                'is_authenticated' => true,
                'priority' => 20,
                'signup_url' => 'https://www.findagrave.com',
                'notes' => 'Free public memorial search. Use conservative scraping and citations.',
            ],
            [
                'provider_id' => 'ellis_island',
                'provider_name' => 'Ellis Island',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\EllisIslandProvider',
                'provider_type' => 'scrape',
                'base_url' => 'https://heritage.statueofliberty.org',
                'auth_type' => 'none',
                'capabilities' => ['search_persons' => true, 'search_records' => true, 'get_record' => true],
                'config' => ['free_access' => true, 'respect_rate_limits' => true],
                'rate_limit_rpm' => 20,
                'is_active' => true,
                'is_authenticated' => true,
                'priority' => 30,
                'signup_url' => 'https://heritage.statueofliberty.org',
                'notes' => 'Public passenger-record search. Use citation review before applying findings.',
            ],
            [
                'provider_id' => 'blm_glo',
                'provider_name' => 'BLM GLO Land Records',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\BLMGLOProvider',
                'provider_type' => 'scrape',
                'base_url' => 'https://glorecords.blm.gov',
                'auth_type' => 'none',
                'capabilities' => ['search_persons' => true, 'search_records' => true, 'get_record' => true],
                'config' => ['free_access' => true, 'respect_rate_limits' => true],
                'rate_limit_rpm' => 20,
                'is_active' => true,
                'is_authenticated' => true,
                'priority' => 40,
                'signup_url' => 'https://glorecords.blm.gov',
                'notes' => 'Public land-record search. Use citation review before applying findings.',
            ],
            [
                'provider_id' => 'billiongraves',
                'provider_name' => 'BillionGraves',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\BillionGravesProvider',
                'provider_type' => 'api',
                'base_url' => 'https://billiongraves.com',
                'auth_type' => 'none',
                'capabilities' => ['search_persons' => true, 'gps_locations' => true],
                'config' => ['free_access' => true],
                'rate_limit_rpm' => 20,
                'is_active' => false,
                'is_authenticated' => false,
                'priority' => 60,
                'signup_url' => 'https://billiongraves.com',
                'notes' => 'Optional public cemetery source. Disabled until verified by the operator.',
            ],
            [
                'provider_id' => 'myheritage',
                'provider_name' => 'MyHeritage',
                'provider_class' => 'App\\Services\\Genealogy\\Providers\\MyHeritageProvider',
                'provider_type' => 'manual',
                'base_url' => 'https://www.myheritage.com',
                'auth_type' => 'none',
                'capabilities' => ['manual_source_reference' => true],
                'config' => ['requires_subscription' => true, 'automation_enabled' => false],
                'rate_limit_rpm' => null,
                'is_active' => false,
                'is_authenticated' => false,
                'priority' => 90,
                'signup_url' => 'https://www.myheritage.com',
                'notes' => 'Manual/private source reference only. Public baseline does not automate subscription access.',
            ],
        ] as $provider) {
            $this->insertIfMissing('genealogy_research_providers', ['provider_id' => $provider['provider_id']], [
                'provider_name' => $provider['provider_name'],
                'provider_class' => $provider['provider_class'],
                'provider_type' => $provider['provider_type'],
                'base_url' => $provider['base_url'],
                'api_key_env' => null,
                'auth_type' => $provider['auth_type'],
                'capabilities' => json_encode($provider['capabilities'], JSON_UNESCAPED_SLASHES),
                'config' => json_encode($provider['config'], JSON_UNESCAPED_SLASHES),
                'rate_limit_rpm' => $provider['rate_limit_rpm'],
                'is_active' => $provider['is_active'],
                'is_authenticated' => $provider['is_authenticated'],
                'priority' => $provider['priority'],
                'signup_url' => $provider['signup_url'],
                'notes' => $provider['notes'],
            ]);
        }
    }

    private function seedSystemConfig(string $section, string $key, string $value, string $dataType, string $description): void
    {
        $this->insertIfMissing('system_configs', [
            'section' => $section,
            'config_key' => $key,
        ], [
            'config_value' => $value,
            'data_type' => $dataType,
            'description' => $description,
        ]);
    }

    private function insertIfMissing(string $table, array $where, array $values): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $query = DB::table($table);
        foreach ($where as $column => $value) {
            if (! $this->hasColumn($table, $column)) {
                return;
            }

            $query->where($column, $value);
        }

        if ($query->exists()) {
            return;
        }

        $row = array_merge($where, $values);
        $now = now();

        if ($this->hasColumn($table, 'created_at') && ! array_key_exists('created_at', $row)) {
            $row['created_at'] = $now;
        }

        if ($this->hasColumn($table, 'updated_at') && ! array_key_exists('updated_at', $row)) {
            $row['updated_at'] = $now;
        }

        DB::table($table)->insert($this->filterColumns($table, $row));
    }

    private function filterColumns(string $table, array $row): array
    {
        $columns = array_flip($this->columns($table));

        return array_filter(
            $row,
            fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->columns($table), true);
    }

    private function columns(string $table): array
    {
        if (! isset($this->columnCache[$table])) {
            $this->columnCache[$table] = Schema::getColumnListing($table);
        }

        return $this->columnCache[$table];
    }

    private function defaultModel(): string
    {
        return $this->configString('services.ollama.model', 'llama3.1:8b');
    }

    private function embeddingModel(): string
    {
        return $this->configString('services.ollama.embedding_model', 'nomic-embed-text');
    }

    private function visionModel(): string
    {
        return $this->configString('services.ollama.vision_model', 'llava:7b');
    }

    private function configString(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
