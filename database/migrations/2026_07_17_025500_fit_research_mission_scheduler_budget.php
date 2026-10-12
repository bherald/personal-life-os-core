<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'research_run_missions';

    private const PREVIOUS_TIMEOUT_MINUTES = 30;

    private const TIMEOUT_MINUTES = 35;

    /** @return array<string,mixed> */
    private function notes(): array
    {
        return [
            'schema' => 'plos.research_mission_scheduler_runtime.v1',
            'runtime' => [
                'minimum_timeout_minutes' => self::TIMEOUT_MINUTES,
                'mission_budget_minutes' => 30,
                'deadline_safety_buffer_minutes' => 3,
                'policy_source' => 'scheduled_jobs.research_run_missions',
            ],
            'policy_notes' => [
                'fit' => 'The outer job budget must exceed the largest pending mission budget plus the command safety buffer.',
                'adaptation' => 'Adaptive timeout may grow from this floor; explicit zero-yield runs are excluded from training history.',
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('timeout_minutes', self::PREVIOUS_TIMEOUT_MINUTES)
            ->whereNull('notes')
            ->update([
                'timeout_minutes' => self::TIMEOUT_MINUTES,
                'notes' => $this->encodedNotes(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->where('timeout_minutes', self::TIMEOUT_MINUTES)
            ->where('notes', $this->encodedNotes())
            ->update([
                'timeout_minutes' => self::PREVIOUS_TIMEOUT_MINUTES,
                'notes' => null,
                'updated_at' => now(),
            ]);
    }

    private function encodedNotes(): string
    {
        return json_encode($this->notes(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
