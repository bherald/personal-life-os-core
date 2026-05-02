<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * OllamaModelRegistryService
 *
 * Manages the model registry for Ollama LLMs across MULTIPLE instances:
 * - Discovers new models and flags them for human vetting
 * - Tracks vetted models and their capabilities PER INSTANCE
 * - Provides recommendations based on task requirements
 * - Alerts when new models need attention
 * - Supports multiple Ollama servers with independent model sets
 *
 * Multi-Instance Support:
 * - Each model is tracked per-instance (same model on 2 servers = 2 rows)
 * - syncModels() now iterates over all registered Ollama instances
 * - Uses instance_id FK to link models to their source instance
 *
 * Workflow:
 * 1. Maintenance job calls syncModels() nightly
 * 2. New models get 'discovered' status
 * 3. Human vets model via UI or CLI, sets to 'vetted'
 * 4. Framework uses only vetted models for production tasks
 */
class OllamaModelRegistryService
{
    private AIService $aiService;
    private ?LLMPoolManagerService $poolManager;

    // Known profile mappings for auto-detection
    private const PROFILE_PATTERNS = [
        'embedding' => ['embed', 'nomic-embed', 'bge', 'e5'],
        'vision' => ['llava', 'bakllava', 'cogvlm', 'vision', 'vl'],
        'coding' => ['codellama', 'deepseek-coder', 'starcoder', 'wizard-coder', 'phind', 'coder'],
        'quality' => ['deepseek-r1'],
        'creative' => ['dolphin', 'nous-hermes', 'openhermes', 'mistral-openorca'],
        'fast' => ['q4', 'q3', 'tiny', 'mini', 'small'],
    ];

    public function __construct(AIService $aiService, ?LLMPoolManagerService $poolManager = null)
    {
        $this->aiService = $aiService;
        $this->poolManager = $poolManager;
    }

    /**
     * Sync models from ALL Ollama instances with the database registry
     * Called nightly by OpsMaintenanceJob
     *
     * @return array Sync results by instance
     */
    public function syncModels(): array
    {
        $results = [
            'discovered' => [],
            'updated' => [],
            'unavailable' => [],
            'errors' => [],
            'by_instance' => [],
        ];

        // Get all Ollama instances from pool manager or fallback to primary
        $instances = $this->getOllamaInstances();

        foreach ($instances as $instance) {
            $instanceResult = $this->syncModelsForInstance($instance);
            $results['by_instance'][$instance['instance_id']] = $instanceResult;

            // Merge into totals
            $results['discovered'] = array_merge($results['discovered'], $instanceResult['discovered']);
            $results['updated'] = array_merge($results['updated'], $instanceResult['updated']);
            $results['unavailable'] = array_merge($results['unavailable'], $instanceResult['unavailable']);
            $results['errors'] = array_merge($results['errors'], $instanceResult['errors']);
        }

        // Alert if new models need vetting
        if (!empty($results['discovered'])) {
            $this->alertNewModels($results['discovered']);
        }

        return $results;
    }

