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

        $now = now();
        $values = [
            'description' => 'Observe-only all-person face/photo source provenance audit.',
            'job_type' => 'command',
            'command' => 'ops:face-person-source-provenance-report --tree=4 --json --compact --limit=25',
            'cron_expression' => '20 */6 * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 10,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Genealogy',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'genealogy',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'face_person_source_provenance',
            'notification_mode' => 'digest',
            'notes' => 'Observe-only all-person face/photo provenance audit. Runs compact source/person rollups for every current face row and FT person-media photo link. Does not auto-link faces, create people, split/merge clusters, write metadata, delete photos, run faces:triage-data, or enable face sync/recluster.',
            'updated_at' => $now,
        ];

        $existing = DB::selectOne(
            'SELECT id FROM scheduled_jobs WHERE name = ? LIMIT 1',
            ['face_person_source_provenance_audit']
        );

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
                    'face_person_source_provenance_audit',
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
                'face_person_source_provenance_audit',
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

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::delete(
            'DELETE FROM scheduled_jobs WHERE name = ?',
            ['face_person_source_provenance_audit']
        );
    }
};
