<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @return array<string,mixed> */
    private function refillPolicy(): array
    {
        return [
            'enabled' => true,
            'packet_limit' => 25,
            'offset_step' => 25,
            'max_packets_per_run' => 2,
            'max_pending_total' => 14,
            'per_status_pending_target' => 2,
            'status_order' => [
                'face_export_person_conflict',
                'face_linked_display_mismatch',
                'face_trusted_export_no_source',
                'face_untrusted_export_review',
                'person_media_unbacked_confirmed',
                'person_media_unbacked_unconfirmed',
                'person_media_source_backed_unconfirmed',
            ],
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('system_configs')) {
            $this->mergeRepairPlanConfig();
        }

        if (Schema::hasTable('scheduled_jobs')) {
            $this->upsertScheduledJob();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', 'face_person_source_repair_packet_refill')
                ->delete();
        }

        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config)) {
            return;
        }

        unset($config['refill_policy']);

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function mergeRepairPlanConfig(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'faces')
            ->where('config_key', 'face_person_source_repair_plan')
            ->first();

        if ($row === null) {
            return;
        }

        $config = json_decode((string) ($row->config_value ?? ''), true);
        if (! is_array($config)) {
            return;
        }

        $config['refill_policy'] ??= $this->refillPolicy();

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function upsertScheduledJob(): void
    {
        $now = now();
        $values = [
            'description' => 'Table-driven refill for preview-only face/photo repair review packets.',
            'job_type' => 'command',
            'command' => 'ops:face-person-source-repair-packet-refill --tree=4 --write --confirm --json --compact',
            'cron_expression' => '25 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 15,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Genealogy',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'genealogy',
            'resource_profile' => 'db',
            'stall_policy' => 'strict',
            'backlog_metric' => 'face_person_source_repair_packets',
            'notification_mode' => 'digest',
            'notes' => 'Creates or reuses preview-only genealogy_review_packet rows for face/photo provenance repair. Work volume is governed by system_configs.faces.face_person_source_repair_plan.refill_policy. Does not auto-link faces, create people, split/merge clusters, change person-media rows, write metadata, delete photos, run faces:triage-data, or enable face sync/recluster.',
            'updated_at' => $now,
        ];

        $existing = DB::table('scheduled_jobs')
            ->where('name', 'face_person_source_repair_packet_refill')
            ->first(['id']);

        if ($existing !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $existing->id)
                ->update($values);

            return;
        }

        DB::table('scheduled_jobs')->insert(array_merge(
            ['name' => 'face_person_source_repair_packet_refill', 'created_at' => $now],
            $values
        ));
    }
};
