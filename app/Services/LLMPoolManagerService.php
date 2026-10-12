<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use App\Support\AI\PoolExecutionReceipt;
use App\Support\RuntimeBoolean;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * LLMPoolManagerService - Dynamic Multi-Provider LLM Routing
 *
 * Manages a pool of LLM instances with:
 * - Dynamic routing based on health, load, and capabilities
 * - Per-instance circuit breakers (fault isolation)
 * - Per-instance or shared admission-group busy locks (prevent resource contention)
 * - Health scoring with automatic recovery
 * - Self-healing: unhealthy instances automatically recover
 * - Cost-aware routing (future)
 *
 * Follows existing AIService patterns:
 * - Circuit breaker: table-owned failure threshold, cooldown, and half-open test
 * - Busy locks: Per-instance with configurable TTL
 * - Health checks: Nightly via OpsMaintenanceJob
 *
 * @see AIService For individual provider implementations
 */
class LLMPoolManagerService
{
    public const CUSTODY_POLICY_KEY = 'pool_execution_custody';

    /** Exact service invocation ownership; request IDs or PIDs alone confer no release authority. */
    private array $poolExecutions = [];

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

    private LlmCircuitBreakerPolicyService $circuitBreakerPolicy;

    private LlmPoolProbePolicyService $probePolicy;