    /**
     * Sync models for a specific Ollama instance
     *
     * @param array $instance Instance data with id, url, db_id
     * @return array Sync results for this instance
     */
    private function syncModelsForInstance(array $instance): array
    {
        $results = [
            'discovered' => [],
            'updated' => [],
            'unavailable' => [],
            'errors' => [],
        ];

        try {
            // Get models from this specific Ollama instance.
            // null = unreachable (bail, don't mark anything unavailable);
            // [] = healthy but empty (continue, so phantom rows flip to unavailable).
            $ollamaModels = $this->getModelsFromOllama($instance['url']);

            if ($ollamaModels === null) {
                Log::warning('OllamaModelRegistry: Ollama instance unreachable, skipping sync', [
                    'instance_id' => $instance['instance_id'],
                    'url' => $instance['url'],
                ]);
                return $results;
            }

            $seenModels = [];

            foreach ($ollamaModels as $model) {
                $modelName = $model['name'];
                $seenModels[] = $modelName;

                // Check if model exists in registry FOR THIS INSTANCE
                $existing = DB::selectOne(
                    'SELECT id, status FROM ollama_models WHERE instance_id = ? AND model_name = ?',
                    [$instance['db_id'], $modelName]
                );

                $sizeGb = isset($model['size']) ? round($model['size'] / 1024 / 1024 / 1024, 2) : null;
                $profile = $this->detectProfile($modelName);
                $capabilities = $this->detectCapabilities($modelName, $profile);

                if (!$existing) {
                    // New model discovered on this instance
                    DB::insert(
                        'INSERT INTO ollama_models
                        (instance_id, model_name, display_name, profile, status, is_available, capabilities, size_gb, first_seen_at, last_seen_at, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW(), NOW(), NOW())',
                        [
                            $instance['db_id'],
                            $modelName,
                            $this->generateDisplayName($modelName),
                            $profile,
                            'discovered',
                            json_encode($capabilities),
                            $sizeGb,
                        ]
                    );

                    $results['discovered'][] = "{$instance['instance_id']}:{$modelName}";

                    Log::info('OllamaModelRegistry: New model discovered on instance', [
                        'instance_id' => $instance['instance_id'],
                        'model' => $modelName,
                        'profile' => $profile,
                        'size_gb' => $sizeGb,
                    ]);
                } else {
                    // Update existing model
                    DB::update(
                        'UPDATE ollama_models
                        SET is_available = 1,
                            last_seen_at = NOW(),
                            size_gb = COALESCE(?, size_gb),
                            profile = COALESCE(profile, ?),
                            updated_at = NOW()
                        WHERE id = ?',
                        [$sizeGb, $profile, $existing->id]
                    );

                    // Only mark as updated if it was previously unavailable
                    if ($existing->status === 'unavailable') {
                        DB::update(
                            'UPDATE ollama_models SET status = ? WHERE id = ?',
                            ['discovered', $existing->id]
                        );
                        $results['updated'][] = "{$instance['instance_id']}:{$modelName}";
                    }
                }
            }

            // Mark models not seen on THIS INSTANCE as unavailable
            if (!empty($seenModels)) {
                $placeholders = implode(',', array_fill(0, count($seenModels), '?'));
                $params = array_merge([$instance['db_id']], $seenModels);
                $unavailable = DB::select(
                    "SELECT model_name FROM ollama_models
                    WHERE instance_id = ? AND model_name NOT IN ({$placeholders}) AND is_available = 1",
                    $params
                );

                foreach ($unavailable as $model) {
                    DB::update(
                        'UPDATE ollama_models SET is_available = 0, updated_at = NOW() WHERE instance_id = ? AND model_name = ?',
                        [$instance['db_id'], $model->model_name]
                    );
                    $results['unavailable'][] = "{$instance['instance_id']}:{$model->model_name}";
                }
            }

        } catch (\Exception $e) {
            Log::error('OllamaModelRegistry: Sync failed for instance', [
                'instance_id' => $instance['instance_id'],
                'error' => $e->getMessage(),
            ]);
            $results['errors'][] = "{$instance['instance_id']}: {$e->getMessage()}";
        }

        return $results;
    }

