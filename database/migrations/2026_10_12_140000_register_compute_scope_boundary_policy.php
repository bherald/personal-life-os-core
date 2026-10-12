<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::transaction(function () {
            $query = DB::table('system_configs')->where('section', 'compute')->where('config_key', 'router_runtime_policy');
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Register compute router policy before the disabled scope boundary.');
            }
            $decoded = json_decode((string) $row->config_value, false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof stdClass) {
                throw new RuntimeException('Compute router policy must be an object.');
            }
            $policy = (array) $decoded;
            if (! array_key_exists('scope_boundary_enabled', $policy)) {
                $policy['scope_boundary_enabled'] = false;
            }
            $query->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        Cache::forget('syscfg:compute.router_runtime_policy');
    }

    public function down(): void
    {
        // Preserve operator qualification, limits, and active custody during rollback.
    }
};
