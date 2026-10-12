<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyReviewPressureService;
use App\Services\ScheduledJobService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenealogyManagerCommand extends Command
{
    protected $signature = 'genealogy:manager
                            {--tree=4 : Genealogy tree ID to manage}
                            {--execute : Run eligible scheduled jobs through scheduler:run}
                            {--dry-run : Force observe-only mode even when --execute is present}
                            {--json : Emit JSON output}
                            {--compact : Omit verbose command output fields from JSON}';

    protected $description = 'Table-driven manager for Genea supervisor lanes and review-safe processing runway';

    public function __construct(
        private ScheduledJobService $scheduledJobService,
        private GenealogyReviewPressureService $reviewPressureService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $config = $this->managerConfig();
        $pressurePolicy = is_array($config['review_pressure'] ?? null) ? $config['review_pressure'] : [];
        $reviewWindowDays = max(1, min(30, (int) ($pressurePolicy['window_days'] ?? 7)));
        $reviewBacklog = $this->reviewBacklogCounts($reviewWindowDays);
        $faceRepair = $this->faceRepairState($treeId, $pressurePolicy, $reviewBacklog);

        $payload = [
            'status' => 'ok',
            'scope' => 'genealogy_manager',
            'tree_id' => $treeId,
            'execute' => $execute,
            'dry_run' => ! $execute,
            'generated_at' => now()->toIso8601String(),
            'policy_source' => 'system_configs.genealogy.supervisor_manager',
            'queue' => $this->queueCounts($treeId),
            'review_backlog' => $reviewBacklog,
            'face_repair' => $faceRepair,
            'next_operator_action' => $faceRepair['review_pressure']['next_operator_action'] ?? null,
            'lanes' => [],
            'summary' => [
                'configured_lanes' => 0,
                'eligible_lanes' => 0,
                'started_lanes' => 0,
                'blocked_lanes' => 0,
                'waiting_lanes' => 0,
            ],
            'safety' => [
                'canonical_writes' => false,
                'direct_batch_apply' => false,
                'guarded_source_media_capture' => true,
                'face_identity_automation' => false,
                'visible_chrome_capture_requires_operator' => true,
                'operator_review_required' => true,
                'scheduler_bookkeeping_required' => true,
            ],
        ];

        if (isset($config['__error'])) {
            $payload['status'] = 'warning';
            $payload['config_error'] = $config['__error'];

            return $this->emitPayload($payload);
        }

        $payload['manager_enabled'] = (bool) ($config['enabled'] ?? false);
        $maxJobsPerPass = $this->positiveInt($config['max_jobs_per_pass'] ?? null);
        $lanes = is_array($config['lanes'] ?? null) ? $config['lanes'] : [];
        $payload['summary']['configured_lanes'] = count($lanes);
        $started = 0;

        foreach ($lanes as $laneConfig) {
            if (! is_array($laneConfig)) {
                continue;
            }

            $lane = $this->evaluateLane($laneConfig, $payload);
            if (($lane['decision'] ?? null) === 'run') {
                $payload['summary']['eligible_lanes']++;
            } elseif (($lane['decision'] ?? null) === 'blocked') {
                $payload['summary']['blocked_lanes']++;
            } else {
                $payload['summary']['waiting_lanes']++;
            }

            if ($execute && $payload['manager_enabled'] && ($lane['decision'] ?? null) === 'run') {
                if ($maxJobsPerPass !== null && $started >= $maxJobsPerPass) {
                    $lane['decision'] = 'waiting';
                    $lane['reason'] = 'max_jobs_per_pass_reached';
                    $payload['summary']['eligible_lanes']--;
                    $payload['summary']['waiting_lanes']++;
                } else {
                    $lane = $this->runLane($lane);
                    $started += (int) (($lane['started'] ?? false) ? 1 : 0);
                    if (($lane['exit_code'] ?? 0) !== 0) {
                        $payload['status'] = 'warning';
                    }
                }
            }

            if (($lane['decision'] ?? null) === 'blocked') {
                $payload['status'] = 'warning';
            }

            $payload['lanes'][] = $lane;
        }

        $payload['summary']['started_lanes'] = $started;

        if (! $payload['manager_enabled']) {
            $payload['status'] = 'warning';
            $payload['manager_disabled_reason'] = 'policy_disabled';
        }

        return $this->emitPayload($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emitPayload(array $payload): int
    {
        if ((bool) $this->option('compact')) {
            unset($payload['tree_id']);
            $payload['tree_scope'] = 'single_tree';
            $payload['tree_filter_applied'] = true;
            $payload['lanes'] = array_map(
                fn (array $lane): array => $this->compactLaneForScheduledOutput($lane),
                is_array($payload['lanes'] ?? null) ? $payload['lanes'] : []
            );
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Genealogy manager: status=%s lanes=%d eligible=%d started=%d pending=%d review=%d',
                $payload['status'] ?? 'unknown',
                (int) ($payload['summary']['configured_lanes'] ?? 0),
                (int) ($payload['summary']['eligible_lanes'] ?? 0),
                (int) ($payload['summary']['started_lanes'] ?? 0),
                (int) ($payload['queue']['pending'] ?? 0),
                (int) ($payload['review_backlog']['pending_total'] ?? 0)
            ));
        }

        return ($payload['status'] ?? 'warning') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function compactLaneForScheduledOutput(array $lane): array
    {
        if (array_key_exists('name', $lane)) {
            $lane['lane_label'] = (string) $lane['name'];
            unset($lane['name']);
        }
        unset($lane['command_output']);

        if (is_array($lane['triggers'] ?? null)) {
            $lane['triggers'] = array_map(
                function (array $trigger): array {
                    if (array_key_exists('name', $trigger)) {
                        $trigger['trigger_label'] = (string) $trigger['name'];
                        unset($trigger['name']);
                    }

                    return $trigger;
                },
                $lane['triggers']
            );
        }

        return $lane;
    }

    /**
     * @return array<string,mixed>
     */
    private function managerConfig(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['genealogy', 'supervisor_manager']
            );
        } catch (Throwable) {
            return ['__error' => 'genealogy_supervisor_manager_config_unavailable'];
        }

        if ($row === null) {
            return ['__error' => 'genealogy_supervisor_manager_config_missing'];
        }

        $decoded = json_decode((string) $row->config_value, true);
        if (! is_array($decoded)) {
            return ['__error' => 'genealogy_supervisor_manager_config_invalid_json'];
        }

        if (! array_key_exists('enabled', $decoded) || ! is_array($decoded['lanes'] ?? null)) {
            return ['__error' => 'genealogy_supervisor_manager_config_missing_keys'];
        }

        return $decoded;
    }

    /**
     * @return array{pending:int,in_progress:int,completed:int,skipped:int,failed:int,total:int}
     */
    private function queueCounts(int $treeId): array
    {
        try {
            $row = DB::selectOne("
                SELECT
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    COUNT(*) AS total
                FROM genealogy_research_queue
                WHERE tree_id = ?
            ", [$treeId]);
        } catch (Throwable) {
            $row = null;
        }

        return [
            'pending' => (int) ($row->pending ?? 0),
            'in_progress' => (int) ($row->in_progress ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'skipped' => (int) ($row->skipped ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'total' => (int) ($row->total ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewBacklogCounts(int $windowDays): array
    {
        try {
            $row = DB::selectOne("
                SELECT
                    SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) THEN 1 ELSE 0 END) AS pending_total,
                    SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) AND review_type = 'genealogy_finding' THEN 1 ELSE 0 END) AS genealogy_finding,
                    SUM(CASE WHEN status = 'pending' AND (expires_at IS NULL OR expires_at > NOW()) AND review_type = 'genealogy_review_packet' THEN 1 ELSE 0 END) AS genealogy_review_packet,
                    SUM(
                        CASE WHEN status = 'pending'
                               AND (expires_at IS NULL OR expires_at > NOW())
                               AND review_type = 'genealogy_finding'
                               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.proposals[0].change_type')) = 'source_add'
                               AND JSON_CONTAINS(JSON_EXTRACT(details, '$.actionability_gate.reasons'), JSON_QUOTE('below_actionability_confidence'))
                               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.ds_governance.writeback_allowed')) = 'false'
                             THEN 1 ELSE 0 END
                    ) AS weak_source_add_findings,
                    SUM(CASE WHEN review_type = 'genealogy_review_packet'
                                  AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowDays} DAY)
                             THEN 1 ELSE 0 END) AS packet_created_in_window,
                    SUM(CASE WHEN review_type = 'genealogy_review_packet'
                                  AND reviewed_at >= DATE_SUB(NOW(), INTERVAL {$windowDays} DAY)
                                  AND status IN ('approved', 'rejected', 'reviewed', 'resolved', 'ignored', 'dismissed')
                             THEN 1 ELSE 0 END) AS packet_cleared_in_window,
                    MIN(CASE WHEN review_type = 'genealogy_review_packet'
                                  AND status = 'pending'
                                  AND (expires_at IS NULL OR expires_at > NOW())
                             THEN created_at END) AS oldest_packet_created_at
                FROM agent_review_queue
            ", []);
        } catch (Throwable) {
            $row = null;
        }

        $oldestPacketCreatedAt = $this->nullableString($row->oldest_packet_created_at ?? null);
        $oldestPendingDays = null;
        if ($oldestPacketCreatedAt !== null) {
            try {
                $oldestPendingDays = max(0, (int) floor(Carbon::parse($oldestPacketCreatedAt)->diffInHours(now()) / 24));
            } catch (Throwable) {
                $oldestPendingDays = null;
            }
        }

        return [
            'pending_total' => (int) ($row->pending_total ?? 0),
            'by_type' => [
                'genealogy_finding' => (int) ($row->genealogy_finding ?? 0),
                'genealogy_review_packet' => (int) ($row->genealogy_review_packet ?? 0),
            ],
            'triage' => [
                'weak_source_add_findings' => (int) ($row->weak_source_add_findings ?? 0),
            ],
            'flow' => [
                'window_days' => $windowDays,
                'packets_created' => (int) ($row->packet_created_in_window ?? 0),
                'packets_cleared' => (int) ($row->packet_cleared_in_window ?? 0),
                'oldest_pending_days' => $oldestPendingDays,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function faceRepairState(int $treeId, array $pressurePolicy, array $reviewBacklog): array
    {
        $findingType = null;
        $maxPendingTotal = null;

        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', 'face_person_source_repair_plan']
            );
            $decoded = $row !== null ? json_decode((string) $row->config_value, true) : null;
            if (is_array($decoded)) {
                $findingType = is_string($decoded['finding_type'] ?? null) ? trim($decoded['finding_type']) : null;
                $maxPendingTotal = $this->positiveInt($decoded['refill_policy']['max_pending_total'] ?? null);
            }
        } catch (Throwable) {
            $findingType = null;
        }

        $pending = 0;
        if ($findingType !== null && $findingType !== '') {
            try {
                $row = DB::selectOne(
                    'SELECT COUNT(*) AS pending FROM agent_review_queue WHERE status = ? AND review_type = ? AND finding_type = ?',
                    ['pending', 'genealogy_review_packet', $findingType]
                );
                $pending = (int) ($row->pending ?? 0);
            } catch (Throwable) {
                $pending = 0;
            }
        }

        $rawBelowConfiguredRunway = $maxPendingTotal !== null && $pending < $maxPendingTotal;
        $reviewFlow = is_array($reviewBacklog['flow'] ?? null) ? $reviewBacklog['flow'] : [];
        $reviewPressure = $this->reviewPressureService->evaluate($pressurePolicy, $pending, $maxPendingTotal, [
            'created' => (int) ($reviewFlow['packets_created'] ?? 0),
            'cleared' => (int) ($reviewFlow['packets_cleared'] ?? 0),
            'oldest_pending_days' => $reviewFlow['oldest_pending_days'] ?? null,
        ]);

        return [
            'config_source' => 'system_configs.faces.face_person_source_repair_plan',
            'finding_type' => $findingType,
            'pending_packets' => $pending,
            'max_pending_total' => $maxPendingTotal,
            'raw_below_configured_runway' => $rawBelowConfiguredRunway,
            'below_configured_runway' => $rawBelowConfiguredRunway && (bool) ($reviewPressure['refill_allowed'] ?? true),
            'review_pressure' => $reviewPressure,
            'coverage_gap_units' => $this->faceCoverageGapUnits($treeId),
        ];
    }

    private function faceCoverageGapUnits(int $treeId): int
    {
        try {
            $face = DB::selectOne(
                "SELECT COUNT(*) AS total
                   FROM file_registry_faces frf
                   JOIN file_registry fr ON fr.id = frf.file_registry_id
                   LEFT JOIN genealogy_persons gp ON gp.id = frf.genealogy_person_id
                   LEFT JOIN face_identity_provenance fip ON fip.file_registry_face_id = frf.id
                  WHERE frf.hidden = 0
                    AND TRIM(COALESCE(frf.person_name, '')) = ''
                    AND frf.genealogy_person_id IS NULL
                    AND fip.id IS NULL
                    AND (gp.tree_id = ?
                         OR fip.tree_id = ?
                         OR (gp.id IS NULL AND fip.id IS NULL))",
                [$treeId, $treeId]
            );
            $photos = DB::selectOne(
                "SELECT COUNT(*) AS total
                   FROM genealogy_media gm
                   LEFT JOIN (
                        SELECT DISTINCT media_id
                        FROM genealogy_person_media
                   ) linked ON linked.media_id = gm.id
                  WHERE gm.tree_id = ?
                    AND gm.media_type = 'photo'
                    AND linked.media_id IS NULL",
                [$treeId]
            );
        } catch (Throwable) {
            return 0;
        }

        return (int) ($face->total ?? 0) + (int) ($photos->total ?? 0);
    }

    /**
     * @param  array<string,mixed>  $laneConfig
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function evaluateLane(array $laneConfig, array $payload): array
    {
        $name = $this->cleanCode($laneConfig['name'] ?? null) ?? 'unnamed_lane';
        $jobName = $this->cleanCode($laneConfig['job_name'] ?? null);
        $lane = [
            'name' => $name,
            'job_name' => $jobName,
            'enabled' => (bool) ($laneConfig['enabled'] ?? false),
            'decision' => 'waiting',
            'reason' => 'not_evaluated',
            'triggers' => [],
            'policy_source' => 'system_configs.genealogy.supervisor_manager.lanes',
        ];

        if (! $lane['enabled']) {
            $lane['decision'] = 'waiting';
            $lane['reason'] = 'lane_disabled';

            return $lane;
        }

        if ($jobName === null) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'job_name_missing';

            return $lane;
        }

        $job = $this->scheduledJobService->getJobByName($jobName);
        if (! $job) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'scheduled_job_missing';

            return $lane;
        }

        $lane['job'] = $this->jobSnapshot($job);
        $allowWhileRunning = (bool) ($laneConfig['allow_while_running'] ?? false);
        if (! $allowWhileRunning && ((int) ($job->running_count ?? 0) > 0 || ($job->last_run_status ?? null) === 'running')) {
            $lane['decision'] = 'waiting';
            $lane['reason'] = 'job_already_running';

            return $lane;
        }

        $cooldownMinutes = $this->nonNegativeInt($laneConfig['min_minutes_between_runs'] ?? null);
        $minutesSinceRun = $this->minutesSince($job->last_run_at ?? null);
        $lane['cooldown'] = [
            'min_minutes_between_runs' => $cooldownMinutes,
            'minutes_since_last_run' => $minutesSinceRun,
            'passed' => $cooldownMinutes === null || $minutesSinceRun === null || $minutesSinceRun >= $cooldownMinutes,
        ];
        if ($cooldownMinutes !== null && $minutesSinceRun !== null) {
            $lane['cooldown']['remaining_minutes'] = max(0, $cooldownMinutes - $minutesSinceRun);
        }

        $triggers = is_array($laneConfig['triggers'] ?? null) ? $laneConfig['triggers'] : [];
        if (! $lane['cooldown']['passed']) {
            $lane['triggers'] = $this->evaluateTriggers($triggers, $payload);
            $lane['trigger_mode'] = $this->triggerMode($laneConfig);
            $lane['would_run_after_cooldown'] = $lane['triggers'] !== []
                && $this->triggersPass($lane['triggers'], $lane['trigger_mode']);
            $lane['decision'] = 'waiting';
            $lane['reason'] = 'cooldown_active';

            return $lane;
        }

        $lane['triggers'] = $this->evaluateTriggers($triggers, $payload);
        if ($lane['triggers'] === []) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'no_triggers_configured';

            return $lane;
        }

        $triggerMode = $this->triggerMode($laneConfig);
        $triggered = $this->triggersPass($lane['triggers'], $triggerMode);
        $lane['trigger_mode'] = $triggerMode;

        if (! $triggered) {
            $lane['decision'] = 'waiting';
            $lane['reason'] = 'triggers_not_met';

            return $lane;
        }

        $lane['decision'] = 'run';
        $lane['reason'] = 'eligible';

        return $lane;
    }

    /**
     * @param  array<string,mixed>  $laneConfig
     */
    private function triggerMode(array $laneConfig): string
    {
        return ($laneConfig['trigger_mode'] ?? 'all') === 'any' ? 'any' : 'all';
    }

    /**
     * @param  list<array<string,mixed>>  $triggers
     */
    private function triggersPass(array $triggers, string $triggerMode): bool
    {
        $passes = array_map(static fn (array $trigger): bool => (bool) ($trigger['passed'] ?? false), $triggers);

        return $triggerMode === 'any'
            ? in_array(true, $passes, true)
            : ! in_array(false, $passes, true);
    }

    /**
     * @param  array<string,mixed>  $triggers
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function evaluateTriggers(array $triggers, array $payload): array
    {
        $evaluated = [];

        if (($minimum = $this->nonNegativeInt($triggers['queue_pending_min'] ?? null)) !== null) {
            $pending = (int) ($payload['queue']['pending'] ?? 0);
            $evaluated[] = [
                'name' => 'queue_pending_min',
                'configured' => $minimum,
                'observed' => $pending,
                'passed' => $pending >= $minimum,
            ];
        }

        if (($maximum = $this->nonNegativeInt($triggers['queue_pending_max'] ?? null)) !== null) {
            $pending = (int) ($payload['queue']['pending'] ?? 0);
            $evaluated[] = [
                'name' => 'queue_pending_max',
                'configured' => $maximum,
                'observed' => $pending,
                'passed' => $pending <= $maximum,
            ];
        }

        if (($slotJobName = $this->cleanCode($triggers['slot_job_name'] ?? null)) !== null
            && ($minimum = $this->nonNegativeInt($triggers['slots_available_min'] ?? null)) !== null) {
            $slotJob = $this->scheduledJobService->getJobByName($slotJobName);
            $running = max(0, (int) ($slotJob->running_count ?? 0));
            $resolvedMax = $slotJob ? max(1, $this->scheduledJobService->resolveMaxParallel($slotJob)) : 0;
            $available = max(0, $resolvedMax - $running);
            $evaluated[] = [
                'name' => 'slots_available_min',
                'job_name' => $slotJobName,
                'configured' => $minimum,
                'observed' => $available,
                'resolved_max' => $resolvedMax,
                'running' => $running,
                'passed' => $slotJob !== null && $available >= $minimum,
            ];
        }

        if (($minutes = $this->nonNegativeInt($triggers['stale_minutes'] ?? null)) !== null
            && ($jobName = $this->cleanCode($triggers['stale_job_name'] ?? null)) !== null) {
            $job = $this->scheduledJobService->getJobByName($jobName);
            $minutesSince = $job ? $this->minutesSince($job->last_completed_at ?? $job->last_run_at ?? null) : null;
            $evaluated[] = [
                'name' => 'stale_minutes',
                'job_name' => $jobName,
                'configured' => $minutes,
                'observed' => $minutesSince,
                'passed' => $job !== null && ($minutesSince === null || $minutesSince >= $minutes),
            ];
        }

        if (array_key_exists('face_repair_pending_below_max', $triggers)) {
            $faceRepair = is_array($payload['face_repair'] ?? null) ? $payload['face_repair'] : [];
            $evaluated[] = [
                'name' => 'face_repair_pending_below_max',
                'configured' => (bool) $triggers['face_repair_pending_below_max'],
                'observed' => (bool) ($faceRepair['below_configured_runway'] ?? false),
                'pending_packets' => (int) ($faceRepair['pending_packets'] ?? 0),
                'max_pending_total' => $faceRepair['max_pending_total'] ?? null,
                'review_pressure_state' => $faceRepair['review_pressure']['state'] ?? 'unknown',
                'review_pressure_reasons' => $faceRepair['review_pressure']['reasons'] ?? [],
                'passed' => (bool) $triggers['face_repair_pending_below_max']
                    && (bool) ($faceRepair['below_configured_runway'] ?? false),
            ];
        }

        if (($minimum = $this->nonNegativeInt($triggers['face_coverage_gap_units_min'] ?? null)) !== null) {
            $coverageGapUnits = (int) ($payload['face_repair']['coverage_gap_units'] ?? 0);
            $evaluated[] = [
                'name' => 'face_coverage_gap_units_min',
                'configured' => $minimum,
                'observed' => $coverageGapUnits,
                'passed' => $coverageGapUnits >= $minimum,
            ];
        }

        if (($minimum = $this->nonNegativeInt($triggers['weak_source_add_min'] ?? null)) !== null) {
            $weakFindings = (int) ($payload['review_backlog']['triage']['weak_source_add_findings'] ?? 0);
            $evaluated[] = [
                'name' => 'weak_source_add_min',
                'configured' => $minimum,
                'observed' => $weakFindings,
                'passed' => $weakFindings >= $minimum,
            ];
        }

        return $evaluated;
    }

    /**
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function runLane(array $lane): array
    {
        $jobName = (string) ($lane['job_name'] ?? '');
        $startedAt = now()->toIso8601String();
        $exitCode = Artisan::call('scheduler:run', [
            '--job' => $jobName,
            '--force' => true,
        ]);
        $output = trim(Artisan::output());

        $lane['started'] = $exitCode === 0;
        $lane['exit_code'] = $exitCode;
        $lane['command_output'] = $output;
        $lane['scheduler_run'] = [
            'job_name' => $jobName,
            'force' => true,
            'forced_sync' => false,
            'respects_run_in_background' => true,
            'started_at' => $startedAt,
            'completed_at' => now()->toIso8601String(),
            'exit_code' => $exitCode,
            'output_summary' => $this->commandOutputSummary($output),
        ];
        $lane['decision'] = $exitCode === 0 ? 'started' : 'blocked';
        $lane['reason'] = $exitCode === 0 ? 'scheduler_run_completed' : 'scheduler_run_failed';

        return $lane;
    }

    private function commandOutputSummary(string $output): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $output));

        return strlen($normalized) > 240 ? substr($normalized, 0, 237).'...' : $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function jobSnapshot(object $job): array
    {
        return [
            'enabled' => (bool) ($job->enabled ?? false),
            'last_run_status' => $this->cleanCode($job->last_run_status ?? null),
            'running_count' => (int) ($job->running_count ?? 0),
            'max_parallel' => (int) ($job->max_parallel ?? 1),
            'resource_profile' => $this->cleanCode($job->resource_profile ?? null),
            'workload_family' => $this->cleanCode($job->workload_family ?? null),
            'backlog_metric' => $this->cleanCode($job->backlog_metric ?? null),
            'last_run_at' => $this->nullableString($job->last_run_at ?? null),
            'last_completed_at' => $this->nullableString($job->last_completed_at ?? null),
        ];
    }

    private function minutesSince(mixed $timestamp): ?int
    {
        if ($timestamp === null || trim((string) $timestamp) === '') {
            return null;
        }

        try {
            return max(0, (int) floor(Carbon::parse((string) $timestamp)->diffInSeconds(now()) / 60));
        } catch (Throwable) {
            return null;
        }
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    private function cleanCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $code = trim((string) $value);
        if ($code === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $code) !== 1) {
            return null;
        }

        return $code;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
