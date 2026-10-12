<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class OfflineCodingRouteService
{
    public const POLICY_KEY = 'ai.offline_coding_runtime_policy';

    private const REASONING_EFFORTS = ['low', 'medium', 'high', 'xhigh'];

    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly ModelHardwareAdmissionService $hardwareAdmission,
    ) {}

    /** @return array<string,mixed> */
    public function resolve(
        ?string $profileOverride = null,
        ?string $modelOverride = null,
        ?string $roleOverride = null,
        ?string $effortOverride = null,
    ): array {
        $policy = $this->systemConfig->get(self::POLICY_KEY);
        if (! is_array($policy)) {
            return $this->blocked('policy_unavailable');
        }

        $instanceId = $this->token($policy['instance_id'] ?? null);
        $profile = $this->token($policy['profile'] ?? null);
        $role = $this->token($policy['model_role'] ?? null);
        $effort = $this->token($policy['reasoning_effort'] ?? null);
        if (($policy['version'] ?? null) !== 1 || $instanceId === null || $profile === null || $role === null || $effort === null) {
            return $this->blocked('policy_invalid');
        }

        foreach ([
            'profile' => [$profileOverride, $profile],
            'role' => [$roleOverride, $role],
        ] as $field => [$override, $expected]) {
            $override = $this->token($override);
            if ($override !== null && $override !== $expected) {
                return $this->blocked($field.'_override_not_admitted');
            }
        }

        try {
            $instance = DB::table('llm_instances')->where('instance_id', $instanceId)->first();
        } catch (Throwable) {
            return $this->blocked('instance_inventory_unavailable');
        }
        if ($instance === null) {
            return $this->blocked('instance_not_found');
        }
        if ((int) ($instance->is_active ?? 0) !== 1) {
            return $this->blocked('instance_inactive');
        }
        if ((int) ($instance->is_healthy ?? 0) !== 1) {
            return $this->blocked('instance_unhealthy');
        }
        if (($instance->routability ?? null) !== 'allowed') {
            return $this->blocked('instance_not_routable');
        }

        $config = $this->map($instance->config ?? null);
        $models = $this->map($config['models'] ?? null);
        $modelOverride = $this->token($modelOverride);
        $model = $modelOverride ?? $this->token($models[$role] ?? null);
        if ($model === null) {
            return $this->blocked('role_model_unconfigured');
        }
        if ($modelOverride !== null && ($policy['allow_model_override'] ?? false) !== true) {
            return $this->blocked('model_override_not_admitted');
        }

        $supported = $this->list($instance->supported_models ?? null);
        if (! in_array($model, $supported, true)) {
            return $this->blocked('model_not_installed');
        }

        $routePolicy = $this->map($config['route_policy'] ?? null);
        $allowedRoles = $this->list($routePolicy['allowed_model_roles'] ?? null);
        if (($routePolicy['require_model_role'] ?? false) === true && ! in_array($role, $allowedRoles, true)) {
            return $this->blocked('role_not_routable');
        }

        $modelAdmission = $this->map($config['model_admission'] ?? null);
        if (($modelAdmission['enforce'] ?? false) === true) {
            $assessment = $this->hardwareAdmission->assess($instance, $model, $role);
            if (($assessment['route_allowed'] ?? false) !== true) {
                return $this->blocked('hardware_admission_rejected');
            }
        }

        $effortOverride = $this->token($effortOverride);
        $resolvedEffort = $effortOverride ?? $effort;
        if (! in_array($resolvedEffort, self::REASONING_EFFORTS, true)) {
            return $this->blocked('reasoning_effort_not_supported');
        }

        return [
            'schema' => 'plos.offline_coding_route.v1',
            'status' => 'admitted',
            'execution_allowed' => true,
            'instance_id' => $instanceId,
            'profile' => $profile,
            'model_role' => $role,
            'model' => $model,
            'reasoning_effort' => $resolvedEffort,
            'model_selection' => $modelOverride === null ? 'instance_role' : 'explicit_admitted_override',
            'policy_source' => 'system_configs.'.self::POLICY_KEY,
            'model_source' => $modelOverride === null
                ? "llm_instances.{$instanceId}.config.models.{$role}"
                : 'explicit_override_checked_against_target_instance',
            'writes_performed' => false,
            'network_probe_performed' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason): array
    {
        return [
            'schema' => 'plos.offline_coding_route.v1',
            'status' => 'blocked',
            'execution_allowed' => false,
            'reason' => $reason,
            'policy_source' => 'system_configs.'.self::POLICY_KEY,
            'writes_performed' => false,
            'network_probe_performed' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    private function token(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '' || strlen($value) > 180) {
            return null;
        }

        return trim($value);
    }

    /** @return array<string,mixed> */
    private function map(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $item): ?string => $this->token($item), $this->map($value)),
            static fn (?string $item): bool => $item !== null,
        )));
    }
}
