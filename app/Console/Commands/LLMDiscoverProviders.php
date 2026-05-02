<?php

namespace App\Console\Commands;

use App\Controllers\NotificationController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * LLM Provider Discovery - Finds free AI LLM APIs and validates existing provider health.
 *
 * Two modes:
 * 1. --discover: Checks OpenRouter for free models, reports what's available
 * 2. --health: Validates all configured external providers in llm_instances (ping, auth check)
 *
 * Run manually or schedule monthly for provider updates.
 */
class LLMDiscoverProviders extends Command
{
    protected $signature = 'llm:discover-providers
                            {--discover : Check OpenRouter/HuggingFace for available free models}
                            {--health : Validate all configured external LLM providers}
                            {--status : Show current provider status from llm_instances}
                            {--notify : Send Pushover notification with results}';

    protected $description = 'Discover free LLM providers and validate configured external APIs';

    private const OPENROUTER_MODELS_URL = 'https://openrouter.ai/api/v1/models';

    private const HUGGINGFACE_MODELS_URL = 'https://huggingface.co/api/models';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('health')) {
            return $this->healthCheck();
        }

        if ($this->option('discover')) {
            return $this->discoverProviders();
        }

        // Default: show status + health
        $this->showStatus();
        $this->healthCheck();

        return 0;
    }

    private function showStatus(): int
    {
        $this->info('=== Configured LLM Providers ===');

        $providers = DB::select('
            SELECT instance_id, instance_name, instance_type, base_url, api_key, api_key_env,
                   priority, is_active, health_score, rate_limit_rpm, cost_tier,
                   total_requests, total_failures, success_rate, last_success_at, config
            FROM llm_instances
            ORDER BY priority ASC
        ');

        $headers = ['ID', 'Name', 'Type', 'Priority', 'Active', 'Health', 'RPM Limit', 'Tier', 'Reqs', 'Fail%', 'Key'];
        $rows = [];

        foreach ($providers as $p) {
            if ($p->api_key) {
                $keySet = 'DB';
            } elseif ($p->api_key_env && $this->resolveRuntimeEnvValue($p->api_key_env)) {
                $keySet = 'ENV';
            } elseif ($p->api_key_env) {
                $keySet = 'MISSING';
            } else {
                $keySet = 'N/A';
            }
            $failRate = $p->total_requests > 0
                ? round(($p->total_failures / $p->total_requests) * 100, 1).'%'
                : '-';

            $rows[] = [
                $p->instance_id,
                substr($p->instance_name, 0, 25),
                $p->instance_type,
                $p->priority,
                $p->is_active ? 'Y' : 'N',
                $p->health_score,
                $p->rate_limit_rpm ?: '-',
                $p->cost_tier ?: '-',
                $p->total_requests,
                $failRate,
                $keySet,
            ];
        }

        $this->table($headers, $rows);

        // Show which providers need API keys
        $needKeys = collect($providers)->filter(fn ($p) => ! $p->api_key && $p->api_key_env && ! $this->resolveRuntimeEnvValue($p->api_key_env));
        if ($needKeys->isNotEmpty()) {
            $this->warn("\nProviders needing API keys (store in DB via UI or artisan):");
            foreach ($needKeys as $p) {
                $cfg = json_decode($p->config ?? '{}', true);
                $signupUrl = $cfg['signup_url'] ?? 'check provider website';
                $this->line("  {$p->instance_id}: Sign up at {$signupUrl}");
            }
        }

        return 0;
    }

    private function healthCheck(): int
    {
        $this->info("\n=== Health Check: External LLM Providers ===");

        $providers = DB::select("
            SELECT instance_id, instance_name, base_url, api_key, api_key_env, config
            FROM llm_instances
            WHERE is_active = 1
              AND instance_type NOT IN ('ollama', 'claude_cli')
        ");

        $results = [];

        foreach ($providers as $p) {
            $apiKey = $p->api_key ?: $this->resolveRuntimeEnvValue($p->api_key_env ?? null);

            if (! $apiKey) {
                $this->line("  {$p->instance_id}: SKIP (no API key)");
                $results[$p->instance_id] = 'no_key';

                continue;
            }

            $config = json_decode($p->config ?? '{}', true);
            $extraHeaders = $config['extra_headers'] ?? [];

            try {
                $headers = array_merge([
                    'Authorization' => "Bearer {$apiKey}",
                ], $extraHeaders);

                $response = Http::withHeaders($headers)
                    ->connectTimeout(5)
                    ->timeout(15)
                    ->get(rtrim($p->base_url, '/').'/models');

                if ($response->successful()) {
                    $models = $response->json('data', []);
                    $modelCount = count($models);
                    $this->info("  {$p->instance_id}: OK ({$modelCount} models available)");
                    $results[$p->instance_id] = 'healthy';

                    // Update health
                    DB::update('UPDATE llm_instances SET is_healthy = 1, last_health_check = NOW() WHERE instance_id = ?', [$p->instance_id]);
                } elseif ($response->status() === 401) {
                    $this->error("  {$p->instance_id}: AUTH FAILED (invalid API key)");
                    $results[$p->instance_id] = 'auth_failed';
                    DB::update('UPDATE llm_instances SET is_healthy = 0, last_health_check = NOW() WHERE instance_id = ?', [$p->instance_id]);
                } else {
                    $this->warn("  {$p->instance_id}: HTTP {$response->status()}");
                    $results[$p->instance_id] = 'http_'.$response->status();
                }
            } catch (\Exception $e) {
                $this->error("  {$p->instance_id}: ERROR - {$e->getMessage()}");
                $results[$p->instance_id] = 'error';
                DB::update('UPDATE llm_instances SET is_healthy = 0, last_health_check = NOW() WHERE instance_id = ?', [$p->instance_id]);
            }
        }

        if ($this->option('notify') && ! empty($results)) {
            $healthy = count(array_filter($results, fn ($r) => $r === 'healthy'));
            $total = count($results);
            $msg = "LLM Provider Health: {$healthy}/{$total} healthy";
            $failed = array_filter($results, fn ($r) => $r !== 'healthy' && $r !== 'no_key');
            if (! empty($failed)) {
                $msg .= "\nFailed: ".implode(', ', array_keys($failed));
            }
            app(NotificationController::class)->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => 'LLM Health Check',
                'message' => $msg,
                'priority' => empty($failed) ? -1 : 0,
            ]);
        }

        return 0;
    }

    private function discoverProviders(): int
    {
        $this->info("\n=== Discovering Free LLM Models ===");

        // Check OpenRouter free models
        $this->info("\n--- OpenRouter Free Models ---");
        try {
            $response = Http::connectTimeout(5)->timeout(30)->get(self::OPENROUTER_MODELS_URL);

            if ($response->successful()) {
                $allModels = $response->json('data', []);
                $freeModels = collect($allModels)->filter(function ($model) {
                    $inputPrice = (float) ($model['pricing']['prompt'] ?? 1);
                    $outputPrice = (float) ($model['pricing']['completion'] ?? 1);

                    return $inputPrice == 0 && $outputPrice == 0;
                })->values();

                $this->info("Found {$freeModels->count()} free models on OpenRouter:");

                $headers = ['Model ID', 'Context', 'Created'];
                $rows = [];
                foreach ($freeModels->take(20) as $model) {
                    $rows[] = [
                        $model['id'] ?? 'unknown',
                        ($model['context_length'] ?? 0).' tokens',
                        isset($model['created']) ? date('Y-m-d', $model['created']) : '-',
                    ];
                }
                $this->table($headers, $rows);

                if ($freeModels->count() > 20) {
                    $this->line('  ... and '.($freeModels->count() - 20).' more');
                }

                // Check if we have models that aren't in our supported_models list
                $existing = DB::selectOne("SELECT supported_models FROM llm_instances WHERE instance_id = 'openrouter_free'");
                if ($existing) {
                    $currentModels = json_decode($existing->supported_models ?? '[]', true);
                    $newFree = $freeModels->pluck('id')->diff(collect($currentModels))->values();
                    if ($newFree->isNotEmpty()) {
                        $this->warn("\nNew free models not in our config:");
                        foreach ($newFree->take(10) as $m) {
                            $this->line("  + {$m}");
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("OpenRouter API error: {$e->getMessage()}");
        }

        // Check HuggingFace trending text-generation models
        $this->info("\n--- HuggingFace Trending Text Generation Models ---");
        try {
            $response = Http::connectTimeout(5)->timeout(30)->get(self::HUGGINGFACE_MODELS_URL, [
                'filter' => 'text-generation',
                'sort' => 'trending',
                'limit' => 15,
            ]);

            if ($response->successful()) {
                $models = $response->json();
                $headers = ['Model', 'Downloads', 'Likes'];
                $rows = [];
                foreach ($models as $model) {
                    $rows[] = [
                        substr($model['id'] ?? 'unknown', 0, 50),
                        number_format($model['downloads'] ?? 0),
                        $model['likes'] ?? 0,
                    ];
                }
                $this->table($headers, $rows);
            }
        } catch (\Exception $e) {
            $this->error("HuggingFace API error: {$e->getMessage()}");
        }

        // Check Groq available models
        $groqKey = $this->resolveRuntimeEnvValue('GROQ_API_KEY');
        if ($groqKey) {
            $this->info("\n--- Groq Available Models ---");
            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$groqKey}",
                ])->connectTimeout(5)->timeout(15)->get('https://api.groq.com/openai/v1/models');

                if ($response->successful()) {
                    $models = collect($response->json('data', []));
                    $this->info("Groq has {$models->count()} models available:");
                    foreach ($models->sortBy('id') as $m) {
                        $this->line("  - {$m['id']}");
                    }
                }
            } catch (\Exception $e) {
                $this->error("Groq API error: {$e->getMessage()}");
            }
        }

        if ($this->option('notify')) {
            app(NotificationController::class)->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => 'LLM Discovery Complete',
                'message' => 'Run `php artisan llm:discover-providers --discover` to see results.',
                'priority' => -1,
            ]);
        }

        return 0;
    }

    private function resolveRuntimeEnvValue(?string $key): ?string
    {
        if (! $key) {
            return null;
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
