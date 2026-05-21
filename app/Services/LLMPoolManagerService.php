<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * LLMPoolManagerService - Dynamic Multi-Provider LLM Routing
 *
 * Manages a pool of LLM instances with:
 * - Dynamic routing based on health, load, and capabilities
 * - Per-instance circuit breakers (fault isolation)
 * - Per-instance busy locks (prevent resource contention)
 * - Health scoring with automatic recovery
 * - Self-healing: unhealthy instances automatically recover
 * - Cost-aware routing (future)
 *
 * Follows existing AIService patterns:
 * - Circuit breaker: 3 failures → open, 60s cooldown, half-open test
 * - Busy locks: Per-instance with configurable TTL
 * - Health checks: Nightly via OpsMaintenanceJob
 *
 * @see AIService For individual provider implementations
 */
class LLMPoolManagerService
{
    // Circuit breaker configuration — reads from config/circuit_breaker.php (SC-2.1)
    private const CIRCUIT_FAILURE_THRESHOLD = 5;    // Fallback only — config() is primary

    private const CIRCUIT_COOLDOWN_SECONDS = 30;

    // Health score thresholds — config/health_thresholds.php is primary (SC-2.5)
    private const HEALTH_SCORE_MIN = 0;

    private const HEALTH_SCORE_MAX = 100;

    private const HEALTH_SCORE_UNHEALTHY_THRESHOLD = 30;

    private const HEALTH_SCORE_DEGRADED_THRESHOLD = 60;

    // Scoring weights for routing decisions — config/health_thresholds.php is primary (SC-2.5)
    private const SCORE_WEIGHT_HEALTH = 0.30;

    private const SCORE_WEIGHT_RESPONSE_TIME = 0.25;

    private const SCORE_WEIGHT_SUCCESS_RATE = 0.20;

    private const SCORE_WEIGHT_PRIORITY = 0.15;

    // Cache TTLs
    private const INSTANCES_CACHE_TTL = 60; // 1 minute

    private const BUSY_LOCK_DEFAULT_TTL = 150; // 2.5 minutes (was 5 min — actual calls take 30-120s)

    public function __construct() {}

    // ═══════════════════════════════════════════════════════════════════
    // INSTANCE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get all active LLM instances
     *
     * @param  bool  $onlyHealthy  Only return healthy instances
     */
    public function getInstances(bool $onlyHealthy = false): array
    {
        $cacheKey = 'llm_instances_'.($onlyHealthy ? 'healthy' : 'all');

        return Cache::remember($cacheKey, self::INSTANCES_CACHE_TTL, function () use ($onlyHealthy) {
            $query = 'SELECT * FROM llm_instances WHERE is_active = 1';
            if ($onlyHealthy) {
                $query .= ' AND is_healthy = 1 AND circuit_state != ?';

                return DB::select($query, ['open']);
            }

            return DB::select($query);
        });
    }

