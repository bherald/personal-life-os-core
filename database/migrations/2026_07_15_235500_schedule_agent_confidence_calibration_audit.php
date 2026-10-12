<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->updateOrInsert(
                ['section' => 'ops', 'config_key' => 'agent_confidence_calibration'],
                [
                    'config_value' => json_encode([
                        'enabled' => true,
                        'review_type' => 'genealogy_finding',
                        'window_days' => 365,
                        'max_samples' => 5000,
                        'min_samples' => 30,
                        'bin_count' => 10,
                        'actionability_threshold' => 0.8,
                        'min_threshold_samples' => 20,
                        'max_expected_calibration_error' => 0.15,
                        'max_brier_score' => 0.25,
                        'min_threshold_precision' => 0.8,
                        'max_monotonicity_violations' => 0,
                    ], JSON_UNESCAPED_SLASHES),
                    'data_type' => 'json',
                    'description' => 'Observe-only calibration policy for model-authored Genea confidence against operator decisions.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $values = [
            'description' => 'Content-free calibration of Genea confidence against operator decision outcomes.',
            'job_type' => 'command',
            'command' => 'ops:agent-confidence-calibration --json --compact',
            'cron_expression' => '37 3 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'observe',
            'workload_family' => 'ops',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'agent_confidence_calibration_review',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'policy_source' => 'system_configs.ops.agent_confidence_calibration',
                'approval_proxy_only' => true,
                'safety' => ['read_only' => true, 'mutation_allowed' => false],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $existingId = DB::table('scheduled_jobs')->where('name', 'agent_confidence_calibration')->value('id');
        if ($existingId !== null) {
            DB::table('scheduled_jobs')->where('id', $existingId)->update($values);
        } else {
            DB::table('scheduled_jobs')->insert(array_merge(['name' => 'agent_confidence_calibration', 'created_at' => $now], $values));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('name', 'agent_confidence_calibration')->delete();
        }
        if (Schema::hasTable('system_configs')) {
            DB::table('system_configs')->where('section', 'ops')->where('config_key', 'agent_confidence_calibration')->delete();
        }
    }
};
