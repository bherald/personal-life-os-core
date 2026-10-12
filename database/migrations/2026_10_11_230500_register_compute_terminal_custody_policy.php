<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULTS = ['cleanup_grace_seconds' => 5, 'transport_margin_seconds' => 10,
        'custody_accounting_margin_seconds' => 10, 'output_max_bytes' => 67108864,
        'capture_raw_ceiling_bytes' => 8388608, 'capture_transport_overhead_bytes' => 8192,
        'capture_memory_reserve_bytes' => 33554432, 'capture_memory_multiplier' => 16];

    public function up(): void
    {
        $this->register();
    }

    public function down(): void
    {
        // Preserve operator policy and all live holds during code rollback.
        // Older policy readers ignore these additive keys.
    }

    private function register(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::transaction(function () {
            $query = DB::table('system_configs')->where('section', 'compute')->where('config_key', 'router_runtime_policy');
            $row = $query->lockForUpdate()->first();
            if ($row === null) {
                throw new RuntimeException('Register the existing compute router runtime policy before terminal custody.');
            }
            $policy = json_decode((string) $row->config_value, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($policy)) {
                throw new RuntimeException('Compute router policy must be a JSON object.');
            }
            foreach (self::DEFAULTS as $key => $value) {
                if (! array_key_exists($key, $policy)) {
                    $policy[$key] = $value;
                }
            }
            $query->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        Cache::forget('syscfg:compute.router_runtime_policy');
    }
};