    /**
     * Get instance by ID
     *
     * @param  string  $instanceId  Instance identifier
     */
    public function getInstance(string $instanceId): ?object
    {
        return DB::selectOne(
            'SELECT * FROM llm_instances WHERE instance_id = ?',
            [$instanceId]
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // ROUTING & SELECTION
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Select best instance for a request
     *
     * Routing logic:
     * 1. Filter by required capabilities
     * 2. Filter by model availability (if specific model requested)
     * 3. Filter out unhealthy/circuit-open instances
     * 4. Score remaining instances
     * 5. Return highest scoring available instance
     *
     * @param  array  $requirements  [
     *                               'capabilities' => ['text', 'vision'],
     *                               'model' => 'llama3.1:8b-instruct-q5_K_M',
     *                               'prefer_instance' => 'ollama_primary',
     *                               'exclude_instances' => ['ollama_secondary'],
     *                               'urgency' => 'normal|high|low',
     *                               ]
     * @return array|null Instance data with routing metadata, or null if none available
     */
    public function selectInstance(array $requirements = []): ?array
    {
        $capabilities = $requirements['capabilities'] ?? ['text'];
        $model = $requirements['model'] ?? null;
        $preferInstance = $requirements['prefer_instance'] ?? null;
        $excludeInstances = $requirements['exclude_instances'] ?? [];
        $urgency = $requirements['urgency'] ?? 'normal';
        // Role lets each instance "advertise" its strength via config.models.{role}.
        // Scoring rewards bigger models for quality/coding/standard/vision roles
        // and smaller models for fast/embedding roles so the right local host
        // wins on fitness rather than a hardcoded preference list.
        $role = $requirements['role'] ?? null;

        // Get all active instances
        $instances = $this->getInstances(false);

        if (empty($instances)) {
            Log::warning('LLMPoolManager: No active instances available', [
                'capabilities' => $capabilities ?? [],
                'model' => $model ?? null,
            ]);

            return null;
        }

        $authoritativeGpuTarget = $model !== null
            ? $this->resolveAuthoritativeGpuTargetForModel((string) $model)
            : null;

        $activeProfile = $this->resolveActiveProfile();
        $profileAllowedTypes = is_array($activeProfile['allowed_instance_types'] ?? null)
            ? $activeProfile['allowed_instance_types']
            : null;
        $profileAllowedCaps = is_array($activeProfile['allowed_capabilities'] ?? null)
            ? $activeProfile['allowed_capabilities']
            : null;

        $candidates = [];

        foreach ($instances as $instance) {
            // Skip excluded instances
            if (in_array($instance->instance_id, $excludeInstances)) {
                continue;
            }

            if (($instance->routability ?? null) !== 'allowed') {
                Log::debug('LLMPoolManager: instance skipped (routability != allowed)', [
                    'instance_id' => $instance->instance_id ?? null,
                    'instance_type' => $instance->instance_type ?? null,
                    'routability' => $instance->routability ?? null,
                ]);

                continue;
            }

            // 3a authority: stale compatibility status means the row has not been
            // re-vetted against the current runtime/backend baseline. Refuse routing.
            // Null/authoritative/provisional are allowed; only explicit 'stale' blocks.
            if (($instance->compat_status ?? null) === 'stale') {
                Log::debug('LLMPoolManager: instance skipped (compat_status=stale)', [
                    'instance_id' => $instance->instance_id ?? null,
                    'compat_runtime_family' => $instance->compat_runtime_family ?? null,
                    'compat_backend' => $instance->compat_backend ?? null,
                ]);

                continue;
            }

            // 3b P02d: provider-class gate via OfflinePolicyService. Under
            // offline profiles only local_llm is allowed; hybrid profiles add
            // cloud_sensitive_safe; cloud_external is only allowed under default.
            if (! $this->offlinePolicyAllowsProvider($instance)) {
                continue;
            }

            if (
                $profileAllowedTypes !== null
                && ! in_array($instance->instance_type ?? '', $profileAllowedTypes, true)
            ) {
                Log::debug('LLMPoolManager: instance skipped (profile instance_type gate)', [
                    'instance_id' => $instance->instance_id ?? null,
                    'instance_type' => $instance->instance_type ?? null,
                    'profile_allowed_types' => $profileAllowedTypes,
                ]);

                continue;
            }

            if (
                $profileAllowedCaps !== null
                && ! empty(array_diff($capabilities, $profileAllowedCaps))
            ) {
                Log::debug('LLMPoolManager: instance skipped (profile capability gate)', [
                    'instance_id' => $instance->instance_id ?? null,
                    'requested_capabilities' => $capabilities,
                    'profile_allowed_capabilities' => $profileAllowedCaps,
                ]);

                continue;
            }

            if (
                $authoritativeGpuTarget !== null
                && $authoritativeGpuTarget !== 'any'
                && ($instance->gpu_target ?? null) !== $authoritativeGpuTarget
            ) {
                continue;
            }

            // Check circuit breaker
            if (! $this->isCircuitClosed($instance)) {
                continue;
            }

            // Check capabilities
            $instanceCapabilities = json_decode($instance->capabilities, true) ?? [];
            if (! empty(array_diff($capabilities, $instanceCapabilities))) {
                continue;
            }

            // Check model availability (for Ollama instances)
            if ($model && $instance->instance_type === 'ollama') {
                if (! $this->instanceHasModel($instance->id, $model)) {
                    continue;
                }
            }

            // Exclude instances that don't advertise a model for the requested role.
            // Keeps selection honest — a host with no config.models.quality should
            // not win for quality work just because it is fast.
            if ($role !== null && $instance->instance_type === 'ollama') {
                if ($this->resolveRoleModel($instance, $role) === null) {
                    continue;
                }
            }

            // Calculate routing score (role-aware when a role is supplied)
            $score = $this->calculateRoutingScore($instance, $preferInstance, $urgency, $role);

            // Check if busy (affects score but doesn't exclude)
            $isBusy = $this->isInstanceBusy($instance->instance_id);

            $candidates[] = [
                'instance' => $instance,
                'score' => $score,
                'is_busy' => $isBusy,
                'adjusted_score' => $isBusy ? $score * 0.5 : $score, // Halve score if busy
            ];
        }

        if (empty($candidates)) {
            Log::warning('LLMPoolManager: No suitable instances for requirements', [
                'capabilities' => $capabilities,
                'model' => $model,
                'authoritative_gpu_target' => $authoritativeGpuTarget,
            ]);

            return null;
        }

        // Sort by adjusted score (highest first)
        usort($candidates, fn ($a, $b) => $b['adjusted_score'] <=> $a['adjusted_score']);

        $selected = $candidates[0];

        Log::debug('LLMPoolManager: Selected instance', [
            'instance_id' => $selected['instance']->instance_id,
            'score' => $selected['score'],
            'adjusted_score' => $selected['adjusted_score'],
            'is_busy' => $selected['is_busy'],
            'candidates_count' => count($candidates),
        ]);

        return [
            'instance' => $selected['instance'],
            'score' => $selected['score'],
            'is_busy' => $selected['is_busy'],
            'all_candidates' => array_map(fn ($c) => [
                'instance_id' => $c['instance']->instance_id,
                'score' => $c['adjusted_score'],
            ], $candidates),
        ];
    }

    /**
     * Calculate routing score for an instance
     *
     * @param  object  $instance  Instance data
     * @param  string|null  $preferInstance  Preferred instance ID
     * @param  string  $urgency  Request urgency
     * @return float Score 0-100
     */
    private function calculateRoutingScore(object $instance, ?string $preferInstance, string $urgency, ?string $role = null): float
    {
        $score = 0;

        // Health score component (0-30 points)
        $healthScore = ($instance->health_score / 100) * 100 * config('health_thresholds.llm.weight_health', self::SCORE_WEIGHT_HEALTH);
        $score += $healthScore;

        // Response time component (0-25 points) - faster is better
        if ($instance->avg_response_ms !== null && $instance->avg_response_ms > 0) {
            // Normalize: 100ms=25pts, 1000ms=15pts, 5000ms=5pts, 10000ms+=0pts
            $responseScore = max(0, 25 - ($instance->avg_response_ms / 400));
            $score += $responseScore * config('health_thresholds.llm.weight_response_time', self::SCORE_WEIGHT_RESPONSE_TIME) / 0.25;
        } else {
            $score += 15; // Unknown = assume moderate
        }

        // Success rate component (0-20 points)
        if ($instance->success_rate !== null) {
            $successScore = ($instance->success_rate / 100) * 100 * config('health_thresholds.llm.weight_success_rate', self::SCORE_WEIGHT_SUCCESS_RATE);
            $score += $successScore;
        } else {
            $score += 15; // Unknown = assume good
        }

        // Priority component (0-15 points) - lower priority number = higher score
        $priorityScore = max(0, (100 - $instance->priority)) * config('health_thresholds.llm.weight_priority', self::SCORE_WEIGHT_PRIORITY);
        $score += $priorityScore;

        // Preference bonus
        if ($preferInstance && $instance->instance_id === $preferInstance) {
            $score += 10;
        }

        // Urgency adjustment
        if ($urgency === 'high') {
            // For urgent requests, weight response time more heavily
            if ($instance->avg_response_ms !== null && $instance->avg_response_ms < 500) {
                $score += 5;
            }
        } elseif ($urgency === 'low') {
            // For low urgency, prefer free/cheap instances
            if ($instance->cost_tier === 'free') {
                $score += 5;
            }
        }

        // Role fitness bonus (0-20 points): let each instance advertise its strength
        // through config.models.{role}. For reasoning-heavy roles a bigger model wins;
        // for latency-sensitive roles a smaller model wins. This is what lets the 4070
        // win `coding`/`quality` work on score without a hardcoded preference list.
        if ($role !== null && $instance->instance_type === 'ollama') {
            $score += $this->scoreRoleFit($instance, $role);
        }

        return min(100, max(0, $score));
    }

    /**
     * Resolve the role-specific model for an instance from its config.models map.
     *
     * @return string|null Model name or null when the role is not advertised
     */
    private function resolveRoleModel(object $instance, string $role): ?string
    {
        try {
            $config = json_decode($instance->config ?? '{}', true) ?: [];
        } catch (\Throwable $e) {
            return null;
        }

        $models = is_array($config['models'] ?? null) ? $config['models'] : [];
        $model = $models[$role] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Score how well an instance's advertised model fits the requested role.
     *
     * Heavy roles (quality/coding/standard/vision/uncensored): bigger parameter
     * count scores higher — more headroom for complex reasoning or JSON discipline.
     *
     * Light roles (fast/embedding): smaller parameter count scores higher —
     * latency dominates over model capability.
     *
     * Returns 0 when the instance does not advertise the role or the parameter
     * count cannot be parsed.
     */
    private function scoreRoleFit(object $instance, string $role): float
    {
        $model = $this->resolveRoleModel($instance, $role);
        if ($model === null) {
            return 0;
        }

        $params = $this->parseModelParameterCount($model);
        if ($params === null) {
            return 0;
        }

        $heavyRoles = ['quality', 'coding', 'standard', 'vision', 'uncensored'];
        $lightRoles = ['fast', 'embedding'];

        if (in_array($role, $heavyRoles, true)) {
            // 4B=4, 8B=12, 14B=19, 32B=20 (clamped). Bigger = better for heavy work.
            return min(20.0, max(0.0, ($params - 2) * 1.5));
        }

        if (in_array($role, $lightRoles, true)) {
            // 2B=20, 4B=16, 8B=8, 14B=0. Smaller = better for latency-sensitive work.
            return min(20.0, max(0.0, 24.0 - ($params * 2.0)));
        }

        // Unknown role — moderate bonus, avoids silently disadvantaging new roles
        return 10.0;
    }

    /**
     * Parse parameter count in billions from an Ollama model tag.
     *
     * Handles the common patterns: `qwen3:8b`, `qwen2.5-coder:14b`,
     * `deepseek-coder:6.7b-instruct-q4_K_M`, `llama3.1:8b-instruct-q5_K_M`.
     */
    private function parseModelParameterCount(string $model): ?float
    {
        if (preg_match('/[:\-](\d+(?:\.\d+)?)b\b/i', $model, $matches)) {
            return (float) $matches[1];
        }

        // Some tags embed the size without a separator (rare): fall through.
        return null;
    }

    /**
     * Check if instance has a specific model
     *
     * @param  int  $instanceDbId  Database ID of instance
     * @param  string  $model  Model name
     */
    private function instanceHasModel(int $instanceDbId, string $model): bool
    {
        $result = DB::selectOne(
            'SELECT id FROM ollama_models
             WHERE instance_id = ? AND model_name = ? AND is_available = 1',
            [$instanceDbId, $model]
        );

        return $result !== null;
    }

    private function resolveAuthoritativeGpuTargetForModel(string $model): ?string
    {
        $cacheKey = 'llm_pool:gpu_target:'.sha1($model);

        return Cache::remember($cacheKey, 60, function () use ($model): ?string {
            $row = DB::selectOne(
                "SELECT li.gpu_target
                 FROM llm_instances li
                 JOIN ollama_models om ON om.instance_id = li.id
                 WHERE li.instance_type = 'ollama'
                   AND li.routability = 'allowed'
                   AND li.compat_status = 'authoritative'
                   AND om.model_name = ?
                   AND om.is_available = 1
                 ORDER BY li.priority ASC, li.id ASC
                 LIMIT 1",
                [$model]
            );

            $gpuTarget = trim((string) ($row->gpu_target ?? ''));

            return $gpuTarget !== '' ? $gpuTarget : null;
        });
    }

    private function resolveActiveProfile(): ?array
    {
        try {
            return app(\App\Services\AIService::class)->getActiveProfile();
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: active-profile lookup failed; treating as no profile', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 3b P02d — defer to OfflinePolicyService for provider-class enforcement.
     * Returns true when the service is unavailable (no regression for tests
     * that omit the binding) or when the provider class is allowed under the
     * active profile.
     */
    private function offlinePolicyAllowsProvider(object $instance): bool
    {
        try {
            $policy = app(\App\Services\OfflinePolicyService::class);
        } catch (\Throwable $e) {
            return true;
        }

        try {
            $decision = $policy->evaluateProvider($instance);
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: offline policy evaluation failed; passing through', [
                'instance_id' => $instance->instance_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return true;
        }

        if (! $decision->allowed) {
            Log::debug('LLMPoolManager: instance skipped by offline policy', [
                'instance_id' => $instance->instance_id ?? null,
                'profile' => $decision->profile,
                'provider_class' => $decision->providerClass,
                'reason' => $decision->reason,
            ]);

            return false;
        }

        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // CIRCUIT BREAKER (Per-Instance)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Check if circuit is closed (requests allowed)
     *
     * @param  object  $instance  Instance data
     * @return bool True if requests should proceed
     */
    public function isCircuitClosed(object $instance): bool
    {
        if ($instance->circuit_state === 'closed') {
            return true;
        }

        if ($instance->circuit_state === 'open') {
            // Check if cooldown has passed
            if ($instance->circuit_retry_at && now()->gte($instance->circuit_retry_at)) {
                // Transition to half-open
                $this->setCircuitState($instance->instance_id, 'half_open');

                return true;
            }

            return false;
        }

        // half_open - allow limited requests
        return true;
    }

    /**
     * Record a successful request (for circuit breaker)
     *
     * @param  string  $instanceId  Instance identifier
     * @param  int  $responseTimeMs  Response time in milliseconds
     */
    public function recordSuccess(string $instanceId, int $responseTimeMs): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance || ! $this->isInstanceActive($instance)) {
            return;
        }

        // If in half-open state, close the circuit
        if ($instance->circuit_state === 'half_open') {
            $this->setCircuitState($instanceId, 'closed');
            Log::info('LLMPoolManager: Circuit closed after successful test', [
                'instance_id' => $instanceId,
            ]);
        }

        // Update metrics
        DB::update(
            'UPDATE llm_instances SET
                total_requests = total_requests + 1,
                consecutive_failures = 0,
                avg_response_ms = COALESCE(
                    (avg_response_ms * 0.9) + (? * 0.1),
                    ?
                ),
                success_rate = ((total_requests - total_failures) * 100.0) / (total_requests + 1),
                last_success_at = NOW(),
                health_score = LEAST(100, health_score + 5),
                is_healthy = CASE WHEN health_score >= ? THEN 1 ELSE is_healthy END,
                updated_at = NOW()
            WHERE instance_id = ?',
            [$responseTimeMs, $responseTimeMs, config('health_thresholds.llm.unhealthy_threshold', self::HEALTH_SCORE_UNHEALTHY_THRESHOLD), $instanceId]
        );

        $this->clearInstanceCache();
    }

    /**
     * Record a failed request (for circuit breaker)
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $error  Error message
     */
    public function recordFailure(string $instanceId, string $error, ?int $retryAfterSeconds = null): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance || ! $this->isInstanceActive($instance)) {
            return;
        }

        $consecutiveFailures = $instance->consecutive_failures + 1;

        // Update metrics
        DB::update(
            'UPDATE llm_instances SET
                total_requests = total_requests + 1,
                total_failures = total_failures + 1,
                consecutive_failures = ?,
                success_rate = ((total_requests - total_failures - 1) * 100.0) / (total_requests + 1),
                last_failure_at = NOW(),
                health_score = GREATEST(0, health_score - 10),  -- 2:1 ratio now (was 10:1)
                is_healthy = CASE WHEN health_score <= ? THEN 0 ELSE is_healthy END,
                updated_at = NOW()
            WHERE instance_id = ?',
            [$consecutiveFailures, config('health_thresholds.llm.unhealthy_threshold', self::HEALTH_SCORE_UNHEALTHY_THRESHOLD), $instanceId]
        );

        // Check if circuit should open
        if ($consecutiveFailures >= config('circuit_breaker.failure_threshold', self::CIRCUIT_FAILURE_THRESHOLD)) {
            $this->openCircuit($instanceId, $error, $retryAfterSeconds);
        }

        // If in half-open, go back to open
        if ($instance->circuit_state === 'half_open') {
            $this->openCircuit($instanceId, "Half-open test failed: {$error}", $retryAfterSeconds);
        }

        $this->clearInstanceCache();
    }

    private function isInstanceActive(object $instance): bool
    {
        return (bool) ($instance->is_active ?? false);
    }

    /**
     * Open the circuit breaker for an instance
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $reason  Reason for opening
     */
    private function openCircuit(string $instanceId, string $reason, ?int $retryAfterSeconds = null): void
    {
        // Honor Retry-After header from 429 responses — use provider's window, not fixed 30s
        $cooldown = config('circuit_breaker.cooldown_seconds', self::CIRCUIT_COOLDOWN_SECONDS);
        if ($retryAfterSeconds !== null && $retryAfterSeconds > $cooldown) {
            $cooldown = min($retryAfterSeconds, 7200); // cap at 2 hours
        }
        $retryAt = now()->addSeconds($cooldown);

        DB::update(
            "UPDATE llm_instances SET
                circuit_state = 'open',
                circuit_opened_at = NOW(),
                circuit_retry_at = ?,
                is_healthy = 0,
                updated_at = NOW()
            WHERE instance_id = ?",
            [$retryAt, $instanceId]
        );

        Log::warning('LLMPoolManager: Circuit opened', [
            'instance_id' => $instanceId,
            'reason' => $reason,
            'retry_at' => $retryAt->toDateTimeString(),
        ]);

        // Check for cascade: 3+ providers with open circuits = systemic failure
        $this->checkCircuitCascade();

        $this->clearInstanceCache();
    }

    /**
     * Set circuit state
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $state  Circuit state
     */
    private function setCircuitState(string $instanceId, string $state): void
    {
        $updates = [
            'circuit_state' => $state,
            'updated_at' => now(),
        ];

        if ($state === 'closed') {
            $updates['circuit_opened_at'] = null;
            $updates['circuit_retry_at'] = null;
            $updates['consecutive_failures'] = 0;
        }

        $sets = [];
        $params = [];
        foreach ($updates as $field => $value) {
            $sets[] = "{$field} = ?";
            $params[] = $value;
        }
        $params[] = $instanceId;

        DB::update(
            'UPDATE llm_instances SET '.implode(', ', $sets).' WHERE instance_id = ?',
            $params
        );

        $this->clearInstanceCache();
    }

    /**
     * Alert when 3+ providers have open circuits simultaneously (cascade failure).
     * Rate-limited to one alert per 30 minutes.
     */
    private function checkCircuitCascade(): void
    {
        try {
            $openCircuits = DB::select(
                "SELECT instance_name FROM llm_instances WHERE circuit_state = 'open' AND is_active = 1"
            );

            if (count($openCircuits) < 3) {
                return;
            }

            if (Cache::has('circuit_cascade_alert_sent')) {
                return;
            }

            $names = implode(', ', array_map(fn ($r) => $r->instance_name, $openCircuits));
            Cache::put('circuit_cascade_alert_sent', true, 1800); // 30 min

            Log::critical('LLMPoolManager: Circuit cascade — '.count($openCircuits).' providers open', [
                'providers' => $names,
            ]);

            // Cascade alerts are informational — circuits self-heal. No emergency ACK needed.
            // Disabled: too noisy, circuits auto-recover. Log is sufficient.
            // app(\App\Controllers\NotificationController::class)->send('pushover', [
            //     'title' => 'LLM CASCADE: ' . count($openCircuits) . ' providers down',
            //     'message' => "Open circuits: {$names}\n\nCircuits will auto-recover. Check if persistent.",
            //     'priority' => -1,
            // ]);
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: cascade check failed', ['error' => $e->getMessage()]);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // BUSY LOCK (Per-Instance)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Check if instance is currently busy
     *
     * @param  string  $instanceId  Instance identifier
     */
    public function isInstanceBusy(string $instanceId): bool
    {
        return Cache::has("llm_busy_lock:{$instanceId}");
    }

    /**
     * Acquire busy lock for an instance
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $requestId  Unique request identifier
     * @param  int|null  $ttl  Lock TTL in seconds
     * @return bool True if lock acquired
     */
    public function acquireBusyLock(string $instanceId, string $requestId, ?int $ttl = null): bool
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return false;
        }

        $cacheKey = "llm_busy_lock:{$instanceId}";
        $ttl = $ttl ?? self::BUSY_LOCK_DEFAULT_TTL;

        // Check max concurrent
        $currentLocks = $this->getActiveLockCount($instanceId);
        if ($currentLocks >= $instance->max_concurrent) {
            return false;
        }

        // For single-concurrent instances, use simple lock
        if ($instance->max_concurrent == 1) {
            return Cache::add($cacheKey, [
                'request_id' => $requestId,
                'started_at' => now()->toDateTimeString(),
                'pid' => getmypid(),
            ], $ttl);
        }

        // For multi-concurrent, use slot system
        return $this->acquireSlot($instanceId, $requestId, $ttl);
    }

    /**
     * Release busy lock for an instance
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $requestId  Request identifier that acquired the lock
     */
    public function releaseBusyLock(string $instanceId, string $requestId): bool
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return false;
        }

        $cacheKey = "llm_busy_lock:{$instanceId}";

        if ($instance->max_concurrent == 1) {
            $lockData = Cache::get($cacheKey);
            if ($lockData && ($lockData['request_id'] ?? null) === $requestId) {
                Cache::forget($cacheKey);

                return true;
            }

            return false;
        }

        // Multi-concurrent slot release
        return $this->releaseSlot($instanceId, $requestId);
    }

