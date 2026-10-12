<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_automatic_selection_policy';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $value = json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $existing = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->value('config_value');
        if ($existing !== null) {
            if ($existing !== $value) {
                throw new RuntimeException('MHC-06 automatic selection policy already exists with different authority.');
            }

            return;
        }

        DB::table('system_configs')->insert([
            'section' => self::SECTION,
            'config_key' => self::KEY,
            'config_value' => $value,
            'data_type' => 'json',
            'description' => 'Inactive MHC-06 automatic serial selection; primary live gate required before activation.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->delete();
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.model_host_controller.automatic_selection_policy.v1',
            'revision' => 'MHC-06-inactive-automatic',
            'enabled' => false,
            'automatic_selection_enabled' => false,
            'normal_routing' => false,
            'execution_mode' => 'serial',
            'concurrent_inference_enabled' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_on_failure' => true,
            'max_batch_size' => 3,
            'heartbeat_interval_seconds' => 15,
            'recovery_after_seconds' => 900,
            'lock_ttl_seconds' => 900,
            'max_switches_per_hour' => 4,
            'role_targets' => [
                'fast' => 'ollama_qwen3_8b_87',
                'standard' => 'qwen38_87',
                'structured_output' => 'qwen38_87',
                'deep_offline' => 'qwen38_87',
                'privacy_deny_prefilter' => 'bitnet_87',
            ],
            'targets' => [
                'qwen38_87' => [
                    'provider_instance_id' => 'qwen38_87',
                    'model' => 'qwen3.8-27b-q4_k_m',
                    'engine' => 'llama.cpp',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'roles' => ['standard', 'structured_output', 'deep_offline'],
                    'idle_ttl_seconds' => 0,
                ],
                'ollama_qwen3_8b_87' => [
                    'provider_instance_id' => 'ollama_secondary',
                    'model' => 'qwen3:8b',
                    'engine' => 'ollama',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'roles' => ['fast', 'standard', 'structured_output'],
                    'idle_ttl_seconds' => 1800,
                ],
                'bitnet_87' => [
                    'provider_instance_id' => 'bitnet_87',
                    'model' => 'bitnet-b1.58-2b-4t-i2s',
                    'engine' => 'bitnet.cpp',
                    'state' => 'production',
                    'activation_allowed' => true,
                    'roles' => ['privacy_deny_prefilter'],
                    'idle_ttl_seconds' => 1800,
                ],
            ],
        ];
    }
};
