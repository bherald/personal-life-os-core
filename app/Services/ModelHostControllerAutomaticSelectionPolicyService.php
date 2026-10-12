<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Table-owned MHC-06 policy. This is deliberately separate from the manual
 * canary catalog and does not alter normal PLOS routing.
 */
class ModelHostControllerAutomaticSelectionPolicyService
{
    public const CONFIG_SECTION = 'ai';

    public const CONFIG_KEY = 'model_host_controller_automatic_selection_policy';

    public const INACTIVE_REVISION = 'MHC-06-inactive-automatic';

    public const ACTIVE_REVISION = 'MHC-06-automatic-v1';

    /** @return array<string,mixed> */
    public function required(): array
    {
        return $this->normalize($this->storedPolicy());
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function normalize(array $policy): array
    {
        $this->assertExactKeys($policy, [
            'schema', 'revision', 'enabled', 'automatic_selection_enabled', 'normal_routing', 'execution_mode',
            'concurrent_inference_enabled', 'tools_enabled', 'fallback_enabled', 'queue_on_failure',
            'max_batch_size', 'heartbeat_interval_seconds', 'recovery_after_seconds', 'lock_ttl_seconds',
            'max_switches_per_hour', 'role_targets', 'targets',
            ...(array_key_exists('heartbeat_timeout_seconds', $policy) ? ['heartbeat_timeout_seconds'] : []),
        ], 'policy');
        if (($policy['schema'] ?? null) !== 'plos.model_host_controller.automatic_selection_policy.v1'
            || ! is_string($policy['revision'] ?? null) || trim($policy['revision']) === ''
            || preg_match('/[\x00-\x1f\x7f]/', $policy['revision'])
            || ! is_bool($policy['enabled'] ?? null) || ! is_bool($policy['automatic_selection_enabled'] ?? null)) {
            throw new RuntimeException('MHC-06 automatic selection policy is unavailable or unsafe.');
        }
        if ($policy['enabled'] !== $policy['automatic_selection_enabled']
            || ($policy['revision'] === self::INACTIVE_REVISION && $policy['enabled'])
            || ($policy['revision'] === self::ACTIVE_REVISION && ! $policy['enabled'])) {
            throw new RuntimeException('MHC-06 automatic selection revision and enablement disagree.');
        }
        foreach (['execution_mode' => 'serial', 'concurrent_inference_enabled' => false,
            'tools_enabled' => false, 'fallback_enabled' => false, 'normal_routing' => false,
            'queue_on_failure' => true] as $key => $expected) {
            if (($policy[$key] ?? null) !== $expected) {
                throw new RuntimeException("MHC-06 automatic policy requires {$key}={$expected}.");
            }
        }
        foreach (['max_batch_size', 'heartbeat_interval_seconds', 'recovery_after_seconds', 'lock_ttl_seconds'] as $key) {
            $this->integer($policy[$key] ?? null, $key);
        }
        if (array_key_exists('heartbeat_timeout_seconds', $policy)) {
            $this->integer($policy['heartbeat_timeout_seconds'], 'heartbeat_timeout_seconds');
        }
        $this->integer($policy['max_switches_per_hour'] ?? null, 'max_switches_per_hour', true);
        if (! is_array($policy['targets'] ?? null) || $policy['targets'] === []) {
            throw new RuntimeException('MHC-06 automatic selection has no target catalog.');
        }
        foreach ($policy['targets'] as $targetId => $target) {
            $this->identity($targetId, 'target');
            if (! is_array($target)) {
                throw new RuntimeException('MHC-06 target is invalid.');
            }
            $this->assertExactKeys($target, ['provider_instance_id', 'provider_model_role', 'model', 'engine',
                'state', 'activation_allowed', 'roles', 'idle_ttl_seconds', 'inference_adapter', 'inference_limits'], "target {$targetId}");
            $this->identity($target['provider_instance_id'], 'provider');
            $this->identity($target['provider_model_role'], 'role');
            foreach (['model', 'engine'] as $key) {
                if (! is_string($target[$key]) || trim($target[$key]) === ''
                    || trim($target[$key]) !== $target[$key] || preg_match('/[\x00-\x1f\x7f]/', $target[$key])) {
                    throw new RuntimeException("MHC-06 target {$targetId} has invalid {$key}.");
                }
            }
            if ($target['state'] !== 'production' || $target['activation_allowed'] !== true
                || ! is_array($target['roles']) || ! array_is_list($target['roles']) || $target['roles'] === []
                || count(array_unique($target['roles'], SORT_REGULAR)) !== count($target['roles'])) {
                throw new RuntimeException("MHC-06 target {$targetId} is not admitted for automatic execution.");
            }
            foreach ($target['roles'] as $role) {
                $this->identity($role, 'role');
            }
            $this->integer($target['idle_ttl_seconds'], 'idle_ttl_seconds', true);
            if (! in_array($target['inference_adapter'], ['ollama', 'openai_compatible', 'privacy_gate'], true)
                || ! is_array($target['inference_limits'])) {
                throw new RuntimeException('MHC-06 inference adapter or limits are invalid.');
            }
            $limits = $target['inference_limits'];
            $this->assertExactKeys($limits, ['default_max_tokens', 'max_tokens', 'privacy_timeout_seconds'], 'inference limits');
            foreach ($limits as $key => $value) {
                $this->integer($value, $key);
            }
            if ($limits['default_max_tokens'] > $limits['max_tokens']) {
                throw new RuntimeException('MHC-06 default output exceeds the table-owned limit.');
            }
        }
        if (! is_array($policy['role_targets'] ?? null) || $policy['role_targets'] === []) {
            throw new RuntimeException('MHC-06 automatic selection has no role map.');
        }
        foreach ($policy['role_targets'] as $role => $target) {
            $this->identity($role, 'role');
            if (! is_string($target) || ! isset($policy['targets'][$target])
                || ! in_array($role, $policy['targets'][$target]['roles'], true)) {
                throw new RuntimeException('MHC-06 role map names an unadmitted target or role.');
            }
        }

        return $policy;
    }

    private function integer(mixed $value, string $field, bool $zeroAllowed = false): void
    {
        if (! is_int($value) || $value < ($zeroAllowed ? 0 : 1)) {
            throw new RuntimeException("MHC-06 {$field} requires an explicit table-owned integer.");
        }
    }

    private function identity(mixed $value, string $kind): void
    {
        $pattern = $kind === 'role' ? '/\A[a-z][a-z0-9_]{1,47}\z/' : '/\A[a-z0-9][a-z0-9_.:-]{2,63}\z/';
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new RuntimeException("MHC-06 {$kind} identity is invalid.");
        }
    }

    /** @return array<string,mixed> */
    private function storedPolicy(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                [self::CONFIG_SECTION, self::CONFIG_KEY]
            );
            $decoded = json_decode((string) ($row->config_value ?? ''), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private function assertExactKeys(array $value, array $expected, string $name): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException("MHC-06 automatic selection {$name} fields are inexact.");
        }
    }
}
