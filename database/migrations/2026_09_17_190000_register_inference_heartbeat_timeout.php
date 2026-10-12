<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')
                ->where('config_key', 'model_host_controller_automatic_selection_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($policy)) {
                throw new RuntimeException('Automatic selection registration is invalid.');
            }
            if (array_key_exists('heartbeat_timeout_seconds', $policy)) {
                return;
            }
            // Persist the existing short controller-read budget, not the model-start mutation budget.
            $timeout = config('model_host_controller.request_timeout_seconds');
            if (! is_int($timeout) || $timeout < 1) {
                throw new RuntimeException('Controller heartbeat timeout registration is unavailable.');
            }
            $policy['heartbeat_timeout_seconds'] = $timeout;
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Preserve operator timing and journal/lease evidence across application rollback.
    }
};
