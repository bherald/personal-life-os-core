<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ModelHostControllerCanaryPolicyService
{
    private const REVISION = 'MHC-05-inactive-manual';

    public const CONFIG_SECTION = 'ai';

    public const CONFIG_KEY = 'model_host_controller_canary_policy';

    /** @var array<string,array{provider_instance_id:string,model:string,engine:string,role:string,adapter:string}> */
    private const TARGETS = [
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
    ];

    /** @return array<string,mixed> */
    public function required(): array
    {
        if (! Schema::hasTable('system_configs')) {
            throw new RuntimeException('MHC-05 canary policy table is unavailable.');
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
        if (($policy['schema'] ?? null) !== 'plos.model_host_controller_canary_policy.v1'
            || ($policy['revision'] ?? null) !== self::REVISION
            || ($policy['enabled'] ?? false) !== true
            || ($policy['automatic_selection_enabled'] ?? true) !== false
            || ($policy['normal_routing'] ?? true) !== false
            || ($policy['execution_mode'] ?? null) !== 'serial'
            || ($policy['tools_enabled'] ?? true) !== false
            || ($policy['fallback_enabled'] ?? true) !== false
            || ($policy['queue_preservation_required'] ?? false) !== true
            || ($policy['content_free'] ?? false) !== true
            || (int) ($policy['max_tokens'] ?? 0) !== 128
            || (int) ($policy['lock_ttl_seconds'] ?? 0) !== 900
        ) {
            throw new RuntimeException('MHC-05 canary policy is unavailable or unsafe.');
        }

        $targets = $policy['targets'] ?? null;
        if (! is_array($targets) || array_keys($targets) !== array_keys(self::TARGETS)) {
            throw new RuntimeException('MHC-05 canary target catalog is not exact.');
        }

        foreach (self::TARGETS as $targetId => $expected) {
            $actual = $targets[$targetId] ?? null;
            if (! is_array($actual)) {
                throw new RuntimeException("MHC-05 target {$targetId} is unavailable.");
            }
            foreach ($expected as $field => $value) {
                if (($actual[$field] ?? null) !== $value) {
                    throw new RuntimeException("MHC-05 target {$targetId} has drifted.");
                }
            }
        }

        return [
            'schema' => 'plos.model_host_controller_canary_policy.v1',
            'revision' => self::REVISION,
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
            'targets' => $targets,
        ];
    }

    /** @return array<string,array{provider_instance_id:string,model:string,engine:string,role:string,adapter:string}> */
    public function targetCatalog(): array
    {
        return self::TARGETS;
    }
}
