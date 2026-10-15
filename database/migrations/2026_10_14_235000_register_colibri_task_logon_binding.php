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
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($policy['targets'] ?? null)) {
                return;
            }
            $changed = false;
            foreach ($policy['targets'] as &$target) {
                if (($target['lifecycle_adapter']['adapter_type'] ?? null) !== 'colibri_gateway'
                    || array_key_exists('task_logon_type', $target['lifecycle_adapter'])) {
                    continue;
                }
                // Declare the exact previous guard, preserving any later operator choice.
                // S4U requires a separately reviewed native/table/capacity projection.
                $target['lifecycle_adapter']['task_logon_type'] = 'Interactive';
                $changed = true;
            }
            unset($target);
            if ($changed) {
                DB::table('system_configs')->where('id', $row->id)->update([
                    'config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Preserve explicit task identity and subsequent operator admission.
    }
};
