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
        // PROD received this tuning separately. Never mark an attempted DEV sync as
        // permission to alter PROD or a controller that is holding a live lease.
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $slot = DB::table('distributed_tasks')->where('task_id', Journal::SLOT_ID)->lockForUpdate()->first();
            $state = $slot ? json_decode((string) $slot->result, true, flags: JSON_THROW_ON_ERROR) : [];
            if (! $slot || $slot->task_type !== Journal::TASK_TYPE || $slot->status !== 'held'
                || ($state['schema'] ?? null) !== Journal::SCHEMA || ($state['phase'] ?? null) !== 'idle') {
                throw new RuntimeException('Controller custody must be registered and idle before DEV heartbeat tuning.');
            }

            $row = DB::table('system_configs')->where('section', 'ai')->where('config_key', Policy::CONFIG_KEY)->lockForUpdate()->first();
            $hostRow = DB::table('system_configs')->where('section', 'ai')->where('config_key', 'model_host_controller_policy')->lockForUpdate()->first();
            if (! $row || ! $hostRow) {
                throw new RuntimeException('Existing controller timeout policies are required.');
            }
            $before = json_decode((string) $row->config_value, true, flags: JSON_THROW_ON_ERROR);
            $policy = (new Policy)->normalize($before);
            $host = json_decode((string) $hostRow->config_value, true, flags: JSON_THROW_ON_ERROR);
            if (($policy['heartbeat_timeout_seconds'] ?? null) === 120) {
                return;
            }
            if (($policy['schema'] ?? null) !== 'plos.model_host_controller.automatic_selection_policy.v1'
                || ($policy['revision'] ?? null) !== 'MHC-06-automatic-v1'
                || ($policy['heartbeat_interval_seconds'] ?? null) !== 15
                || ($policy['heartbeat_timeout_seconds'] ?? null) !== 30
                || ($policy['lock_ttl_seconds'] ?? null) !== 900
                || ($policy['recovery_after_seconds'] ?? null) !== 900
                || ($host['schema'] ?? null) !== 'plos.model_host_controller_policy.v1'
                || ($host['host_id'] ?? null) !== '.87'
                || ($host['lease_ttl_seconds'] ?? null) !== 7200) {
                throw new RuntimeException('DEV controller heartbeat predecessor differs; review before changing it.');
            }

            $policy['heartbeat_timeout_seconds'] = 120;
            (new Policy)->normalize($policy);
            $updated = DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('DEV controller heartbeat policy changed during registration.');
            }
        });
        Cache::forget('syscfg:ai.'.Policy::CONFIG_KEY);
    }

    public function down(): void
    {
        // Do not restore a stale controller timeout over a later operator choice.
    }
};
