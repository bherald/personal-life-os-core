<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ModelHostControllerPolicyService
{
    public function __construct(
        private readonly ?Closure $localPolicy = null,
        private readonly ?Closure $localHostIdentity = null,
    ) {}

    public const CONFIG_SECTION = 'ai';

    public const CONFIG_KEY = 'model_host_controller_policy';

    /** @return array<string,mixed> */
    public function required(): array
    {
        return $this->normalize($this->storedPolicy());
    }

    /** Copy explicit table-owned lifecycle parameters into a reviewed host manifest. */
    public function projectLifecycleControls(array $manifest): array
    {
        $stored = $this->storedPolicy();
        $policy = $this->normalize($stored);
        if (($manifest['host_id'] ?? null) !== $policy['host_id']
            || ($manifest['execution_mode'] ?? null) !== $policy['execution_mode']
            || ($manifest['concurrent_inference_enabled'] ?? null) !== false
            || ($manifest['compatible_residency_enabled'] ?? null) !== false
            || $policy['compatible_residency_enabled']
            || ! is_array($manifest['targets'] ?? null) || $manifest['targets'] === []) {
            throw new RuntimeException('Lifecycle projection host or custody posture is invalid.');
        }
        // Normal readers retain their legacy defaults. Projection may never silently
        // replace missing, malformed or out-of-range table values with those defaults.
        foreach (['lease_ttl_seconds', 'max_switches_per_hour'] as $field) {
            if (! is_int($stored[$field] ?? null) || $stored[$field] !== $policy[$field]) {
                throw new RuntimeException('Lifecycle projection requires explicit valid table parameters.');
            }
            $manifest[$field] = $policy[$field];
        }
        foreach ($manifest['targets'] as $id => &$spec) {
            $target = $policy['targets'][$id] ?? null;
            $raw = $stored['targets'][$id] ?? null;
            if (! is_array($spec) || $target === null || ! is_array($raw)
                || ! is_int($raw['startup_timeout_seconds'] ?? null)
                || $raw['startup_timeout_seconds'] !== $target['startup_timeout_seconds']
                || ! is_bool($raw['switch_budget_exempt'] ?? null)) {
                throw new RuntimeException('Lifecycle target requires an exact table-owned control binding.');
            }
            $spec['startup_timeout_seconds'] = $raw['startup_timeout_seconds'];
            unset($spec['verification_timeout_seconds']);
            if (array_key_exists('verification_timeout_seconds', $target)) {
                $spec['verification_timeout_seconds'] = $target['verification_timeout_seconds'];
            }
            $spec['switch_budget_exempt'] = $raw['switch_budget_exempt'];
            $adapter = $this->lifecycleAdapter($raw['lifecycle_adapter'] ?? null);
            foreach ($adapter as $field => $value) {
                $spec[$field] = $value;
            }
        }
        unset($spec);

        return $manifest;
    }

    /** Adapter capability names select existing code; model policy stays in table data. */
    private function lifecycleAdapter(mixed $value): array
    {
        $fields = ['adapter_type', 'control_roots', 'required_process_arguments', 'resource_mode'];
        if (is_array($value) && ($value['adapter_type'] ?? null) === 'authenticated_image') {
            $fields = array_merge($fields, ['interpreter_path', 'interpreter_sha256']);
        }
        if (is_array($value) && ($value['adapter_type'] ?? null) === 'colibri_gateway' && array_key_exists('native_tools_enabled', $value)) {
            if (! is_bool($value['native_tools_enabled'])) {
                throw new RuntimeException('Colibri tool capability must be boolean.');
            }
            $fields[] = 'native_tools_enabled';
        }
        if (! is_array($value)
            || count($value) !== count($fields)
            || array_diff(array_keys($value), $fields) !== []
            || ! in_array($value['adapter_type'] ?? null, ['pinned_llama', 'ollama_coex', 'scheduled_llama', 'scheduled_process_group', 'colibri_gateway', 'authenticated_image'], true)
            || ! in_array($value['resource_mode'] ?? null, ['full', 'runtime_managed', 'ram_commit_c'], true)) {
            throw new RuntimeException('Lifecycle adapter registration is missing or invalid.');
        }
        if ($value['adapter_type'] === 'authenticated_image'
            && (! is_string($value['interpreter_path'] ?? null)
                || preg_match('/\A[A-Za-z]:\\\\[^\r\n]+\z/', $value['interpreter_path']) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $value['interpreter_sha256'] ?? '') !== 1)) {
            throw new RuntimeException('Image interpreter registration is invalid.');
        }
        foreach (['control_roots', 'required_process_arguments'] as $field) {
            if (! is_array($value[$field] ?? null)) {
                throw new RuntimeException('Lifecycle adapter parameters must be maps.');
            }
            foreach ($value[$field] as $key => $parameter) {
                $pattern = $field === 'control_roots' ? '/\A[A-Z][A-Za-z0-9]*Root\z/' : '/\A--[a-z][a-z0-9-]*\z/';
                if (! is_string($key) || preg_match($pattern, $key) !== 1
                    || ! is_string($parameter) || $parameter === '' || preg_match('/[\x00-\x1f\x7f]/', $parameter)
                    || ($field === 'control_roots' && preg_match('/\A[A-Za-z]:\\\\/', $parameter) !== 1)) {
                    throw new RuntimeException('Lifecycle adapter contains an invalid parameter.');
                }
            }
            // Preserve map shape across the PHP-to-PowerShell JSON boundary, including empty maps.
            $value[$field] = (object) $value[$field];
        }
        if (in_array($value['adapter_type'], ['pinned_llama', 'ollama_coex'], true)
            && count((array) $value['control_roots']) === 0) {
            throw new RuntimeException('Pinned-control adapter requires explicit roots.');
        }

        return $value;
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function normalize(array $policy): array
    {
        if (($policy['schema'] ?? null) !== 'plos.model_host_controller_policy.v1') {
            throw new RuntimeException('Model-host controller policy schema is unavailable or unsupported.');
        }

        $hostId = trim((string) ($policy['host_id'] ?? ''));
        $expectedHostId = $this->localHostIdentity !== null
            ? ($this->localHostIdentity)()
            : app(SystemConfigService::class)->llmRoutingValue('controller_transport', 'expected_host_id');
        if ($hostId === '' || $hostId !== $expectedHostId) {
            throw new RuntimeException('Model-host controller policy has the wrong host identity.');
        }
        if (($policy['execution_mode'] ?? null) !== 'serial') {
            throw new RuntimeException('Only serial model-host execution is currently admitted.');
        }
        if (($policy['concurrent_inference_enabled'] ?? true) !== false) {
            throw new RuntimeException('Concurrent model-host inference is not admitted.');
        }

        $targets = $this->map($policy['targets'] ?? null);
        if ($targets === []) {
            throw new RuntimeException('Model-host controller policy has no exact targets.');
        }

        $normalizedTargets = [];
        foreach ($targets as $targetId => $target) {
            if (! is_string($targetId) || preg_match('/\A[a-z0-9][a-z0-9_.:-]{2,63}\z/', $targetId) !== 1) {
                throw new RuntimeException('Model-host controller policy contains an invalid target identity.');
            }
            $target = $this->map($target);
            $state = (string) ($target['state'] ?? '');
            if (! in_array($state, ['production', 'staged', 'storage_absent', 'research_retained'], true)) {
                throw new RuntimeException("Model-host target {$targetId} has an invalid state.");
            }
            $roles = array_values(array_unique(array_filter(
                (array) ($target['roles'] ?? []),
                static fn (mixed $role): bool => is_string($role)
                    && preg_match('/\A[a-z][a-z0-9_]{1,47}\z/', $role) === 1
            )));
            if ($roles === []) {
                throw new RuntimeException("Model-host target {$targetId} has no admitted roles.");
            }
            $activationAllowed = ($target['activation_allowed'] ?? false) === true;
            if ($activationAllowed && ! in_array($state, ['production', 'staged'], true)) {
                throw new RuntimeException("Model-host target {$targetId} cannot activate from state {$state}.");
            }
            $normalizedTargets[$targetId] = [
                'model' => trim((string) ($target['model'] ?? '')),
                'engine' => trim((string) ($target['engine'] ?? '')),
                'state' => $state,
                'activation_allowed' => $activationAllowed,
                'requires_operator_override' => ($target['requires_operator_override'] ?? true) === true,
                'roles' => $roles,
                'resource_group' => trim((string) ($target['resource_group'] ?? '')),
                'startup_timeout_seconds' => $this->boundedInt($target['startup_timeout_seconds'] ?? null, 15, 7200, 300),
                'idle_ttl_seconds' => $this->boundedInt($target['idle_ttl_seconds'] ?? null, 0, 86400, 0),
            ];
            if (array_key_exists('verification_timeout_seconds', $target)) {
                $verification = $target['verification_timeout_seconds'];
                if (! in_array($target['lifecycle_adapter']['adapter_type'] ?? null, ['scheduled_llama', 'scheduled_process_group'], true)
                    || ! is_int($verification) || $verification < 1 || $verification > 7200) {
                    throw new RuntimeException('Verification requires an explicit scheduled-launch budget.');
                }
                $normalizedTargets[$targetId]['verification_timeout_seconds'] = $verification;
                $normalizedTargets[$targetId]['lifecycle_adapter'] = $target['lifecycle_adapter'];
            }
            if ($normalizedTargets[$targetId]['model'] === ''
                || $normalizedTargets[$targetId]['engine'] === ''
                || $normalizedTargets[$targetId]['resource_group'] === '') {
                throw new RuntimeException("Model-host target {$targetId} is incomplete.");
            }
        }

        $defaultTarget = trim((string) ($policy['default_target'] ?? ''));
        if (! isset($normalizedTargets[$defaultTarget])
            || $normalizedTargets[$defaultTarget]['state'] !== 'production'
            || ! $normalizedTargets[$defaultTarget]['activation_allowed']) {
            throw new RuntimeException('Model-host controller default target is not production-admitted.');
        }

        return [
            'schema' => 'plos.model_host_controller_policy.v1',
            'revision' => trim((string) ($policy['revision'] ?? '')),
            'enabled' => ($policy['enabled'] ?? false) === true,
            'automatic_activation_enabled' => ($policy['automatic_activation_enabled'] ?? false) === true,
            'compatible_residency_enabled' => ($policy['compatible_residency_enabled'] ?? false) === true,
            'concurrent_inference_enabled' => false,
            'execution_mode' => 'serial',
            'host_id' => $hostId,
            'default_target' => $defaultTarget,
            'lease_ttl_seconds' => $this->boundedInt($policy['lease_ttl_seconds'] ?? null, 60, 86400, 7200),
            'max_switches_per_hour' => $this->boundedInt($policy['max_switches_per_hour'] ?? null, 1, 24, 4),
            'targets' => $normalizedTargets,
        ];
    }

    /** @return array<string,mixed> */
    private function storedPolicy(): array
    {
        if ($this->localPolicy !== null) {
            $value = ($this->localPolicy)();

            return is_array($value) ? $value : $this->map($value);
        }
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs
                 WHERE section = ? AND config_key = ? LIMIT 1',
                [self::CONFIG_SECTION, self::CONFIG_KEY]
            );
            $decoded = json_decode((string) ($row->config_value ?? ''), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
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

    private function boundedInt(mixed $value, int $minimum, int $maximum, int $default): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/\A\d+\z/', $value) === 1)) {
            return $default;
        }

        return max($minimum, min($maximum, (int) $value));
    }
}