    /**
     * Get active lock count for multi-concurrent instances
     *
     * @param  string  $instanceId  Instance identifier
     */
    private function getActiveLockCount(string $instanceId): int
    {
        $slotsKey = "llm_slots:{$instanceId}";
        $slots = Cache::get($slotsKey, []);

        // Clean stale slots
        $activeSlots = array_filter($slots, function ($slot) {
            // Check if process is still running
            if (isset($slot['pid']) && ! $this->isProcessRunning($slot['pid'])) {
                return false;
            }

            return true;
        });

        return count($activeSlots);
    }

    /**
     * Acquire a slot for multi-concurrent instances
     */
    private function acquireSlot(string $instanceId, string $requestId, int $ttl): bool
    {
        $slotsKey = "llm_slots:{$instanceId}";

        return Cache::lock("{$slotsKey}_lock", 5)->get(function () use ($slotsKey, $instanceId, $requestId, $ttl) {
            $instance = $this->getInstance($instanceId);
            $slots = Cache::get($slotsKey, []);

            // Clean stale slots
            $slots = array_filter($slots, function ($slot) {
                if (isset($slot['pid']) && ! $this->isProcessRunning($slot['pid'])) {
                    return false;
                }

                return true;
            });

            if (count($slots) >= $instance->max_concurrent) {
                Cache::put($slotsKey, $slots, $ttl * 2);

                return false;
            }

            $slots[$requestId] = [
                'request_id' => $requestId,
                'started_at' => now()->toDateTimeString(),
                'pid' => getmypid(),
            ];

            Cache::put($slotsKey, $slots, $ttl * 2);

            return true;
        });
    }

