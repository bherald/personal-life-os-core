<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ADDED_JOB_NAMES = [
        'llm_health_reconcile',
        'face_person_source_repair_packet_refill',
        'face_person_source_provenance_audit',
        'genealogy_weak_source_add_cleanup',
    ];

    /** @return array<string,list<string>> */
    private function commandPrefixes(): array
    {
        return [
            'genealogy_manager' => ['genealogy:manager --tree=4 --execute --json --compact'],
            'llm_health_reconcile' => ['ops:llm-health-reconcile --json --compact'],
            'face_person_source_repair_packet_refill' => ['ops:face-person-source-repair-packet-refill --tree=4 --write --confirm --json --compact'],
            'face_person_source_provenance_audit' => ['ops:face-person-source-provenance-report --tree=4 --json --compact --limit=25'],
            'genealogy_weak_source_add_cleanup' => ['genealogy:weak-source-add-cleanup --tree=4 --execute --confirm --json --compact'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function addedLanes(): array
    {
        return [
            [
                'name' => 'llm_health_reconcile_runway',
                'job_name' => 'llm_health_reconcile',
                'enabled' => true,
                'min_minutes_between_runs' => 10,
                'trigger_mode' => 'all',
                'triggers' => [
                    'stale_job_name' => 'llm_health_reconcile',
                    'stale_minutes' => 10,
                ],
            ],
            [
                'name' => 'face_repair_packet_refill_runway',
                'job_name' => 'face_person_source_repair_packet_refill',
                'enabled' => true,
                'min_minutes_between_runs' => 30,
                'trigger_mode' => 'all',
                'triggers' => [
                    'face_repair_pending_below_max' => true,
                    'stale_job_name' => 'face_person_source_repair_packet_refill',
                    'stale_minutes' => 30,
                ],
            ],
            [
                'name' => 'face_provenance_audit_runway',
                'job_name' => 'face_person_source_provenance_audit',
                'enabled' => true,
                'min_minutes_between_runs' => 60,
                'trigger_mode' => 'all',
                'triggers' => [
                    'stale_job_name' => 'face_person_source_provenance_audit',
                    'stale_minutes' => 60,
                ],
            ],
            [
                'name' => 'weak_source_add_cleanup_runway',
                'job_name' => 'genealogy_weak_source_add_cleanup',
                'enabled' => true,
                'min_minutes_between_runs' => 15,
                'trigger_mode' => 'all',
                'triggers' => [
                    'weak_source_add_min' => 1,
                    'stale_job_name' => 'genealogy_weak_source_add_cleanup',
                    'stale_minutes' => 15,
                ],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            $policy = $this->basePolicy();
        }

        $policy['max_jobs_per_pass'] = max(2, (int) ($policy['max_jobs_per_pass'] ?? 0));
        $policy['allowed_job_names'] = $this->mergeList(
            is_array($policy['allowed_job_names'] ?? null) ? $policy['allowed_job_names'] : [],
            array_merge(['genealogy_manager'], self::ADDED_JOB_NAMES)
        );
        $policy['allowed_resource_profiles'] = $this->mergeList(
            is_array($policy['allowed_resource_profiles'] ?? null) ? $policy['allowed_resource_profiles'] : [],
            ['db', 'genealogy_llm', 'network']
        );
        $policy['allowed_command_prefixes'] = array_replace(
            is_array($policy['allowed_command_prefixes'] ?? null) ? $policy['allowed_command_prefixes'] : [],
            $this->commandPrefixes()
        );
        $policy['lanes'] = $this->mergeLanes(
            is_array($policy['lanes'] ?? null) ? $policy['lanes'] : [],
            $this->addedLanes()
        );
        $policy['feedback'] = array_replace(
            is_array($policy['feedback'] ?? null) ? $policy['feedback'] : [],
            [
                'safe_lane_expansion' => 'Capacity manager may wake LLM health, face/photo provenance packet runway, face provenance audit, and weak source_add cleanup lanes when table triggers pass.',
                'command_prefix_policy' => 'Each allowed scheduler lane must match system_configs.ops.capacity_utilization_manager.allowed_command_prefixes.',
            ]
        );

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => 'capacity_utilization_manager'],
            [
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Table-driven manager policy for matching private LLM capacity and safe maintenance runway to scheduler lanes.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        $row = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->first(['config_value']);

        $policy = $row !== null ? json_decode((string) $row->config_value, true) : null;
        if (! is_array($policy)) {
            return;
        }

        $policy['max_jobs_per_pass'] = 1;
        if (is_array($policy['allowed_job_names'] ?? null)) {
            $policy['allowed_job_names'] = array_values(array_filter(
                $policy['allowed_job_names'],
                static fn (mixed $name): bool => is_string($name) && ! in_array($name, self::ADDED_JOB_NAMES, true)
            ));
        }
        if (is_array($policy['allowed_resource_profiles'] ?? null)) {
            $policy['allowed_resource_profiles'] = array_values(array_filter(
                $policy['allowed_resource_profiles'],
                static fn (mixed $profile): bool => is_string($profile) && $profile !== 'network'
            ));
        }
        if (is_array($policy['allowed_command_prefixes'] ?? null)) {
            foreach (self::ADDED_JOB_NAMES as $jobName) {
                unset($policy['allowed_command_prefixes'][$jobName]);
            }
        }
        if (is_array($policy['lanes'] ?? null)) {
            $addedNames = array_column($this->addedLanes(), 'name');
            $policy['lanes'] = array_values(array_filter(
                $policy['lanes'],
                static fn (mixed $lane): bool => ! is_array($lane)
                    || ! in_array((string) ($lane['name'] ?? ''), $addedNames, true)
            ));
        }

        DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', 'capacity_utilization_manager')
            ->update([
                'config_value' => json_encode($policy, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /** @return array<string,mixed> */
    private function basePolicy(): array
    {
        return [
            'enabled' => true,
            'max_jobs_per_pass' => 2,
            'allowed_job_names' => ['genealogy_manager'],
            'allowed_resource_profiles' => ['db', 'genealogy_llm'],
            'blocked_safety_true_flags' => [
                'canonical_writes',
                'direct_genealogy_writes',
                'face_identity_automation',
                'relationship_changes',
                'canonical_source_link_writeback',
            ],
            'capacity' => [
                'window_minutes' => 60,
                'model_roles' => ['standard', 'quality', 'coding'],
                'required_capability' => 'text',
                'excluded_capabilities' => ['privacy_deny_prefilter'],
                'private_scopes' => ['local_private', 'private_allowed'],
                'local_instance_types' => ['ollama', 'local_llm'],
                'watched_instance_ids' => ['ollama_jetson', 'codex_exec'],
                'running_resource_profiles' => ['genealogy_llm'],
            ],
            'workload' => [
                'tree_id' => 4,
                'signals' => [
                    'genealogy_queue',
                    'genealogy_review_backlog',
                    'face_repair',
                    'llm_running_jobs',
                ],
            ],
            'lanes' => [[
                'name' => 'genealogy_capacity_runway',
                'job_name' => 'genealogy_manager',
                'enabled' => true,
                'min_minutes_between_runs' => 3,
                'trigger_mode' => 'all',
                'triggers' => [
                    'private_llm_capacity_min' => 1,
                    'local_llm_capacity_min' => 1,
                    'idle_llm_slots_min' => 1,
                    'watched_instances_available_min' => 1,
                    'genealogy_queue_pending_min' => 1,
                    'safe_work_units_min' => 1,
                    'weak_source_add_max' => 20,
                    'stale_job_name' => 'genealogy_manager',
                    'stale_minutes' => 3,
                ],
            ]],
            'safety' => [
                'canonical_writes' => false,
                'face_identity_automation' => false,
                'operator_review_required' => true,
                'scheduler_bookkeeping_required' => true,
            ],
        ];
    }

    /**
     * @param  list<mixed>  $existing
     * @param  list<string>  $added
     * @return list<string>
     */
    private function mergeList(array $existing, array $added): array
    {
        $items = [];
        foreach (array_merge($existing, $added) as $item) {
            if (! is_string($item) || trim($item) === '') {
                continue;
            }
            $items[] = trim($item);
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  list<mixed>  $existing
     * @param  list<array<string,mixed>>  $added
     * @return list<array<string,mixed>>
     */
    private function mergeLanes(array $existing, array $added): array
    {
        $lanes = [];
        foreach ($existing as $lane) {
            if (is_array($lane)) {
                $lanes[(string) ($lane['name'] ?? $lane['job_name'] ?? count($lanes))] = $lane;
            }
        }
        foreach ($added as $lane) {
            $lanes[(string) ($lane['name'] ?? $lane['job_name'])] = $lane;
        }

        return array_values($lanes);
    }
};
