<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'episodic_memory_backfill_recent';

    public function up(): void
    {
        $this->updatePolicy(true);
    }

    public function down(): void
    {
        $this->updatePolicy(false);
    }

    private function updatePolicy(bool $enabled): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->first(['command', 'notes']);
        if (! $row || ! str_starts_with((string) $row->command, 'episodic:memory --backfill')) {
            return;
        }

        $notes = is_string($row->notes ?? null) ? json_decode((string) $row->notes, true) : [];
        $notes = is_array($notes) ? $notes : [];
        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];

        if ($enabled) {
            $notes['runtime']['freshness_slo_hours'] = 12;
            $notes['runtime']['fresh_debt_policy'] = 'Count-only warnings are suppressed only while the enabled backfill job has a recent successful run, the full backlog fits the next run, and the oldest session remains inside the freshness SLO.';
            $notes['runtime']['freshness_slo_evidence'] = 'The job runs every six hours and has a verified 200-session run capacity; two cadence windows allow one delayed run without hiding the existing 48-hour stale-debt warning.';
        } else {
            unset(
                $notes['runtime']['freshness_slo_hours'],
                $notes['runtime']['fresh_debt_policy'],
                $notes['runtime']['freshness_slo_evidence']
            );
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        Cache::forget('operator_evidence:agent_doctor:v1');
    }
};
