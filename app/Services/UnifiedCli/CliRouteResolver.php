<?php

namespace App\Services\UnifiedCli;

use App\Services\LlmRoutePolicyService;
use App\Services\ModelHardwareAdmissionService;
use App\Support\RuntimeBoolean;
use Closure;
use RuntimeException;

/** Table-owned selection, followed by mandatory current runtime admission. */
final class CliRouteResolver
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function inspect(array $session, bool $modelChoices = false): array
    {
        $values = $this->configuration->effective($session)['values'];
        $selection = fn ($key) => ($values[$key] ?? 'auto') === 'auto' ? null : $values[$key];
        $selected = $selection('cli.model');
        $engine = $selection('cli.engine');
        $profileName = $selection('cli.profile');
        $requestedRole = $selection('cli.role');
        $profiles = array_column($this->configuration->profiles($session), null, 'profile_name');
        $profile = $profileName === null ? null : ($profiles[$profileName] ?? null);
        $instances = array_column($this->configuration->definitions($session, 'llm_instances'), null, 'instance_id');
        $candidates = [];
        $routePolicy = new LlmRoutePolicyService;
        $sessionSelection = $selected;
        foreach ($this->configuration->modelsForSession($session) as $model) {
            // Evaluate every exact picker choice against one shared registry view.
            $selected = $modelChoices ? $model['id'] : $sessionSelection;
            if ($selected !== null && $selected !== $model['id']) {
                continue;
            }
            if ($selected === null && (($engine !== null && $engine !== $model['engine'])
                || ($profileName !== null && ($profile['model_name'] ?? null) !== $model['model']))) {
                continue;
            }
            $instance = $instances[$model['instance']] ?? [];
            $config = $this->map($instance['config'] ?? []);
            $policy = $this->map($config['route_policy'] ?? []);
            $roles = $this->map($config['models'] ?? []);
            $allowedRoles = $routePolicy->allowedRoles($config) ?? array_keys($roles);
            $role = $requestedRole;
            $reasons = [];
            if (! $model['available']) {
                $reasons[] = 'registry_unavailable';
            }
            $capabilities = $model['capabilities'] ?? [];
            if (in_array('embedding', $capabilities, true)
                && array_intersect($capabilities, ['completion', 'text_output', 'chat']) === []) {
                $reasons[] = 'embedding_only_workflow';
            }
            if (($instance['compat_status'] ?? null) === 'stale') {
                $reasons[] = 'runtime_compatibility_stale';
            }
            if (($instance['quarantine_status'] ?? 'none') !== 'none' || ($routePolicy->requiresCachedHealth($config) && ($instance['circuit_state'] ?? 'closed') !== 'closed')) {
                $reasons[] = 'instance_quarantined_or_circuit_open';
            }
            if ($this->truth($policy['operator_route_block'] ?? false)) {
                $reasons[] = 'operator_route_block';
            }
            if ($engine !== null && $model['engine'] !== $engine) {
                $reasons[] = 'manual_engine_model_conflict';
            }
            if ($profileName !== null) {
                if ($profile === null || ($profile['model_name'] ?? null) !== $model['model']) {
                    $reasons[] = 'manual_profile_model_conflict';
                } elseif (! $this->truth($profile['enabled'] ?? false) || ($profile['status'] ?? null) !== 'active') {
                    $reasons[] = 'profile_not_enabled';
                }
            }
            if (! $routePolicy->allowsInstance($config, $model['instance'] ?? 'catalog', $selected !== null, $selected === null ? null : $model['instance'])) {
                $reasons[] = 'explicit_instance_required';
            }
            if ($role === null && ($selected !== null || $profileName !== null || ($config['default_model'] ?? null) === $model['model'])) {
                $matching = array_values(array_filter($allowedRoles, fn ($roleName) => ($roles[$roleName] ?? null) === $model['model']));
                // An exact operator-selected model can map to several roles.
                // The table's allowed role order (or model map order) chooses
                // one without granting a role absent from that instance policy.
                if ($matching !== [] && ($selected !== null || count($matching) === 1)) {
                    $role = $matching[0];
                }
            }
            if ($role !== null) {
                if (! $routePolicy->allowsRole($config, $role) || ($roles[$role] ?? null) !== $model['model']) {
                    $reasons[] = 'role_model_not_advertised';
                }
            } elseif (! $routePolicy->allowsUnscoped($config)) {
                $reasons[] = 'explicit_role_required';
            } elseif ($selected === null && $profileName === null && ($config['default_model'] ?? null) !== $model['model']) {
                $reasons[] = 'not_instance_default';
            }
            $supported = $this->map($instance['supported_models'] ?? []);
            if (! in_array($model['model'], $supported, true)) {
                $reasons[] = 'exact_model_not_installed';
            }
            $hardware = null;
            $admission = $this->map($config['model_admission'] ?? []);
            if (RuntimeBoolean::isTrue($admission['enforce'] ?? false)) {
                $hardware = (new ModelHardwareAdmissionService)->assessAgainstPolicy(
                    trim((string) ($admission['hardware_class'] ?? '')),
                    $model['model'], $role,
                    $this->map($values['llm.model_hardware_evidence_policy'] ?? []),
                    $model['instance'] ?? ''
                );
                if (! $hardware['route_allowed']) {
                    $reasons[] = $hardware['decision'];
                }
            }
            $candidates[] = ['id' => $model['id'], 'instance_id' => $model['instance'], 'model' => $model['model'],
                'engine' => $model['engine'], 'role' => $role, 'profile' => $profileName,
                'selection' => $selected === null ? 'automatic' : 'explicit', 'priority' => (int) ($instance['priority'] ?? PHP_INT_MAX),
                'configuration_eligible' => $reasons === [], 'reasons' => $reasons,
                'native_tools_configured' => $routePolicy->nativeToolsConfigured($config, $model['capabilities'], $role),
                'instance' => $instance, 'model_definition' => $model, 'hardware_admission' => $hardware];
        }
        usort($candidates, fn ($a, $b) => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);

        return ['selection' => $sessionSelection === null ? 'automatic' : 'explicit', 'candidates' => $candidates,
            'runtime_admission_checked' => false, 'inference_performed' => false];
    }

    public function resolve(array $session, Closure $admit): array
    {
        $inspection = $this->inspect($session);
        $failures = [];
        foreach ($inspection['candidates'] as $candidate) {
            if (! $candidate['configuration_eligible']) {
                $failures[$candidate['id']] = $candidate['reasons'];

                continue;
            }
            $decision = $admit($session, $candidate);
            if (($decision['allowed'] ?? false) !== true) {
                if (($decision['selection_committed'] ?? false) === true) {
                    throw new RuntimeException('Selected model activation was not admitted: '.($decision['reason'] ?? 'current_runtime_admission_unavailable').'; no alternate model was attempted.');
                }
                $failures[$candidate['id']] = [$decision['reason'] ?? 'current_runtime_admission_unavailable'];

                continue;
            }
            $route = $decision['route'] ?? [];
            if (($route['instance_id'] ?? null) !== $candidate['instance_id'] || ($route['model'] ?? null) !== $candidate['model']
                || ($route['agent_admitted'] ?? false) !== true) {
                throw new RuntimeException('Runtime admission changed the selected instance/model or did not admit an agent.');
            }

            return $route;
        }
        throw new RuntimeException('No selected route is currently admitted: '.json_encode($failures, JSON_THROW_ON_ERROR));
    }

    private function map(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR) ?? []);
    }

    private function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }
}
