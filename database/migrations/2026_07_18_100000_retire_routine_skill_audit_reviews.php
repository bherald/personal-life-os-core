<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'agent_skill_drift_audit';

    private const MARKER = '2026_07_18_retire_routine_skill_audit_reviews';

    private const RETIREMENT_NOTE = '{"schema":"agent_skill_audit_auto_retirement.v1","reason":"scheduled_integrity_audit_is_operational_signal_not_operator_decision"}';

    public function up(): void
    {
        $this->makeScheduledAuditObserveOnly();
        $this->retirePendingAuditAcknowledgements();
    }

    public function down(): void
    {
        $this->restoreScheduledAudit();

        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('review_type', 'skill_optimization')
            ->where('status', 'expired')
            ->where('reviewer_notes', self::RETIREMENT_NOTE)
            ->update([
                'status' => 'pending',
                'reviewed_at' => null,
                'reviewer_notes' => null,
                'updated_at' => now(),
            ]);
    }

    private function makeScheduledAuditObserveOnly(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $job = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id', 'command', 'description', 'runtime_mode', 'notes']);
        if ($job === null || ! str_contains((string) $job->command, '--queue-review')) {
            return;
        }

        $previousNotes = (string) ($job->notes ?? '');
        $notes = json_decode($previousNotes, true);
        $notes = is_array($notes) ? $notes : [];
        $extensions = is_array($notes['_migration_extensions'] ?? null)
            ? $notes['_migration_extensions']
            : [];
        if (isset($extensions[self::MARKER])) {
            return;
        }

        $extensions[self::MARKER] = [
            'previous' => [
                'command' => (string) $job->command,
                'description' => $job->description,
                'runtime_mode' => $job->runtime_mode,
                'notes' => $previousNotes,
            ],
        ];
        $notes['_migration_extensions'] = $extensions;
        $notes['summary'] = 'Scans complete configured PLOS skill bundles, writes a content-free SHA-256 manifest, and reports drift through operations without creating operator review work.';
        $notes['review_surface'] = 'operations';
        $notes['runtime']['queue_review'] = false;
        $notes['safety']['read_only'] = true;
        $notes['safety']['operator_review_required_for_caution'] = false;
        unset(
            $notes['safety']['review_queue_write_only'],
            $notes['safety']['queue_materialization_only'],
            $notes['safety']['approval_effect'],
            $notes['safety']['rejection_effect']
        );

        $command = trim((string) preg_replace('/\s+--queue-review(?:\s+|$)/', ' ', (string) $job->command));
        DB::table('scheduled_jobs')->where('id', $job->id)->update([
            'command' => $command,
            'description' => 'Observe-only PLOS skill integrity drift audit; actionable drift is reported through operations.',
            'runtime_mode' => 'observe',
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function restoreScheduledAudit(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $job = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['id', 'notes']);
        if ($job === null) {
            return;
        }

        $notes = json_decode((string) ($job->notes ?? ''), true);
        $previous = is_array($notes)
            ? ($notes['_migration_extensions'][self::MARKER]['previous'] ?? null)
            : null;
        if (! is_array($previous)) {
            return;
        }

        DB::table('scheduled_jobs')->where('id', $job->id)->update([
            'command' => $previous['command'] ?? null,
            'description' => $previous['description'] ?? null,
            'runtime_mode' => $previous['runtime_mode'] ?? null,
            'notes' => $previous['notes'] ?? null,
            'updated_at' => now(),
        ]);
    }

    private function retirePendingAuditAcknowledgements(): void
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return;
        }

        DB::table('agent_review_queue')
            ->where('review_type', 'skill_optimization')
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'details'])
            ->each(function (object $row): void {
                $details = json_decode((string) ($row->details ?? ''), true);
                if (! is_array($details) || ($details['schema'] ?? null) !== 'agent_skill_audit_review_queue.v2') {
                    return;
                }

                DB::table('agent_review_queue')->where('id', $row->id)->update([
                    'status' => 'expired',
                    'reviewed_at' => now(),
                    'reviewer_notes' => self::RETIREMENT_NOTE,
                    'updated_at' => now(),
                ]);
            });
    }
};
