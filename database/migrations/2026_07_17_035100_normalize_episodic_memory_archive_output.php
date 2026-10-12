<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'episodic_memory_archive';

    private const COMMAND = 'episodic:memory --archive --limit=500 --confirm --json --compact';

    private const SCHEMA = 'plos.scheduled_output_normalization.v1';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasColumn('scheduled_jobs', 'last_run_output')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::COMMAND)
            ->first(['id', 'last_run_output']);
        if ($row === null || ! is_string($row->last_run_output) || trim($row->last_run_output) === '') {
            return;
        }

        json_decode($row->last_run_output, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return;
        }

        $receipt = [
            'schema' => self::SCHEMA,
            'status' => 'legacy_output_retired',
            'generated_at' => now()->toIso8601String(),
            'command' => 'episodic:memory',
            'mode' => 'archive',
            'compact' => true,
            'summary' => [
                'legacy_output_bytes' => strlen($row->last_run_output),
                'legacy_output_sha256' => hash('sha256', $row->last_run_output),
                'memory_records_changed' => 0,
                'embeddings_changed' => 0,
            ],
            'posture' => [
                'migration_normalization' => true,
                'command_executed' => false,
                'legacy_output_content_retained' => false,
            ],
        ];

        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'last_run_output' => json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasColumn('scheduled_jobs', 'last_run_output')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('command', self::COMMAND)
            ->first(['id', 'last_run_output']);
        if ($row === null || ! is_string($row->last_run_output)) {
            return;
        }

        $decoded = json_decode($row->last_run_output, true);
        if (
            ! is_array($decoded)
            || ($decoded['schema'] ?? null) !== self::SCHEMA
            || ($decoded['status'] ?? null) !== 'legacy_output_retired'
            || ($decoded['posture']['command_executed'] ?? null) !== false
        ) {
            return;
        }

        // The retired free-form output is intentionally not restored.
        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'last_run_output' => null,
            'updated_at' => now(),
        ]);
    }
};
