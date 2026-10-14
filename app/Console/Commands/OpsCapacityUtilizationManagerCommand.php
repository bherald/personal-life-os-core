<?php

namespace App\Console\Commands;

use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

class OpsCapacityUtilizationManagerCommand extends Command
{
    private const CONFIG_KEY = 'ops.capacity_utilization_manager';

    protected $signature = 'ops:capacity-utilization-manager
                            {--execute : Run eligible scheduled jobs through scheduler:run}
                            {--dry-run : Force observe-only mode even when --execute is present}
                            {--json : Emit JSON output}
                            {--compact : Omit verbose command output fields from JSON}';

    protected $description = 'Table-driven manager that matches safe queued work to available private LLM capacity';

    public function __construct(
        private ScheduledJobService $scheduledJobService,
        private SystemConfigService $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute') && ! (bool) $this->option('dry-run');
        $policy = $this->managerPolicy();

        $payload = [
            'status' => 'ok',
            'scope' => 'ops_capacity_utilization_manager',
            'execute' => $execute,
            'dry_run' => ! $execute,
            'generated_at' => now()->toIso8601String(),
            'policy_source' => 'system_configs.'.self::CONFIG_KEY,
            'manager_enabled' => false,
            'capacity' => [],
            'work' => [],
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
                'face_identity_automation' => false,
                'operator_review_required' => true,
                'scheduler_bookkeeping_required' => true,
            ],
        ];

        if (isset($policy['__error'])) {
            $payload['status'] = 'warning';
            $payload['config_error'] = $policy['__error'];

            return $this->emitPayload($payload);
        }

        $payload['manager_enabled'] = (bool) ($policy['enabled'] ?? false);
        $payload['capacity'] = $this->capacitySnapshot(
            is_array($policy['capacity'] ?? null) ? $policy['capacity'] : []
        );
        $payload['work'] = $this->workloadSnapshot(
            is_array($policy['workload'] ?? null) ? $policy['workload'] : []
        );

        $maxJobsPerPass = $this->positiveInt($policy['max_jobs_per_pass'] ?? null);
        $lanes = is_array($policy['lanes'] ?? null) ? $policy['lanes'] : [];
        $payload['summary']['configured_lanes'] = count($lanes);
        $started = 0;

        foreach ($lanes as $laneConfig) {
            if (! is_array($laneConfig)) {
                continue;
            }

            $lane = $this->evaluateLane($laneConfig, $payload, $policy);
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
            $payload['compact'] = true;
            $payload['capacity'] = $this->compactCapacityForScheduledOutput(
                is_array($payload['capacity'] ?? null) ? $payload['capacity'] : []
            );
            if (is_array($payload['work'] ?? null) && array_key_exists('tree_id', $payload['work'])) {
                unset($payload['work']['tree_id']);
                $payload['work']['tree_scope'] = 'single_tree';
                $payload['work']['tree_filter_applied'] = true;
            }
            $payload['lanes'] = array_map(
                fn (array $lane): array => $this->compactLaneForScheduledOutput($lane),
                is_array($payload['lanes'] ?? null) ? $payload['lanes'] : []
            );
        }

        if ((bool) $this->option('json')) {
            $jsonFlags = JSON_UNESCAPED_SLASHES;
            if (! (bool) $this->option('compact')) {
                $jsonFlags |= JSON_PRETTY_PRINT;
            }
            $this->line(json_encode($payload, $jsonFlags));
        } else {
            $this->info(sprintf(
                'Capacity utilization manager: status=%s lanes=%d eligible=%d started=%d idle_llm_slots=%d safe_work=%d',
                $payload['status'] ?? 'unknown',
                (int) ($payload['summary']['configured_lanes'] ?? 0),
                (int) ($payload['summary']['eligible_lanes'] ?? 0),
                (int) ($payload['summary']['started_lanes'] ?? 0),
                (int) ($payload['capacity']['idle_private_text_slots'] ?? 0),
                (int) ($payload['work']['safe_work_units'] ?? 0),
            ));
        }

