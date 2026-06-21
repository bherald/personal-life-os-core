<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LANE_NAME = 'genealogy_supervisor_worker_drain';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $minutes = $this->supervisorManagerCadence();
        if ($minutes === null) {
            return;
        }

        $this->updateGenealogyManagerPolicy($minutes);
        $this->annotateCapacityManagerPolicy();
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $this->removeGenealogyManagerLane();
        $this->removeCapacityManagerAnnotation();
    }

    private function supervisorManagerCadence(): ?int
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->first(['notes']);

        $notes = $row !== null ? json_decode((string) $row->notes, true) : null;
        if (! is_array($notes)) {
            return null;
        }

        $minutes = $notes['training']['manager_min_minutes_between_runs'] ?? null;
        if (! is_numeric($minutes)) {
            return null;
        }

        $minutes = (int) $minutes;

        return $minutes > 0 ? $minutes : null;
    }

    private function updateGenealogyManagerPolicy(int $minutes): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        $policy['lanes'] = $this->mergeLane($policy['lanes'], [
            'name' => self::LANE_NAME,
            'job_name' => 'genealogy_supervisor',
            'enabled' => true,
            'min_minutes_between_runs' => $minutes,
            'trigger_mode' => 'all',
            'triggers' => [
                'queue_pending_min' => 1,
                'slot_job_name' => 'genealogy_agent_research_queue',
                'slots_available_min' => 1,
            ],
        ]);

        $policy['manager_notes'] = array_replace(
            is_array($policy['manager_notes'] ?? null) ? $policy['manager_notes'] : [],
            [
                'genealogy_supervisor_worker_drain_lane' => 'When safe Genea queue work exists and worker slots are open, genealogy_manager may wake genealogy_supervisor even when the queue is above queue_low. Low-queue refill remains a separate table-owned lane.',
                'genealogy_supervisor_worker_drain_cadence_source' => 'scheduled_jobs.genealogy_supervisor.notes.training.manager_min_minutes_between_runs',
            ]
        );

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function annotateCapacityManagerPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        if (is_array($policy['workload']['signals'] ?? null)) {
            $policy['workload']['signals'] = array_values(array_unique(array_merge(
                $policy['workload']['signals'],
                ['genealogy_worker_drain']
            )));
        }

        $policy['feedback'] = array_replace(
            is_array($policy['feedback'] ?? null) ? $policy['feedback'] : [],
            [
                'genealogy_worker_drain' => 'High-queue capacity wakeups hand off to genealogy_manager, whose worker-drain lane starts genealogy_supervisor when queue items and worker slots are both available.',
            ]
        );

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<mixed>  $lanes
     * @param  array<string,mixed>  $added
     * @return list<array<string,mixed>>
     */
    private function mergeLane(array $lanes, array $added): array
    {
        $merged = [];
        foreach ($lanes as $lane) {
            if (is_array($lane)) {
                $merged[(string) ($lane['name'] ?? $lane['job_name'] ?? count($merged))] = $lane;
            }
        }

        $merged[(string) ($added['name'] ?? $added['job_name'])] = $added;

        return array_values($merged);
    }

    private function removeGenealogyManagerLane(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        $policy['lanes'] = array_values(array_filter(
            $policy['lanes'],
            static fn (mixed $lane): bool => ! is_array($lane)
                || ($lane['name'] ?? null) !== self::LANE_NAME
        ));

        if (is_array($policy['manager_notes'] ?? null)) {
            unset(
                $policy['manager_notes']['genealogy_supervisor_worker_drain_lane'],
                $policy['manager_notes']['genealogy_supervisor_worker_drain_cadence_source']
            );
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function removeCapacityManagerAnnotation(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['id', 'config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        if (is_array($policy['workload']['signals'] ?? null)) {
            $policy['workload']['signals'] = array_values(array_filter(
                $policy['workload']['signals'],
                static fn (mixed $signal): bool => $signal !== 'genealogy_worker_drain'
            ));
        }

        if (is_array($policy['feedback'] ?? null)) {
            unset($policy['feedback']['genealogy_worker_drain']);
        }

        DB::table('system_configs')
            ->where('id', $row->id)
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
