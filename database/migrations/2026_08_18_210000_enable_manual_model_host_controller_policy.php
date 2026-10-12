<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_policy';

    private const CANARY_KEY = 'model_host_controller_canary_policy';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::transaction(function (): void {
            $row = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->lockForUpdate()
                ->first();
            $policy = $this->decode($row->config_value ?? null);
            $this->assertPolicy($policy, 'MHC-01-staged', false);

            $policy['revision'] = 'MHC-05-manual';
            $policy['enabled'] = true;
            $policy['automatic_activation_enabled'] = false;

            DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->update([
                    'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'description' => 'Manual-only .87 model lifecycle policy; automatic selection remains disabled.',
                    'updated_at' => now(),
                ]);

            $canaryPolicy = $this->canaryPolicy();
            $existingCanary = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::CANARY_KEY)
                ->lockForUpdate()
                ->value('config_value');
            if ($existingCanary !== null) {
                $existing = $this->decode($existingCanary);
                if ($existing !== $canaryPolicy) {
                    throw new RuntimeException('Manual model-host canary policy already exists with different authority.');
                }
            } else {
                DB::table('system_configs')->insert([
                    'section' => self::SECTION,
                    'config_key' => self::CANARY_KEY,
                    'config_value' => json_encode($canaryPolicy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'data_type' => 'json',
                    'description' => 'Explicit-only MHC-05 canaries; normal routing and automatic selection remain disabled.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::transaction(function (): void {
            $row = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->lockForUpdate()
                ->first();
            $policy = $this->decode($row->config_value ?? null);
            $this->assertPolicy($policy, 'MHC-05-manual', true);

            $policy['revision'] = 'MHC-01-staged';
            $policy['enabled'] = false;
            $policy['automatic_activation_enabled'] = false;

            DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::KEY)
                ->update([
                    'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'description' => 'Disabled-by-default .87 model lifecycle policy; host safety remains authoritative.',
                    'updated_at' => now(),
                ]);

            $deleted = DB::table('system_configs')
                ->where('section', self::SECTION)
                ->where('config_key', self::CANARY_KEY)
                ->where('config_value', json_encode($this->canaryPolicy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                ->delete();
            if ($deleted !== 1) {
                throw new RuntimeException('Manual model-host canary policy rollback found missing or drifted authority.');
            }
        });
    }

    /** @return array<string,mixed> */
    private function decode(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $policy */
    private function assertPolicy(array $policy, string $revision, bool $enabled): void
    {
        $targets = is_array($policy['targets'] ?? null) ? $policy['targets'] : [];
        $expectedTargets = [
            'qwen38_87' => ['qwen3.8-27b-q4_k_m', 'llama.cpp', true, ['standard', 'structured_output', 'deep_offline']],
            'ollama_qwen3_8b_87' => ['qwen3:8b', 'ollama', true, ['fast', 'standard', 'structured_output']],
            'bitnet_87' => ['bitnet-b1.58-2b-4t-i2s', 'bitnet.cpp', true, ['privacy_deny_prefilter']],
            'glm52_87' => ['glm-5.2-colibri', 'colibri', false, ['deep_offline', 'research', 'synthesis']],
            'kimi_k3_87' => ['kimi-k3-colibri', 'colibri-k3', false, ['ultra_deep', 'research', 'archival']],
        ];

        if (($policy['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
            || ($policy['revision'] ?? null) !== $revision
            || ($policy['enabled'] ?? null) !== $enabled
            || ($policy['automatic_activation_enabled'] ?? null) !== false
            || ($policy['compatible_residency_enabled'] ?? null) !== false
            || ($policy['concurrent_inference_enabled'] ?? null) !== false
            || ($policy['execution_mode'] ?? null) !== 'serial'
            || ($policy['host_id'] ?? null) !== '.87'
            || ($policy['default_target'] ?? null) !== 'qwen38_87'
            || (int) ($policy['lease_ttl_seconds'] ?? 0) !== 7200
            || (int) ($policy['max_switches_per_hour'] ?? 0) !== 4
            || array_keys($targets) !== array_keys($expectedTargets)) {
            throw new RuntimeException('Manual model-host policy requires the exact preceding guarded policy.');
        }

        foreach ($expectedTargets as $targetId => [$model, $engine, $allowed, $roles]) {
            $target = is_array($targets[$targetId] ?? null) ? $targets[$targetId] : [];
            if (($target['model'] ?? null) !== $model
                || ($target['engine'] ?? null) !== $engine
                || ($target['activation_allowed'] ?? null) !== $allowed
                || ! is_array($target['roles'] ?? null)
                || array_values($target['roles']) !== $roles) {
                throw new RuntimeException('Manual model-host policy target identities have drifted.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function canaryPolicy(): array
    {
        return [
            'schema' => 'plos.model_host_controller_canary_policy.v1',
            'revision' => 'MHC-05-inactive-manual',
            'enabled' => true,
            'automatic_selection_enabled' => false,
            'normal_routing' => false,
            'execution_mode' => 'serial',
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_preservation_required' => true,
            'content_free' => true,
            'max_tokens' => 128,
            'lock_ttl_seconds' => 900,
            'targets' => [
                'qwen38_87' => [
                    'provider_instance_id' => 'qwen38_87',
                    'model' => 'qwen3.8-27b-q4_k_m',
                    'engine' => 'llama.cpp',
                    'role' => 'deep_offline',
                    'adapter' => 'qwen',
                ],
                'ollama_qwen3_8b_87' => [
                    'provider_instance_id' => 'ollama_secondary',
                    'model' => 'qwen3:8b',
                    'engine' => 'ollama',
                    'role' => 'standard',
                    'adapter' => 'ollama',
                ],
                'bitnet_87' => [
                    'provider_instance_id' => 'bitnet_87',
                    'model' => 'bitnet-b1.58-2b-4t-i2s',
                    'engine' => 'bitnet.cpp',
                    'role' => 'privacy_deny_prefilter',
                    'adapter' => 'bitnet',
                ],
            ],
        ];
    }
};
