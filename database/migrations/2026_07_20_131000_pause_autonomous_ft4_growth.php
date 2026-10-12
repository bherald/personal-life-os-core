<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MARKER = '2026_07_20_pause_autonomous_genea_growth_by_operator';

    /** @var list<string> */
    private const JOBS = [
        'genealogy_agent_assess',
        'genealogy_agent_research_queue',
        'genealogy_supervisor',
    ];

    /** @var array<string,list<string>> */
    private const CONFIG_LANES = [
        'genealogy.supervisor_manager' => [
            'genealogy_supervisor',
            'genealogy_supervisor_worker_drain',
        ],
        'ops.capacity_utilization_manager' => [
            'genealogy_capacity_runway',
            'genealogy_low_queue_refill_runway',
        ],
    ];

    public function up(): void
    {
        $this->pauseJobs();
        $this->pauseManagerLanes();
    }

    public function down(): void
    {
        $this->restoreManagerLanes();
        $this->restoreJobs();
    }

    private function pauseJobs(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $columns = ['id', 'name', 'enabled', 'notes'];
        foreach (['next_run_at', 'running_count', 'running_pids', 'last_pid'] as $column) {
            if (Schema::hasColumn('scheduled_jobs', $column)) {
                $columns[] = $column;
            }
        }

        foreach (DB::table('scheduled_jobs')->whereIn('name', self::JOBS)->get($columns) as $job) {
            $notes = $this->decodeJsonObject($job->notes ?? null);
            $extensions = $this->decodeJsonObject($notes['_migration_extensions'] ?? null);
            if (isset($extensions[self::MARKER])) {
                continue;
            }

            $extensions[self::MARKER] = [
                'previous_enabled' => (bool) $job->enabled,
                'previous_next_run_at' => property_exists($job, 'next_run_at') ? $job->next_run_at : null,
                'previous_operator_pause' => $notes['operator_pause'] ?? null,
            ];
            $notes['_migration_extensions'] = $extensions;
            $notes['operator_pause'] = $this->pauseRecord();

            $updates = [
                'enabled' => 0,
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];
            foreach ([
                'next_run_at' => null,
                'running_count' => 0,
                'running_pids' => null,
                'last_pid' => null,
            ] as $column => $value) {
                if (Schema::hasColumn('scheduled_jobs', $column)) {
                    $updates[$column] = $value;
                }
            }
            DB::table('scheduled_jobs')->where('id', $job->id)->update($updates);
        }
    }

    private function restoreJobs(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $columns = ['id', 'enabled', 'notes'];
        foreach (['next_run_at', 'running_count', 'running_pids', 'last_pid'] as $column) {
            if (Schema::hasColumn('scheduled_jobs', $column)) {
                $columns[] = $column;
            }
        }

        foreach (DB::table('scheduled_jobs')->whereIn('name', self::JOBS)->get($columns) as $job) {
            $notes = $this->decodeJsonObject($job->notes ?? null);
            $previous = $notes['_migration_extensions'][self::MARKER] ?? null;
            if (! is_array($previous)
                || (int) $job->enabled !== 0
                || ($notes['operator_pause']['marker'] ?? null) !== self::MARKER) {
                continue;
            }

            if (($previous['previous_operator_pause'] ?? null) === null) {
                unset($notes['operator_pause']);
            } else {
                $notes['operator_pause'] = $previous['previous_operator_pause'];
            }
            unset($notes['_migration_extensions'][self::MARKER]);
            if (($notes['_migration_extensions'] ?? null) === []) {
                unset($notes['_migration_extensions']);
            }

            $updates = [
                'enabled' => (bool) ($previous['previous_enabled'] ?? false),
                'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];
            foreach (['next_run_at' => 'previous_next_run_at'] as $column => $previousKey) {
                if (Schema::hasColumn('scheduled_jobs', $column)) {
                    $updates[$column] = $previous[$previousKey] ?? null;
                }
            }
            foreach (['running_count' => 0, 'running_pids' => null, 'last_pid' => null] as $column => $value) {
                if (Schema::hasColumn('scheduled_jobs', $column)) {
                    $updates[$column] = $value;
                }
            }
            DB::table('scheduled_jobs')->where('id', $job->id)->update($updates);
        }
    }

    private function pauseManagerLanes(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        foreach (self::CONFIG_LANES as $qualifiedKey => $laneNames) {
            [$section, $configKey] = explode('.', $qualifiedKey, 2);
            $row = DB::table('system_configs')
                ->where('section', $section)
                ->where('config_key', $configKey)
                ->first(['id', 'config_value']);
            if ($row === null) {
                continue;
            }

            $config = $this->decodeJsonObject($row->config_value ?? null);
            $extensions = $this->decodeJsonObject($config['_migration_extensions'] ?? null);
            if (isset($extensions[self::MARKER]) || ! is_array($config['lanes'] ?? null)) {
                continue;
            }

            $previousLaneEnabled = [];
            foreach ($config['lanes'] as &$lane) {
                if (! is_array($lane) || ! in_array($lane['name'] ?? null, $laneNames, true)) {
                    continue;
                }
                $previousLaneEnabled[(string) $lane['name']] = $lane['enabled'] ?? null;
                $lane['enabled'] = false;
            }
            unset($lane);
            if (count($previousLaneEnabled) !== count($laneNames)) {
                continue;
            }

            $extensions[self::MARKER] = [
                'previous_lane_enabled' => $previousLaneEnabled,
                'previous_operator_pause' => $config['operator_pause'] ?? null,
            ];
            $config['_migration_extensions'] = $extensions;
            $config['operator_pause'] = $this->pauseRecord();
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    private function restoreManagerLanes(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        foreach (self::CONFIG_LANES as $qualifiedKey => $laneNames) {
            [$section, $configKey] = explode('.', $qualifiedKey, 2);
            $row = DB::table('system_configs')
                ->where('section', $section)
                ->where('config_key', $configKey)
                ->first(['id', 'config_value']);
            if ($row === null) {
                continue;
            }

            $config = $this->decodeJsonObject($row->config_value ?? null);
            $previous = $config['_migration_extensions'][self::MARKER] ?? null;
            if (! is_array($previous)
                || ($config['operator_pause']['marker'] ?? null) !== self::MARKER
                || ! is_array($config['lanes'] ?? null)) {
                continue;
            }

            $currentLaneEnabled = [];
            foreach ($config['lanes'] as $lane) {
                $name = is_array($lane) ? (string) ($lane['name'] ?? '') : '';
                if (in_array($name, $laneNames, true)) {
                    $currentLaneEnabled[$name] = $lane['enabled'] ?? null;
                }
            }
            if (count($currentLaneEnabled) !== count($laneNames)
                || array_filter($currentLaneEnabled, static fn (mixed $enabled): bool => $enabled !== false) !== []) {
                continue;
            }

            foreach ($config['lanes'] as &$lane) {
                $name = is_array($lane) ? (string) ($lane['name'] ?? '') : '';
                if (! in_array($name, $laneNames, true)
                    || ! array_key_exists($name, $previous['previous_lane_enabled'] ?? [])) {
                    continue;
                }
                $lane['enabled'] = $previous['previous_lane_enabled'][$name];
            }
            unset($lane);
            if (($previous['previous_operator_pause'] ?? null) === null) {
                unset($config['operator_pause']);
            } else {
                $config['operator_pause'] = $previous['previous_operator_pause'];
            }
            unset($config['_migration_extensions'][self::MARKER]);
            if (($config['_migration_extensions'] ?? null) === []) {
                unset($config['_migration_extensions']);
            }
            DB::table('system_configs')->where('id', $row->id)->update([
                'config_value' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function pauseRecord(): array
    {
        return [
            'active' => true,
            'marker' => self::MARKER,
            'requested_at' => '2026-07-20',
            'scope' => 'autonomous FT4 assessment, queue research, and tree expansion',
            'reason' => 'operator reports no useful Genea progress',
            'reactivation_gate' => 'explicit operator request',
            'preserved' => [
                'pending research queue history',
                'integrity and backup jobs',
                'review and audit lanes',
                'media, RAG, and evidence maintenance',
                'face identity operator boundaries',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
