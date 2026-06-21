<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_configs') || ! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $queueLow = $this->supervisorQueueLow();
        if ($queueLow === null) {
            return;
        }

        $this->updateGenealogyManagerPolicy($queueLow);
        $this->updateCapacityManagerPolicy($queueLow);
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $this->restoreGenealogyManagerPolicy();
        $this->restoreCapacityManagerPolicy();
    }

    private function supervisorQueueLow(): ?int
    {
        $row = DB::table('scheduled_jobs')
            ->where('name', 'genealogy_supervisor')
            ->first(['notes']);

        $notes = $row !== null ? json_decode((string) $row->notes, true) : null;
        if (! is_array($notes)) {
            return null;
        }

        $queueLow = $notes['training']['queue_low'] ?? null;
        if (! is_numeric($queueLow)) {
            return null;
        }

        $queueLow = (int) $queueLow;

        return $queueLow >= 0 ? $queueLow : null;
    }

    private function updateGenealogyManagerPolicy(int $queueLow): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== 'genealogy_supervisor') {
                continue;
            }

            $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
            $triggers['queue_pending_min'] = 0;
            $triggers['queue_pending_max'] = $queueLow;
            $lane['triggers'] = $triggers;
        }
        unset($lane);

        $policy['manager_notes'] = array_replace(
            is_array($policy['manager_notes'] ?? null) ? $policy['manager_notes'] : [],
            [
                'queue_low_runway_threshold_source' => 'scheduled_jobs.genealogy_supervisor.notes.training.queue_low',
                'queue_low_runway_policy' => 'genealogy_supervisor is manager-woken when queue pending is at or below the table-owned queue_low threshold.',
            ]
        );

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function updateCapacityManagerPolicy(int $queueLow): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        $highQueueMin = $queueLow + 1;
        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== 'genealogy_capacity_runway') {
                continue;
            }

            $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
            $triggers['genealogy_queue_pending_min'] = $highQueueMin;
            unset($triggers['genealogy_queue_pending_max']);
            $lane['triggers'] = $triggers;
        }
        unset($lane);

        $policy['lanes'] = $this->mergeLane($policy['lanes'], [
            'name' => 'genealogy_low_queue_refill_runway',
            'job_name' => 'genealogy_manager',
            'enabled' => true,
            'min_minutes_between_runs' => 3,
            'trigger_mode' => 'all',
            'triggers' => [
                'private_llm_capacity_min' => 1,
                'local_llm_capacity_min' => 1,
                'idle_llm_slots_min' => 1,
                'watched_instances_available_min' => 1,
                'genealogy_queue_pending_min' => 0,
                'genealogy_queue_pending_max' => $queueLow,
                'stale_job_name' => 'genealogy_manager',
                'stale_minutes' => 3,
            ],
        ]);

        if (is_array($policy['workload']['signals'] ?? null)) {
            $policy['workload']['signals'] = array_values(array_unique(array_merge(
                $policy['workload']['signals'],
                ['genealogy_queue_low_runway']
            )));
        }

        $policy['feedback'] = array_replace(
            is_array($policy['feedback'] ?? null) ? $policy['feedback'] : [],
            [
                'queue_low_runway' => 'Capacity manager splits high-queue and low-queue Genea wakeups using scheduled_jobs.genealogy_supervisor.notes.training.queue_low.',
            ]
        );

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
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

    private function restoreGenealogyManagerPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== 'genealogy_supervisor') {
                continue;
            }

            $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
            $triggers['queue_pending_min'] = 1;
            unset($triggers['queue_pending_max']);
            $lane['triggers'] = $triggers;
        }
        unset($lane);

        if (is_array($policy['manager_notes'] ?? null)) {
            unset(
                $policy['manager_notes']['queue_low_runway_threshold_source'],
                $policy['manager_notes']['queue_low_runway_policy']
            );
        }

        DB::table('system_configs')
            ->where('section', 'genealogy')
            ->where('config_key', 'supervisor_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function restoreCapacityManagerPolicy(): void
    {
        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy) || ! is_array($policy['lanes'] ?? null)) {
            return;
        }

        foreach ($policy['lanes'] as &$lane) {
            if (! is_array($lane) || ($lane['name'] ?? null) !== 'genealogy_capacity_runway') {
                continue;
            }

            $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
            $triggers['genealogy_queue_pending_min'] = 1;
            unset($triggers['genealogy_queue_pending_max']);
            $lane['triggers'] = $triggers;
        }
        unset($lane);

        $policy['lanes'] = array_values(array_filter(
            $policy['lanes'],
            static fn (mixed $lane): bool => ! is_array($lane)
                || ($lane['name'] ?? null) !== 'genealogy_low_queue_refill_runway'
        ));

        if (is_array($policy['feedback'] ?? null)) {
            unset($policy['feedback']['queue_low_runway']);
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }
};
