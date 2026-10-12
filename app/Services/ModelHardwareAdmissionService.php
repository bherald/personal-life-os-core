<?php

namespace App\Services;

use App\Support\RuntimeBoolean;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ModelHardwareAdmissionService
{
    /**
     * Decide whether an exact model may serve a role on an instance.
     *
     * Instances opt in through config.model_admission.enforce. Published
     * hardware/model evidence establishes feasibility; only table-promoted
     * models are admitted to live routing.
     *
     * @return array<string,mixed>
     */
    public function assess(object $instance, string $model, ?string $role = null): array
    {
        $config = $this->decodeMap($instance->config ?? []);
        $admission = $this->decodeMap($config['model_admission'] ?? []);

        if (! $this->truthy($admission['enforce'] ?? false)) {
            return [
                'decision' => 'not_enforced',
                'route_allowed' => true,
                'hardware_feasible' => null,
                'instance_id' => (string) ($instance->instance_id ?? ''),
                'hardware_class' => null,
                'model' => $model,
                'role' => $role,
                'reasons' => ['Instance has not enabled model hardware admission enforcement.'],
                'required_gates' => [],
            ];
        }

        $hardwareClass = trim((string) ($admission['hardware_class'] ?? ''));

        return $this->assessAgainstPolicy(
            $hardwareClass,
            $model,
            $role,
            $this->policy(),
            (string) ($instance->instance_id ?? '')
        );
    }

    /**
     * Pure policy evaluator used by routing, reports, and focused tests.
     *
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public function assessAgainstPolicy(
        string $hardwareClass,
        string $model,
        ?string $role,
        array $policy,
        string $instanceId = ''
    ): array {
        $classes = $this->decodeMap($policy['hardware_classes'] ?? []);
        $classPolicy = $this->decodeMap($classes[$hardwareClass] ?? []);
        $models = $this->decodeMap($classPolicy['models'] ?? []);
        $modelPolicy = $this->decodeMap($models[$model] ?? []);
        $base = [
            'instance_id' => $instanceId,
            'hardware_class' => $hardwareClass,
            'model' => $model,
            'role' => $role,
            'policy_version' => $policy['version'] ?? null,
        ];

        if ($hardwareClass === '' || $classPolicy === []) {
            return $base + [
                'decision' => 'blocked_unknown_hardware_class',
                'route_allowed' => false,
                'hardware_feasible' => null,
                'reasons' => ['No table-owned hardware evidence exists for this enforced hardware class.'],
                'required_gates' => ['register official hardware evidence'],
            ];
        }

        if ($modelPolicy === []) {
            return $base + [
                'decision' => 'blocked_unknown_model',
                'route_allowed' => false,
                'hardware_feasible' => null,
                'reasons' => ['The exact model artifact is not registered for this enforced hardware class.'],
                'required_gates' => ['register exact model artifact evidence'],
            ];
        }

        $hardwareFit = (string) ($modelPolicy['hardware_fit'] ?? 'unknown');
        $deploymentState = (string) ($modelPolicy['deployment_state'] ?? 'candidate');
        $roles = array_values(array_filter(
            (array) ($modelPolicy['roles'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));
        $reasons = array_values(array_filter(
            (array) ($modelPolicy['reasons'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));
        $requiredGates = array_values(array_filter(
            (array) ($modelPolicy['required_gates'] ?? []),
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));
        $hardwareFeasible = in_array($hardwareFit, ['feasible', 'conditional'], true);

        if (! $hardwareFeasible) {
            return $base + [
                'decision' => 'blocked_hardware_fit',
                'route_allowed' => false,
                'hardware_feasible' => false,
                'hardware_fit' => $hardwareFit,
                'deployment_state' => $deploymentState,
                'artifact_size_gb' => $modelPolicy['artifact_size_gb'] ?? null,
                'parameters_b' => $modelPolicy['parameters_b'] ?? null,
                'reasons' => $reasons !== [] ? $reasons : ['Published model artifact evidence exceeds the hardware-class envelope.'],
                'required_gates' => $requiredGates,
            ];
        }

        if ($role !== null && $roles !== [] && ! in_array($role, $roles, true)) {
            return $base + [
                'decision' => 'blocked_role_mismatch',
                'route_allowed' => false,
                'hardware_feasible' => true,
                'hardware_fit' => $hardwareFit,
                'deployment_state' => $deploymentState,
                'artifact_size_gb' => $modelPolicy['artifact_size_gb'] ?? null,
                'parameters_b' => $modelPolicy['parameters_b'] ?? null,
                'reasons' => ["Model evidence does not admit role {$role} on {$hardwareClass}."],
                'required_gates' => $requiredGates,
            ];
        }

        $routeAllowed = $deploymentState === 'production';
        $decision = match ($deploymentState) {
            'production' => 'admitted',
            'candidate' => 'candidate_only',
            default => 'blocked_deployment_state',
        };

        return $base + [
            'decision' => $decision,
            'route_allowed' => $routeAllowed,
            'hardware_feasible' => true,
            'hardware_fit' => $hardwareFit,
            'deployment_state' => $deploymentState,
            'artifact_size_gb' => $modelPolicy['artifact_size_gb'] ?? null,
            'parameters_b' => $modelPolicy['parameters_b'] ?? null,
            'roles' => $roles,
            'reasons' => $reasons,
            'required_gates' => $requiredGates,
        ];
    }

    /** @return array<string,mixed> */
    public function policy(): array
    {
        return Cache::remember('llm_model_hardware_evidence_policy', 60, function (): array {
            try {
                $row = DB::selectOne(
                    "SELECT config_value
                     FROM system_configs
                     WHERE section = 'llm'
                       AND config_key = 'model_hardware_evidence_policy'
                     LIMIT 1"
                );
                $decoded = json_decode((string) ($row->config_value ?? ''), true);

                return is_array($decoded) ? $decoded : [];
            } catch (\Throwable $e) {
                Log::debug('Model hardware evidence policy unavailable', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truthy(mixed $value): bool
    {
        return RuntimeBoolean::isTrue($value);
    }
}
