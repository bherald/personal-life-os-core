<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $key = ['section' => 'weather', 'config_key' => 'node_runtime_policy'];
            $row = DB::table('system_configs')->where($key)->lockForUpdate()->first();
            $existing = $row ? json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR) : [];
            if (! is_array($existing)) {
                throw new RuntimeException('Weather runtime policy must be a JSON object');
            }
            $policy = array_replace([
                'observation_stale_after_seconds' => 3600,
                'observation_future_tolerance_seconds' => 300,
            ], $existing);
            $values = ['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($row) {
                DB::table('system_configs')->where($key)->update($values);
            } else {
                DB::table('system_configs')->insert([...$key, ...$values, 'data_type' => 'json',
                    'description' => 'WeatherAPI observation validation and freshness policy.', 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Preserve operator values. Prior application code ignores these additive keys.
    }
};
