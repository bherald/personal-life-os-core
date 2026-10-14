<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Exact, explicit-only policy boundary for the retained K3 lane.
 *
 * This policy is deliberately separate from the normal model-host policy:
 * K3 must never become a default or fallback target by being added here.
 */
class ModelHostControllerMhc08K3PolicyService
{
    public const CONFIG_SECTION = 'ai';

    public const CONFIG_KEY = 'model_host_controller_mhc08_k3_policy';

    public const INACTIVE_REVISION = 'MHC-08-k3-inactive-v1';

    public const ACCEPTANCE_REVISION = 'MHC-08-k3-acceptance-v1';

    public const EXPLICIT_REVISION = 'MHC-08-k3-explicit-v1';

    public const DRAINING_REVISION = 'MHC-08-k3-draining-v1';

    /** @return array<string,mixed> */
    public function required(): array
    {
        if (! Schema::hasTable('system_configs')) {
            throw new RuntimeException('MHC-08 K3 policy table is unavailable.');
        }

        $row = DB::table('system_configs')
            ->where('section', self::CONFIG_SECTION)
            ->where('config_key', self::CONFIG_KEY)
            ->first(['config_value']);
        $policy = json_decode((string) ($row->config_value ?? ''), true);

        return $this->normalize(is_array($policy) ? $policy : []);
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function normalize(array $policy): array
    {
        $expectedKeys = [
            'schema', 'revision', 'enabled', 'automatic_selection_enabled', 'normal_routing',
            'execution_mode', 'concurrent_inference_enabled', 'tools_enabled', 'fallback_enabled',
            'queue_on_failure', 'max_concurrent', 'max_tokens', 'lease_ttl_seconds',
            'startup_timeout_seconds', 'roles', 'target',
        ];
        $actualKeys = array_keys($policy);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new RuntimeException('MHC-08 K3 policy keys are not exact.');
        }

        $revision = $policy['revision'] ?? null;
        $enabled = $policy['enabled'] ?? null;
        $expectedEnabled = match ($revision) {
            self::INACTIVE_REVISION => false,
            self::DRAINING_REVISION => false,
            self::ACCEPTANCE_REVISION => true,
            self::EXPLICIT_REVISION => true,
            default => null,
        };
        if (($policy['schema'] ?? null) !== 'plos.model_host_controller.mhc08_k3_policy.v1'
            || $expectedEnabled === null
            || $enabled !== $expectedEnabled
            || ($policy['automatic_selection_enabled'] ?? true) !== false
            || ($policy['normal_routing'] ?? true) !== false
            || ($policy['execution_mode'] ?? null) !== 'serial'
            || ($policy['concurrent_inference_enabled'] ?? true) !== false
            || ($policy['tools_enabled'] ?? true) !== false
            || ($policy['fallback_enabled'] ?? true) !== false
            || ($policy['queue_on_failure'] ?? false) !== true
            || (int) ($policy['max_concurrent'] ?? 0) !== 1
            || (int) ($policy['max_tokens'] ?? 0) !== 128
            || (int) ($policy['lease_ttl_seconds'] ?? 0) !== 25200
            || (int) ($policy['startup_timeout_seconds'] ?? 0) !== 1800
        ) {
            throw new RuntimeException('MHC-08 K3 policy is unavailable or unsafe.');
        }

        if (($policy['roles'] ?? null) !== ['ultra_deep', 'archival']) {
            throw new RuntimeException('MHC-08 K3 role catalog is not exact.');
        }

        $target = $policy['target'] ?? null;
        $expectedTarget = [
            'target_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'provider_instance_id' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'instance_id'),
            'provider_model_role' => 'deep_offline',
            'model' => app(SystemConfigService::class)->llmRoutingValue('mhc08_k3', 'model'),
            'engine' => 'colibri-k3',
            'requires_explicit_target' => true,
        ];
        $targetKeys = is_array($target) ? array_keys($target) : [];
        $expectedTargetKeys = array_keys($expectedTarget);
        sort($targetKeys);
        sort($expectedTargetKeys);
        if ($targetKeys !== $expectedTargetKeys || $target !== $expectedTarget) {
            throw new RuntimeException('MHC-08 K3 target contract is not exact.');
        }

        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_policy.v1',
            'revision' => $revision,
            'enabled' => $enabled,
            'automatic_selection_enabled' => false,
            'normal_routing' => false,
            'execution_mode' => 'serial',
            'concurrent_inference_enabled' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_on_failure' => true,
            'max_concurrent' => 1,
            'max_tokens' => 128,
            'lease_ttl_seconds' => 25200,
            'startup_timeout_seconds' => 1800,
            'roles' => ['ultra_deep', 'archival'],
            'target' => $expectedTarget,
        ];
    }

    /** @param array<string,mixed> $policy */
    public function assertExplicitRole(string $role, array $policy): void
    {
        if (($policy['enabled'] ?? false) !== true
            || ($policy['revision'] ?? null) !== self::EXPLICIT_REVISION
            || ! in_array($role, $policy['roles'] ?? [], true)) {
            throw new RuntimeException('MHC-08 K3 explicit admission is unavailable for this role.');
        }
    }

    /** @param array<string,mixed> $policy */
    public function assertAcceptanceRole(string $role, array $policy): void
    {
        if (($policy['enabled'] ?? false) !== true
            || ($policy['revision'] ?? null) !== self::ACCEPTANCE_REVISION
            || ! in_array($role, $policy['roles'] ?? [], true)) {
            throw new RuntimeException('MHC-08 K3 acceptance admission is unavailable for this role.');
        }
    }
}