    /**
     * Get all Ollama instances from pool manager or config
     *
     * @return array List of instances with id, url, db_id
     */
    private function getOllamaInstances(): array
    {
        $instances = [];

        // Try to get from pool manager first
        if ($this->poolManager) {
            try {
                $poolInstances = $this->poolManager->getInstances(false);
                foreach ($poolInstances as $inst) {
                    if ($inst->instance_type === 'ollama' && $inst->is_active) {
                        $instances[] = [
                            'instance_id' => $inst->instance_id,
                            'url' => $inst->base_url,
                            'db_id' => $inst->id,
                        ];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('OllamaModelRegistry: Failed to get instances from pool manager', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: get from llm_instances table directly
        if (empty($instances)) {
            try {
                $dbInstances = DB::select(
                    "SELECT id, instance_id, base_url FROM llm_instances
                     WHERE instance_type = 'ollama' AND is_active = 1
                     ORDER BY priority ASC"
                );
                foreach ($dbInstances as $inst) {
                    $instances[] = [
                        'instance_id' => $inst->instance_id,
                        'url' => $inst->base_url,
                        'db_id' => $inst->id,
                    ];
                }
            } catch (\Exception $e) {
                Log::warning('OllamaModelRegistry: Failed to get instances from DB', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Final fallback: use config
        if (empty($instances)) {
            $instances[] = [
                'instance_id' => 'ollama_primary',
                'url' => config('services.ollama.api_url', 'http://127.0.0.1:11434'),
                'db_id' => 1, // Assume primary is ID 1
            ];
        }

        return $instances;
    }

    /**
     * Get models directly from an Ollama instance.
     *
     * Tri-state return disambiguates "empty but healthy" from "unreachable":
     *   - `null`  → HTTP call failed (exception OR non-successful status).
     *   - `[]`    → Call succeeded but host has zero models installed.
     *   - array  → Call succeeded with a populated models list.
     *
     * @param string $url Ollama API URL
     * @return array<int, array<string, mixed>>|null Models list, or null if unreachable
     */
    private function getModelsFromOllama(string $url): ?array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->get("{$url}/api/tags");

            if ($response->successful()) {
                return $response->json()['models'] ?? [];
            }
        } catch (\Exception $e) {
            Log::warning('OllamaModelRegistry: Failed to fetch models from Ollama', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Row 3 follow-up — drift check between live `/api/tags` inventory and
     * the `llm_instances.supported_models` JSON column.
     *
     * Returns per-instance drift report without mutating any state. Two
     * drift categories are tracked independently:
     *
     *   - `in_live_not_in_db` — models present on the host but missing
     *     from the DB. Usually means someone ran `ollama pull` outside
     *     PLOS control. Informational: operator decides whether to vett.
     *   - `in_db_not_in_live` — models listed in DB but absent from the
     *     live host. Usually a phantom row from a previous rollback or
     *     cache flush. Higher severity: the DB is lying about what's
     *     available, which can break routing if assigned to a role.
     *
     * Unreachable instances are surfaced with `unreachable: true` and no
     * drift lists, so operators can distinguish "no drift" from "couldn't
     * talk to the host."
     *
     * @return array<int, array{instance_id:string, base_url:string, unreachable:bool, live_count:int, db_count:int, in_live_not_in_db:array<int,string>, in_db_not_in_live:array<int,string>}>
     */
    public function driftCheck(): array
    {
        $instances = DB::select(
            "SELECT instance_id, base_url, supported_models
             FROM llm_instances
             WHERE instance_type = 'ollama' AND is_active = 1
             ORDER BY instance_id"
        );

        $report = [];
        foreach ($instances as $row) {
            $baseUrl = (string) ($row->base_url ?? '');
            $dbModels = [];
            if (! empty($row->supported_models)) {
                $decoded = json_decode((string) $row->supported_models, true);
                if (is_array($decoded)) {
                    $dbModels = array_values(array_filter($decoded, 'is_string'));
                }
            }

            $liveRaw = $this->getModelsFromOllama($baseUrl);
            $unreachable = $liveRaw === null;

            $liveModels = [];
            foreach (($liveRaw ?? []) as $entry) {
                if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                    $liveModels[] = $entry['name'];
                }
            }

            $dbSet = array_flip($dbModels);
            $liveSet = array_flip($liveModels);

            $inLiveNotInDb = $unreachable ? [] : array_values(array_filter($liveModels, static fn ($m) => ! isset($dbSet[$m])));
            $inDbNotInLive = $unreachable ? [] : array_values(array_filter($dbModels, static fn ($m) => ! isset($liveSet[$m])));

            sort($inLiveNotInDb);
            sort($inDbNotInLive);

            $report[] = [
                'instance_id' => (string) $row->instance_id,
                'base_url' => $baseUrl,
                'unreachable' => $unreachable,
                'live_count' => count($liveModels),
                'db_count' => count($dbModels),
                'in_live_not_in_db' => $inLiveNotInDb,
                'in_db_not_in_live' => $inDbNotInLive,
            ];
        }

        return $report;
    }

    /**
     * Get all models pending vetting
     *
     * @return array Models that need human review
     */
    public function getPendingVetting(): array
    {
        return DB::select(
            "SELECT * FROM ollama_models
            WHERE status = 'discovered' AND is_available = 1
            ORDER BY first_seen_at DESC"
        );
    }

    /**
     * Get vetted models for a specific profile
     *
     * @param string|null $profile Filter by profile
     * @return array Vetted and available models
     */
    public function getVettedModels(?string $profile = null): array
    {
        $query = "SELECT * FROM ollama_models
                  WHERE status = 'vetted' AND is_available = 1";
        $params = [];

        if ($profile) {
            $query .= ' AND profile = ?';
            $params[] = $profile;
        }

        // MySQL doesn't support NULLS LAST, use COALESCE workaround
        $query .= ' ORDER BY COALESCE(quality_rating, 0) DESC, COALESCE(success_rate, 0) DESC';

        return DB::select($query, $params);
    }

    /**
     * Vet a model (mark as approved for production use)
     *
     * @param string $modelName Model to vet
     * @param array $vettingData Vetting information
     * @return bool Success
     */
    public function vetModel(string $modelName, array $vettingData): bool
    {
        $model = DB::selectOne(
            'SELECT id FROM ollama_models WHERE model_name = ?',
            [$modelName]
        );

        if (!$model) {
            return false;
        }

        DB::update(
            "UPDATE ollama_models SET
                status = 'vetted',
                profile = COALESCE(?, profile),
                capabilities = COALESCE(?, capabilities),
                use_cases = ?,
                description = ?,
                quality_rating = ?,
                vetting_notes = ?,
                vetted_at = NOW(),
                vetted_by = ?,
                updated_at = NOW()
            WHERE id = ?",
            [
                $vettingData['profile'] ?? null,
                isset($vettingData['capabilities']) ? json_encode($vettingData['capabilities']) : null,
                isset($vettingData['use_cases']) ? json_encode($vettingData['use_cases']) : null,
                $vettingData['description'] ?? null,
                $vettingData['quality_rating'] ?? null,
                $vettingData['vetting_notes'] ?? null,
                $vettingData['vetted_by'] ?? 'system',
                $model->id,
            ]
        );

        // Clear cache
        Cache::forget('ollama_available_models');
        Cache::forget('ollama_vetted_models');

        Log::info('OllamaModelRegistry: Model vetted', [
            'model' => $modelName,
            'profile' => $vettingData['profile'] ?? null,
            'rating' => $vettingData['quality_rating'] ?? null,
        ]);

        return true;
    }

    /**
     * Record model usage metrics
     *
     * @param string $modelName Model used
     * @param bool $success Request succeeded
     * @param int $responseTimeMs Response time in milliseconds
     * @param float|null $tokensPerSecond Generation speed
     */
    public function recordUsage(string $modelName, bool $success, int $responseTimeMs, ?float $tokensPerSecond = null): void
    {
        try {
            // Update metrics with running averages
            DB::update(
                "UPDATE ollama_models SET
                    total_requests = total_requests + 1,
                    total_failures = total_failures + ?,
                    avg_response_time_ms = COALESCE(
                        (avg_response_time_ms * (total_requests - 1) + ?) / total_requests,
                        ?
                    ),
                    avg_tokens_per_second = CASE
                        WHEN ? IS NOT NULL THEN COALESCE(
                            (avg_tokens_per_second * (total_requests - 1) + ?) / total_requests,
                            ?
                        )
                        ELSE avg_tokens_per_second
                    END,
                    success_rate = ((total_requests - total_failures) * 100.0) / total_requests,
                    updated_at = NOW()
                WHERE model_name = ?",
                [
                    $success ? 0 : 1,
                    $responseTimeMs,
                    $responseTimeMs,
                    $tokensPerSecond,
                    $tokensPerSecond,
                    $tokensPerSecond,
                    $modelName,
                ]
            );
        } catch (\Exception $e) {
            // Don't fail the request if metrics update fails
            Log::debug('OllamaModelRegistry: Failed to record usage', [
                'model' => $modelName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get best model for a task type
     * Returns vetted model if available, otherwise falls back to AIService profiles
     *
     * @param string $taskType Task type (default, fast, creative, coding, vision, embedding)
     * @return array Model recommendation
     */
    public function getBestModelForTask(string $taskType): array
    {
        // Try vetted models first
        $vetted = $this->getVettedModels($taskType);

        if (!empty($vetted)) {
            $best = $vetted[0];
            return [
                'model' => $best->model_name,
                'source' => 'vetted_registry',
                'quality_rating' => $best->quality_rating,
                'success_rate' => $best->success_rate,
                'profile' => $best->profile,
            ];
        }

        // Fallback to AIService profiles
        $model = $this->aiService->selectModel($taskType);
        return [
            'model' => $model,
            'source' => 'profile_default',
            'quality_rating' => null,
            'success_rate' => null,
            'profile' => $taskType,
        ];
    }

    /**
     * Get registry statistics
     *
     * @return array Stats summary
     */
    public function getStats(): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_models,
                SUM(CASE WHEN status = 'vetted' THEN 1 ELSE 0 END) as vetted,
                SUM(CASE WHEN status = 'discovered' THEN 1 ELSE 0 END) as pending_vetting,
                SUM(CASE WHEN status = 'testing' THEN 1 ELSE 0 END) as testing,
                SUM(CASE WHEN is_available = 1 THEN 1 ELSE 0 END) as available,
                AVG(CASE WHEN status = 'vetted' THEN quality_rating END) as avg_quality_rating
            FROM ollama_models"
        );

        return [
            'total_models' => (int) $stats->total_models,
            'vetted' => (int) $stats->vetted,
            'pending_vetting' => (int) $stats->pending_vetting,
            'testing' => (int) $stats->testing,
            'available' => (int) $stats->available,
            'avg_quality_rating' => $stats->avg_quality_rating ? round($stats->avg_quality_rating, 1) : null,
        ];
    }

    /**
     * Alert about new models needing vetting
     */
    private function alertNewModels(array $models): void
    {
        $count = count($models);
        $modelList = implode(', ', array_slice($models, 0, 3));
        if ($count > 3) {
            $remaining = $count - 3;
            $modelList .= " (+{$remaining} more)";
        }

        $message = "New Ollama models discovered: {$modelList}. Please vet for production use.";

        Log::info('OllamaModelRegistry: New models alert', ['models' => $models]);

        try {
            $notifier = new \App\Controllers\NotificationController();
            $notifier->send('pushover', [
                'source_group' => 'ops_maintenance',
                'title' => 'New Ollama Models',
                'message' => $message,
                'priority' => 0,
            ]);
        } catch (\Exception $e) {
            Log::debug('OllamaModelRegistry: Failed to send Pushover alert', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Detect profile from model name patterns
     */
    private function detectProfile(string $modelName): ?string
    {
        $nameLower = strtolower($modelName);

        foreach (self::PROFILE_PATTERNS as $profile => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($nameLower, $pattern)) {
                    return $profile;
                }
            }
        }

        $parameterCount = $this->extractParameterCountBillions($nameLower);
        if ($parameterCount !== null && $parameterCount <= 4.5) {
            return 'fast';
        }

        // Default profile based on model characteristics
        if (
            str_contains($nameLower, 'instruct')
            || str_contains($nameLower, 'chat')
            || str_contains($nameLower, 'qwen')
            || str_contains($nameLower, 'gemma')
            || str_contains($nameLower, 'llama')
            || str_contains($nameLower, 'mistral')
        ) {
            return 'default';
        }

        return null;
    }

    /**
     * Detect capabilities from model name and profile
     */
    private function detectCapabilities(string $modelName, ?string $profile): array
    {
        $capabilities = ['text']; // All models can do text

        switch ($profile) {
            case 'vision':
                $capabilities[] = 'vision';
                $capabilities[] = 'image_analysis';
                break;
            case 'embedding':
                $capabilities = ['embedding', 'similarity'];
                break;
            case 'coding':
                $capabilities[] = 'code';
                $capabilities[] = 'code_review';
                break;
            case 'creative':
                $capabilities[] = 'creative_writing';
                $capabilities[] = 'uncensored';
                break;
        }

        // Check for tool use capability
        if (str_contains(strtolower($modelName), 'tool') || str_contains(strtolower($modelName), 'function')) {
            $capabilities[] = 'tool_use';
        }

        return array_unique($capabilities);
    }

    private function extractParameterCountBillions(string $modelName): ?float
    {
        if (! preg_match('/[:\-](\d+(?:\.\d+)?)b\b/', $modelName, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }

    /**
     * Generate human-friendly display name
     */
    private function generateDisplayName(string $modelName): string
    {
        // Remove quantization suffixes for display
        $name = preg_replace('/:[\w-]+$/', '', $modelName);
        $name = str_replace(['-', '_'], ' ', $name);
        return ucwords($name);
    }
}
