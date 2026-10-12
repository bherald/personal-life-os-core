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
            $notes['runtime']['capacity_horizon_mode'] = 'cron_runs_before_oldest_freshness_slo';
            $notes['runtime']['capacity_projection_scope'] = 'current_backlog_only';
            $notes['runtime']['fresh_debt_policy'] = 'Count-only warnings are suppressed only while the enabled oldest-first backfill job has a recent successful run, the current backlog fits the bounded scheduled runs available before the oldest session reaches the freshness SLO, and the oldest session remains inside that SLO.';
            $notes['runtime']['capacity_horizon_evidence'] = 'arXiv:2606.06448v1 treats construction cadence and freshness as a feasibility constraint; arXiv:2606.24775v1 supports workload-aligned localized maintenance. PLOS projects only the current backlog from the table-owned cron, session limit, daily capacity, and freshness SLO.';
        } else {
            unset(
                $notes['runtime']['capacity_horizon_mode'],
                $notes['runtime']['capacity_projection_scope'],
                $notes['runtime']['capacity_horizon_evidence']
            );
            $notes['runtime']['fresh_debt_policy'] = 'Count-only warnings are suppressed only while the enabled backfill job has a recent successful run, the full backlog fits the next run, and the oldest session remains inside the freshness SLO.';
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