    public function __construct(
        ?LlmCircuitBreakerPolicyService $circuitBreakerPolicy = null,
        ?LlmPoolProbePolicyService $probePolicy = null
    ) {
        $this->circuitBreakerPolicy = $circuitBreakerPolicy ?? app(LlmCircuitBreakerPolicyService::class);
        $this->probePolicy = $probePolicy ?? app(LlmPoolProbePolicyService::class);
    }

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
     *                               'model_capability_groups' => [['tool_calling', 'tool_use', 'tools']],
     *                               'model' => 'llama3.1:8b-instruct-q5_K_M',
     *                               'prefer_instance' => 'ollama_primary',
     *                               'explicit_instance_selection' => true,
     *                               'exclude_instances' => ['ollama_secondary'],
     *                               'urgency' => 'normal|high|low',
     *                               ]
     * @return array|null Instance data with routing metadata, or null if none available
     */
    public function selectInstance(array $requirements = []): ?array
    {
        $capabilities = $requirements['capabilities'] ?? ['text'];
        $modelCapabilityGroups = is_array($requirements['model_capability_groups'] ?? null)
            ? $requirements['model_capability_groups']
            : [];
        $model = $requirements['model'] ?? null;
        $preferInstance = $requirements['prefer_instance'] ?? null;
        $explicitInstanceSelection = $this->truthyDbValue($requirements['explicit_instance_selection'] ?? false);
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

        // An exact operator-selected instance has its own model and hardware
        // admission below. A global model-name GPU target can belong to a
        // different host carrying the same model (for example EmbeddingGemma).
        $authoritativeGpuTarget = $model !== null && ! ($explicitInstanceSelection && is_string($preferInstance) && $preferInstance !== '')
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

            $instanceConfig = $this->decodeInstanceConfig($instance);
            if (! (new LlmRoutePolicyService)->allowsInstance($instanceConfig, $instance->instance_id, $explicitInstanceSelection, $preferInstance)) {
                Log::debug('LLMPoolManager: explicit-only instance skipped without exact preference', [
                    'instance_id' => $instance->instance_id ?? null,
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

            if (($instance->quarantine_status ?? 'none') === 'quarantined') {
                Log::debug('LLMPoolManager: instance skipped (quarantined)', [
                    'instance_id' => $instance->instance_id ?? null,
                    'quarantine_source' => $instance->quarantine_source ?? null,
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

            // Exclude instances whose table-owned route policy does not allow
            // the requested role. A provider that requires a model role must
            // also reject unscoped requests; otherwise callers omitting `role`
            // could bypass the BitNet/Jetson route policy entirely.
            $config = $this->decodeInstanceConfig($instance);
            if ($role === null && ! $this->providerConfigAllowsUnscopedRequest($config)) {
                continue;
            }

            // Ollama additionally must advertise an explicit role model so
            // fallback iteration cannot silently widen from quality/standard
            // into a light model.
            $roleModel = null;
            if ($role !== null) {
                if (! $this->providerConfigAllowsModelRole($config, $role)) {
                    continue;
                }

                if ($instance->instance_type === 'ollama') {
                    $roleModel = $this->resolveRoleModel($instance, $role);
                    if ($roleModel === null) {
                        continue;
                    }
                }
            }

            if ($instance->instance_type === 'ollama') {
                $admissionModel = (string) ($model ?: $roleModel ?: '');
                if (
                    $modelCapabilityGroups !== []
                    && ! $this->modelMatchesCapabilityGroups($instance->id, $admissionModel, $modelCapabilityGroups)
                ) {
                    continue;
                }

                $admission = app(ModelHardwareAdmissionService::class)->assess(
                    $instance,
                    $admissionModel,
                    $role
                );
                if (! (bool) ($admission['route_allowed'] ?? false)) {
                    Log::debug('LLMPoolManager: instance skipped by model hardware admission', [
                        'instance_id' => $instance->instance_id ?? null,
                        'model' => $admissionModel !== '' ? $admissionModel : null,
                        'role' => $role,
                        'decision' => $admission['decision'] ?? 'unknown',
                    ]);

                    continue;
                }
            }

            // Calculate routing score (role-aware when a role is supplied)
            $score = $this->calculateRoutingScore($instance, $preferInstance, $urgency, $role);

            // Check if busy (affects score but doesn't exclude)
            $isBusy = in_array($instance->instance_type ?? null, ['ollama', 'local_llm'], true)
                && $this->isInstanceBusy((string) $instance->instance_id);

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
     * Require each capability group to have at least one exact model-level match.
     * Instance capabilities describe the host; they do not prove that the role
     * model can emit the native protocol needed by this request.
     *
     * @param  array<int,array<int,string>>  $groups
     */
    private function modelMatchesCapabilityGroups(int $instanceId, string $model, array $groups): bool
    {
        if ($model === '') {
            return false;
        }

        $row = DB::selectOne(
            'SELECT capabilities FROM ollama_models
             WHERE instance_id = ? AND model_name = ? AND is_available = 1
             LIMIT 1',
            [$instanceId, $model]
        );
        $capabilities = json_decode((string) ($row->capabilities ?? '[]'), true);
        if (! is_array($capabilities)) {
            return false;
        }

        return (new LlmRoutePolicyService)->matchesCapabilities($capabilities, $groups);
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

        if ($role !== null) {
            $score += $this->providerRoleBias($instance, $role);
        }

        // Role fitness bonus (0-20 points): let each instance advertise its strength
        // through config.models.{role}. For reasoning-heavy roles a bigger model wins;
        // for latency-sensitive roles a smaller model wins. This is what lets the 4070
        // win `coding`/`quality` work on score without a hardcoded preference list.
        if ($role !== null && $instance->instance_type === 'ollama') {
            $score += $this->scoreRoleFit($instance, $role);
            $score -= $this->recentRoleAttemptPenalty($instance, $role);
        }

        return min(100, max(0, $score));
    }

    private function providerRoleBias(object $instance, string $role): float
    {
        $policy = $this->getProviderRoleBiasPolicy();
        if (! (bool) ($policy['enabled'] ?? false)) {
            return 0.0;
        }

        $instanceId = trim((string) ($instance->instance_id ?? ''));
        if ($instanceId === '') {
            return 0.0;
        }

        $roles = is_array($policy['roles'] ?? null) ? $policy['roles'] : [];
        $rolePolicy = is_array($roles[$role] ?? null) ? $roles[$role] : [];
        $bias = $rolePolicy[$instanceId] ?? null;
        if (! is_numeric($bias)) {
            return 0.0;
        }

        $maxAbsBias = max(0.0, (float) ($policy['max_abs_bias'] ?? 20.0));

        return max(-$maxAbsBias, min($maxAbsBias, (float) $bias));
    }

    /**
     * @return array<string,mixed>
     */
    private function getProviderRoleBiasPolicy(): array
    {
        return Cache::remember('llm_provider_role_bias_policy', 60, function (): array {
            try {
                $row = DB::selectOne(
                    "SELECT config_value
                     FROM system_configs
                     WHERE section = 'llm'
                       AND config_key = 'provider_role_bias_policy'
                     LIMIT 1"
                );

                $decoded = json_decode((string) ($row->config_value ?? ''), true);
                if (is_array($decoded) && $decoded !== []) {
                    return $decoded;
                }
            } catch (\Throwable $e) {
                Log::debug('LLMPoolManager: provider role bias policy config unavailable', [
                    'error' => $e->getMessage(),
                ]);
            }

            return ['enabled' => false, 'roles' => []];
        });
    }

    /**
     * Penalize an otherwise healthy host when recent telemetry shows that this
     * specific caller/role is failing there. This catches cases where unrelated
     * successful direct calls keep the provider circuit closed while agent-fast
     * work is timing out repeatedly.
     */
    private function recentRoleAttemptPenalty(object $instance, string $role): float
    {
        $instanceId = trim((string) ($instance->instance_id ?? ''));
        if ($instanceId === '') {
            return 0.0;
        }

        $requestKind = match ($role) {
            'embedding' => 'embedding',
            'vision' => 'vision',
            default => 'chat',
        };

        $cacheKey = 'llm_pool:recent_role_attempt_penalty:'.sha1($instanceId.'|'.$role.'|'.$requestKind);

        return (float) Cache::remember($cacheKey, 60, function () use ($instanceId, $role, $requestKind): float {
            try {
                $row = DB::selectOne(
                    'SELECT COUNT(*) AS attempts,
                            COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) AS failures
                     FROM llm_model_request_logs
                     WHERE instance_id = ?
                       AND model_role = ?
                       AND caller = ?
                       AND request_kind = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)',
                    [$instanceId, $role, $role.'_agent', $requestKind]
                );
            } catch (\Throwable $e) {
                Log::debug('LLMPoolManager: recent role telemetry lookup failed', [
                    'instance_id' => $instanceId,
                    'role' => $role,
                    'error' => $e->getMessage(),
                ]);

                return 0.0;
            }

            $attempts = (int) ($row->attempts ?? 0);
            $failures = (int) ($row->failures ?? 0);
            if ($attempts < 5 || $failures === 0) {
                return 0.0;
            }

            $failureRate = $failures / max(1, $attempts);
            if ($failures >= 3 && $failureRate >= 0.25) {
                return 30.0;
            }
            if ($failures >= 3 && $failureRate >= 0.10) {
                return 15.0;
            }
            if ($failureRate >= 0.02) {
                return 8.0;
            }

            return 0.0;
        });
    }

    /**
     * Resolve the role-specific model for an instance from its config.models map.
     *
     * @return string|null Model name or null when the role is not advertised
     */
    private function resolveRoleModel(object $instance, string $role): ?string
    {
        $config = $this->decodeInstanceConfig($instance);

        if (! $this->providerConfigAllowsModelRole($config, $role)) {
            return null;
        }

        $models = is_array($config['models'] ?? null) ? $config['models'] : [];
        $model = $models[$role] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeInstanceConfig(object $instance): array
    {
        try {
            $config = json_decode($instance->config ?? '{}', true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     */
    private function providerConfigAllowsModelRole(array $providerConfig, string $role): bool
    {
        return (new LlmRoutePolicyService)->allowsRole($providerConfig, $role);
    }

    /**
     * @param  array<string,mixed>  $providerConfig
     */
    private function providerConfigAllowsUnscopedRequest(array $providerConfig): bool
    {
        return (new LlmRoutePolicyService)->allowsUnscoped($providerConfig);
    }

    private function truthyDbValue(mixed $value): bool
    {
        return RuntimeBoolean::isTrue($value);
    }

    /**
     * Score how well an instance's advertised model fits the requested role.
     * The scoring policy is table-owned so model/hardware evidence can evolve
     * without changing framework code. Identical model tags receive identical
     * role-fit credit across hosts; per-host telemetry decides ordering after
     * that.
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

        $policy = $this->getRoleFitPolicy();
        $rolePolicies = is_array($policy['roles'] ?? null) ? $policy['roles'] : [];
        $rolePolicy = is_array($rolePolicies[$role] ?? null) ? $rolePolicies[$role] : [];

        if ($rolePolicy === []) {
            $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];

            return min(
                (float) ($default['max_bonus'] ?? 10.0),
                max(0.0, (float) ($default['bonus'] ?? 10.0))
            );
        }

        $direction = (string) ($rolePolicy['direction'] ?? 'larger');
        $maxBonus = max(0.0, (float) ($rolePolicy['max_bonus'] ?? 20.0));
        $scale = (float) ($rolePolicy['scale'] ?? 1.0);
        $baseBonus = (float) ($rolePolicy['base_bonus'] ?? 0.0);

        if ($direction === 'larger') {
            $minParams = (float) ($rolePolicy['min_params_b'] ?? 0.0);

            return min($maxBonus, max(0.0, $baseBonus + (($params - $minParams) * $scale)));
        }

        if ($direction === 'smaller') {
            $targetParams = (float) ($rolePolicy['target_params_b'] ?? 0.0);

            return min($maxBonus, max(0.0, $baseBonus - (max(0.0, $params - $targetParams) * $scale)));
        }

        if ($direction === 'fixed') {
            return min($maxBonus, max(0.0, (float) ($rolePolicy['bonus'] ?? $baseBonus)));
        }

        return 0.0;
    }

    /**
     * @return array<string,mixed>
     */
    private function getRoleFitPolicy(): array
    {
        return Cache::remember('llm_role_fit_policy', 60, function (): array {
            $fallback = [
                'roles' => [
                    'quality' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                    'coding' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                    'standard' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                    'vision' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                    'uncensored' => ['direction' => 'larger', 'min_params_b' => 2, 'scale' => 1.5, 'max_bonus' => 20],
                    'fast' => ['direction' => 'smaller', 'target_params_b' => 2, 'base_bonus' => 20, 'scale' => 2.0, 'max_bonus' => 20],
                    'embedding' => ['direction' => 'smaller', 'target_params_b' => 2, 'base_bonus' => 20, 'scale' => 2.0, 'max_bonus' => 20],
                ],
                'default' => ['bonus' => 10, 'max_bonus' => 10],
            ];

            try {
                $row = DB::selectOne(
                    "SELECT config_value
                     FROM system_configs
                     WHERE section = 'llm'
                       AND config_key = 'role_fit_policy'
                     LIMIT 1"
                );

                $decoded = json_decode((string) ($row->config_value ?? ''), true);
                if (is_array($decoded) && $decoded !== []) {
                    return $decoded;
                }
            } catch (\Throwable $e) {
                Log::debug('LLMPoolManager: role-fit policy config unavailable; using bootstrap defaults', [
                    'error' => $e->getMessage(),
                ]);
            }

            return $fallback;
        });
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
            return app(AIService::class)->getActiveProfile();
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: active-profile lookup failed; treating as no profile', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 3b P02d — defer to OfflinePolicyService for provider-class enforcement.
     * Fails closed when policy resolution/evaluation is unavailable. Provider
     * gates should never widen because the policy service had a transient error.
     */
    private function offlinePolicyAllowsProvider(object $instance): bool
    {
        try {
            $policy = app(OfflinePolicyService::class);
        } catch (\Throwable $e) {
            Log::warning('LLMPoolManager: offline policy unavailable; blocking provider', [
                'instance_id' => $instance->instance_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        try {
            $decision = $policy->evaluateProvider($instance);
        } catch (\Throwable $e) {
            Log::warning('LLMPoolManager: offline policy evaluation failed; blocking provider', [
                'instance_id' => $instance->instance_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
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
    public function recordSuccess(string $instanceId, int $responseTimeMs, array $metadata = []): void
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
                success_rate = ((total_requests - total_failures + 1) * 100.0) / (total_requests + 1),
                total_requests = total_requests + 1,
                consecutive_failures = 0,
                avg_response_ms = COALESCE(
                    (avg_response_ms * 0.9) + (? * 0.1),
                    ?
                ),
                last_success_at = NOW(),
                health_score = LEAST(100, health_score + 5),
                is_healthy = CASE WHEN health_score >= ? THEN 1 ELSE is_healthy END,
                updated_at = NOW()
            WHERE instance_id = ?',
            [$responseTimeMs, $responseTimeMs, config('health_thresholds.llm.unhealthy_threshold', self::HEALTH_SCORE_UNHEALTHY_THRESHOLD), $instanceId]
        );

        $this->recordModelUsage($instanceId, $metadata, true, $responseTimeMs);

        $this->clearInstanceCache();
    }

    /**
     * Record a failed request (for circuit breaker)
     *
     * @param  string  $instanceId  Instance identifier
     * @param  string  $error  Error message
     */
    public function recordFailure(string $instanceId, string $error, ?int $retryAfterSeconds = null, array $metadata = []): void
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance || ! $this->isInstanceActive($instance)) {
            return;
        }

        $consecutiveFailures = $instance->consecutive_failures + 1;

        // Update metrics
        DB::update(
            'UPDATE llm_instances SET
                success_rate = ((total_requests - total_failures) * 100.0) / (total_requests + 1),
                total_requests = total_requests + 1,
                total_failures = total_failures + 1,
                consecutive_failures = ?,
                last_failure_at = NOW(),
                health_score = GREATEST(0, health_score - 10),  -- 2:1 ratio now (was 10:1)
                is_healthy = CASE WHEN health_score <= ? THEN 0 ELSE is_healthy END,
                updated_at = NOW()
            WHERE instance_id = ?',
            [$consecutiveFailures, config('health_thresholds.llm.unhealthy_threshold', self::HEALTH_SCORE_UNHEALTHY_THRESHOLD), $instanceId]
        );

        // Check if circuit should open
        if ($consecutiveFailures >= $this->circuitBreakerPolicy->failureThreshold()) {
            $this->openCircuit($instanceId, $error, $retryAfterSeconds);
        }

        // If in half-open, go back to open
        if ($instance->circuit_state === 'half_open') {
            $this->openCircuit($instanceId, "Half-open test failed: {$error}", $retryAfterSeconds);
        }

        $metadata['error'] ??= $error;
        $this->recordModelUsage($instanceId, $metadata, false, null);

        $this->clearInstanceCache();
    }

    /**
     * Persist an authoritative model attempt without changing provider health or circuit state.
     */
    public function recordModelRequestAttempt(
        string $instanceId,
        bool $success,
        ?int $responseTimeMs,
        array $metadata = []
    ): void {
        $instance = $this->getInstance($instanceId);
        if (! $instance || ! $this->isInstanceActive($instance)) {
            return;
        }

        $this->recordModelUsage($instanceId, $metadata, $success, $responseTimeMs);
    }

    private function recordModelUsage(string $instanceId, array $metadata, bool $success, ?int $responseTimeMs): void
    {
        $modelName = trim((string) ($metadata['model'] ?? $metadata['model_name'] ?? ''));
        if ($modelName === '') {
            return;
        }

        $tokensPerSecond = $metadata['tokens_per_second'] ?? null;
        $tokensPerSecond = is_numeric($tokensPerSecond) ? (float) $tokensPerSecond : null;
        $failureIncrement = $success ? 0 : 1;

        $this->recordModelRequestLog($instanceId, $metadata, $success, $responseTimeMs, $tokensPerSecond);

        try {
            DB::update(
                'UPDATE ollama_models om
                 JOIN llm_instances li ON li.id = om.instance_id
                 SET
                    om.avg_response_time_ms = CASE
                        WHEN ? IS NOT NULL THEN
                            CASE
                                WHEN om.total_requests > 0 AND om.avg_response_time_ms IS NOT NULL
                                    THEN ((om.avg_response_time_ms * om.total_requests) + ?) / (om.total_requests + 1)
                                ELSE ?
                            END
                        ELSE om.avg_response_time_ms
                    END,
                    om.avg_tokens_per_second = CASE
                        WHEN ? IS NOT NULL THEN
                            CASE
                                WHEN om.total_requests > 0 AND om.avg_tokens_per_second IS NOT NULL
                                    THEN ((om.avg_tokens_per_second * om.total_requests) + ?) / (om.total_requests + 1)
                                ELSE ?
                            END
                        ELSE om.avg_tokens_per_second
                    END,
                    om.success_rate = (((om.total_requests + 1) - (om.total_failures + ?)) * 100.0) / (om.total_requests + 1),
                    om.total_failures = om.total_failures + ?,
                    om.total_requests = om.total_requests + 1,
                    om.updated_at = NOW()
                 WHERE li.instance_id = ?
                   AND om.model_name = ?',
                [
                    $responseTimeMs,
                    $responseTimeMs,
                    $responseTimeMs,
                    $tokensPerSecond,
                    $tokensPerSecond,
                    $tokensPerSecond,
                    $failureIncrement,
                    $failureIncrement,
                    $instanceId,
                    $modelName,
                ]
            );
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: model usage telemetry update failed', [
                'instance_id' => $instanceId,
                'model' => $modelName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recordModelRequestLog(
        string $instanceId,
        array $metadata,
        bool $success,
        ?int $responseTimeMs,
        ?float $tokensPerSecond
    ): void {
        try {
            $tableExists = Cache::remember(
                'llm_model_request_logs_table_exists',
                60,
                static fn (): bool => Schema::hasTable('llm_model_request_logs')
            );

            if (! $tableExists) {
                return;
            }

            $instance = DB::table('llm_instances')
                ->select(['id', 'instance_type'])
                ->where('instance_id', $instanceId)
                ->first();

            $error = $metadata['error'] ?? null;
            if (is_string($error)) {
                $error = mb_substr($error, 0, 255);
            } else {
                $error = null;
            }

            $log = [
                'llm_instance_row_id' => $instance->id ?? null,
                'instance_id' => $instanceId,
                'instance_type' => $instance->instance_type ?? null,
                'model_name' => $metadata['model'] ?? $metadata['model_name'] ?? null,
                'caller' => $metadata['caller'] ?? null,
                'model_role' => $metadata['model_role'] ?? null,
                'request_kind' => $metadata['request_kind'] ?? 'chat',
                'success' => $success ? 1 : 0,
                'latency_ms' => $responseTimeMs,
                'tokens_per_second' => $tokensPerSecond,
                'quality_score' => $metadata['quality_score'] ?? null,
                'escalated' => array_key_exists('escalated', $metadata) ? (bool) $metadata['escalated'] : null,
                'error_summary' => $error,
                'created_at' => now(),
            ];

            $hasRouteTraceColumns = Cache::remember(
                'llm_model_request_logs_route_trace_columns_exist',
                60,
                static fn (): bool => Schema::hasColumns(
                    'llm_model_request_logs',
                    ['route_trace_id', 'route_attempt_index']
                )
            );
            if ($hasRouteTraceColumns) {
                $traceId = strtolower(trim((string) ($metadata['route_trace_id'] ?? '')));
                $log['route_trace_id'] = preg_match('/^[0-9a-f]{32}$/', $traceId) === 1
                    && $traceId !== str_repeat('0', 32)
                    ? $traceId
                    : null;
                $attemptIndex = filter_var(
                    $metadata['route_attempt_index'] ?? null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 65535]]
                );
                $log['route_attempt_index'] = $attemptIndex === false ? null : $attemptIndex;
            }

            $usageColumns = [
                'input_tokens',
                'cached_input_tokens',
                'cache_write_input_tokens',
                'output_tokens',
                'reasoning_output_tokens',
            ];
            $hasUsageColumns = Cache::remember(
                'llm_model_request_logs_token_usage_columns_exist',
                60,
                static fn (): bool => Schema::hasColumns('llm_model_request_logs', $usageColumns)
            );
            if ($hasUsageColumns) {
                foreach ($usageColumns as $column) {
                    $value = filter_var(
                        $metadata[$column] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 0]]
                    );
                    $log[$column] = $value === false ? null : $value;
                }
            }

            $transportColumns = [
                'queue_wait_ms',
                'time_to_first_content_ms',
                'response_bytes',
                'transport_chunk_count',
                'finish_reason',
            ];
            $hasTransportColumns = Cache::remember(
                'llm_model_request_logs_transport_columns_exist',
                60,
                static fn (): bool => Schema::hasColumns('llm_model_request_logs', $transportColumns)
            );
            if ($hasTransportColumns) {
                foreach (array_slice($transportColumns, 0, 4) as $column) {
                    $value = filter_var(
                        $metadata[$column] ?? null,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 0]]
                    );
                    $log[$column] = $value === false ? null : $value;
                }
                $finishReason = trim((string) ($metadata['finish_reason'] ?? ''));
                $log['finish_reason'] = $finishReason !== '' ? mb_substr($finishReason, 0, 64) : null;
            }

            DB::table('llm_model_request_logs')->insert($log);
        } catch (\Throwable $e) {
            Log::debug('LLMPoolManager: model request log insert failed', [
                'instance_id' => $instanceId,
                'error' => $e->getMessage(),
            ]);
        }
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
        // Honor Retry-After from 429 responses within the shared operator-owned cap.
        $cooldown = $this->circuitBreakerPolicy->cooldownSeconds();
        if ($retryAfterSeconds !== null && $retryAfterSeconds > $cooldown) {
            $cooldown = min($retryAfterSeconds, $this->circuitBreakerPolicy->retryAfterMaxSeconds());
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
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return false;
        }

        return $this->isInstanceBusyForInstance($instance);
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
        if (isset($this->poolExecutions[$instanceId][$requestId])) {
            throw new InferenceAdmissionException('Pool invocation already owns custody; another acquire is held.');
        }
        try {
            $instance = $this->getInstance($instanceId);
            if ($instance === null) {
                throw new InferenceAdmissionException('Pool instance catalog is unavailable; dispatch held.');
            }
            $policy = $this->resolveSlotPolicy($instance, $ttl);
            $entered = false;
            $admitted = Cache::lock($policy['slot_lock_key'], $policy['lock_ttl_seconds'])->get(function () use ($instanceId, $requestId, $ttl, &$entered): bool {
                $entered = true;

                return DB::transaction(function () use ($instanceId, $requestId, $ttl): bool {
                    $catalog = $this->qualifiedPoolCatalog(true);
                    $instance = $catalog[$instanceId] ?? null;
                    if ($instance === null || app()->isDownForMaintenance()) {
                        throw new InferenceAdmissionException('Pool admission is held before dispatch.');
                    }
                    $policy = $this->resolveSlotPolicy($instance, $ttl);
                    [$group, $members, $capacity] = $this->poolGroup($instance, $catalog);
                    $held = $this->poolHeldRows($group, $members, $catalog);
                    $this->assertPoolCacheOwners($catalog, $held, $group, $members);
                    if (count($held) >= $capacity) {
                        return false;
                    }
                    $used = array_column(array_map(fn ($row) => json_decode($row->result_envelope, true, flags: JSON_THROW_ON_ERROR), $held), 'slot');
                    $slot = 0;
                    while (in_array($slot, $used, true)) {
                        $slot++;
                    }
                    $owner = bin2hex(random_bytes(32));
                    $envelope = ['schema' => 'plos.pool_execution.v1', 'instance_id' => $instanceId,
                        'group_sha256' => hash('sha256', $group), 'members' => $members, 'slot' => $slot,
                        'catalog_sha256' => $this->poolCatalogDigest($catalog), 'dispatch_sequence' => 0];
                    $id = DB::table('task_custody_records')->insertGetId([
                        'surface' => TaskCustodyService::SURFACE_LLM_POOL_EXECUTION,
                        'surface_ref' => hash('sha256', $group).':'.$slot, 'owner_token' => $owner,
                        'acquired_at' => now(), 'expires_at' => now()->addSeconds($policy['request_ttl_seconds']),
                        'progress_note' => 'pool_prepared', 'result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $row = DB::table('task_custody_records')->where('id', $id)->first();
                    $this->poolExecutions[$instanceId][$requestId] = ['row' => $row, 'policy' => $policy,
                        'instance' => $instance, 'receipt' => null];
                    // Cache is an advisory mirror. Failure cannot erase the durable reservation.
                    $cacheOwner = ['request_id' => $requestId, 'custody_id' => $id, 'owner_token' => $owner,
                        'started_at' => now()->toDateTimeString(), 'pid' => getmypid()];
                    if ($capacity === 1) {
                        if (! Cache::add($policy['busy_lock_key'], $cacheOwner, $policy['request_ttl_seconds'])) {
                            throw new InferenceAdmissionException('Pool cache owner changed during reservation; custody retained.');
                        }
                    } else {
                        $slots = Cache::get($policy['slot_cache_key'], []);
                        $slots[$requestId] = $cacheOwner;
                        Cache::put($policy['slot_cache_key'], $slots, $policy['slot_cache_ttl_seconds']);
                    }

                    return true;
                });
            });
            if (! $entered) {
                throw new InferenceAdmissionException('Pool admission mutex is unavailable; dispatch held.');
            }

            return (bool) $admitted;
        } catch (InferenceAdmissionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Pool durable admission is unavailable; dispatch held.', 0, $error);
        }
    }

    public function executionReceipt(string $instanceId, string $requestId, string $model): PoolExecutionReceipt
    {
        $execution = &$this->poolExecutions[$instanceId][$requestId];
        if (! is_array($execution) || trim($model) === '') {
            throw new InferenceAdmissionException('Exact pool invocation receipt is unavailable.');
        }
        if ($execution['receipt'] instanceof PoolExecutionReceipt) {
            return $execution['receipt'];
        }
        $row = $execution['row'];
        $base = rtrim((string) $execution['instance']->base_url, '/');
        $parts = parse_url($base);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InferenceAdmissionException('Pool transport endpoint binding is invalid.');
        }
        $endpoints = $execution['instance']->instance_type === 'ollama'
            ? ['ollama_generate' => hash('sha256', $base.'/api/generate'), 'ollama_chat' => hash('sha256', $base.'/api/chat')]
            : ['openai_chat_completions' => hash('sha256', $base.'/chat/completions')];
        $execution['receipt'] = new PoolExecutionReceipt($model, $endpoints,
            function (string $expected, string $next, array $metadata) use ($row): void {
                DB::transaction(function () use ($row, $expected, $next, $metadata): void {
                    if (str_starts_with($next, 'pool_dispatched:')) {
                        $catalog = $this->qualifiedPoolCatalog(true);
                        $acquired = json_decode($row->result_envelope, true, flags: JSON_THROW_ON_ERROR);
                        if (! hash_equals($acquired['catalog_sha256'], $this->poolCatalogDigest($catalog))) {
                            throw new InferenceAdmissionException('Pool invocation admission catalog changed; dispatch held.');
                        }
                        if (app()->isDownForMaintenance()) {
                            throw new InferenceAdmissionException('Pool maintenance gate held before send.');
                        }
                    }
                    $this->verifyOwnedPoolCheckpoint($row, $expected);
                    $envelope = json_decode($row->result_envelope, true, flags: JSON_THROW_ON_ERROR);
                    $envelope = array_replace($envelope, $metadata);
                    if ($this->ownedPoolRow($row)->where('progress_note', $expected)->update([
                        'progress_note' => $next, 'result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]) !== 1) {
                        throw new InferenceAdmissionException('Pool owned checkpoint CAS failed; custody retained.');
                    }
                    $row->progress_note = $next;
                    $row->result_envelope = json_encode($envelope, JSON_THROW_ON_ERROR);
                });
            },
            function (string $phase, string $outcome) use ($row): void {
                DB::transaction(function () use ($row, $phase, $outcome): void {
                    $this->verifyOwnedPoolCheckpoint($row, $phase);
                    if ($this->ownedPoolRow($row)->where('progress_note', $phase)->update([
                        'released_at' => now(), 'outcome' => $outcome, 'notification_state' => 'suppressed', 'updated_at' => now(),
                    ]) !== 1) {
                        throw new InferenceAdmissionException('Pool owned terminal release CAS failed; custody retained.');
                    }
                });
            });

        return $execution['receipt'];
    }

    public function releaseBusyLock(string $instanceId, string $requestId): bool
    {
        $execution = $this->poolExecutions[$instanceId][$requestId] ?? null;
        if ($execution === null) {
            return false;
        }
        // An unbound reservation has never reached any transport checkpoint.
        $receipt = $execution['receipt'] ?? $this->executionReceipt($instanceId, $requestId, '__no_send__');
        $receipt->finish();
        $row = $execution['row'];
        $policy = $execution['policy'];
        try {
            $releasedCache = Cache::lock($policy['slot_lock_key'], $policy['lock_ttl_seconds'])->get(function () use ($policy, $row, $requestId): bool {
                $busy = Cache::get($policy['busy_lock_key']);
                if (is_array($busy) && ($busy['custody_id'] ?? null) === $row->id && ($busy['owner_token'] ?? null) === $row->owner_token) {
                    Cache::forget($policy['busy_lock_key']);
                }
                $slots = Cache::get($policy['slot_cache_key'], []);
                if (is_array($slots) && ($slots[$requestId]['custody_id'] ?? null) === $row->id
                    && ($slots[$requestId]['owner_token'] ?? null) === $row->owner_token) {
                    unset($slots[$requestId]);
                    Cache::put($policy['slot_cache_key'], $slots, $policy['slot_cache_ttl_seconds']);
                }

                return true;
            });
            if ($releasedCache !== true) {
                throw new InferenceAdmissionException('Pool terminal cache mutex is unavailable; next dispatch held.');
            }
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Pool terminal cache publication failed; next dispatch held.', 0, $error);
        }
        unset($this->poolExecutions[$instanceId][$requestId]);

        return true;
    }

    private function ownedPoolRow(object $row): Builder
    {
        return DB::table('task_custody_records')->where('id', $row->id)->where('surface', TaskCustodyService::SURFACE_LLM_POOL_EXECUTION)
            ->where('surface_ref', $row->surface_ref)->where('owner_token', $row->owner_token)->whereNull('released_at');
    }

    private function verifyOwnedPoolCheckpoint(object $row, string $phase): void
    {
        $current = $this->ownedPoolRow($row)->where('progress_note', $phase)->lockForUpdate()->first();
        if ($current === null || json_decode($current->result_envelope, true, flags: JSON_THROW_ON_ERROR)
            != json_decode($row->result_envelope, true, flags: JSON_THROW_ON_ERROR)) {
            throw new InferenceAdmissionException('Pool invocation phase or published evidence changed; custody retained.');
        }
    }

    /** Fresh table policy and a complete deterministic catalog, never cache-derived admission. */
    private function qualifiedPoolCatalog(bool $lock): array
    {
        $query = DB::table('system_configs')->where('section', 'ai')->where('config_key', self::CUSTODY_POLICY_KEY);
        $policyRow = ($lock ? $query->lockForUpdate() : $query)->first();
        $policy = json_decode($policyRow->config_value ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($policy) || ($policy['schema'] ?? null) !== 'plos.pool_execution_policy.v1'
            || ($policy['surface'] ?? null) !== TaskCustodyService::SURFACE_LLM_POOL_EXECUTION
            || ($policy['enabled'] ?? null) !== true || ($policy['old_writers_qualified'] ?? null) !== true) {
            throw new InferenceAdmissionException('Pool registration or independent old-writer qualification is unavailable; dispatch held.');
        }
        $query = DB::table('llm_instances')->orderBy('instance_id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get(['instance_id', 'instance_type', 'base_url', 'max_concurrent', 'config', 'supported_models', 'capabilities']);
        $catalog = [];
        foreach ($rows as $row) {
            $catalog[$row->instance_id] = $row;
        }
        foreach ($catalog as $member) {
            if (in_array($member->instance_type, ['ollama', 'local_llm'], true)) {
                $this->poolGroup($member, $catalog);
            }
        }
        $digest = $this->poolCatalogDigest($catalog);
        if (! is_string($policy['catalog_sha256'] ?? null) || ! hash_equals($digest, $policy['catalog_sha256'])) {
            throw new InferenceAdmissionException('Pool registered admission catalog changed; dispatch held.');
        }

        return $catalog;
    }

    private function poolCatalogDigest(array $catalog): string
    {
        $binding = [];
        foreach ($catalog as $id => $row) {
            $config = json_decode((string) ($row->config ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
            $slot = $config['slot_policy'] ?? null;
            if ($slot !== null && ! is_array($slot)) {
                throw new InferenceAdmissionException('Pool table slot policy is invalid.');
            }
            if (is_array($slot)) {
                ksort($slot);
            }
            // Include inactive members too: activation/removal cannot erase older holds.
            $binding[$id] = [(string) $row->instance_type, hash('sha256', (string) $row->base_url), (string) $row->max_concurrent, $slot,
                hash('sha256', json_encode($config, JSON_THROW_ON_ERROR)),
                hash('sha256', (string) $row->supported_models), hash('sha256', (string) $row->capabilities)];
        }
        ksort($binding);

        return hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR));
    }

    private function poolGroup(object $instance, array $catalog): array
    {
        $policy = $this->decodeInstanceConfig($instance)['slot_policy'] ?? null;
        if (! is_array($policy) || ($policy['policy_source'] ?? null) !== 'llm_instances.config.slot_policy'
            || ($policy['source'] ?? null) !== 'llm_instances.max_concurrent'
            || ! in_array($instance->instance_type, ['ollama', 'local_llm'], true)) {
            throw new InferenceAdmissionException('Pool table capacity source is unqualified.');
        }
        $group = $this->policyString($policy['admission_group'] ?? null, $instance->instance_id);
        $members = [];
        $capacity = null;
        $keys = null;
        foreach ($catalog as $id => $row) {
            $rowPolicy = $this->decodeInstanceConfig($row)['slot_policy'] ?? [];
            if ($this->policyString($rowPolicy['admission_group'] ?? null, $id) !== $group) {
                continue;
            }
            if (! is_array($rowPolicy) || ($rowPolicy['policy_source'] ?? null) !== 'llm_instances.config.slot_policy'
                || ($rowPolicy['source'] ?? null) !== 'llm_instances.max_concurrent'
                || ! ctype_digit((string) $row->max_concurrent) || (int) $row->max_concurrent < 1
                || ! in_array($row->instance_type, ['ollama', 'local_llm'], true)) {
                throw new InferenceAdmissionException('Pool group capacity is missing or ambiguous.');
            }
            $resolved = $this->resolveSlotPolicy($row);
            $rowKeys = array_intersect_key($resolved, array_flip(['busy_lock_key', 'slot_cache_key', 'slot_lock_key']));
            if (($capacity !== null && $capacity !== (int) $row->max_concurrent) || ($keys !== null && $keys !== $rowKeys)) {
                throw new InferenceAdmissionException('Pool group policies disagree; dispatch held.');
            }
            $capacity = (int) $row->max_concurrent;
            $keys = $rowKeys;
            $members[] = $id;
        }
        sort($members);

        return [$group, $members, $capacity];
    }

    /** Every unreleased row is counted irrespective of expiry, slot index, or current provider existence. */
    private function poolHeldRows(string $group, array $members, array $catalog): array
    {
        $held = [];
        $history = [];
        $catalogGroups = [];
        $catalogMembers = [];
        foreach ($catalog as $id => $member) {
            if (! in_array($member->instance_type ?? null, ['ollama', 'local_llm'], true)) {
                continue;
            }
            $catalogMembers[] = $id;
            $policy = $this->decodeInstanceConfig($member)['slot_policy'] ?? [];
            $catalogGroups[] = hash('sha256', $this->policyString($policy['admission_group'] ?? null, $id));
        }
        foreach (DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_LLM_POOL_EXECUTION)->whereNull('released_at')->get() as $row) {
            $envelope = json_decode($row->result_envelope ?? '', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($envelope) || ($envelope['schema'] ?? null) !== 'plos.pool_execution.v1'
                || ! is_array($envelope['members'] ?? null) || $envelope['members'] === []
                || count(array_filter($envelope['members'], 'is_string')) !== count($envelope['members'])
                || ! is_string($envelope['instance_id'] ?? null) || ! in_array($envelope['instance_id'], $envelope['members'], true)
                || ! is_string($envelope['group_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $envelope['group_sha256'])
                || ! is_string($envelope['catalog_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $envelope['catalog_sha256'])
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $row->owner_token)
                || ! is_int($envelope['slot'] ?? null) || $envelope['slot'] < 0
                || $row->surface_ref !== $envelope['group_sha256'].':'.$envelope['slot']) {
                throw new InferenceAdmissionException('Pool historical custody binding is unqualified; dispatch held.');
            }
            $history[] = [$row, $envelope];
        }
        $closure = static function (array $groups, array $memberIds) use ($history): array {
            $held = [];
            do {
                $changed = false;
                foreach ($history as [$row, $envelope]) {
                    if (isset($held[$row->id])) {
                        continue;
                    }
                    if (in_array($envelope['group_sha256'], $groups, true)
                        || array_intersect($memberIds, $envelope['members']) !== []) {
                        $held[$row->id] = $row;
                        $groups[] = $envelope['group_sha256'];
                        $memberIds = array_values(array_unique(array_merge($memberIds, $envelope['members'])));
                        $changed = true;
                    }
                }
            } while ($changed);

            return array_values($held);
        };
        if (count($closure($catalogGroups, $catalogMembers)) !== count($history)) {
            throw new InferenceAdmissionException('Pool orphan custody has no qualified catalog binding; dispatch held.');
        }

        return $closure([hash('sha256', $group)], $members);
    }

    private function assertPoolCacheOwners(array $catalog, array $held, string $group, array $members): void
    {
        $qualified = [];
        foreach ($held as $row) {
            $qualified[(string) $row->id] = $row->owner_token;
        }
        $policies = [];
        foreach ($members as $id) {
            $policies[] = $this->resolveSlotPolicy($catalog[$id]);
        }
        foreach ($policies as $policy) {
            $busy = Cache::get($policy['busy_lock_key']);
            $slots = Cache::get($policy['slot_cache_key'], []);
            if (! is_array($slots) || ($busy !== null && ! is_array($busy))) {
                throw new InferenceAdmissionException('Pool cache owner is unqualified; dispatch held.');
            }
            foreach (array_merge($busy === null ? [] : [$busy], array_values($slots)) as $owner) {
                if (! is_array($owner) || ! isset($owner['custody_id'], $owner['owner_token'])
                    || ($qualified[(string) $owner['custody_id']] ?? null) !== $owner['owner_token']) {
                    throw new InferenceAdmissionException('Pool legacy or foreign cache owner is unqualified; dispatch held.');
                }
            }
        }
        if (Cache::has('ollama_busy_lock')) {
            throw new InferenceAdmissionException('Pool legacy global owner is unqualified; dispatch held.');
        }
    }

    /**
     * Get active lock count for multi-concurrent instances
     *
     * @param  string  $instanceId  Instance identifier
     */
    private function getActiveLockCount(string $instanceId): int
    {
        $instance = $this->getInstance($instanceId);
        if (! $instance) {
            return 0;
        }

        return $this->getActiveLockCountForInstance($instance);
    }

    private function isInstanceBusyForInstance(object $instance): bool
    {
        return $this->getActiveLockCountForInstance($instance) >= max(1, (int) ($instance->max_concurrent ?? 1));
    }

    private function getActiveLockCountForInstance(object $instance): int
    {
        if (! in_array($instance->instance_type ?? null, ['ollama', 'local_llm'], true)) {
            return 0;
        }
        try {
            $catalog = [];
            foreach (DB::table('llm_instances')->orderBy('instance_id')->get(['instance_id', 'instance_type', 'base_url', 'max_concurrent', 'config', 'supported_models', 'capabilities']) as $row) {
                $catalog[$row->instance_id] = $row;
            }
            [$group, $members] = $this->poolGroup($instance, $catalog);
            $held = $this->poolHeldRows($group, $members, $catalog);
            $this->assertPoolCacheOwners($catalog, $held, $group, $members);

            return count($held);
        } catch (\Throwable $error) {
            throw new InferenceAdmissionException('Pool occupancy is unqualified; dispatch held.', 0, $error);
        }
    }

    /**
     * @return array{
     *     busy_lock_key:string,
     *     slot_cache_key:string,
     *     slot_lock_key:string,
     *     request_ttl_seconds:int,
     *     slot_cache_ttl_seconds:int,
     *     lock_ttl_seconds:int
     * }
     */
    private function resolveSlotPolicy(object $instance, ?int $requestTtlOverride = null): array
    {
        $config = $this->decodeInstanceConfig($instance);
        $policy = is_array($config['slot_policy'] ?? null) ? $config['slot_policy'] : [];

        $requestTtl = $requestTtlOverride
            ?? $this->positivePolicyInt($policy['request_ttl_seconds'] ?? null, self::BUSY_LOCK_DEFAULT_TTL);
        $slotCacheTtl = $this->positivePolicyInt(
            $policy['slot_cache_ttl_seconds'] ?? null,
            $requestTtl * 2
        );
        $lockTtl = $this->positivePolicyInt($policy['lock_ttl_seconds'] ?? null, 5);

        $busyPrefix = $this->policyString($policy['busy_lock_key_prefix'] ?? null, 'llm_busy_lock');
        $slotPrefix = $this->policyString($policy['slot_cache_key_prefix'] ?? null, 'llm_slots');
        $lockSuffix = $this->policyString($policy['slot_lock_key_suffix'] ?? null, '_lock');
        $instanceId = (string) $instance->instance_id;
        $admissionGroup = $this->policyString($policy['admission_group'] ?? null, $instanceId);
        $slotCacheKey = "{$slotPrefix}:{$admissionGroup}";

        return [
            'busy_lock_key' => "{$busyPrefix}:{$admissionGroup}",
            'slot_cache_key' => $slotCacheKey,
            'slot_lock_key' => "{$slotCacheKey}{$lockSuffix}",
            'request_ttl_seconds' => $requestTtl,
            'slot_cache_ttl_seconds' => $slotCacheTtl,
            'lock_ttl_seconds' => $lockTtl,
        ];
    }

    private function positivePolicyInt(mixed $value, int $fallback): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $fallback;
    }

    private function policyString(mixed $value, string $fallback): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return $fallback;
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
            'unknown' => 0,
            'managed_idle' => 0,
            'details' => [],
        ];

        $instances = $this->getInstances(false);
        $controllerHealth = app(DailyReportAiRuntimeSnapshotService::class)->controllerHealthObservations($instances);

        foreach ($instances as $instance) {
            $results['checked']++;

            $checkResult = $this->healthCheckInstance($instance, $controllerHealth[$instance->instance_id] ?? null);
            $results['details'][$instance->instance_id] = $checkResult;

            if ($checkResult['healthy'] === null) {
                $results['unknown']++;
            } elseif ($checkResult['healthy']) {
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
            if (in_array($checkResult['state'] ?? null, ['MANAGED-IDLE', 'RESEARCH-IDLE'], true)) {
                $results['managed_idle']++;
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
    public function healthCheckInstance(object $instance, ?array $controllerObservation = null): array
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

        $config = json_decode((string) ($instance->config ?? '{}'), true);
        if (is_array($config) && array_key_exists('controller_catalog', $config)) {
            $observation = $controllerObservation
                ?? app(DailyReportAiRuntimeSnapshotService::class)->controllerHealthObservations([$instance])[$instance->instance_id];

            return array_replace($result, $observation);
        }

        $blocked = $this->providerProbeBlockReason($instance);
        if ($blocked !== null) {
            return array_replace($result, ['healthy' => null, 'state' => 'PROBE-SKIPPED',
                'health_persisted' => false, 'reason_code' => $blocked]);
        }

        $startTime = microtime(true);

        try {
            $healthy = match ($instance->instance_type) {
                'ollama' => $this->healthCheckOllama($instance),
                'local_llm' => $this->probeLocalOpenAICompatible($instance),
                'claude_cli', 'anthropic_api' => false,
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
            $response = Http::connectTimeout($this->probePolicy->httpConnectTimeoutSeconds())
                ->timeout($this->probePolicy->healthRequestTimeoutSeconds())
                ->get(rtrim($instance->base_url, '/').'/api/tags');

            return $response->successful();
        } catch (Exception $e) {
            Log::debug('LLMPoolManagerService: Ollama health check failed', ['url' => $instance->base_url, 'error' => $e->getMessage()]);

            return false;
        }
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
        $result = Process::timeout($this->probePolicy->processLookupTimeoutSeconds())->run(['which', $cliPath]);
        $path = trim($result->output());
        if (! $result->successful() || $path === '' || ! file_exists($path) || ! is_executable($path)) {
            return false;
        }

        $version = Process::timeout($this->probePolicy->cliStatusTimeoutSeconds())->run([$cliPath, '--version']);

        return $version->successful() && trim($version->output().$version->errorOutput()) !== '';
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
            $response = Http::connectTimeout($this->probePolicy->httpConnectTimeoutSeconds())
                ->timeout($this->probePolicy->healthRequestTimeoutSeconds())
                ->get($instance->base_url);

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

            $response = Http::connectTimeout($this->probePolicy->httpConnectTimeoutSeconds())
                ->timeout($this->probePolicy->externalHealthRequestTimeoutSeconds())
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
            $instance->is_busy = $this->isInstanceBusyForInstance($instance);
            $instance->active_slots = $this->getActiveLockCountForInstance($instance);
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
                    circuit_state, is_healthy, health_score, supported_models, consecutive_failures,
                    routability, compat_status, quarantine_status, allows_private_data, capabilities
             FROM llm_instances
             WHERE is_active = 1
               AND (circuit_state = 'open' OR is_healthy = 0)
             ORDER BY priority ASC"
        );

        foreach ($unhealthy as $instance) {
            $providerId = $instance->instance_id;

            $config = json_decode((string) ($instance->config ?? '{}'), true);
            if (is_array($config) && array_key_exists('controller_catalog', $config)) {
                $results[$providerId] = 'skipped_controller_managed';

                continue;
            }
            $blocked = $this->providerProbeBlockReason($instance);
            if ($blocked !== null) {
                $results[$providerId] = $blocked;

                continue;
            }
            if (in_array($instance->instance_type, ['claude_cli', 'anthropic_api', 'codex_cli'], true)) {
                $results[$providerId] = 'skipped_unsupported';

                continue;
            }

            try {
                if ($instance->instance_type === 'ollama') {
                    $success = $this->probeOllama($instance);
                } elseif ($instance->instance_type === 'local_llm') {
                    $success = $this->probeLocalOpenAICompatible($instance);
                } elseif (in_array($instance->instance_type, ['claude_cli', 'anthropic_api'], true)) {
                    $success = false;
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
                $results[$providerId] = 'probe_error';
                Log::debug('LLMPoolManager: Health probe failed', ['instance_id' => $providerId]);
            }
        }

        $this->clearInstanceCache();

        return $results;
    }

    /** Recovery ignores current health/circuit, never routing or privacy admission. */
    public function providerProbeBlockReason(object $instance): ?string
    {
        if (($instance->routability ?? null) !== 'allowed') {
            return 'skipped_routability';
        }
        if (($instance->compat_status ?? null) === 'stale') {
            return 'skipped_compatibility';
        }
        if (($instance->quarantine_status ?? 'none') === 'quarantined') {
            return 'skipped_quarantine';
        }
        if (! is_array(json_decode((string) ($instance->config ?? '{}'), true))) {
            return 'skipped_config';
        }

        return $this->offlinePolicyAllowsProvider($instance) ? null : 'skipped_policy';
    }

    /**
     * Probe an Ollama instance by hitting /api/tags
     */
    private function probeOllama(object $instance): bool
    {
        $response = Http::connectTimeout($this->probePolicy->httpConnectTimeoutSeconds())
            ->timeout($this->probePolicy->healthRequestTimeoutSeconds())
            ->get(rtrim($instance->base_url, '/').'/api/tags');

        return $response->successful();
    }

    /**
     * Probe a local OpenAI-compatible sidecar, such as llama-server.
     *
     * Local sidecars do not require API keys and should not be classified as
     * external API providers just to participate in health recovery.
     */
    private function probeLocalOpenAICompatible(object $instance): bool
    {
        $config = json_decode($instance->config ?? '{}', true) ?: [];
        $healthPath = $config['health_path'] ?? '/health';
        $healthBaseUrl = $config['health_base_url'] ?? $instance->base_url;
        $headers = is_array($config['extra_headers'] ?? null) ? $config['extra_headers'] : [];

        $response = Http::connectTimeout($this->probePolicy->localSidecarConnectTimeoutSeconds())
            ->timeout($this->probePolicy->healthRequestTimeoutSeconds())
            ->withHeaders($headers)
            ->get(rtrim($healthBaseUrl, '/').$healthPath);

        return $response->successful();
    }

    /**
     * Probe an external API by sending a minimal completion request.
     * Only a successful response qualifies for recovery; rate limits remain held.
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

        $response = Http::connectTimeout($this->probePolicy->httpConnectTimeoutSeconds())
            ->timeout($this->probePolicy->externalHealthRequestTimeoutSeconds())
            ->withHeaders($headers)
            ->post(rtrim($instance->base_url, '/').'/chat/completions', [
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'Reply with OK']],
                'max_tokens' => 5,
                'temperature' => 0,
            ]);

        return $response->successful();
    }

    // ═══════════════════════════════════════════════════════════════════
    // UTILITIES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Clear instance cache
     */
    public static function clearInstanceCache(bool $strict = false): void
    {
        foreach (['llm_instances_all', 'llm_instances_healthy'] as $key) {
            Cache::forget($key);
            if ($strict && Cache::has($key)) {
                throw new \RuntimeException('Provider inventory cache invalidation unavailable.');
            }
        }
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