        return ($payload['status'] ?? 'warning') === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $capacity
     * @return array<string,mixed>
     */
    private function compactCapacityForScheduledOutput(array $capacity): array
    {
        $watched = [];
        foreach (is_array($capacity['watched_instances'] ?? null) ? $capacity['watched_instances'] : [] as $instance) {
            if (! is_array($instance)) {
                continue;
            }
            $watched[] = [
                'instance_id' => (string) ($instance['instance_id'] ?? ''),
                'instance_type' => $instance['instance_type'] ?? null,
                'eligible' => (bool) ($instance['eligible'] ?? false),
                'healthy' => (bool) ($instance['healthy'] ?? false),
                'slots' => (int) ($instance['slots'] ?? 0),
                'missing' => (bool) ($instance['missing'] ?? false),
            ];
        }

        return [
            'status' => (string) ($capacity['status'] ?? 'unknown'),
            'window_minutes' => $capacity['window_minutes'] ?? null,
            'private_text_slots' => (int) ($capacity['private_text_slots'] ?? 0),
            'local_private_text_slots' => (int) ($capacity['local_private_text_slots'] ?? 0),
            'running_llm_jobs' => (int) ($capacity['running_llm_jobs'] ?? 0),
            'idle_private_text_slots' => (int) ($capacity['idle_private_text_slots'] ?? 0),
            'watched_available' => (int) ($capacity['watched_available'] ?? 0),
            'watched_instances' => $watched,
        ];
    }

