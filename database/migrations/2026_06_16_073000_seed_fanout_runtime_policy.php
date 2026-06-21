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
            'max_parallel' => 10,
            'http_pool_timeout_seconds' => 30,
            'policy_notes' => [
                'max_parallel' => 'Default FanOutNode branch cap when workflow node config does not provide max_parallel.',
                'http_pool_timeout_seconds' => 'Default timeout for FanOutNode Http::pool requests when workflow node config does not provide http_timeout.',
                'overrides' => 'Workflow node config remains the per-run override for max_parallel and http_timeout.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'workflow')
            ->where('config_key', 'fanout_runtime_policy')
            ->first(['config_value']);

        $existing = $row !== null
            ? json_decode((string) $row->config_value, true)
            : null;
        if (! is_array($existing)) {
            $existing = [];
        }

        $policy = array_replace_recursive($this->policy(), $existing);

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'workflow', 'config_key' => 'fanout_runtime_policy'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Runtime policy for FanOutNode branch caps and HTTP pool timeout defaults.',
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
            ->where('section', 'workflow')
            ->where('config_key', 'fanout_runtime_policy')
            ->delete();
    }
};
