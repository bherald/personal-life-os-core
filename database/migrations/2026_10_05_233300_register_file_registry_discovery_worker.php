<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOTES = '{"schema":"file_registry_discovery_worker.v1","policy_source":"files.registry_discovery_worker_policy","scope":"dedicated_durable_discovery_queue","identity_writes":false}';

    public function up(): void
    {
        DB::transaction(function (): void {
            $retirement = DB::table('system_configs')->where('section', 'files')->where('config_key', 'registry_discovery_retirement_policy')->lockForUpdate()->first();
            $legacy = ['connection' => 'database', 'queue' => 'long-running', 'tries' => 3, 'backoff_seconds' => 30];
            if ($retirement && json_decode($retirement->config_value, true) === $legacy
                && config('queue.connections.database.driver') === 'database'
                && DB::connection(config('queue.connections.database.connection')) === DB::connection()
                && DB::table('file_registry_discovery_scopes')->whereNotNull('lease_token')->where('lease_expires_at', '>', now('UTC'))
                    ->lockForUpdate()->first(['id']) === null
                && DB::table(config('queue.connections.database.table'))->where('queue', $legacy['queue'])->lockForUpdate()->first(['id']) === null) {
                DB::table('system_configs')->where('id', $retirement->id)->where('config_value', $retirement->config_value)->update([
                    'config_value' => json_encode(config('file_registry.discovery.retirement'), JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
            }
            DB::table('system_configs')->insertOrIgnore([
                'section' => 'files', 'config_key' => 'registry_discovery_worker_policy',
                'config_value' => json_encode(config('file_registry.discovery.worker'), JSON_THROW_ON_ERROR),
                'data_type' => 'json', 'description' => 'Bounded child process for the dedicated durable discovery queue; custom policy retained.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('scheduled_jobs')->insertOrIgnore([
                'name' => 'file_registry_discovery_worker', 'description' => 'Drain one bounded dedicated discovery database queue slice.',
                'job_type' => 'command', 'command' => 'files:discovery-drain --json',
                'cron_expression' => config('file_registry.discovery.worker_schedule.cron_expression'),
                'enabled' => 1, 'run_in_background' => 1, 'without_overlapping' => 1, 'max_parallel' => 1,
                'timeout_minutes' => config('file_registry.discovery.worker_schedule.timeout_minutes'),
                'stall_exempt' => 0, 'category' => 'Maintenance', 'source_module' => 'Files',
                'runtime_mode' => 'maintenance', 'workload_family' => 'files', 'resource_profile' => 'io',
                'stall_policy' => 'strict', 'notification_mode' => 'silent', 'notes' => self::NOTES,
                'next_run_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        Cache::forget('syscfg:files.registry_discovery_retirement_policy');
        Cache::forget('syscfg:files.registry_discovery_worker_policy');
    }

    public function down(): void
    {
        DB::table('scheduled_jobs')->where('name', 'file_registry_discovery_worker')
            ->where('command', 'files:discovery-drain --json')->where('notes', self::NOTES)
            ->update(['enabled' => 0, 'next_run_at' => null, 'updated_at' => now()]);
        // Retain queued work, dedicated scope, operator policy, and run history.
    }
};