    /**
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function compactLaneForScheduledOutput(array $lane): array
    {
        $triggers = is_array($lane['triggers'] ?? null) ? $lane['triggers'] : [];
        $triggerSummary = $this->compactTriggerSummary($triggers);

        $compact = [
            'lane_label' => (string) ($lane['name'] ?? $lane['lane_label'] ?? 'unnamed_lane'),
            'job_name' => $lane['job_name'] ?? null,
            'enabled' => (bool) ($lane['enabled'] ?? false),
            'decision' => (string) ($lane['decision'] ?? 'unknown'),
            'reason' => (string) ($lane['reason'] ?? 'unknown'),
            'trigger_mode' => $lane['trigger_mode'] ?? null,
            'trigger_summary' => $triggerSummary,
        ];

        if (array_key_exists('would_run_after_cooldown', $lane)) {
            $compact['would_run_after_cooldown'] = (bool) $lane['would_run_after_cooldown'];
        }

        if (is_array($lane['cooldown'] ?? null)) {
            $compact['cooldown'] = [
                'min_minutes_between_runs' => $lane['cooldown']['min_minutes_between_runs'] ?? null,
                'minutes_since_last_run' => $lane['cooldown']['minutes_since_last_run'] ?? null,
                'remaining_minutes' => $lane['cooldown']['remaining_minutes'] ?? null,
                'passed' => (bool) ($lane['cooldown']['passed'] ?? false),
            ];
        }

        if (is_array($lane['job_safety'] ?? null)) {
            $compact['job_safety'] = [
                'allowed' => (bool) ($lane['job_safety']['allowed'] ?? false),
                'blocked_reasons' => is_array($lane['job_safety']['blocked_reasons'] ?? null)
                    ? array_values($lane['job_safety']['blocked_reasons'])
                    : [],
                'resource_profile' => $lane['job_safety']['resource_profile'] ?? null,
            ];
        }

        if (is_array($lane['job'] ?? null)) {
            $compact['job'] = [
                'enabled' => (bool) ($lane['job']['enabled'] ?? false),
                'last_run_status' => $lane['job']['last_run_status'] ?? null,
                'running_count' => (int) ($lane['job']['running_count'] ?? 0),
                'max_parallel' => (int) ($lane['job']['max_parallel'] ?? 1),
                'resource_profile' => $lane['job']['resource_profile'] ?? null,
                'workload_family' => $lane['job']['workload_family'] ?? null,
                'backlog_metric' => $lane['job']['backlog_metric'] ?? null,
            ];
        }

        if (array_key_exists('started', $lane)) {
            $compact['started'] = (bool) $lane['started'];
            $compact['exit_code'] = (int) ($lane['exit_code'] ?? 0);
        }

        if (is_array($lane['scheduler_run'] ?? null)) {
            $compact['scheduler_run'] = [
                'job_name' => $lane['scheduler_run']['job_name'] ?? $lane['job_name'] ?? null,
                'force' => (bool) ($lane['scheduler_run']['force'] ?? false),
                'forced_sync' => (bool) ($lane['scheduler_run']['forced_sync'] ?? false),
                'respects_run_in_background' => (bool) ($lane['scheduler_run']['respects_run_in_background'] ?? false),
                'exit_code' => (int) ($lane['scheduler_run']['exit_code'] ?? 0),
                'output_summary' => $lane['scheduler_run']['output_summary'] ?? null,
            ];
        }

        return $compact;
    }

    /**
     * @param  list<array<string,mixed>>  $triggers
     * @return array<string,mixed>
     */
    private function compactTriggerSummary(array $triggers): array
    {
        $failed = [];
        $passed = 0;

        foreach ($triggers as $trigger) {
            $label = (string) ($trigger['name'] ?? $trigger['trigger_label'] ?? 'unknown_trigger');
            if ((bool) ($trigger['passed'] ?? false)) {
                $passed++;
            } else {
                $failed[] = $label;
            }
        }

        return [
            'total' => count($triggers),
            'passed' => $passed,
            'failed' => count($failed),
            'failed_trigger_labels' => array_values(array_unique($failed)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function managerPolicy(): array
    {
        try {
            $policy = $this->config->get(self::CONFIG_KEY);
        } catch (Throwable) {
            return ['__error' => 'capacity_utilization_manager_policy_unavailable'];
        }

        if (! is_array($policy)) {
            return ['__error' => 'capacity_utilization_manager_policy_missing'];
        }

        foreach (['enabled', 'capacity', 'workload', 'lanes', 'allowed_job_names'] as $key) {
            if (! array_key_exists($key, $policy)) {
                return ['__error' => 'capacity_utilization_manager_policy_missing_keys'];
            }
        }

        if (! is_array($policy['capacity']) || ! is_array($policy['workload']) || ! is_array($policy['lanes'])) {
            return ['__error' => 'capacity_utilization_manager_policy_invalid_shape'];
        }

        if ($this->positiveInt($policy['workload']['tree_id'] ?? null) === null) {
            return ['__error' => 'capacity_utilization_manager_workload_policy_missing_keys'];
        }

        foreach ([
            'model_roles',
            'required_capability',
            'excluded_capabilities',
            'private_scopes',
            'local_instance_types',
            'watched_instance_ids',
            'running_resource_profiles',
            'window_minutes',
        ] as $key) {
            if (! array_key_exists($key, $policy['capacity'])) {
                return ['__error' => 'capacity_utilization_manager_capacity_policy_missing_keys'];
            }
        }

        return $policy;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function capacitySnapshot(array $policy): array
    {
        $roles = $this->stringList($policy['model_roles'] ?? []);
        $requiredCapability = $this->cleanCode($policy['required_capability'] ?? null);
        $excludedCapabilities = $this->stringList($policy['excluded_capabilities'] ?? []);
        $privateScopes = $this->stringList($policy['private_scopes'] ?? []);
        $localInstanceTypes = $this->stringList($policy['local_instance_types'] ?? []);
        $watchedInstanceIds = $this->stringList($policy['watched_instance_ids'] ?? []);
        $resourceProfiles = $this->stringList($policy['running_resource_profiles'] ?? []);
        $windowMinutes = $this->positiveInt($policy['window_minutes'] ?? null);

        $instances = [];
        $privateSlots = 0;
        $localSlots = 0;
        $watched = [];

        try {
            $rows = DB::select('
                SELECT instance_id, instance_name, instance_type, is_active, is_healthy, circuit_state,
                       routability, allows_private_data, data_privacy_scope, max_concurrent,
                       capabilities, config, last_success_at, success_rate, avg_response_ms
                FROM llm_instances
                WHERE is_active = 1
                ORDER BY priority ASC, instance_id ASC
            ');
        } catch (Throwable $e) {
            return [
                'status' => 'warning',
                'error' => 'llm_instances_unavailable',
                'message' => $e->getMessage(),
                'private_text_slots' => 0,
                'local_private_text_slots' => 0,
                'running_llm_jobs' => 0,
                'idle_private_text_slots' => 0,
                'watched_instances' => [],
                'watched_available' => 0,
                'window_minutes' => $windowMinutes,
            ];
        }

        foreach ($rows as $row) {
            $capabilities = $this->decodeJsonList($row->capabilities ?? null);
            $config = $this->decodeJsonObject($row->config ?? null);
            $models = is_array($config['models'] ?? null) ? $config['models'] : [];
            $modelRoles = array_values(array_filter(
                array_map('strval', array_keys($models)),
                static fn (string $role): bool => $role !== ''
            ));
            $slotCount = max(1, (int) ($row->max_concurrent ?? 1));
            $hasRequiredCapability = $requiredCapability !== null
                && in_array($requiredCapability, $capabilities, true);
            $hasExcludedCapability = false;
            foreach ($excludedCapabilities as $capability) {
                if (in_array($capability, $capabilities, true)) {
                    $hasExcludedCapability = true;
                    break;
                }
            }

            $roleMatched = $roles === [] || array_intersect($roles, $modelRoles) !== [];
            $scopeMatched = in_array((string) ($row->data_privacy_scope ?? ''), $privateScopes, true);
            $eligible = (int) ($row->is_active ?? 0) === 1
                && (int) ($row->is_healthy ?? 0) === 1
                && (string) ($row->circuit_state ?? '') !== 'open'
                && (string) ($row->routability ?? 'allowed') === 'allowed'
                && (int) ($row->allows_private_data ?? 0) === 1
                && $scopeMatched
                && $hasRequiredCapability
                && ! $hasExcludedCapability
                && $roleMatched;

            if ($eligible) {
                $privateSlots += $slotCount;
                if (in_array((string) ($row->instance_type ?? ''), $localInstanceTypes, true)) {
                    $localSlots += $slotCount;
                }
            }

            $instance = [
                'instance_id' => (string) ($row->instance_id ?? ''),
                'instance_type' => (string) ($row->instance_type ?? ''),
                'eligible' => $eligible,
                'slots' => $slotCount,
                'roles' => $modelRoles,
                'healthy' => (int) ($row->is_healthy ?? 0) === 1,
                'circuit_state' => (string) ($row->circuit_state ?? ''),
                'privacy_scope' => (string) ($row->data_privacy_scope ?? ''),
                'last_success_at' => $this->nullableString($row->last_success_at ?? null),
                'success_rate' => $row->success_rate !== null ? (float) $row->success_rate : null,
                'avg_response_ms' => $row->avg_response_ms !== null ? (float) $row->avg_response_ms : null,
            ];
            $instances[] = $instance;

            if (in_array($instance['instance_id'], $watchedInstanceIds, true)) {
                $watched[$instance['instance_id']] = $instance;
            }
        }

        foreach ($watchedInstanceIds as $instanceId) {
            if (! array_key_exists($instanceId, $watched)) {
                $watched[$instanceId] = [
                    'instance_id' => $instanceId,
                    'eligible' => false,
                    'healthy' => false,
                    'missing' => true,
                ];
            }
        }

        $running = $this->runningLlmJobs($resourceProfiles);
        $recentRequests = $this->recentLlmRequests($windowMinutes);

        return [
            'status' => 'ok',
            'window_minutes' => $windowMinutes,
            'capacity_model_roles' => $roles,
            'required_capability' => $requiredCapability,
            'excluded_capabilities' => $excludedCapabilities,
            'private_text_slots' => $privateSlots,
            'local_private_text_slots' => $localSlots,
            'running_llm_jobs' => $running,
            'idle_private_text_slots' => max(0, $privateSlots - $running),
            'watched_instances' => array_values($watched),
            'watched_available' => count(array_filter(
                $watched,
                static fn (array $instance): bool => (bool) ($instance['eligible'] ?? false)
            )),
            'instances' => $instances,
            'recent_requests' => $recentRequests,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function workloadSnapshot(array $policy): array
    {
        $treeId = $this->positiveInt($policy['tree_id'] ?? null) ?? 0;
        $queue = $this->genealogyQueueCounts($treeId);
        $reviewBacklog = $this->genealogyReviewBacklog();
        $faceRepair = $this->faceRepairState($treeId);

        return [
            'status' => 'ok',
            'tree_id' => $treeId,
            'genealogy_queue' => $queue,
            'genealogy_review_backlog' => $reviewBacklog,
            'face_repair' => $faceRepair,
            'safe_work_units' => (int) ($queue['pending'] ?? 0),
            'review_noise_units' => (int) ($reviewBacklog['triage']['weak_source_add_findings'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $laneConfig
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function evaluateLane(array $laneConfig, array $payload, array $policy): array
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
            'policy_source' => 'system_configs.'.self::CONFIG_KEY.'.lanes',
        ];

        if (! $lane['enabled']) {
            $lane['reason'] = 'lane_disabled';

            return $lane;
        }

        if ($jobName === null) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'job_name_missing';

            return $lane;
        }

        $allowedJobNames = $this->stringList($policy['allowed_job_names'] ?? []);
        if (! in_array($jobName, $allowedJobNames, true)) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'job_not_in_table_allowed_list';

            return $lane;
        }

        $job = $this->scheduledJobService->getJobByName($jobName);
        if (! $job) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'scheduled_job_missing';

            return $lane;
        }

        $lane['job'] = $this->jobSnapshot($job);
        $lane['job_safety'] = $this->jobSafetySnapshot($job, $policy, $jobName);
        if (! (bool) ($lane['job_safety']['allowed'] ?? false)) {
            $lane['decision'] = 'blocked';
            $lane['reason'] = 'scheduled_job_safety_policy_blocked';

            return $lane;
        }

        $allowWhileRunning = (bool) ($laneConfig['allow_while_running'] ?? false);
        if (! $allowWhileRunning && ((int) ($job->running_count ?? 0) > 0 || ($job->last_run_status ?? null) === 'running')) {
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

        $this->appendMinimumTrigger($evaluated, 'private_llm_capacity_min', $triggers, (int) ($payload['capacity']['private_text_slots'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'local_llm_capacity_min', $triggers, (int) ($payload['capacity']['local_private_text_slots'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'idle_llm_slots_min', $triggers, (int) ($payload['capacity']['idle_private_text_slots'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'watched_instances_available_min', $triggers, (int) ($payload['capacity']['watched_available'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'genealogy_queue_pending_min', $triggers, (int) ($payload['work']['genealogy_queue']['pending'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'safe_work_units_min', $triggers, (int) ($payload['work']['safe_work_units'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'weak_source_add_min', $triggers, (int) ($payload['work']['genealogy_review_backlog']['triage']['weak_source_add_findings'] ?? 0));
        $this->appendMinimumTrigger($evaluated, 'face_coverage_gap_units_min', $triggers, (int) ($payload['work']['face_repair']['coverage_gap_units'] ?? 0));

        $this->appendMaximumTrigger($evaluated, 'running_llm_jobs_max', $triggers, (int) ($payload['capacity']['running_llm_jobs'] ?? 0));
        $this->appendMaximumTrigger($evaluated, 'review_pending_max', $triggers, (int) ($payload['work']['genealogy_review_backlog']['pending_total'] ?? 0));
        $this->appendMaximumTrigger($evaluated, 'genealogy_queue_pending_max', $triggers, (int) ($payload['work']['genealogy_queue']['pending'] ?? 0));
        $this->appendMaximumTrigger($evaluated, 'weak_source_add_max', $triggers, (int) ($payload['work']['genealogy_review_backlog']['triage']['weak_source_add_findings'] ?? 0));
        $this->appendMaximumTrigger($evaluated, 'source_backed_packets_max', $triggers, (int) ($payload['work']['genealogy_review_backlog']['triage']['source_backed_packets'] ?? 0));

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
            $faceRepair = is_array($payload['work']['face_repair'] ?? null) ? $payload['work']['face_repair'] : [];
            $evaluated[] = [
                'name' => 'face_repair_pending_below_max',
                'configured' => (bool) $triggers['face_repair_pending_below_max'],
                'observed' => (bool) ($faceRepair['below_configured_runway'] ?? false),
                'pending_packets' => (int) ($faceRepair['pending_packets'] ?? 0),
                'max_pending_total' => $faceRepair['max_pending_total'] ?? null,
                'passed' => (bool) $triggers['face_repair_pending_below_max']
                    && (bool) ($faceRepair['below_configured_runway'] ?? false),
            ];
        }

        return $evaluated;
    }

    /**
     * @param  list<array<string,mixed>>  $evaluated
     * @param  array<string,mixed>  $triggers
     */
    private function appendMinimumTrigger(array &$evaluated, string $name, array $triggers, int $observed): void
    {
        $minimum = $this->nonNegativeInt($triggers[$name] ?? null);
        if ($minimum === null) {
            return;
        }

        $evaluated[] = [
            'name' => $name,
            'configured' => $minimum,
            'observed' => $observed,
            'passed' => $observed >= $minimum,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $evaluated
     * @param  array<string,mixed>  $triggers
     */
    private function appendMaximumTrigger(array &$evaluated, string $name, array $triggers, int $observed): void
    {
        $maximum = $this->nonNegativeInt($triggers[$name] ?? null);
        if ($maximum === null) {
            return;
        }

        $evaluated[] = [
            'name' => $name,
            'configured' => $maximum,
            'observed' => $observed,
            'passed' => $observed <= $maximum,
        ];
    }

    /**
     * @param  array<int,string>  $resourceProfiles
     */
    private function runningLlmJobs(array $resourceProfiles): int
    {
        try {
            $sql = "SELECT COALESCE(SUM(running_count), 0) AS running
                    FROM scheduled_jobs
                    WHERE last_run_status = 'running'";
            $bindings = [];

            if ($resourceProfiles !== []) {
                $sql .= ' AND resource_profile IN ('.implode(',', array_fill(0, count($resourceProfiles), '?')).')';
                $bindings = $resourceProfiles;
            }

            $row = DB::selectOne($sql, $bindings);

            return (int) ($row->running ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentLlmRequests(?int $windowMinutes): array
    {
        if ($windowMinutes === null || $windowMinutes <= 0) {
            return [];
        }

        try {
            $rows = DB::select('
                SELECT instance_id, model_role, COUNT(*) AS requests,
                       SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successes,
                       AVG(latency_ms) AS avg_latency_ms,
                       AVG(tokens_per_second) AS avg_tokens_per_second
                FROM llm_model_request_logs
                WHERE created_at >= ?
                GROUP BY instance_id, model_role
                ORDER BY requests DESC, instance_id ASC
            ', [now()->subMinutes($windowMinutes)->format('Y-m-d H:i:s')]);
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn (object $row): array => [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'model_role' => (string) ($row->model_role ?? ''),
            'requests' => (int) ($row->requests ?? 0),
            'successes' => (int) ($row->successes ?? 0),
            'avg_latency_ms' => $row->avg_latency_ms !== null ? round((float) $row->avg_latency_ms, 1) : null,
            'avg_tokens_per_second' => $row->avg_tokens_per_second !== null ? round((float) $row->avg_tokens_per_second, 2) : null,
        ], $rows);
    }

    /**
     * @return array{pending:int,in_progress:int,completed:int,skipped:int,failed:int,total:int}
     */
    private function genealogyQueueCounts(int $treeId): array
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
    private function genealogyReviewBacklog(): array
    {
        try {
            $row = DB::selectOne("
                SELECT
                    COUNT(*) AS pending_total,
                    SUM(CASE WHEN review_type = 'genealogy_finding' THEN 1 ELSE 0 END) AS genealogy_finding,
                    SUM(CASE WHEN review_type = 'genealogy_review_packet' THEN 1 ELSE 0 END) AS genealogy_review_packet,
                    SUM(CASE WHEN review_type = 'genealogy_evidence_asset_capture' THEN 1 ELSE 0 END) AS genealogy_evidence_asset_capture,
                    SUM(
                        CASE WHEN review_type = 'genealogy_finding'
                               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.proposals[0].change_type')) = 'source_add'
                               AND JSON_CONTAINS(JSON_EXTRACT(details, '$.actionability_gate.reasons'), JSON_QUOTE('below_actionability_confidence'))
                               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.ds_governance.writeback_allowed')) = 'false'
                             THEN 1 ELSE 0 END
                    ) AS weak_source_add_findings,
                    SUM(
                        CASE WHEN review_type = 'genealogy_finding'
                               AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.actionability_gate.state')) = 'operator_actionable'
                             THEN 1 ELSE 0 END
                    ) AS operator_actionable_findings
                FROM agent_review_queue
                WHERE status = 'pending'
                  AND review_type IN (
                    'genealogy_finding',
                    'genealogy_review_packet',
                    'genealogy_evidence_asset_capture'
                  )
                  AND (expires_at IS NULL OR expires_at > NOW())
            ");
        } catch (Throwable) {
            $row = null;
        }

        $byType = [
            'genealogy_finding' => (int) ($row->genealogy_finding ?? 0),
            'genealogy_review_packet' => (int) ($row->genealogy_review_packet ?? 0),
            'genealogy_evidence_asset_capture' => (int) ($row->genealogy_evidence_asset_capture ?? 0),
        ];

        return [
            'pending_total' => (int) ($row->pending_total ?? 0),
            'by_type' => $byType,
            'triage' => [
                'weak_source_add_findings' => (int) ($row->weak_source_add_findings ?? 0),
                'operator_actionable_findings' => (int) ($row->operator_actionable_findings ?? 0),
                'source_backed_packets' => $byType['genealogy_review_packet'],
                'evidence_capture_pending' => $byType['genealogy_evidence_asset_capture'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function faceRepairState(int $treeId): array
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

        return [
            'config_source' => 'system_configs.faces.face_person_source_repair_plan',
            'finding_type' => $findingType,
            'pending_packets' => $pending,
            'max_pending_total' => $maxPendingTotal,
            'below_configured_runway' => $maxPendingTotal !== null && $pending < $maxPendingTotal,
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
     * @param  array<string,mixed>  $lane
     * @return array<string,mixed>
     */
    private function runLane(array $lane): array
    {
        $jobName = (string) ($lane['job_name'] ?? '');
        $startedAt = now()->toIso8601String();
        $custodyRefusal = null;
        try {
            $exitCode = Artisan::call('scheduler:run', [
                '--job' => $jobName,
                '--force' => true,
            ]);
            $output = trim(Artisan::output());
        } catch (Throwable $error) {
            $custodyRefusal = ScheduledJobService::preDispatchCustodyRefusalReason($error);
            if ($custodyRefusal === null) {
                throw $error;
            }
            $exitCode = 1;
            $output = $custodyRefusal;
        }

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
        $lane['reason'] = $custodyRefusal ?? ($exitCode === 0 ? 'scheduler_run_completed' : 'scheduler_run_failed');

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

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function jobSafetySnapshot(object $job, array $policy, string $jobName): array
    {
        $allowedProfiles = $this->stringList($policy['allowed_resource_profiles'] ?? []);
        $blockedSafetyFlags = $this->stringList($policy['blocked_safety_true_flags'] ?? []);
        $allowedCommandPrefixes = $this->allowedCommandPrefixes($policy, $jobName);
        $blockedCommandFragments = $this->stringList($policy['blocked_command_fragments'] ?? []);
        $command = trim((string) ($job->command ?? ''));
        $profile = $this->cleanCode($job->resource_profile ?? null);
        $notes = $this->decodeJsonObject($job->notes ?? null);
        $safety = is_array($notes['safety'] ?? null) ? $notes['safety'] : [];
        $blocked = [];

        if ($allowedProfiles !== [] && ($profile === null || ! in_array($profile, $allowedProfiles, true))) {
            $blocked[] = 'resource_profile_not_allowed';
        }

        foreach ($blockedSafetyFlags as $flag) {
            $value = $safety[$flag] ?? $notes[$flag] ?? null;
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true) {
                $blocked[] = 'safety_flag_true:'.$flag;
            }
        }

        foreach ($blockedCommandFragments as $fragment) {
            if ($fragment !== '' && str_contains($command, $fragment)) {
                $blocked[] = 'command_fragment_blocked:'.$fragment;
            }
        }

        if ($allowedCommandPrefixes !== []) {
            $matched = false;
            foreach ($allowedCommandPrefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($command, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $blocked[] = 'command_prefix_not_allowed';
            }
        }

        return [
            'allowed' => $blocked === [],
            'blocked_reasons' => $blocked,
            'resource_profile' => $profile,
            'blocked_safety_true_flags' => $blockedSafetyFlags,
            'allowed_command_prefixes' => $allowedCommandPrefixes,
            'blocked_command_fragments' => $blockedCommandFragments,
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = str_contains($value, ',') ? explode(',', $value) : [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $code = $this->cleanCode($item);
            if ($code !== null) {
                $items[] = $code;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return list<string>
     */
    private function allowedCommandPrefixes(array $policy, string $jobName): array
    {
        $prefixes = $policy['allowed_command_prefixes'] ?? null;
        if (! is_array($prefixes)) {
            return [];
        }

        $value = $prefixes[$jobName] ?? null;
        if (is_string($value)) {
            $value = [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_string($item) && ! is_numeric($item)) {
                continue;
            }

            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return list<string>
     */
    private function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            $decoded = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
        } else {
            $decoded = [];
        }

        return $this->stringList(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
