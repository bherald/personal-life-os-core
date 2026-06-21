<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function jobs(): array
    {
        return [
            [
                'name' => 'file_lifecycle_reconcile_report',
                'description' => 'Daily read-only file lifecycle reconciliation report.',
                'command' => 'files:reconcile-lifecycle --json --compact',
                'cron_expression' => '11 6 * * *',
                'source_module' => 'File Registry',
                'workload_family' => 'files',
                'resource_profile' => 'db',
                'backlog_metric' => 'file_lifecycle',
                'notes' => 'Observe-only daily file lifecycle status. No samples, writeback, purge, move, or delete path is scheduled.',
            ],
            [
                'name' => 'file_duplicate_status_report',
                'description' => 'Daily read-only duplicate status report.',
                'command' => 'files:resolve-duplicates --status --json --compact',
                'cron_expression' => '21 6 * * *',
                'source_module' => 'File Registry',
                'workload_family' => 'files',
                'resource_profile' => 'db',
                'backlog_metric' => 'file_duplicates',
                'notes' => 'Observe-only duplicate status. Does not audit sample rows, resolve, merge, move, or confirm duplicate changes.',
            ],
            [
                'name' => 'genealogy_evidence_sprint_weekly_report',
                'description' => 'Weekly read-only genealogy evidence sprint readiness report.',
                'command' => 'genealogy:evidence-sprint-report --days=30 --limit=500 --json --compact',
                'cron_expression' => '31 6 * * 1',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_evidence_sprint',
                'notes' => 'Observe-only weekly evidence sprint readiness report. Revisit under maintenance if the sprint lane is closed or no longer useful.',
            ],
            [
                'name' => 'genealogy_agent_triage_report',
                'description' => 'Daily read-only genealogy agent triage report.',
                'command' => 'genealogy:agent-triage --days=30 --json --compact',
                'cron_expression' => '41 6 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_agent_triage',
                'notes' => 'Observe-only disabled/low-yield Genea agent triage. Does not re-enable agents or dispatch work.',
            ],
            [
                'name' => 'genealogy_media_intake_gap_report',
                'description' => 'Daily read-only genealogy media intake gap report.',
                'command' => 'genealogy:media-intake-report --tree=4 --json --compact',
                'cron_expression' => '47 6 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_media_intake',
                'notes' => 'Observe-only media intake gap report for FT4. Does not stage files, download, write links, or start intake runs.',
            ],
            [
                'name' => 'genealogy_packet_reason_codes_report',
                'description' => 'Daily read-only genealogy review packet reason-code rollup.',
                'command' => 'genealogy:packet-reason-codes --days=30 --json --compact',
                'cron_expression' => '53 6 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_packet_reasons',
                'notes' => 'Observe-only review packet reason-code rollup. Does not apply, reject, or mutate review packets.',
            ],
            [
                'name' => 'genealogy_reject_codes_report',
                'description' => 'Daily read-only genealogy reject-code rollup.',
                'command' => 'genealogy:reject-codes --days=30 --json --compact',
                'cron_expression' => '59 6 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_reject_codes',
                'notes' => 'Observe-only structured reject-code rollup. Does not change review, packet, or task state.',
            ],
            [
                'name' => 'runtime_metadata_audit_report',
                'description' => 'Daily read-only runtime metadata audit.',
                'command' => 'ops:runtime-metadata-audit --json',
                'cron_expression' => '4 7 * * *',
                'source_module' => 'Ops',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'backlog_metric' => 'runtime_metadata',
                'notes' => 'Observe-only runtime metadata drift audit. Strict failure mode is intentionally not scheduled.',
            ],
            [
                'name' => 'privacy_routing_audit_report',
                'description' => 'Daily read-only privacy routing audit.',
                'command' => 'ops:audit-privacy-routing --json --compact',
                'cron_expression' => '8 7 * * *',
                'source_module' => 'AI',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'backlog_metric' => 'privacy_routing',
                'notes' => 'Observe-only privacy routing audit. Strict failure mode and detailed sensitive lists are intentionally not scheduled.',
            ],
            [
                'name' => 'face_bridge_alignment_daily_report',
                'description' => 'Daily read-only face bridge alignment report.',
                'command' => 'ops:face-bridge-alignment-report --tree=4 --json --compact --limit=20',
                'cron_expression' => '18 7 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'face_bridge_alignment',
                'notes' => 'Observe-only face bridge alignment report after nightly face sync and daily integrity audit. No repair command is scheduled.',
            ],
            [
                'name' => 'queue_fit_audit_report',
                'description' => 'Daily read-only queue fit audit.',
                'command' => 'ops:queue-fit-audit --json --compact',
                'cron_expression' => '22 7 * * *',
                'source_module' => 'Ops',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'backlog_metric' => 'queue_fit',
                'notes' => 'Observe-only queue fit audit. Strict failure mode is intentionally not scheduled.',
            ],
            [
                'name' => 'scheduled_output_audit_report',
                'description' => 'Daily read-only scheduled output hygiene audit.',
                'command' => 'ops:scheduled-output-audit --json --compact',
                'cron_expression' => '26 7 * * *',
                'source_module' => 'Ops',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'backlog_metric' => 'scheduled_output_hygiene',
                'notes' => 'Observe-only scheduled output hygiene audit. Failure-on-violation mode is intentionally not scheduled.',
            ],
            [
                'name' => 'genealogy_review_feedback_report',
                'description' => 'Daily read-only genealogy review feedback rollup.',
                'command' => 'genealogy:review-feedback --days=30 --json --compact',
                'cron_expression' => '31 7 * * *',
                'source_module' => 'Genealogy',
                'workload_family' => 'genealogy',
                'resource_profile' => 'db',
                'backlog_metric' => 'genealogy_review_feedback',
                'notes' => 'Observe-only review feedback rollup. Does not apply feedback or mutate review state.',
            ],
            [
                'name' => 'review_backlog_report',
                'description' => 'Daily read-only review backlog report.',
                'command' => 'ops:review-backlog-report --json --compact',
                'cron_expression' => '36 7 * * *',
                'source_module' => 'Ops',
                'workload_family' => 'ops',
                'resource_profile' => 'db',
                'backlog_metric' => 'review_backlog',
                'notes' => 'Observe-only review backlog report. Does not emit a next-target selector or mutate review rows.',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function retiredJobNames(): array
    {
        return [
            'genealogy_analyst',
            'genealogy_auto_research',
            'genealogy_media_enrichment_batch',
            'genealogy_newspaper_research',
            'genealogy_research_colonial_fan',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach ($this->jobs() as $job) {
            $this->upsertJob($job);
        }

        $this->retireLegacyJobs();
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        foreach ($this->jobs() as $job) {
            DB::delete('DELETE FROM scheduled_jobs WHERE name = ?', [$job['name']]);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function upsertJob(array $job): void
    {
        $now = now();
        $existing = DB::selectOne(
            'SELECT id FROM scheduled_jobs WHERE name = ? LIMIT 1',
            [$job['name']]
        );

        $values = [
            'description' => $job['description'],
            'job_type' => 'command',
            'command' => $job['command'],
            'cron_expression' => $job['cron_expression'],
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => $job['source_module'],
            'runtime_mode' => 'maintenance',
            'workload_family' => $job['workload_family'],
            'resource_profile' => $job['resource_profile'],
            'stall_policy' => 'strict',
            'backlog_metric' => $job['backlog_metric'],
            'notification_mode' => 'digest',
            'notes' => $job['notes'],
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            DB::update(
                'UPDATE scheduled_jobs
                 SET description = ?, job_type = ?, command = ?, cron_expression = ?, enabled = ?,
                     run_in_background = ?, without_overlapping = ?, stall_exempt = ?, timeout_minutes = ?,
                     max_parallel = ?, category = ?, source_module = ?, runtime_mode = ?, workload_family = ?,
                     resource_profile = ?, stall_policy = ?, backlog_metric = ?, notification_mode = ?,
                     notes = ?, updated_at = ?
                 WHERE name = ?',
                [
                    $values['description'],
                    $values['job_type'],
                    $values['command'],
                    $values['cron_expression'],
                    $values['enabled'],
                    $values['run_in_background'],
                    $values['without_overlapping'],
                    $values['stall_exempt'],
                    $values['timeout_minutes'],
                    $values['max_parallel'],
                    $values['category'],
                    $values['source_module'],
                    $values['runtime_mode'],
                    $values['workload_family'],
                    $values['resource_profile'],
                    $values['stall_policy'],
                    $values['backlog_metric'],
                    $values['notification_mode'],
                    $values['notes'],
                    $values['updated_at'],
                    $job['name'],
                ]
            );

            return;
        }

        DB::insert(
            'INSERT INTO scheduled_jobs (
                name, description, job_type, command, cron_expression, enabled,
                run_in_background, without_overlapping, stall_exempt, timeout_minutes,
                max_parallel, category, source_module, runtime_mode, workload_family,
                resource_profile, stall_policy, backlog_metric, notification_mode,
                notes, created_at, updated_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $job['name'],
                $values['description'],
                $values['job_type'],
                $values['command'],
                $values['cron_expression'],
                $values['enabled'],
                $values['run_in_background'],
                $values['without_overlapping'],
                $values['stall_exempt'],
                $values['timeout_minutes'],
                $values['max_parallel'],
                $values['category'],
                $values['source_module'],
                $values['runtime_mode'],
                $values['workload_family'],
                $values['resource_profile'],
                $values['stall_policy'],
                $values['backlog_metric'],
                $values['notification_mode'],
                $values['notes'],
                $now,
                $values['updated_at'],
            ]
        );
    }

    private function retireLegacyJobs(): void
    {
        foreach ($this->retiredJobNames() as $name) {
            DB::update(
                'UPDATE scheduled_jobs
                 SET enabled = 0,
                     next_run_at = NULL,
                     category = ?,
                     source_module = ?,
                     runtime_mode = ?,
                     notes = ?
                 WHERE name = ?',
                [
                    'Retired',
                    'Genealogy',
                    'retired',
                    'Retired 2026-06-08: disabled legacy Genea scheduled lane preserved in place so scheduled_job_runs history is not cascade-deleted. Superseded by read-only scheduler coverage reports and gated task/review workflows. Do not re-enable without operator review.',
                    $name,
                ]
            );
        }
    }
};
