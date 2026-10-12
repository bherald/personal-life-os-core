<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $this->upsertScheduledJob('file_registry_verify', [
            'description' => 'Verify active file registry rows frequently so missing files are marked orphaned promptly',
            'job_type' => 'command',
            'command' => 'files:registry --verify --limit=5000',
            'cron_expression' => '0 */6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 180,
            'max_parallel' => 1,
            'category' => 'E13-FileRegistry',
            'source_module' => 'FileRegistry',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'files',
            'resource_profile' => 'io',
            'stall_policy' => 'strict',
            'backlog_metric' => 'file_registry_verify',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'runtime' => [
                    'policy' => 'frequent_missing_file_verification',
                    'limit' => 5000,
                    'operator_goal' => 'Keep Knowledge file/search counts free of stale missing-file rows.',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $this->upsertScheduledJob('file_registry_missing_cleanup', [
            'description' => 'Promote confirmed missing orphaned file registry rows out of active user-facing views',
            'job_type' => 'command',
            'command' => 'files:registry --maintenance --failure-threshold=1 --limit=10000',
            'cron_expression' => '30 */6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 180,
            'max_parallel' => 1,
            'category' => 'E13-FileRegistry',
            'source_module' => 'FileRegistry',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'files',
            'resource_profile' => 'io',
            'stall_policy' => 'strict',
            'backlog_metric' => 'file_registry_missing_cleanup',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'runtime' => [
                    'policy' => 'aggressive_missing_file_cleanup',
                    'failure_threshold' => 1,
                    'operator_goal' => 'Rows that cannot be opened should not remain visible in Knowledge files/review.',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);

        $this->upsertScheduledJob('file_registry_verify_all_monthly', [
            'description' => 'Monthly full verification of every active file registry row against local storage/Nextcloud',
            'job_type' => 'command',
            'command' => 'files:registry --verify --all',
            'cron_expression' => '0 1 1 * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 360,
            'max_parallel' => 1,
            'category' => 'E13-FileRegistry',
            'source_module' => 'FileRegistry',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'files',
            'resource_profile' => 'io',
            'stall_policy' => 'strict',
            'backlog_metric' => 'file_registry_verify_all',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'runtime' => [
                    'policy' => 'monthly_full_file_registry_verification',
                    'limit' => 'all',
                    'operator_goal' => 'Find orphaned registry rows before a user opens/searches stale files.',
                ],
            ], JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->whereIn('name', ['file_registry_missing_cleanup', 'file_registry_verify_all_monthly'])
            ->delete();

        DB::table('scheduled_jobs')
            ->where('name', 'file_registry_verify')
            ->update($this->filterColumns([
                'command' => 'files:registry --verify --limit=2000',
                'cron_expression' => '0 8,20 * * *',
                'timeout_minutes' => 120,
                'updated_at' => now(),
            ]));
    }

    private function upsertScheduledJob(string $name, array $values): void
    {
        $exists = DB::table('scheduled_jobs')->where('name', $name)->exists();
        $payload = $this->filterColumns(array_merge($values, [
            'name' => $name,
            'updated_at' => now(),
        ]));

        if (! $exists && Schema::hasColumn('scheduled_jobs', 'created_at')) {
            $payload['created_at'] = now();
        }

        DB::table('scheduled_jobs')->updateOrInsert(['name' => $name], $payload);
    }

    private function filterColumns(array $values): array
    {
        $columns = array_flip(Schema::getColumnListing('scheduled_jobs'));

        return array_intersect_key($values, $columns);
    }
};
