<?php

use App\Services\ModelHostControllerAutomaticSelectionPolicyService as Policy;
use App\Services\ModelHostControllerSelectionJournal as Journal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')
            || DB::table('migrations')->where('migration', pathinfo(__FILE__, PATHINFO_FILENAME))->exists()) {
            return;
        }
        DB::transaction(function (): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode($slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle') {
                throw new RuntimeException('Controller custody must be registered and idle before changing heartbeat policy.');
            }
            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', Policy::CONFIG_KEY)->lockForUpdate()->first();
            $hostRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if (! $row || ! $hostRow) {
                throw new RuntimeException('Existing controller timeout policies are required.');
            }
            $before = json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $policy = (new Policy)->normalize($before);
            $host = json_decode($hostRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['heartbeat_timeout_seconds'] ?? null) === 120) {
                return;
            }
            if (($host['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
                || ($host['host_id'] ?? null) !== '.87' || ($host['lease_ttl_seconds'] ?? null) !== 7200
                || ($policy['heartbeat_timeout_seconds'] ?? null) !== 30
                || $host['lease_ttl_seconds'] <= $policy['heartbeat_interval_seconds'] + 120
                || $policy['lock_ttl_seconds'] <= $policy['heartbeat_interval_seconds'] + 120
                || $policy['recovery_after_seconds'] <= $policy['heartbeat_interval_seconds'] + 120) {
                throw new RuntimeException('Controller heartbeat predecessor or lease headroom differs; review before changing it.');
            }
            $policy['heartbeat_timeout_seconds'] = 120;
            (new Policy)->normalize($policy);
            $after = json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $updated = DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => $after, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('Controller heartbeat policy changed during registration.');
            }
        });
        Cache::forget('syscfg:ai.'.Policy::CONFIG_KEY);
    }

    public function down(): void
    {
        // No automatic stale policy restoration. Roll back only an exactly reviewed current table value.
    }
};
