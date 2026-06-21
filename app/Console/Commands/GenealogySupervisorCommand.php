<?php

namespace App\Console\Commands;

use App\Services\ScheduledJobService;
use App\Services\SystemConfigService;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GenealogySupervisorCommand extends Command
{
    protected $signature = 'genealogy:supervisor
                            {--tree=4 : Genealogy tree ID to supervise}
                            {--queue-low=0 : Refill assessment queue threshold; 0 derives from scheduled_jobs notes}
                            {--max-workers=0 : Maximum research workers this supervisor may start per pass; 0 derives from scheduled_jobs}
                            {--review-soft-cap=0 : Weak Genea review findings that trigger worker throttling; 0 derives from scheduled_jobs notes}
                            {--media-rag-limit=0 : Bounded media RAG records to index per pass; omitted derives from scheduled_jobs notes, explicit 0 skips}
                            {--media-rag-seconds=0 : Wall-clock seconds for the media RAG batch; 0 derives from scheduled_jobs notes}
                            {--dry-run : Report actions without starting workers or running maintenance}
                            {--json : Emit JSON output}
                            {--compact : Omit verbose command output fields from JSON}';

    protected $description = 'Coordinate bounded Genea FT4 queue, worker, media RAG, and review-blocker progress';

    private const RUNTIME_POLICY_SOURCE = 'scheduled_jobs.genealogy_supervisor.notes.training';

    private const DEFAULT_POLICY_CONFIG_KEY = 'genealogy.supervisor_runtime_defaults';

    private const DEFAULT_POLICY_SOURCE = 'system_configs.genealogy.supervisor_runtime_defaults';

    public function __construct(
        private ScheduledJobService $scheduledJobService,
        private SystemConfigService $systemConfigService
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $treeId = max(1, (int) $this->option('tree'));
        $supervisorPolicy = $this->supervisorPolicy();
        $supervisorPolicySources = $this->supervisorPolicySources($supervisorPolicy);
        $supervisorPolicyWarnings = $this->supervisorPolicyWarnings($supervisorPolicy);
        $queueLow = $this->supervisorPolicyIntOption('queue-low', $supervisorPolicy, 'queue_low');
        $maxWorkers = $this->configuredMaxWorkers();
        $maxWorkersOption = max(0, (int) $this->option('max-workers'));
        if ($maxWorkersOption > 0) {
            $maxWorkers = $maxWorkersOption;
        }
        $reviewSoftCapOption = max(0, (int) $this->option('review-soft-cap'));
        $reviewSoftCap = $reviewSoftCapOption > 0
            ? $reviewSoftCapOption
            : $this->optionalPositiveInt($supervisorPolicy['review_soft_cap'] ?? null);
        $mediaRagLimit = $this->supervisorPolicyIntOption('media-rag-limit', $supervisorPolicy, 'media_rag_limit');
        $mediaRagSeconds = $this->supervisorPolicyIntOption('media-rag-seconds', $supervisorPolicy, 'media_rag_seconds');
        $dryRun = (bool) $this->option('dry-run');

        $beforeQueue = $this->queueCounts($treeId);
        $assessment = $this->maybeRunAssessment($treeId, $beforeQueue['pending'], $queueLow, $dryRun);
        $afterAssessQueue = $assessment['ran'] ? $this->queueCounts($treeId) : $beforeQueue;
        $reviewBacklog = $this->reviewBacklogCounts($reviewSoftCap, $this->reviewTypeMinConfidence('genealogy_finding'));
        $llmCapacity = $this->localPrivateLlmCapacity($this->researchQueueCapacityModelRoles());
        $training = $this->trainingPolicy(
            $reviewBacklog,
            $maxWorkers,
            $llmCapacity,
            $supervisorPolicy,
            $supervisorPolicySources,
            $supervisorPolicyWarnings
        );
        $effectiveMaxWorkers = min($maxWorkers, (int) $training['worker_throttle']['worker_cap']);
        $workers = $this->startResearchWorkers($afterAssessQueue['pending'], $effectiveMaxWorkers, $dryRun);
        $mediaRag = $this->runMediaRagBatch($treeId, $mediaRagLimit, $mediaRagSeconds, $dryRun);
        $assessmentFailed = $assessment['exit_code'] !== null && $assessment['exit_code'] !== 0;
        $mediaRagFailed = $mediaRag['exit_code'] !== null && $mediaRag['exit_code'] !== 0;

        $payload = [
            'status' => ($workers['errors'] ?? 0) > 0 || $assessmentFailed || $mediaRagFailed ? 'warning' : 'ok',
            'scope' => 'genealogy_supervisor',
            'tree_id' => $treeId,
            'dry_run' => $dryRun,
            'generated_at' => now()->toIso8601String(),
            'queue' => [
                'before' => $beforeQueue,
                'after_assessment' => $afterAssessQueue,
            ],
            'assessment' => $assessment,
            'workers' => $workers,
            'media_rag' => $mediaRag,
            'review_backlog' => $reviewBacklog,
            'training' => $training,
            'safety' => [
                'canonical_writes' => false,
                'face_identity_automation' => false,
                'operator_review_required' => true,
            ],
        ];

        if ((bool) $this->option('json')) {
            if ((bool) $this->option('compact')) {
                $payload['tree_scope'] = 'single_tree';
                $payload['tree_filter_applied'] = true;
                unset($payload['tree_id']);
                unset($payload['media_rag']['output']);
            }

            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Genealogy supervisor tree %d: pending %d -> %d, workers started %d, media indexed %d, review pending %d.',
                $treeId,
                $beforeQueue['pending'],
                $afterAssessQueue['pending'],
                $workers['started'],
                $mediaRag['items_processed'],
                $reviewBacklog['pending_total']
            ));
        }

        return $payload['status'] === 'warning' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{pending:int,in_progress:int,completed:int,skipped:int,failed:int,total:int}
     */
    private function queueCounts(int $treeId): array
    {
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
     * @return array{threshold:int,triggered:bool,ran:bool,exit_code:int|null}
     */
    private function maybeRunAssessment(int $treeId, int $pending, int $queueLow, bool $dryRun): array
    {
        $triggered = $pending <= $queueLow;

        if (! $triggered || $dryRun) {
            return [
                'threshold' => $queueLow,
                'triggered' => $triggered,
                'ran' => false,
                'exit_code' => null,
            ];
        }

        $exitCode = Artisan::call('scheduler:run', [
            '--job' => 'genealogy_agent_assess',
            '--force' => true,
            '--sync' => true,
        ]);

        Log::info('Genealogy supervisor ran queue assessment', [
            'tree_id' => $treeId,
            'pending_before' => $pending,
            'threshold' => $queueLow,
            'exit_code' => $exitCode,
        ]);

        return [
            'threshold' => $queueLow,
            'triggered' => true,
            'ran' => true,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return array{job_found:bool,resolved_max:int,running:int,pending:int,slots_available:int,requested:int,started:int,errors:int}
     */
    private function startResearchWorkers(int $pending, int $maxWorkers, bool $dryRun): array
    {
        $job = $this->scheduledJobService->getJobByName('genealogy_agent_research_queue');

        if (! $job) {
            return [
                'job_found' => false,
                'resolved_max' => 0,
                'running' => 0,
                'pending' => $pending,
                'slots_available' => 0,
                'requested' => 0,
                'started' => 0,
                'errors' => 1,
            ];
        }

        $resolvedMax = max(1, $this->scheduledJobService->resolveMaxParallel($job));
        $running = max(0, (int) ($job->running_count ?? 0));
        $slotsAvailable = max(0, min($resolvedMax - $running, $pending));
        $requested = min($maxWorkers, $slotsAvailable);

        if ($dryRun || $requested <= 0) {
            return [
                'job_found' => true,
                'resolved_max' => $resolvedMax,
                'running' => $running,
                'pending' => $pending,
                'slots_available' => $slotsAvailable,
                'requested' => $requested,
                'started' => 0,
                'errors' => 0,
            ];
        }

        $started = 0;
        $errors = 0;

        for ($i = 0; $i < $requested; $i++) {
            $result = $this->scheduledJobService->markParallelWorkerStarted((int) $job->id);
            $runId = (int) $result['run_id'];
            $workerId = (string) $result['worker_id'];

            if ($running + $started + 1 >= $resolvedMax) {
                $nextRun = (new CronExpression($job->cron_expression))
                    ->getNextRunDate(now()->toDateTimeImmutable())
                    ->format('Y-m-d H:i:s');
                DB::update('UPDATE scheduled_jobs SET next_run_at = ? WHERE id = ?', [$nextRun, $job->id]);
            }

            $pid = $this->startWorkerProcess((int) $job->id, $workerId);

            if ($pid > 0) {
                $this->scheduledJobService->registerWorkerPid((int) $job->id, $pid, $runId);
                $started++;

                continue;
            }

            $errors++;
            $this->scheduledJobService->markJobCompleted(
                (int) $job->id,
                $runId,
                false,
                'Genealogy supervisor failed to spawn research worker process.',
                0.0
            );
        }

        return [
            'job_found' => true,
            'resolved_max' => $resolvedMax,
            'running' => $running,
            'pending' => $pending,
            'slots_available' => $slotsAvailable,
            'requested' => $requested,
            'started' => $started,
            'errors' => $errors,
        ];
    }

    private function startWorkerProcess(int $jobId, string $workerId): int
    {
        $pendingProcess = Process::path(base_path())
            ->forever()
            ->quietly();

        if (! app()->runningUnitTests()) {
            $pendingProcess->options(['create_new_console' => true]);
        }

        $process = $pendingProcess->start([
            PHP_BINARY,
            'artisan',
            'scheduler:run',
            '--job='.$jobId,
            '--force',
            '--sync',
            '--worker-id='.$workerId,
        ]);

        return (int) ($process->id() ?? 0);
    }

    /**
     * @return array{skipped:bool,exit_code:int|null,items_processed:int,output?:string}
     */
    private function runMediaRagBatch(int $treeId, int $limit, int $seconds, bool $dryRun): array
    {
        if ($limit <= 0 || $dryRun) {
            return [
                'skipped' => true,
                'exit_code' => null,
                'items_processed' => 0,
            ];
        }

        $exitCode = Artisan::call('genealogy:media-rag-index', [
            '--tree' => $treeId,
            '--limit' => $limit,
            '--max-seconds' => $seconds,
        ]);
        $output = Artisan::output();
        $itemsProcessed = 0;

        if (preg_match('/\[ITEMS_PROCESSED:(\d+)\]/', $output, $match) === 1) {
            $itemsProcessed = (int) $match[1];
        }

        return [
            'skipped' => false,
            'exit_code' => $exitCode,
            'items_processed' => $itemsProcessed,
            'output' => $output,
        ];
    }

    /**
     * @return array{pending_total:int,by_type:array<string,int>,triage:array<string,int>,soft_cap:?int,min_action_confidence:?float}
     */
    private function reviewBacklogCounts(?int $softCap, ?float $minActionConfidence): array
    {
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
            'soft_cap' => $softCap,
            'min_action_confidence' => $minActionConfidence,
        ];
    }

    /**
     * @param  array{pending_total:int,by_type:array<string,int>,triage:array<string,int>,soft_cap:?int,min_action_confidence:?float}  $reviewBacklog
     * @param  array{providers:int,capacity:int,available:bool}  $llmCapacity
     * @param  array<string,mixed>  $supervisorPolicy
     * @param  array<string,string>  $supervisorPolicySources
     * @param  string[]  $supervisorPolicyWarnings
     * @return array<string,mixed>
     */
    private function trainingPolicy(
        array $reviewBacklog,
        int $maxWorkers,
        array $llmCapacity,
        array $supervisorPolicy,
        array $supervisorPolicySources,
        array $supervisorPolicyWarnings
    ): array
    {
        $weakFindings = (int) ($reviewBacklog['triage']['weak_source_add_findings'] ?? 0);
        $softCap = $reviewBacklog['soft_cap'];
        $throttleActive = $softCap !== null && $weakFindings >= $softCap;
        $capacity = max(1, (int) ($llmCapacity['capacity'] ?? 1));
        $capacityWorkerCap = min($maxWorkers, $capacity);
        $workerCap = $throttleActive ? min($capacityWorkerCap, 1) : $capacityWorkerCap;

        $recommendations = [];
        if ($capacity > $maxWorkers) {
            $recommendations[] = 'Private LLM capacity exceeds configured worker cap; raise scheduler cap only after review quality stays clean.';
        }
        if ($throttleActive) {
            $recommendations[] = 'Pause broad worker fan-out until below-threshold source_add review noise is ignored or retrained.';
        }
        if ((int) ($reviewBacklog['triage']['source_backed_packets'] ?? 0) > 0) {
            $recommendations[] = 'Prioritize source-backed review packets before increasing worker autonomy.';
        }
        if ((int) ($reviewBacklog['triage']['operator_actionable_findings'] ?? 0) > 0) {
            $recommendations[] = 'Keep operator-actionable findings visible; do not auto-apply canonical facts or source links.';
        }

        return [
            'active_lane' => 'genealogy_agent_research_queue',
            'version' => 1,
            'policy_source' => [
                'worker_cap' => 'scheduled_jobs.genealogy_agent_research_queue.max_parallel',
                'review_soft_cap' => 'scheduled_jobs.genealogy_supervisor.notes.training.review_soft_cap',
                'queue_low' => $this->supervisorPolicySource($supervisorPolicySources, 'queue_low'),
                'media_rag_limit' => $this->supervisorPolicySource($supervisorPolicySources, 'media_rag_limit'),
                'media_rag_seconds' => $this->supervisorPolicySource($supervisorPolicySources, 'media_rag_seconds'),
                'supervisor_defaults' => self::DEFAULT_POLICY_SOURCE,
                'runtime_hard_cap' => 'scheduled_jobs.genealogy_agent_research_queue.notes.runtime.hard_cap',
                'finding_min_confidence' => 'review_type_registry.actionability_min_confidence',
                'below_threshold_source_add_cleanup' => 'system_configs.genealogy.weak_source_add_cleanup_policy',
                'table_driven' => true,
            ],
            'safe_to_automate' => [
                'queue_refill',
                'bounded_worker_starts',
                'media_rag_indexing',
                'review_backlog_classification',
                'below_threshold_source_add_cleanup',
            ],
            'requires_operator' => [
                'canonical_fact_writeback',
                'canonical_source_link_writeback',
                'relationship_changes',
                'face_or_photo_identity',
                'derivative_packet_acceptance',
            ],
            'quality_thresholds' => [
                'genealogy_finding_min_action_confidence' => $reviewBacklog['min_action_confidence'],
                'weak_source_add_soft_cap' => $softCap,
                'weak_source_add_definition' => 'source_add with below_actionability_confidence gate reason and writeback_allowed=false',
                'notes' => (string) ($supervisorPolicy['quality_notes'] ?? 'Configured through scheduled job runtime metadata.'),
            ],
            'llm_capacity' => [
                'profile' => 'genealogy_llm',
                'healthy_private_text_providers' => (int) ($llmCapacity['providers'] ?? 0),
                'healthy_private_text_capacity' => $capacity,
                'capacity_model_roles' => array_values(array_map('strval', $llmCapacity['capacity_model_roles'] ?? [])),
                'available' => (bool) ($llmCapacity['available'] ?? false),
                'capacity_worker_cap' => $capacityWorkerCap,
            ],
            'worker_throttle' => [
                'active' => $throttleActive,
                'reason' => $throttleActive ? 'weak_source_add_review_backlog_saturated' : null,
                'configured_max_workers' => $maxWorkers,
                'worker_cap' => $workerCap,
            ],
            'recommendations' => $recommendations,
            'policy_warnings' => $supervisorPolicyWarnings,
        ];
    }

    /**
     * @param  string[]  $capacityModelRoles
     * @return array{providers:int,capacity:int,available:bool,capacity_model_roles:string[]}
     */
    private function localPrivateLlmCapacity(array $capacityModelRoles = []): array
    {
        $capacityModelRoles = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $role): string => trim((string) $role),
                $capacityModelRoles
            ),
            static fn (string $role): bool => $role !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $role) === 1
        )));

        try {
            $roleWhere = '';
            $bindings = [];
            if ($capacityModelRoles !== []) {
                $rolePredicates = [];
                foreach ($capacityModelRoles as $role) {
                    $rolePredicates[] = 'JSON_EXTRACT(COALESCE(config, JSON_OBJECT()), ?) IS NOT NULL';
                    $bindings[] = '$.models.'.$role;
                }
                $roleWhere = ' AND ('.implode(' OR ', $rolePredicates).')';
            }

            $row = DB::selectOne("
                SELECT
                    COUNT(*) AS providers,
                    COALESCE(SUM(GREATEST(COALESCE(max_concurrent, 1), 1)), 0) AS capacity
                FROM llm_instances
                WHERE is_active = 1
                  AND is_healthy = 1
                  AND circuit_state != 'open'
                  AND COALESCE(routability, 'allowed') = 'allowed'
                  AND COALESCE(allows_private_data, 0) = 1
                  AND COALESCE(data_privacy_scope, 'private_allowed') IN ('local_private', 'private_allowed')
                  AND JSON_CONTAINS(COALESCE(capabilities, JSON_ARRAY()), JSON_QUOTE('text')) = 1
                  AND JSON_CONTAINS(COALESCE(capabilities, JSON_ARRAY()), JSON_QUOTE('privacy_deny_prefilter')) = 0
                  {$roleWhere}
            ", $bindings);

            $providers = (int) ($row->providers ?? 0);
            $capacity = (int) ($row->capacity ?? 0);

            return [
                'providers' => $providers,
                'capacity' => max(1, $capacity),
                'available' => $providers > 0 && $capacity > 0,
                'capacity_model_roles' => $capacityModelRoles,
            ];
        } catch (\Throwable $e) {
            Log::debug('Genealogy supervisor could not read local private LLM capacity', [
                'error' => $e->getMessage(),
            ]);

            return [
                'providers' => 0,
                'capacity' => 1,
                'available' => false,
                'capacity_model_roles' => $capacityModelRoles,
            ];
        }
    }

    /**
     * @return string[]
     */
    private function researchQueueCapacityModelRoles(): array
    {
        $job = $this->scheduledJobService->getJobByName('genealogy_agent_research_queue');
        if (! $job || ! is_string($job->notes ?? null)) {
            return [];
        }

        $decoded = json_decode((string) $job->notes, true);
        if (! is_array($decoded)) {
            return [];
        }

        $roles = $decoded['runtime']['capacity_model_roles'] ?? [];
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $role): string => trim((string) $role), $roles),
            static fn (string $role): bool => $role !== ''
        ));
    }

    private function configuredMaxWorkers(): int
    {
        $job = $this->scheduledJobService->getJobByName('genealogy_agent_research_queue');
        if (! $job) {
            return 1;
        }

        return max(1, (int) ($job->max_parallel ?? 1));
    }

    /**
     * @return array<string,mixed>
     */
    private function supervisorPolicy(): array
    {
        $policy = $this->supervisorDefaultPolicy();
        $sources = [];
        foreach (array_keys($policy) as $key) {
            if ($key === 'policy_notes') {
                continue;
            }

            $sources[$key] = self::DEFAULT_POLICY_SOURCE.'.'.$key;
        }
        $hasConfiguredPolicy = $policy !== [];

        $job = $this->scheduledJobService->getJobByName('genealogy_supervisor');
        if (! $job || ! is_string($job->notes ?? null)) {
            $policy['_policy_sources'] = $sources;
            $policy['_policy_warnings'] = $hasConfiguredPolicy ? [] : ['supervisor_runtime_policy_unconfigured'];

            return $policy;
        }

        $decoded = json_decode((string) $job->notes, true);
        if (! is_array($decoded)) {
            $policy['_policy_sources'] = $sources;
            $policy['_policy_warnings'] = ! $hasConfiguredPolicy
                ? ['supervisor_runtime_policy_unconfigured']
                : ['scheduled_job_notes_invalid_json'];

            return $policy;
        }

        $training = $decoded['training'] ?? [];

        if (is_array($training)) {
            foreach ($training as $key => $value) {
                $policy[$key] = $value;
                $sources[$key] = self::RUNTIME_POLICY_SOURCE.'.'.$key;
            }
            $hasConfiguredPolicy = $hasConfiguredPolicy || $training !== [];
        }

        $policy['_policy_sources'] = $sources;
        $policy['_policy_warnings'] = $hasConfiguredPolicy ? [] : ['supervisor_runtime_policy_unconfigured'];

        return $policy;
    }

    /**
     * @return array<string,mixed>
     */
    private function supervisorDefaultPolicy(): array
    {
        $policy = $this->systemConfigService->get(self::DEFAULT_POLICY_CONFIG_KEY, []);

        return is_array($policy) ? $policy : [];
    }

    /**
     * @param  array<string,mixed>  $supervisorPolicy
     * @return array<string,string>
     */
    private function supervisorPolicySources(array $supervisorPolicy): array
    {
        $sources = $supervisorPolicy['_policy_sources'] ?? [];

        return is_array($sources)
            ? array_filter(
                array_map(static fn (mixed $source): string => (string) $source, $sources),
                static fn (string $source): bool => $source !== ''
            )
            : [];
    }

    /**
     * @param  array<string,mixed>  $supervisorPolicy
     * @return string[]
     */
    private function supervisorPolicyWarnings(array $supervisorPolicy): array
    {
        $warnings = $supervisorPolicy['_policy_warnings'] ?? [];

        return is_array($warnings)
            ? array_values(array_filter(
                array_map(static fn (mixed $warning): string => (string) $warning, $warnings),
                static fn (string $warning): bool => $warning !== ''
            ))
            : [];
    }

    /**
     * @param  array<string,string>  $sources
     */
    private function supervisorPolicySource(array $sources, string $key): string
    {
        return $sources[$key] ?? 'unconfigured';
    }

    private function reviewTypeMinConfidence(string $reviewType): ?float
    {
        try {
            $row = DB::selectOne(
                'SELECT actionability_min_confidence FROM review_type_registry WHERE name = ? AND enabled = 1 LIMIT 1',
                [$reviewType]
            );

            return $row?->actionability_min_confidence !== null
                ? (float) $row->actionability_min_confidence
                : null;
        } catch (\Throwable $e) {
            Log::debug('Genealogy supervisor could not read review actionability threshold', [
                'review_type' => $reviewType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue > 0 ? $intValue : null;
    }

    /**
     * @param  array<string,mixed>  $supervisorPolicy
     */
    private function supervisorPolicyIntOption(string $option, array $supervisorPolicy, string $policyKey): int
    {
        if ($this->optionWasProvided($option)) {
            return max(0, (int) $this->option($option));
        }

        $policyValue = $this->optionalNonNegativeInt($supervisorPolicy[$policyKey] ?? null);

        return $policyValue ?? 0;
    }

    private function optionWasProvided(string $option): bool
    {
        return $this->input->hasParameterOption('--'.$option);
    }

    private function optionalNonNegativeInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue >= 0 ? $intValue : null;
    }
}
