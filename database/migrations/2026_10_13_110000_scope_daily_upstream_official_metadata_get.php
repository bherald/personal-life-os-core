<?php

use App\Services\UpstreamSourceDriftRuntimePolicyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('scheduled_jobs')) {
            return;
        }
        DB::transaction(function (): void {
            $job = DB::table('scheduled_jobs')->where('name', 'whiteroom_monitor_due_report')
                ->where('job_type', 'command')->where('command', 'whiteroom:monitor --format=json --compact --prepare')
                ->where('enabled', 1)->lockForUpdate()->first();
            $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'upstream_source_drift_runtime_policy')->lockForUpdate()->first();
            if ($job === null || $row === null) {
                return;
            }
            $policy = json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($policy) || ($policy['schema'] ?? null) !== UpstreamSourceDriftRuntimePolicyService::SCHEMA
                || ! is_array($policy['preparation'] ?? null) || ($policy['preparation']['enabled'] ?? null) !== true
                || array_key_exists('metadata_network_profile', $policy['preparation'])) {
                return;
            }
            $policy['preparation']['metadata_network_profile'] = 'upstream_public_metadata';
            DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        Cache::forget('syscfg:ops.upstream_source_drift_runtime_policy');
    }

    public function down(): void
    {
        // Preserve changed operator policy, cursor, receipts and queued work.
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        DB::transaction(function (): void {
            $row = DB::table('system_configs')->where('section', 'ops')->where('config_key', 'upstream_source_drift_runtime_policy')->lockForUpdate()->first();
            $policy = $row ? json_decode($row->config_value, true, 512, JSON_THROW_ON_ERROR) : null;
            if (! is_array($policy) || ($policy['schema'] ?? null) !== UpstreamSourceDriftRuntimePolicyService::SCHEMA
                || ($policy['preparation']['metadata_network_profile'] ?? null) !== 'upstream_public_metadata') {
                return;
            }
            unset($policy['preparation']['metadata_network_profile']);
            DB::table('system_configs')->where('id', $row->id)->where('config_value', $row->config_value)
                ->update(['config_value' => json_encode($policy, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        });
        Cache::forget('syscfg:ops.upstream_source_drift_runtime_policy');
    }
};
