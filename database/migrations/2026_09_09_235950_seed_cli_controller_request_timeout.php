<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('system_configs')->insertOrIgnore($this->definition() + ['created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Remove only this migration's unchanged default; retain operator edits.
        DB::table('system_configs')->where($this->definition())->delete();
    }

    private function definition(): array
    {
        return ['section' => 'cli', 'config_key' => 'controller_request_timeout_seconds',
            'data_type' => 'integer', 'config_value' => '60',
            'description' => 'Controller read/plan timeout in seconds (1-600). Status telemetry on .87 can approach 30 seconds. Session override and optional saving use normal CLI settings. This does not alter lease expiry, mutation timeouts, ownership checks or replay policy.'];
    }
};
