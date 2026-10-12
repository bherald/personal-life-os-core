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
        DB::table('system_configs')->insertOrIgnore([
            'section' => 'ai', 'config_key' => 'model_host_controller_resource_policy',
            'config_value' => $this->value(), 'data_type' => 'json',
            'description' => 'Table-owned model capacity profiles; staged disabled until exact host/model qualification. Temperature is observational only.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_resource_policy')
                ->where('config_value', $this->value())->delete();
        }
    }

    private function value(): string
    {
        return json_encode([
            'schema' => 'plos.model_host_controller.resource_policy.v1',
            'revision' => 'hybrid-resource-stage-1', 'enabled' => false,
            'temperature_mode' => 'observation_only',
            'maximum_sample_age_ms' => 30000, 'maximum_future_skew_ms' => 1000,
            'profiles' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
