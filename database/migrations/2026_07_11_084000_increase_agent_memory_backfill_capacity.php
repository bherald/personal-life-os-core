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
        $this->setLimit(200, true);
    }

    public function down(): void
    {
        $this->setLimit(100, false);
    }

    private function setLimit(int $limit, bool $recordEvidence): void
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

        $command = preg_replace('/--limit=\d+/', "--limit={$limit}", (string) $row->command, 1);
        if (! is_string($command)) {
            return;
        }

        $notes = is_string($row->notes ?? null) ? json_decode((string) $row->notes, true) : [];
        $notes = is_array($notes) ? $notes : [];
        $notes['runtime'] = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];

        if ($recordEvidence) {
            $notes['runtime']['session_limit'] = 200;
            $notes['runtime']['daily_capacity'] = 800;
            $notes['runtime']['capacity_evidence'] = 'A confirmed 200-session production canary completed in about five minutes with zero failures; the job retains a 30-minute timeout, six-hour cadence, and no-overlap gate.';
        } else {
            unset(
                $notes['runtime']['session_limit'],
                $notes['runtime']['daily_capacity'],
                $notes['runtime']['capacity_evidence']
            );
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->update([
                'command' => $command,
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);

        Cache::forget('operator_evidence:agent_doctor:v1');
    }
};