    /**
     * Release a slot for multi-concurrent instances
     */
    private function releaseSlot(string $instanceId, string $requestId): bool
    {
        $slotsKey = "llm_slots:{$instanceId}";

        return Cache::lock("{$slotsKey}_lock", 5)->get(function () use ($slotsKey, $requestId) {
            $slots = Cache::get($slotsKey, []);
            if (isset($slots[$requestId])) {
                unset($slots[$requestId]);
                Cache::put($slotsKey, $slots, self::BUSY_LOCK_DEFAULT_TTL * 2);

                return true;
            }

            return false;
        });
    }

    /**
     * Check if a process is still running
     */
    private function isProcessRunning(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return Process::timeout(5)->run(['tasklist', '/FI', "PID eq {$pid}"])->successful();
        }

        return posix_kill($pid, 0);
    }

    // ═══════════════════════════════════════════════════════════════════
    // HEALTH CHECKS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Run health check on all instances
     *
     * @return array Health check results
     */
    public function healthCheckAllInstances(): array
    {
        $results = [
            'checked' => 0,
            'healthy' => 0,
            'unhealthy' => 0,
            'recovered' => 0,
            'degraded' => 0,
            'details' => [],
        ];

        $instances = $this->getInstances(false);

        foreach ($instances as $instance) {
            $results['checked']++;

            $checkResult = $this->healthCheckInstance($instance);
            $results['details'][$instance->instance_id] = $checkResult;

            if ($checkResult['healthy']) {
                $results['healthy']++;
                if ($checkResult['recovered']) {
                    $results['recovered']++;
                }
            } else {
                $results['unhealthy']++;
            }

            if ($checkResult['degraded']) {
                $results['degraded']++;
            }
        }

        Log::info('LLMPoolManager: Health check complete', $results);

        return $results;
    }

    /**
     * Health check a single instance
     *
     * @param  object  $instance  Instance data
     * @return array Check result
     */
    public function healthCheckInstance(object $instance): array
    {
        $result = [
            'instance_id' => $instance->instance_id,
            'type' => $instance->instance_type,
            'healthy' => false,
            'recovered' => false,
            'degraded' => false,
            'response_ms' => null,
            'error' => null,
        ];

        $startTime = microtime(true);

        try {
            $healthy = match ($instance->instance_type) {
                'ollama' => $this->healthCheckOllama($instance),
                'claude_cli' => $this->healthCheckClaudeCli($instance),
                'codex_cli' => $this->healthCheckCodexCli($instance),
                default => $this->healthCheckGeneric($instance),
            };

            $result['response_ms'] = (int) ((microtime(true) - $startTime) * 1000);
            $result['healthy'] = $healthy;

            // Check if this is a recovery
            if ($healthy && ! $instance->is_healthy) {
                $result['recovered'] = true;
                Log::info('LLMPoolManager: Instance recovered', [
                    'instance_id' => $instance->instance_id,
                ]);
            }

            // Check for degradation
            if ($healthy && $instance->health_score < config('health_thresholds.llm.degraded_threshold', self::HEALTH_SCORE_DEGRADED_THRESHOLD)) {
                $result['degraded'] = true;
            }

            // Update instance health
            $this->updateInstanceHealth($instance->instance_id, $healthy, $result['response_ms']);

        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $this->updateInstanceHealth($instance->instance_id, false, null);
            Log::warning('LLMPoolManager: Health check failed', [
                'instance_id' => $instance->instance_id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Health check for Ollama instance
     */
    private function healthCheckOllama(object $instance): bool
    {
        if (empty($instance->base_url)) {
            return false;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)->get("{$instance->base_url}/api/tags");

            return $response->successful();
        } catch (Exception $e) {
            Log::debug('LLMPoolManagerService: Ollama health check failed', ['url' => $instance->base_url, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Health check for Claude CLI — verifies binary exists AND OAuth token is valid.
     * Caches token expiry check for 5 minutes to avoid repeated filesystem reads.
     */
    private function healthCheckClaudeCli(object $instance): bool
    {
        // Check if configured claude command exists
        $cliPath = config('services.anthropic.cli_path', 'claude');
        $result = Process::timeout(5)->run(['which', $cliPath]);
        $path = trim($result->output());
        if (! $result->successful() || $path === '' || ! file_exists($path) || ! is_executable($path)) {
            return false;
        }

        // Check OAuth token expiry (cached for 5 minutes). The credentials file
        // can contain a stale expiresAt even when `claude auth status` is valid,
        // so checkClaudeTokenExpiry verifies the CLI before returning expired.
        $tokenStatus = $this->checkClaudeTokenExpiry();
        if ($tokenStatus === 'expired') {
            Log::warning('LLMPoolManagerService: Claude CLI OAuth token is expired — marking unhealthy', [
                'action' => 'Run: claude login on prod to refresh',
            ]);

            return false;
        }
        if ($tokenStatus === 'expiring_soon') {
            Log::warning('LLMPoolManagerService: Claude CLI OAuth token expires within 24h', [
                'action' => 'Run: claude login on prod to refresh before expiry',
            ]);
            // Still healthy but warn — token works until actual expiry
        }

        return true;
    }

    /**
     * Health check for Codex CLI. Authentication is intentionally verified by
     * a tiny noninteractive version/catalog command, not by reading private
     * Codex state from disk.
     */
    private function healthCheckCodexCli(object $instance): bool
    {
        $config = json_decode($instance->config ?? '{}', true) ?: [];
        $cliPath = $config['executable'] ?? 'codex';
        $result = Process::timeout(5)->run(['which', $cliPath]);
        $path = trim($result->output());
        if (! $result->successful() || $path === '' || ! file_exists($path) || ! is_executable($path)) {
            return false;
        }

        $version = Process::timeout(10)->run([$cliPath, '--version']);

        return $version->successful() && trim($version->output().$version->errorOutput()) !== '';
    }

    public function checkClaudeTokenExpiry(): string
    {
        return Cache::remember('claude_token_expiry_status', 300, function () {
            $status = $this->readClaudeCredentialExpiryStatus();

            if ($status !== 'expired') {
                return $status;
            }

            if ($this->claudeAuthStatusLoggedIn()) {
                Log::info('LLMPoolManagerService: Claude credentials expiry is stale but CLI auth status is logged in');

                return 'valid';
            }

            return 'expired';
        });
    }

    /**
     * Check Claude CLI OAuth token expiry from credentials file.
     * Returns: 'valid', 'expiring_soon' (<24h), 'expired', or 'unknown' (no file/parse error).
     */
    private function readClaudeCredentialExpiryStatus(): string
    {
        $home = $this->resolveRuntimeEnvValue('HOME') ?: $this->resolveCurrentUserHome();
        if (! $home) {
            Log::debug('LLMPoolManagerService: HOME not available for Claude credentials check');

            return 'unknown';
        }

        $credPath = rtrim($home, '/').'/.claude/.credentials.json';

        if (! file_exists($credPath)) {
            Log::debug('LLMPoolManagerService: Claude credentials file not found', ['path' => $credPath]);

            return 'unknown';
        }

        try {
            $creds = json_decode(file_get_contents($credPath), true);
            $expiresAt = $creds['claudeAiOauth']['expiresAt'] ?? null;

            if ($expiresAt === null) {
                return 'unknown';
            }

            // expiresAt is in milliseconds.
            $expiresAtSeconds = (int) ($expiresAt / 1000);
            $now = time();

            if ($now >= $expiresAtSeconds) {
                return 'expired';
            }

            // Warn if expiring within 24 hours.
            if (($expiresAtSeconds - $now) < 86400) {
                return 'expiring_soon';
            }

            return 'valid';
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManagerService: Failed to parse Claude credentials', ['error' => $e->getMessage()]);

            return 'unknown';
        }
    }

    private function claudeAuthStatusLoggedIn(): bool
    {
        $cliPath = config('services.anthropic.cli_path', 'claude');
        $result = $this->runClaudeAuthStatus($cliPath);
        $data = $this->extractClaudeAuthStatusData($result['output'] ?? '');

        if (($data['loggedIn'] ?? false) === true) {
            return true;
        }

        if (($result['successful'] ?? false) === false) {
            Log::debug('LLMPoolManagerService: Claude auth status check failed', [
                'output' => mb_substr((string) ($result['output'] ?? ''), 0, 500),
            ]);
        }

        return false;
    }

    /**
     * Health-only Claude CLI auth probe. Inference remains inside AIService/AIRouter.
     *
     * @return array{successful: bool, output: string}
     */
    protected function runClaudeAuthStatus(string $cliPath): array
    {
        $pending = Process::timeout(10);
        $env = $this->buildClaudeCliEnv();
        if ($env !== null) {
            $pending = $pending->env($env);
        }

        $result = $pending->run([$cliPath, 'auth', 'status']);

        return [
            'successful' => $result->successful(),
            'output' => trim($result->output()."\n".$result->errorOutput()),
        ];
    }

    private function extractClaudeAuthStatusData(string $output): ?array
    {
        $data = json_decode(trim($output), true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{[^}]*"loggedIn"[^}]*\}/', $output, $jsonMatch)) {
            $data = json_decode($jsonMatch[0], true);

            return is_array($data) ? $data : null;
        }

        return null;
    }

    private function buildClaudeCliEnv(): ?array
    {
        $env = [];
        foreach (['HOME', 'PATH', 'USER', 'LOGNAME', 'SHELL', 'LANG', 'TERM', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'PWD'] as $key) {
            $value = $this->resolveRuntimeEnvValue($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }

        $home = $env['HOME'] ?? $this->resolveCurrentUserHome();
        if ($home !== null) {
            $env['HOME'] = $home;
        }

        $token = config('services.anthropic.cli_oauth_token');
        if ($token) {
            $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        }

        return $env !== [] ? $env : null;
    }

    /**
     * Health check for external API providers (Groq, SambaNova, Cerebras, OpenRouter, etc.)
     * Sends a minimal chat completion request to verify the API is responsive.
     * Falls back to a simple GET if no API key is configured.
     */
    private function healthCheckGeneric(object $instance): bool
    {
        if (empty($instance->base_url)) {
            return true; // No URL to check
        }

        // For external APIs, a GET to the base URL won't work (returns 401/403).
        // Send a minimal completion request instead.
        $apiKey = $instance->api_key ?: $this->resolveRuntimeEnvValue($instance->api_key_env ?? null);
        if ($apiKey) {
            return $this->healthCheckExternalApi($instance, $apiKey);
        }

        // No API key — fall back to simple GET
        try {
            $response = Http::connectTimeout(5)->timeout(10)->get($instance->base_url);

            return $response->successful();
        } catch (Exception $e) {
            Log::debug('LLMPoolManagerService: generic health check failed', ['url' => $instance->base_url, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Health check external API via minimal chat completion.
     * Treats 429 (rate-limited) as healthy — provider is responsive, just throttled.
     */
    private function healthCheckExternalApi(object $instance, string $apiKey): bool
    {
        try {
            $config = json_decode($instance->config ?? '{}', true) ?: [];
            $models = json_decode($instance->supported_models ?? '[]', true) ?: [];
            $model = $config['default_model'] ?? ($models[0] ?? null);

            if (! $model) {
                return false;
            }

            $headers = ['Authorization' => "Bearer {$apiKey}"];
            foreach (($config['extra_headers'] ?? []) as $key => $value) {
                $headers[$key] = $value;
            }

            $response = Http::connectTimeout(5)->timeout(15)
                ->withHeaders($headers)
                ->post(rtrim($instance->base_url, '/').'/chat/completions', [
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => 'Reply with OK']],
                    'max_tokens' => 5,
                    'temperature' => 0,
                ]);

            // 200 = healthy, 429 = rate-limited but alive
            return $response->successful() || $response->status() === 429;
        } catch (Exception $e) {
            Log::debug('LLMPoolManagerService: external API health check failed', ['instance' => $instance->instance_id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Update instance health status
     */
    private function updateInstanceHealth(string $instanceId, bool $healthy, ?int $responseMs): void
    {
        $healthDelta = $healthy ? 5 : -15;

        DB::update(
            "UPDATE llm_instances SET
                is_healthy = ?,
                health_score = LEAST(100, GREATEST(0, health_score + ?)),
                avg_response_ms = CASE
                    WHEN ? IS NOT NULL THEN COALESCE((avg_response_ms * 0.9) + (? * 0.1), ?)
                    ELSE avg_response_ms
                END,
                last_health_check = NOW(),
                circuit_state = CASE
                    WHEN ? = 1 AND circuit_state = 'open' AND circuit_retry_at <= NOW() THEN 'half_open'
                    ELSE circuit_state
                END,
                updated_at = NOW()
            WHERE instance_id = ?",
            [$healthy, $healthDelta, $responseMs, $responseMs, $responseMs, $healthy, $instanceId]
        );

        $this->clearInstanceCache();
    }

    // ═══════════════════════════════════════════════════════════════════
    // STATISTICS & MONITORING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 3b P03a — describe local Ollama availability in operator-visible terms.
     *
     * Returns one of:
     *   - all_locals_up       (both local Ollama roles healthy + circuit closed)
     *   - primary_down        (secondary healthy; primary not)
     *   - secondary_down      (primary healthy; secondary not)
     *   - all_locals_down     (neither healthy — fail-closed condition)
     *
     * The degraded states are informational: routing still works via the
     * healthy host because `selectInstance()` already filters on
     * is_healthy + circuit_state. This method exists so operator tooling
     * (daily report, ops:health-gate, future ollama:offline-preflight) can
     * surface the capability loss explicitly.
     */
    public function describeLocalAvailability(): array
    {
        $rows = DB::select(
            "SELECT instance_id, host_affinity, is_healthy, circuit_state
             FROM llm_instances
             WHERE instance_type = 'ollama' AND is_active = 1 AND routability = 'allowed'"
        );

        $primaryUp = false;
        $secondaryUp = false;
        $details = [];
        $localIndex = 0;

        foreach ($rows as $row) {
            $healthy = ((int) ($row->is_healthy ?? 0)) === 1
                && ($row->circuit_state ?? 'closed') !== 'open';
            $details[$row->instance_id] = [
                'healthy' => $healthy,
                'circuit_state' => $row->circuit_state ?? 'closed',
                'host_affinity' => $row->host_affinity,
            ];

            $role = $this->localAvailabilityRole($row->host_affinity ?? null, $row->instance_id ?? null, $localIndex);
            $localIndex++;

            if ($role === 'primary') {
                $primaryUp = $primaryUp || $healthy;
            }
            if ($role === 'secondary') {
                $secondaryUp = $secondaryUp || $healthy;
            }
        }

        $state = match (true) {
            $primaryUp && $secondaryUp => 'all_locals_up',
            $primaryUp && ! $secondaryUp => 'secondary_down',
            ! $primaryUp && $secondaryUp => 'primary_down',
            default => 'all_locals_down',
        };

        return [
            'state' => $state,
            'primary_up' => $primaryUp,
            'secondary_up' => $secondaryUp,
            'details' => $details,
        ];
    }

    private function localAvailabilityRole(mixed $hostAffinity, mixed $instanceId, int $fallbackIndex): ?string
    {
        $affinity = strtolower((string) ($hostAffinity ?? ''));
        $id = strtolower((string) ($instanceId ?? ''));

        if (str_contains($affinity, 'primary') || str_contains($id, 'primary')) {
            return 'primary';
        }

        if (str_contains($affinity, 'secondary') || str_contains($id, 'secondary')) {
            return 'secondary';
        }

        if (str_contains($affinity, 'local')) {
            return match ($fallbackIndex) {
                0 => 'primary',
                1 => 'secondary',
                default => null,
            };
        }

        return null;
    }

    /**
     * Get pool statistics
     *
     * @return array Pool stats
     */
    public function getPoolStats(): array
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total_instances,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_healthy = 1 THEN 1 ELSE 0 END) as healthy,
                SUM(CASE WHEN circuit_state = 'open' THEN 1 ELSE 0 END) as circuits_open,
                SUM(CASE WHEN circuit_state = 'half_open' THEN 1 ELSE 0 END) as circuits_half_open,
                AVG(health_score) as avg_health_score,
                AVG(avg_response_ms) as avg_response_ms,
                SUM(total_requests) as total_requests,
                SUM(total_failures) as total_failures
            FROM llm_instances"
        );

        $byType = DB::select(
            'SELECT
                instance_type,
                COUNT(*) as count,
                SUM(CASE WHEN is_healthy = 1 THEN 1 ELSE 0 END) as healthy
            FROM llm_instances
            WHERE is_active = 1
            GROUP BY instance_type'
        );

        return [
            'total_instances' => (int) $stats->total_instances,
            'active' => (int) $stats->active,
            'healthy' => (int) $stats->healthy,
            'circuits_open' => (int) $stats->circuits_open,
            'circuits_half_open' => (int) $stats->circuits_half_open,
            'avg_health_score' => $stats->avg_health_score ? round($stats->avg_health_score, 1) : null,
            'avg_response_ms' => $stats->avg_response_ms ? round($stats->avg_response_ms, 0) : null,
            'total_requests' => (int) $stats->total_requests,
            'total_failures' => (int) $stats->total_failures,
            'overall_success_rate' => $stats->total_requests > 0
                ? round((($stats->total_requests - $stats->total_failures) / $stats->total_requests) * 100, 2)
                : null,
            'by_type' => $byType,
        ];
    }

    /**
     * Get instance details for monitoring
     *
     * @return array All instances with full details
     */
    public function getInstancesForMonitoring(): array
    {
        $instances = DB::select(
            'SELECT
                i.*,
                (SELECT COUNT(*) FROM ollama_models m WHERE m.instance_id = i.id AND m.is_available = 1) as model_count
            FROM llm_instances i
            ORDER BY i.priority ASC, i.health_score DESC'
        );

        foreach ($instances as &$instance) {
            $instance->is_busy = $this->isInstanceBusy($instance->instance_id);
            $instance->active_slots = $this->getActiveLockCount($instance->instance_id);
            $instance->capabilities = json_decode($instance->capabilities, true);
            $instance->config = json_decode($instance->config, true);
        }

        return $instances;
    }

    // ═══════════════════════════════════════════════════════════════════
    // HEALTH PROBES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Probe all external providers with open/unhealthy circuits and attempt recovery.
     * For Ollama: hits /api/tags. For external APIs: sends a lightweight test request.
     * Should be called periodically (e.g., every 30 min via scheduled job).
     *
     * @return array Summary of probe results
     */
    public function probeUnhealthyProviders(): array
    {
        $results = [];

        $unhealthy = DB::select(
            "SELECT instance_id, instance_type, base_url, api_key, api_key_env, config,
                    circuit_state, is_healthy, health_score, supported_models, consecutive_failures
             FROM llm_instances
             WHERE is_active = 1
               AND (circuit_state = 'open' OR is_healthy = 0)
             ORDER BY priority ASC"
        );

        foreach ($unhealthy as $instance) {
            $providerId = $instance->instance_id;

            try {
                if ($instance->instance_type === 'ollama') {
                    $success = $this->probeOllama($instance);
                } elseif ($instance->instance_type === 'claude_cli') {
                    $success = $this->healthCheckClaudeCli($instance);
                } else {
                    $success = $this->probeExternalApi($instance);
                }

                if ($success) {
                    $this->setCircuitState($providerId, 'closed');
                    DB::update(
                        'UPDATE llm_instances SET
                            is_healthy = 1,
                            health_score = GREATEST(health_score, ?),
                            consecutive_failures = 0,
                            last_health_check = NOW(),
                            updated_at = NOW()
                        WHERE instance_id = ?',
                        [config('health_thresholds.llm.degraded_threshold', self::HEALTH_SCORE_DEGRADED_THRESHOLD), $providerId]
                    );
                    // Also clear cache-based circuit breaker
                    Cache::forget("circuit_breaker_{$providerId}");
                    $results[$providerId] = 'recovered';
                    Log::info('LLMPoolManager: Provider recovered via health probe', [
                        'instance_id' => $providerId,
                    ]);
                } else {
                    DB::update(
                        'UPDATE llm_instances SET last_health_check = NOW(), updated_at = NOW()
                         WHERE instance_id = ?',
                        [$providerId]
                    );
                    $results[$providerId] = 'still_unhealthy';
                }

            } catch (\Throwable $e) {
                $results[$providerId] = 'probe_error: '.$e->getMessage();
                Log::debug("LLMPoolManager: Health probe failed for {$providerId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearInstanceCache();

        return $results;
    }

    /**
     * Probe an Ollama instance by hitting /api/tags
     */
    private function probeOllama(object $instance): bool
    {
        $response = Http::connectTimeout(5)->timeout(10)->get(rtrim($instance->base_url, '/').'/api/tags');

        return $response->successful();
    }

    /**
     * Probe an external API by sending a minimal completion request.
     * Returns true if the API responds (200 or 429 rate-limited = alive).
     */
    private function probeExternalApi(object $instance): bool
    {
        $apiKey = $instance->api_key ?: $this->resolveRuntimeEnvValue($instance->api_key_env ?? null);
        if (! $apiKey) {
            return false; // No API key configured — can't probe
        }

        $config = json_decode($instance->config ?? '{}', true) ?: [];
        $models = json_decode($instance->supported_models ?? '[]', true) ?: [];
        $model = $config['default_model'] ?? ($models[0] ?? null);

        if (! $model) {
            return false;
        }

        $headers = ['Authorization' => "Bearer {$apiKey}"];
        foreach (($config['extra_headers'] ?? []) as $key => $value) {
            $headers[$key] = $value;
        }

        $response = Http::connectTimeout(5)->timeout(15)
            ->withHeaders($headers)
            ->post(rtrim($instance->base_url, '/').'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Reply with OK']],
                'max_tokens' => 5,
                'temperature' => 0,
            ]);

        // 200 = healthy, 429 = rate-limited but alive, both count as recovered
        return $response->successful() || $response->status() === 429;
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Clear instance cache
     */
    private function clearInstanceCache(): void
    {
        Cache::forget('llm_instances_all');
        Cache::forget('llm_instances_healthy');
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

    private function resolveCurrentUserHome(): ?string
    {
        if (! function_exists('posix_getpwuid') || ! function_exists('posix_geteuid')) {
            return null;
        }

        $user = posix_getpwuid(posix_geteuid());
        $home = is_array($user) ? $user['dir'] : null;

        return is_string($home) && $home !== '' ? $home : null;
    }
}
