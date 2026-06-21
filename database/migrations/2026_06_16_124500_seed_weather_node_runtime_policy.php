<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function policy(): array
    {
        return [
            'connect_timeout_seconds' => 5,
            'request_timeout_seconds' => 15,
            'policy_notes' => [
                'connect_timeout_seconds' => 'Default connect timeout for WeatherAPI NWS and OpenWeather requests.',
                'request_timeout_seconds' => 'Default total request timeout for WeatherAPI NWS and OpenWeather requests.',
                'node_config_owner' => 'Explicit workflow node config still wins for matching timeout keys.',
                'provider_owner' => 'Provider, fallback provider, API URLs, API keys, user agent, and forecast_days remain owned by workflow node config and services.weather config.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'weather')
            ->where('config_key', 'node_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $defaultPolicy = $this->policy();
        $policy = array_replace($defaultPolicy, $existing);
        if (isset($existing['policy_notes']) && is_array($existing['policy_notes'])) {
            $policy['policy_notes'] = array_replace(
                $defaultPolicy['policy_notes'],
                $existing['policy_notes']
            );
        } else {
            $policy['policy_notes'] = $defaultPolicy['policy_notes'];
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'weather', 'config_key' => 'node_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for WeatherAPI node HTTP timeout defaults.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')
            ->where('section', 'weather')
            ->where('config_key', 'node_runtime_policy')
            ->delete();
    }
};
