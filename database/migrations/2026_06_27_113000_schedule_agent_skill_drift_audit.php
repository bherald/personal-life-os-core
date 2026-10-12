<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'agent_skill_drift_audit';

    private const COMMAND = 'agents:skill-audit --format=json --compact --compare-latest --fail-on-drift --write-report';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $this->upsertScheduledJob();
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->delete();
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Observe-only installed PLOS skill audit with report drift detection.',
            'job_type' => 'command',
            'command' => self::COMMAND,
            'cron_expression' => '23 6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Agents',
            'runtime_mode' => 'observe',
            'workload_family' => 'agent_safety',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'agent_skill_audit_drift',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'summary' => 'Scans configured PLOS agent skills, writes a report artifact, and fails the scheduled run only when normalized audit drift is detected.',
                'policy_source' => 'config.agents.skill_audit',
                'runtime' => [
                    'target' => 'config.agents.skills_path',
                    'compare_latest' => true,
                    'compact' => true,
                    'fail_on_drift' => true,
                    'write_report' => true,
                    'sidecar_enforcement' => 'disabled_by_default',
                    'llm_scan' => 'disabled_by_default',
                ],
                'safety' => [
                    'read_only' => true,
                    'skill_mutation_allowed' => false,
                    'dependency_change_approved' => false,
                    'operator_review_required_for_caution' => true,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => self::JOB_NAME, 'created_at' => $now],
            $values
        ));
    }
};
