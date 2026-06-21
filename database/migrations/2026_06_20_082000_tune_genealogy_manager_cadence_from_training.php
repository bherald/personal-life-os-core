<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_MINUTES = 3;

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('system_configs')) {
            return;
        }

        $minutes = $this->seedSupervisorTrainingCadence();
        $this->updateManagerCadence($minutes);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs') || ! Schema::hasTable('system_configs')) {
            return;
        }

        $this->restoreSupervisorTrainingCadence();
        $this->updateManagerCadence(5);
    }

    private function seedSupervisorTrainingCadence(): int
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->first(['id', 'notes']);

        $notes = $row !== null ? json_decode((string) $row->notes, true) : null;
        if (! is_array($notes)) {
            $notes = [];
        }

        $training = is_array($notes['training'] ?? null) ? $notes['training'] : [];
        $training['manager_min_minutes_between_runs'] = self::TARGET_MINUTES;
        $training['manager_cadence_source'] = 'scheduled_jobs.genealogy_supervisor.notes.training.manager_min_minutes_between_runs';
        $notes['training'] = $training;

        if ($row !== null) {
            DB::table('scheduled_jobs')
                ->where('id', $row->id)
                ->update([
                    'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
        }

        return self::TARGET_MINUTES;
    }

    private function restoreSupervisorTrainingCadence(): void
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->first(['id', 'notes']);

        $notes = $row !== null ? json_decode((string) $row->notes, true) : null;
        if (! is_array($notes) || ! is_array($notes['training'] ?? null)) {
            return;
        }

        unset(
            $notes['training']['manager_min_minutes_between_runs'],
            $notes['training']['manager_cadence_source']
        );

        DB::table('scheduled_jobs')
            ->where('id', $row->id)
            ->update([
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function updateManagerCadence(int $minutes): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== 'genealogy_supervisor') {
                continue;
            }

            $lane['min_minutes_between_runs'] = max(1, $minutes);
        }
        unset($lane);

        $policy['manager_notes'] = array_replace(
            is_array($policy['manager_notes'] ?? null) ? $policy['manager_notes'] : [],
            [
                'genealogy_supervisor_manager_cadence_source' => 'scheduled_jobs.genealogy_supervisor.notes.training.manager_min_minutes_between_runs',
                'genealogy_supervisor_manager_cadence_policy' => 'Genea manager may wake genealogy_supervisor sooner when worker slots are available; worker count still stays bounded by scheduled_jobs.max_parallel and notes.runtime.hard_cap.',
            ]
        );

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
