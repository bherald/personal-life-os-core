<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class OpsFacePersonSourceRepairPacketRefillCommand extends Command
{
    private const CONFIG_PATH = 'system_configs.faces.face_person_source_repair_plan.refill_policy';

    private const REQUIRED_REFILL_KEYS = [
        'enabled',
        'status_order',
        'packet_limit',
        'offset_step',
        'max_packets_per_run',
        'max_pending_total',
        'max_open_research_tasks',
        'per_status_pending_target',
    ];

    protected $signature = 'ops:face-person-source-repair-packet-refill
        {--tree=4 : Genealogy tree id}
        {--write : Create or reuse preview-only genealogy review packets}
        {--confirm : Required with --write}
        {--json : Emit machine-readable JSON}
        {--compact : Omit nested command payloads from JSON output}
        {--dry-run : Plan refill actions without writing packets}';

    protected $description = 'Table-driven refill for preview-only face/photo provenance repair packets';

    public function handle(): int
    {
        $config = $this->repairPlanConfig();
        if (($config['__error'] ?? null) !== null) {
            return $this->failPayload((string) $config['__error']);
        }

        $policy = is_array($config['refill_policy'] ?? null) ? $config['refill_policy'] : [];
        $policyError = $this->validateRefillPolicy($config, $policy);
        if ($policyError !== null) {
            return $this->failPayload($policyError);
        }

        $treeId = max(1, (int) $this->option('tree'));
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && ! (bool) $this->option('confirm') && ! $dryRun) {
            return $this->failPayload('write_requires_confirm');
        }

        $pendingByStatus = $this->pendingRepairPacketCounts();
        $usedOffsets = $this->usedRepairPacketOffsets();
        $openResearchTasks = $this->openRepairResearchTaskCount($treeId);
        $maxOpenResearchTasks = max(0, (int) $policy['max_open_research_tasks']);
        $researchTaskBacklogBlocked = $openResearchTasks > $maxOpenResearchTasks;
        $candidateCounts = ! $researchTaskBacklogBlocked && (bool) ($policy['candidate_count_guard_enabled'] ?? false)
            ? $this->candidateCountsByStatus($treeId)
            : [];
        $plans = $researchTaskBacklogBlocked
            ? []
            : $this->buildRefillPlans($treeId, $config, $policy, $pendingByStatus, $usedOffsets, $candidateCounts);
        $runway = $this->buildRunwayTelemetry($policy, $pendingByStatus, $usedOffsets, $candidateCounts, $plans);

        $payload = [
            'command' => 'ops:face-person-source-repair-packet-refill',
            'mode' => $dryRun ? 'dry_run' : ($write ? 'write' : 'plan'),
            'dry_run' => $dryRun,
            'read_only' => ! $write || $dryRun,
            'mutation_allowed' => false,
            'review_packet_write_allowed' => $write && ! $dryRun,
            'captured_at' => now()->toIso8601String(),
            'posture' => [
                'schema' => 'face_person_source_repair_packet_refill.v1',
                'config_source' => self::CONFIG_PATH,
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'face_table_mutation_allowed' => false,
                'person_media_mutation_allowed' => false,
            ],
            'policy' => [
                'enabled' => (bool) $policy['enabled'],
                'packet_limit' => (int) $policy['packet_limit'],
                'offset_step' => (int) $policy['offset_step'],
                'max_packets_per_run' => (int) $policy['max_packets_per_run'],
                'max_pending_total' => (int) $policy['max_pending_total'],
                'max_open_research_tasks' => $maxOpenResearchTasks,
                'per_status_pending_target' => (int) $policy['per_status_pending_target'],
                'candidate_count_guard_enabled' => (bool) ($policy['candidate_count_guard_enabled'] ?? false),
                'status_order' => array_values(array_map('strval', $policy['status_order'])),
            ],
            'queue' => [
                'pending_total' => array_sum($pendingByStatus),
                'pending_by_status' => $pendingByStatus,
                'open_research_tasks' => $openResearchTasks,
                'max_open_research_tasks' => $maxOpenResearchTasks,
                'research_task_backlog_blocked' => $researchTaskBacklogBlocked,
            ],
            'runway' => $runway,
            'summary' => [
                'planned_packets' => count($plans),
                'attempted_packets' => 0,
                'review_packets_created' => 0,
                'review_packets_reused' => 0,
                'identity_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'metadata_files_changed' => 0,
            ],
            'plans' => $plans,
            'results' => [],
            'next_action' => $researchTaskBacklogBlocked
                ? 'face_photo_research_task_backlog_requires_operator_disposition'
                : (count($plans) > 0
                    ? 'refill_preview_packets_for_operator_review'
                    : 'pending_repair_packet_runway_satisfied'),
        ];

        if (! (bool) $policy['enabled']) {
            $payload['next_action'] = 'refill_policy_disabled';
            $payload['plans'] = [];
            $payload['summary']['planned_packets'] = 0;
            $this->renderPayload($this->compactIfRequested($payload));

            return self::SUCCESS;
        }

        if ($write && ! $dryRun) {
            foreach ($plans as $plan) {
                $result = $this->runRepairPlan($plan);
                $payload['results'][] = $result;
                $payload['summary']['attempted_packets']++;
                $payload['summary']['review_packets_created'] += (int) ($result['review_packets_created'] ?? 0);
                $payload['summary']['review_packets_reused'] += (int) ($result['review_packets_reused'] ?? 0);
                if (($result['exit_code'] ?? 1) !== 0) {
                    $payload['next_action'] = 'repair_packet_refill_stopped_after_nested_failure';
                    $this->renderPayload($this->compactIfRequested($payload));

                    return self::FAILURE;
                }
            }
        }

        $this->renderPayload($this->compactIfRequested($payload));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function validateRefillPolicy(array $config, array $policy): ?string
    {
        if (! is_numeric($config['max_limit'] ?? null) || ! is_numeric($config['max_offset'] ?? null)) {
            return 'face_person_source_repair_plan_config_invalid_numeric_value';
        }

        if (! is_array($config['actionable_status_order'] ?? null) || $config['actionable_status_order'] === []) {
            return 'face_person_source_repair_plan_config_invalid_action_policy';
        }

        foreach (self::REQUIRED_REFILL_KEYS as $key) {
            if (! array_key_exists($key, $policy)) {
                return 'face_person_source_repair_plan_refill_policy_missing_keys';
            }
        }

        foreach (['packet_limit', 'offset_step', 'max_packets_per_run', 'max_pending_total', 'per_status_pending_target'] as $key) {
            if (! is_numeric($policy[$key]) || (int) $policy[$key] < 1) {
                return 'face_person_source_repair_plan_refill_policy_invalid_numeric_value';
            }
        }

        if (! is_numeric($policy['max_open_research_tasks']) || (int) $policy['max_open_research_tasks'] < 0) {
            return 'face_person_source_repair_plan_refill_policy_invalid_numeric_value';
        }

        if (! is_bool($policy['enabled'])) {
            return 'face_person_source_repair_plan_refill_policy_invalid_enabled_value';
        }

        if (! is_array($policy['status_order']) || $policy['status_order'] === []) {
            return 'face_person_source_repair_plan_refill_policy_invalid_status_order';
        }

        $allowed = array_values(array_map('strval', is_array($config['actionable_status_order'] ?? null) ? $config['actionable_status_order'] : []));
        foreach ($policy['status_order'] as $status) {
            if (! is_string($status) || ! in_array($status, $allowed, true)) {
                return 'face_person_source_repair_plan_refill_policy_unknown_status';
            }
        }

        if ((int) $policy['packet_limit'] > (int) $config['max_limit']) {
            return 'face_person_source_repair_plan_refill_policy_packet_limit_exceeds_plan_limit';
        }

        if ((int) $policy['max_pending_total'] < (int) $policy['per_status_pending_target']) {
            return 'face_person_source_repair_plan_refill_policy_invalid_pending_targets';
        }

        return null;
    }

    /**
     * @return array<string,int>
     */
    private function pendingRepairPacketCounts(): array
    {
        $rows = DB::select(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '$.packet.metadata.filters.status')) AS status_bucket,
                    COUNT(*) AS total
               FROM agent_review_queue
              WHERE review_type = ?
                AND status = ?
                AND title LIKE ?
                AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.packet.metadata.origin')) = ?
              GROUP BY status_bucket",
            ['genealogy_review_packet', 'pending', '%Face/photo repair plan%', 'ops:face-person-source-repair-plan']
        );

        $counts = [];
        foreach ($rows as $row) {
            $status = $this->cleanString($row->status_bucket ?? null);
            if ($status === null) {
                continue;
            }

            $counts[$status] = max(0, (int) ($row->total ?? 0));
        }

        ksort($counts);

        return $counts;
    }

    private function openRepairResearchTaskCount(int $treeId): int
    {
        try {
            $row = DB::selectOne(
                "SELECT COUNT(*) AS total
                   FROM genealogy_research_tasks t
                   JOIN agent_review_queue q ON q.id = t.queue_item_id
                  WHERE t.tree_id = ?
                    AND t.status IN ('queued', 'processing')
                    AND q.review_type = ?
                    AND q.finding_type = ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(t.parameters, '$.source')) = ?
                    AND JSON_UNQUOTE(JSON_EXTRACT(t.parameters, '$.operation_type')) = ?",
                [
                    $treeId,
                    'genealogy_review_packet',
                    'face_person_source_repair_plan',
                    'genealogy_review_packet',
                    'genealogy_todo_create',
                ]
            );
        } catch (Throwable) {
            return PHP_INT_MAX;
        }

        return max(0, (int) ($row->total ?? 0));
    }

    /**
     * @return array<string,array<int,bool>>
     */
    private function usedRepairPacketOffsets(): array
    {
        $rows = DB::select(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(details, '$.packet.metadata.filters.status')) AS status_bucket,
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(details, '$.packet.metadata.filters.offset')) AS UNSIGNED) AS offset_value
               FROM agent_review_queue
              WHERE review_type = ?
                AND title LIKE ?
                AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.packet.metadata.origin')) = ?",
            ['genealogy_review_packet', '%Face/photo repair plan%', 'ops:face-person-source-repair-plan']
        );

        $offsets = [];
        foreach ($rows as $row) {
            $status = $this->cleanString($row->status_bucket ?? null);
            $offset = is_numeric($row->offset_value ?? null) ? (int) $row->offset_value : null;
            if ($status === null || $offset === null || $offset < 0) {
                continue;
            }

            $offsets[$status][$offset] = true;
        }

        ksort($offsets);

        return $offsets;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $policy
     * @param  array<string,int>  $pendingByStatus
     * @param  array<string,array<int,bool>>  $usedOffsets
     * @param  array<string,int>  $candidateCounts
     * @return array<int,array<string,mixed>>
     */
    private function buildRefillPlans(int $treeId, array $config, array $policy, array $pendingByStatus, array $usedOffsets, array $candidateCounts = []): array
    {
        if (! (bool) $policy['enabled']) {
            return [];
        }

        $plans = [];
        $packetLimit = (int) $policy['packet_limit'];
        $offsetStep = (int) $policy['offset_step'];
        $maxPackets = (int) $policy['max_packets_per_run'];
        $maxPendingTotal = (int) $policy['max_pending_total'];
        $targetPerStatus = (int) $policy['per_status_pending_target'];
        $maxOffset = (int) $config['max_offset'];
        $projectedPendingTotal = array_sum($pendingByStatus);

        foreach (array_values(array_map('strval', $policy['status_order'])) as $status) {
            if (count($plans) >= $maxPackets || $projectedPendingTotal >= $maxPendingTotal) {
                break;
            }

            $projectedStatusPending = (int) ($pendingByStatus[$status] ?? 0);
            $candidateCount = array_key_exists($status, $candidateCounts)
                ? max(0, (int) $candidateCounts[$status])
                : null;
            $availablePacketCount = $candidateCount === null
                ? null
                : (int) ceil($candidateCount / max(1, $packetLimit));

            while ($projectedStatusPending < $targetPerStatus
                && count($plans) < $maxPackets
                && $projectedPendingTotal < $maxPendingTotal
            ) {
                if ($availablePacketCount !== null && $projectedStatusPending >= $availablePacketCount) {
                    break;
                }

                $offset = $this->nextOffset($usedOffsets[$status] ?? [], $offsetStep, $maxOffset);
                if ($offset === null) {
                    break;
                }

                if ($candidateCount !== null && $offset >= $candidateCount) {
                    break;
                }

                $usedOffsets[$status][$offset] = true;
                $plans[] = [
                    'tree_id' => $treeId,
                    'status' => $status,
                    'limit' => $packetLimit,
                    'offset' => $offset,
                    'command' => sprintf(
                        'ops:face-person-source-repair-plan --tree=%d --status=%s --limit=%d --offset=%d --write --confirm --json --compact',
                        $treeId,
                        $status,
                        $packetLimit,
                        $offset
                    ),
                ];
                $projectedStatusPending++;
                $projectedPendingTotal++;
            }
        }

        return $plans;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,int>  $pendingByStatus
     * @param  array<string,array<int,bool>>  $usedOffsets
     * @param  array<string,int>  $candidateCounts
     * @param  array<int,array<string,mixed>>  $plans
     * @return array<string,mixed>
     */
    private function buildRunwayTelemetry(array $policy, array $pendingByStatus, array $usedOffsets, array $candidateCounts, array $plans): array
    {
        $packetLimit = max(1, (int) ($policy['packet_limit'] ?? 1));
        $targetPerStatus = max(1, (int) ($policy['per_status_pending_target'] ?? 1));
        $plannedOffsetsByStatus = [];

        foreach ($plans as $plan) {
            $status = $this->cleanString($plan['status'] ?? null);
            $offset = is_numeric($plan['offset'] ?? null) ? (int) $plan['offset'] : null;
            if ($status === null || $offset === null || $offset < 0) {
                continue;
            }

            $plannedOffsetsByStatus[$status][] = $offset;
        }

        $byStatus = [];
        foreach (array_values(array_map('strval', $policy['status_order'] ?? [])) as $status) {
            $statusUsedOffsets = array_keys($usedOffsets[$status] ?? []);
            sort($statusUsedOffsets, SORT_NUMERIC);

            $statusPlannedOffsets = array_values($plannedOffsetsByStatus[$status] ?? []);
            sort($statusPlannedOffsets, SORT_NUMERIC);

            $candidateCount = array_key_exists($status, $candidateCounts)
                ? max(0, (int) $candidateCounts[$status])
                : null;
            $availablePacketEstimate = $candidateCount === null
                ? null
                : (int) ceil($candidateCount / $packetLimit);

            $coveredCandidateEstimate = count($statusUsedOffsets) * $packetLimit;
            $coveredAfterPlannedEstimate = (count(array_unique(array_merge($statusUsedOffsets, $statusPlannedOffsets))) * $packetLimit);
            if ($candidateCount !== null) {
                $coveredCandidateEstimate = min($candidateCount, $coveredCandidateEstimate);
                $coveredAfterPlannedEstimate = min($candidateCount, $coveredAfterPlannedEstimate);
            }

            $byStatus[] = [
                'status' => $status,
                'pending_packets' => max(0, (int) ($pendingByStatus[$status] ?? 0)),
                'target_pending_packets' => $targetPerStatus,
                'runway_deficit' => max(0, $targetPerStatus - (int) ($pendingByStatus[$status] ?? 0)),
                'used_offsets' => $statusUsedOffsets,
                'planned_next_offsets' => $statusPlannedOffsets,
                'planned_packets' => count($statusPlannedOffsets),
                'packet_limit' => $packetLimit,
                'candidate_count' => $candidateCount,
                'available_packet_estimate' => $availablePacketEstimate,
                'covered_candidate_estimate' => $coveredCandidateEstimate,
                'remaining_candidate_estimate' => $candidateCount === null
                    ? null
                    : max(0, $candidateCount - $coveredCandidateEstimate),
                'covered_after_planned_candidate_estimate' => $coveredAfterPlannedEstimate,
                'remaining_after_planned_candidate_estimate' => $candidateCount === null
                    ? null
                    : max(0, $candidateCount - $coveredAfterPlannedEstimate),
            ];
        }

        return [
            'schema' => 'face_person_source_repair_packet_runway.v1',
            'candidate_count_guard_enabled' => (bool) ($policy['candidate_count_guard_enabled'] ?? false),
            'by_status' => $byStatus,
        ];
    }

    /**
     * @param  array<int,bool>  $used
     */
    private function nextOffset(array $used, int $offsetStep, int $maxOffset): ?int
    {
        for ($offset = 0; $offset <= $maxOffset; $offset += $offsetStep) {
            if (! isset($used[$offset])) {
                return $offset;
            }
        }

        return null;
    }

    /**
     * @return array<string,int>
     */
    private function candidateCountsByStatus(int $treeId): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('ops:face-person-source-provenance-report', [
            '--tree' => $treeId,
            '--json' => true,
            '--compact' => true,
            '--limit' => 1,
        ], $output);

        if ($exitCode !== self::SUCCESS) {
            return [];
        }

        try {
            $decoded = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $counts = [];
        foreach (is_array($decoded['status_counts'] ?? null) ? $decoded['status_counts'] : [] as $status => $count) {
            if (is_string($status) && is_numeric($count)) {
                $counts[$status] = max(0, (int) $count);
            }
        }

        $coverage = is_array($decoded['coverage_gap_rollups'] ?? null) ? $decoded['coverage_gap_rollups'] : [];
        $counts['coverage_visible_unnamed_unlinked_face'] = array_sum(array_map(
            static fn (array $row): int => max(0, (int) ($row['face_count'] ?? 0)),
            array_filter($coverage['visible_unnamed_unlinked_faces'] ?? [], 'is_array')
        ));
        $counts['coverage_unlinked_photo_media'] = array_sum(array_map(
            static fn (array $row): int => max(0, (int) ($row['photo_count'] ?? 0)),
            array_filter($coverage['unlinked_photo_media'] ?? [], 'is_array')
        ));

        return $counts;
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function runRepairPlan(array $plan): array
    {
        $output = new BufferedOutput;
        $exitCode = Artisan::call('ops:face-person-source-repair-plan', [
            '--tree' => (int) $plan['tree_id'],
            '--status' => (string) $plan['status'],
            '--limit' => (int) $plan['limit'],
            '--offset' => (int) $plan['offset'],
            '--write' => true,
            '--confirm' => true,
            '--json' => true,
            '--compact' => true,
        ], $output);

        $decoded = null;
        try {
            $decoded = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $decoded = null;
        }

        return [
            'status' => (string) $plan['status'],
            'offset' => (int) $plan['offset'],
            'limit' => (int) $plan['limit'],
            'exit_code' => $exitCode,
            'review_packets_created' => is_array($decoded) ? (int) ($decoded['summary']['review_packets_created'] ?? 0) : 0,
            'review_packets_reused' => is_array($decoded) ? (int) ($decoded['summary']['review_packets_reused'] ?? 0) : 0,
            'nested_next_action' => is_array($decoded) ? (string) ($decoded['next_action'] ?? '') : 'nested_payload_unavailable',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairPlanConfig(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', 'face_person_source_repair_plan']
            );
        } catch (Throwable) {
            return ['__error' => 'face_person_source_repair_plan_config_unavailable'];
        }

        if ($row === null) {
            return ['__error' => 'face_person_source_repair_plan_config_missing'];
        }

        $decoded = json_decode((string) $row->config_value, true);
        if (! is_array($decoded)) {
            return ['__error' => 'face_person_source_repair_plan_config_invalid_json'];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactIfRequested(array $payload): array
    {
        if (! (bool) $this->option('compact')) {
            return $payload;
        }

        unset($payload['results']);
        $payload['plans'] = array_map(
            fn (array $plan): array => [
                'status' => (string) ($plan['status'] ?? ''),
                'limit' => (int) ($plan['limit'] ?? 0),
                'offset' => (int) ($plan['offset'] ?? 0),
            ],
            is_array($payload['plans'] ?? null) ? $payload['plans'] : []
        );
        $payload['compact'] = true;

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face/photo repair packet refill: planned=%d created=%d reused=%d next=%s',
            (int) ($payload['summary']['planned_packets'] ?? 0),
            (int) ($payload['summary']['review_packets_created'] ?? 0),
            (int) ($payload['summary']['review_packets_reused'] ?? 0),
            (string) ($payload['next_action'] ?? 'unknown')
        ));
    }

    private function failPayload(string $error): int
    {
        $payload = [
            'command' => 'ops:face-person-source-repair-packet-refill',
            'error' => $error,
            'read_only' => true,
            'mutation_allowed' => false,
            'review_packet_write_allowed' => false,
            'summary' => [
                'planned_packets' => 0,
                'attempted_packets' => 0,
                'review_packets_created' => 0,
                'review_packets_reused' => 0,
                'identity_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'metadata_files_changed' => 0,
            ],
        ];

        $this->renderPayload($payload);

        return self::FAILURE;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
