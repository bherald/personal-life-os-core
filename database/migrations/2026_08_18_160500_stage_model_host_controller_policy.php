<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $exists = DB::table('system_configs')
            ->where('section', 'ai')
            ->where('config_key', 'model_host_controller_policy')
            ->exists();
        if (! $exists) {
            DB::table('system_configs')->insert([
                'section' => 'ai',
                'config_key' => 'model_host_controller_policy',
                'config_value' => json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'data_type' => 'json',
                'description' => 'Disabled-by-default .87 model lifecycle policy; host safety remains authoritative.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')
                ->where('section', 'ai')
                ->where('config_key', 'model_host_controller_policy')
                ->where('config_value', json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
                ->delete();
        }
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.model_host_controller_policy.v1',
            'revision' => 'MHC-01-staged',
            'enabled' => false,
            'automatic_activation_enabled' => false,
            'compatible_residency_enabled' => false,
            'concurrent_inference_enabled' => false,
            'execution_mode' => 'serial',
            'host_id' => '.87',
            'default_target' => 'qwen38_87',
            'lease_ttl_seconds' => 7200,
            'max_switches_per_hour' => 4,
            'targets' => [
                'qwen38_87' => [
                    'model' => 'qwen3.8-27b-q4_k_m',
                    'engine' => 'llama.cpp',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'requires_operator_override' => false,
                    'roles' => ['standard', 'structured_output', 'deep_offline'],
                    'resource_group' => 'host87_inference',
                    'startup_timeout_seconds' => 300,
                    'idle_ttl_seconds' => 0,
                ],
                'ollama_qwen3_8b_87' => [
                    'model' => 'qwen3:8b',
                    'engine' => 'ollama',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'requires_operator_override' => false,
                    'roles' => ['fast', 'standard', 'structured_output'],
                    'resource_group' => 'host87_inference',
                    'startup_timeout_seconds' => 180,
                    'idle_ttl_seconds' => 1800,
                ],
                'bitnet_87' => [
                    'model' => 'bitnet-b1.58-2b-4t-i2s',
                    'engine' => 'bitnet.cpp',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'requires_operator_override' => false,
                    'roles' => ['privacy_deny_prefilter'],
                    'resource_group' => 'host87_auxiliary_cpu',
                    'startup_timeout_seconds' => 120,
                    'idle_ttl_seconds' => 1800,
                ],
                'glm52_87' => [
                    'model' => 'glm-5.2-colibri',
                    'engine' => 'colibri',
                    'state' => 'storage_absent',
                    'activation_allowed' => false,
                    'requires_operator_override' => true,
                    'roles' => ['deep_offline', 'research', 'synthesis'],
                    'resource_group' => 'host87_inference',
                    'startup_timeout_seconds' => 1800,
                    'idle_ttl_seconds' => 3600,
                ],
                'kimi_k3_87' => [
                    'model' => 'kimi-k3-colibri',
                    'engine' => 'colibri-k3',
                    'state' => 'research_retained',
                    'activation_allowed' => false,
                    'requires_operator_override' => true,
                    'roles' => ['ultra_deep', 'research', 'archival'],
                    'resource_group' => 'host87_inference',
                    'startup_timeout_seconds' => 1800,
                    'idle_ttl_seconds' => 3600,
                ],
            ],
        ];
    }
};
