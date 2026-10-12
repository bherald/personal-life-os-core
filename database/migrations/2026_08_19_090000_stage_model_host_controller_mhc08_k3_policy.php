<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_mhc08_k3_policy';

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
            if (! hash_equals(hash('sha256', $value), hash('sha256', (string) $existing))) {
                throw new RuntimeException('MHC-08 K3 policy already exists with different authority.');
            }

            return;
        }

        DB::table('system_configs')->insert([
            'section' => self::SECTION,
            'config_key' => self::KEY,
            'config_value' => $value,
            'data_type' => 'json',
            'description' => 'Inactive explicit-only MHC-08 K3 policy; completed MHC-06 soak and a separate promotion migration are required.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $value = json_encode($this->policy(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->where('config_value', $value)
            ->delete();
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'schema' => 'plos.model_host_controller.mhc08_k3_policy.v1',
            'revision' => 'MHC-08-k3-inactive-v1',
            'enabled' => false,
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
            'target' => [
                'target_id' => 'kimi_k3_87',
                'provider_instance_id' => 'kimi_k3_87',
                'provider_model_role' => 'deep_offline',
                'model' => 'kimi-k3-colibri',
                'engine' => 'colibri-k3',
                'requires_explicit_target' => true,
            ],
        ];
    }
};
