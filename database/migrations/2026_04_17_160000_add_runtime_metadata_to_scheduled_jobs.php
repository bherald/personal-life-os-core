<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * B2 — scheduled_jobs typed runtime-metadata columns.
 *
 * Adds six NULL-default columns so legacy rows keep working while new reporting
 * paths can classify jobs without inspecting the `command` substring. The
 * existing `stall_exempt` boolean is preserved; `stall_policy` is a richer
 * enum that extends rather than replaces it.
 *
 * Every ADD/DROP is guarded by an information_schema check so the migration is
 * idempotent and reversible. No data is backfilled — classification remains a
 * separate operator decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('scheduled_jobs', 'runtime_mode')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN runtime_mode VARCHAR(30) NULL
                 AFTER source_module"
            );
        }

        if (! $this->columnExists('scheduled_jobs', 'workload_family')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN workload_family VARCHAR(30) NULL
                 AFTER runtime_mode"
            );
        }

        if (! $this->columnExists('scheduled_jobs', 'resource_profile')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN resource_profile VARCHAR(30) NULL
                 AFTER workload_family"
            );
        }

        if (! $this->columnExists('scheduled_jobs', 'stall_policy')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN stall_policy ENUM('strict','stall_exempt','adaptive_extend') NULL
                 AFTER resource_profile"
            );
        }

        if (! $this->columnExists('scheduled_jobs', 'backlog_metric')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN backlog_metric VARCHAR(50) NULL
                 AFTER stall_policy"
            );
        }

        if (! $this->columnExists('scheduled_jobs', 'notification_mode')) {
            DB::statement(
                "ALTER TABLE scheduled_jobs
                 ADD COLUMN notification_mode ENUM('silent','digest','high_priority') NULL
                 AFTER backlog_metric"
            );
        }
    }

    public function down(): void
    {
        if ($this->columnExists('scheduled_jobs', 'notification_mode')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN notification_mode');
        }

        if ($this->columnExists('scheduled_jobs', 'backlog_metric')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN backlog_metric');
        }

        if ($this->columnExists('scheduled_jobs', 'stall_policy')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN stall_policy');
        }

        if ($this->columnExists('scheduled_jobs', 'resource_profile')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN resource_profile');
        }

        if ($this->columnExists('scheduled_jobs', 'workload_family')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN workload_family');
        }

        if ($this->columnExists('scheduled_jobs', 'runtime_mode')) {
            DB::statement('ALTER TABLE scheduled_jobs DROP COLUMN runtime_mode');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?',
            [$table, $column]
        );

        return (int) ($row->count ?? 0) > 0;
    }
};
