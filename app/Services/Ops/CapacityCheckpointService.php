<?php

namespace App\Services\Ops;

use App\Services\OperatorEvidenceService;
use App\Services\RagBacklogService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Throwable;

class CapacityCheckpointService
{
    private const COLLECTORS = [
        'capacity_report' => [
            'class' => CapacityReportService::class,
            'method' => 'buildReport',
        ],
        'scheduler_optimization' => [
            'class' => SchedulerOptimizeReportService::class,
            'method' => 'buildPayload',
        ],
        'runtime_diagnostics' => [
            'class' => OpsRuntimeDiagnosticsService::class,
            'method' => 'buildEnvelope',
        ],
        'rag_kg' => [
            'class' => RagBacklogService::class,
            'method' => 'getDigestMetrics',
        ],
        'dba_telemetry' => [
            'class' => DbaTelemetryReportService::class,
            'method' => 'collect',
        ],
        'operator_evidence' => [
            'class' => OperatorEvidenceService::class,
            'method' => 'collect',
        ],
    ];

    private const BLOCKED_ACTIONS = [
        'db_writes',
        'scheduler_tuning',
        'kg_tuning',
        'queue_retries',
        'cache_flushes',
        'notifications',
        'provider_probes',
        'prod_commands',
        'public_sync',
        'artifact_writes_without_explicit_write_flag',
    ];

    private const STATUS_RANK = [
        'observe_ok' => 0,
        'observe_warning' => 1,
        'review_required' => 2,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array{minutes:int,canonical:string}|null
     */
    public function parseWindow(string $raw): ?array
    {
        $trimmed = trim($raw);
        if (! preg_match('/^(\d+)([mhd])$/', $trimmed, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        if ($value <= 0) {
            return null;
        }

        $unit = $matches[2];
        $minutes = match ($unit) {
            'm' => $value,
            'h' => $value * 60,
            'd' => $value * 60 * 24,
        };

        return [
            'minutes' => $minutes,
            'canonical' => $value.$unit,
        ];
    }

    /**
     * @param  array{minutes:int,canonical:string}  $window
     * @return array<string, mixed>
     */
    public function buildCheckpoint(array $window, bool $dryRun = false): array
    {
        $evidence = $dryRun ? $this->dryRunEvidence() : [
            'capacity_report' => $this->collectSection(
                'capacity_report',
                fn (): array => $this->collectCapacityReport()
            ),
            'scheduler_optimization' => $this->collectSection(
                'scheduler_optimization',
                fn (): array => $this->collectSchedulerOptimization($window)
            ),
            'runtime_diagnostics' => $this->collectSection(
                'runtime_diagnostics',
                fn (): array => $this->collectRuntimeDiagnostics($window)
            ),
            'rag_kg' => $this->collectSection(
                'rag_kg',
                fn (): array => $this->collectRagKg()
            ),
            'dba_telemetry' => $this->collectSection(
                'dba_telemetry',
                fn (): array => $this->collectDbaTelemetry()
            ),
            'operator_evidence' => $this->collectSection(
                'operator_evidence',
                fn (): array => $this->collectOperatorEvidence()
            ),
        ];

        $evidenceErrors = $this->evidenceErrors($evidence);
        $summary = $this->buildSummary($evidence, $evidenceErrors);

        return [
            'version' => 1,
            'command' => 'ops:capacity-checkpoint',
            'mode' => 'observe',
            'decision' => 'no_decision',
            'dry_run' => $dryRun,
            'read_only' => true,
            'artifact_write' => false,
            'captured_at' => Carbon::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'window' => $window['canonical'],
            'window_minutes' => $window['minutes'],
            'posture' => [
                'tuning_allowed' => false,
                'writes_enabled' => false,
                'artifact_write_enabled' => false,
                'scheduler_changes_allowed' => false,
                'queue_mutations_allowed' => false,
                'cache_flush_allowed' => false,
                'notifications_allowed' => false,
                'blocked_actions' => self::BLOCKED_ACTIONS,
                'notes' => [
                    'This checkpoint bundles existing read-only evidence only.',
                    'It does not decide, tune, retry, flush, notify, deploy, or sync public artifacts.',
                ],
            ],
            'summary' => $summary,
            'evidence' => $evidence,
            'evidence_errors' => $evidenceErrors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toText(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $evidence = $payload['evidence'] ?? [];

        $lines = [
            sprintf(
                'capacity-checkpoint mode=%s decision=%s status=%s window=%s dry_run=%s read_only=%s artifact_write=%s',
                (string) ($payload['mode'] ?? 'observe'),
                (string) ($payload['decision'] ?? 'no_decision'),
                (string) ($summary['status'] ?? 'unknown'),
                (string) ($payload['window'] ?? '-'),
                $this->boolText($payload['dry_run'] ?? false),
                $this->boolText($payload['read_only'] ?? false),
                $this->boolText($payload['artifact_write'] ?? true)
            ),
            sprintf(
                'capacity: status=%s enforcement_ready=%s warnings=%d heavy_window_captures=%s',
                (string) ($summary['capacity_status'] ?? '-'),
                $this->boolText($summary['capacity_enforcement_ready'] ?? false),
                (int) ($summary['observe_threshold_warning_count'] ?? 0),
                $this->valueOrDash($summary['jobs_heavy_window_captures'] ?? null)
            ),
            sprintf(
                'scheduler: status=%s jobs=%s recommendations=%s warning_recommendations=%s',
                (string) ($summary['scheduler_status'] ?? '-'),
                $this->valueOrDash($summary['scheduler_job_count'] ?? null),
                $this->valueOrDash($summary['scheduler_recommendation_count'] ?? null),
                $this->valueOrDash($summary['scheduler_warning_recommendations'] ?? null)
            ),
            sprintf(
                'runtime: status=%s runs=%s failed=%s timeout=%s stale_sessions=%s past_deadline_jobs=%s',
                (string) ($summary['runtime_status'] ?? '-'),
                $this->valueOrDash($summary['runtime_run_total'] ?? null),
                $this->valueOrDash($summary['runtime_failed_runs'] ?? null),
                $this->valueOrDash($summary['runtime_timeout_runs'] ?? null),
                $this->valueOrDash($summary['stale_agent_sessions'] ?? null),
                $this->valueOrDash($summary['past_deadline_jobs'] ?? null)
            ),
            sprintf(
                'operator: status=%s queue_depth=%s kg_pending=%s kg_net_burn=%s dba_breaches=%s',
                (string) ($summary['operator_status'] ?? '-'),
                $this->valueOrDash($summary['queue_depth_total'] ?? null),
                $this->valueOrDash($summary['kg_pending'] ?? null),
                $this->valueOrDash($summary['kg_net_burn_per_day'] ?? null),
                $this->valueOrDash($summary['dba_threshold_breach_count'] ?? null)
            ),
        ];

        foreach ($evidence as $name => $section) {
            $lines[] = sprintf(
                'evidence:%s status=%s available=%s',
                (string) $name,
                (string) ($section['status'] ?? 'unknown'),
                $this->boolText($section['available'] ?? false)
            );
        }

        foreach (($payload['evidence_errors'] ?? []) as $error) {
            $lines[] = sprintf(
                'evidence-error:%s %s %s',
                (string) ($error['section'] ?? 'unknown'),
                (string) ($error['type'] ?? 'unknown'),
                (string) ($error['message'] ?? '')
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            '# Ops Capacity Checkpoint',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Decision: `'.($payload['decision'] ?? 'no_decision').'`',
            '- Status: `'.($summary['status'] ?? 'unknown').'`',
            '- Window: `'.($payload['window'] ?? '-').'`',
            '- Captured: `'.($payload['captured_at'] ?? '-').'`',
            '- Dry run: `'.$this->boolText($payload['dry_run'] ?? false).'`',
            '- Read only: `'.$this->boolText($payload['read_only'] ?? false).'`',
            '- Artifact write: `'.$this->boolText($payload['artifact_write'] ?? true).'`',
            '',
            '## Summary',
            '',
            '- Capacity enforcement ready: `'.$this->boolText($summary['capacity_enforcement_ready'] ?? false).'`',
            '- Observe-threshold warnings: `'.(int) ($summary['observe_threshold_warning_count'] ?? 0).'`',
            '- Jobs heavy-window captures: `'.$this->valueOrDash($summary['jobs_heavy_window_captures'] ?? null).'`',
            '- Scheduler recommendations: `'.$this->valueOrDash($summary['scheduler_recommendation_count'] ?? null).'`',
            '- Scheduler warning recommendations: `'.$this->valueOrDash($summary['scheduler_warning_recommendations'] ?? null).'`',
            '- Runtime failed runs: `'.$this->valueOrDash($summary['runtime_failed_runs'] ?? null).'`',
            '- Runtime timeout runs: `'.$this->valueOrDash($summary['runtime_timeout_runs'] ?? null).'`',
            '- Stale agent sessions: `'.$this->valueOrDash($summary['stale_agent_sessions'] ?? null).'`',
            '- Past-deadline jobs: `'.$this->valueOrDash($summary['past_deadline_jobs'] ?? null).'`',
            '- Queue depth total: `'.$this->valueOrDash($summary['queue_depth_total'] ?? null).'`',
            '- KG pending: `'.$this->valueOrDash($summary['kg_pending'] ?? null).'`',
            '- DBA threshold breaches: `'.$this->valueOrDash($summary['dba_threshold_breach_count'] ?? null).'`',
            '- Evidence errors: `'.(int) ($summary['evidence_error_count'] ?? 0).'`',
            '',
            '## Evidence Sections',
            '',
        ];

        foreach (($payload['evidence'] ?? []) as $name => $section) {
            $lines[] = sprintf(
                '- `%s`: status `%s`, available `%s`',
                (string) $name,
                (string) ($section['status'] ?? 'unknown'),
                $this->boolText($section['available'] ?? false)
            );
        }

        if (($payload['evidence_errors'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Evidence Errors';
            $lines[] = '';
            foreach ($payload['evidence_errors'] as $error) {
                $lines[] = sprintf(
                    '- `%s`: `%s` %s',
                    (string) ($error['section'] ?? 'unknown'),
                    (string) ($error['type'] ?? 'unknown'),
                    (string) ($error['message'] ?? '')
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function collectCapacityReport(): array
    {
        /** @var CapacityReportService $service */
        $service = $this->container->make(CapacityReportService::class);
        $payload = $service->buildReport();
        $jobs = $payload['scenarios']['jobs'] ?? [];

        return [
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'observe_warning')),
            'available' => true,
            'summary' => [
                'status' => $payload['status'] ?? 'unknown',
                'enforcement_ready' => (bool) ($payload['enforcement_ready'] ?? false),
                'warning_count' => count((array) ($payload['warnings'] ?? [])),
                'jobs_captures' => (int) ($jobs['captures'] ?? 0),
                'jobs_heavy_window_captures' => (int) ($jobs['heavy_window_captures'] ?? 0),
                'jobs_latest_captured_at' => $jobs['latest_captured_at'] ?? null,
                'jobs_queue_depth_total' => $jobs['latest_metrics']['queue_depth_total'] ?? null,
            ],
            'payload' => $payload,
        ];
    }

    /**
     * @param  array{minutes:int,canonical:string}  $window
     * @return array<string, mixed>
     */
    private function collectSchedulerOptimization(array $window): array
    {
        /** @var SchedulerOptimizeReportService $service */
        $service = $this->container->make(SchedulerOptimizeReportService::class);
        $payload = $service->buildPayload($window);
        $compact = $service->compactPayload($payload);
        $warningCount = (int) (($compact['severity_counts']['warning'] ?? 0));

        return [
            'status' => $warningCount > 0 ? 'observe_warning' : 'observe_ok',
            'available' => true,
            'summary' => $compact,
        ];
    }

    /**
     * @param  array{minutes:int,canonical:string}  $window
     * @return array<string, mixed>
     */
    private function collectRuntimeDiagnostics(array $window): array
    {
        /** @var OpsRuntimeDiagnosticsService $service */
        $service = $this->container->make(OpsRuntimeDiagnosticsService::class);
        $payload = $service->buildEnvelope($window, 'all');
        $summary = $this->runtimeSummary($payload);

        return [
            'status' => $summary['status'],
            'available' => true,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectRagKg(): array
    {
        /** @var RagBacklogService $service */
        $service = $this->container->make(RagBacklogService::class);
        $metrics = $service->getDigestMetrics();
        $netBurn = $service->getNetBurn(7);
        $kg = is_array($metrics['kg'] ?? null) ? $metrics['kg'] : [];
        $raptor = is_array($metrics['raptor'] ?? null) ? $metrics['raptor'] : [];
        $sentence = is_array($metrics['sentence'] ?? null) ? $metrics['sentence'] : [];
        $lanes = is_array($netBurn['lanes'] ?? null) ? $netBurn['lanes'] : [];
        $kgNetBurn = is_array($lanes['kg'] ?? null) ? $lanes['kg'] : [];
        $raptorNetBurn = is_array($lanes['raptor'] ?? null) ? $lanes['raptor'] : [];
        $sentenceNetBurn = is_array($lanes['sentence'] ?? null) ? $lanes['sentence'] : [];
        $evidenceErrors = array_merge(
            array_values((array) ($metrics['evidence_errors'] ?? [])),
            array_values((array) ($netBurn['evidence_errors'] ?? []))
        );

        return [
            'status' => $evidenceErrors === [] ? 'observe_ok' : 'observe_warning',
            'available' => true,
            'summary' => [
                'documents' => isset($metrics['documents']) ? (int) $metrics['documents'] : null,
                'kg_pending' => isset($kg['pending']) ? (int) $kg['pending'] : null,
                'kg_fresh_pending' => isset($kg['fresh']) ? (int) $kg['fresh'] : null,
                'kg_stale_pending' => isset($kg['stale']) ? (int) $kg['stale'] : null,
                'kg_entities' => isset($kg['entities']) ? (int) $kg['entities'] : null,
                'kg_throughput_per_day' => $kg['throughput_per_day'] ?? null,
                'kg_eta_days' => $kg['eta_days'] ?? null,
                'kg_net_burn_per_day' => $kgNetBurn['net_burn_per_day'] ?? null,
                'kg_net_burn_trend' => $kgNetBurn['trend'] ?? null,
                'raptor_pending' => isset($raptor['pending']) ? (int) $raptor['pending'] : null,
                'raptor_net_burn_trend' => $raptorNetBurn['trend'] ?? null,
                'sentence_pending' => isset($sentence['pending']) ? (int) $sentence['pending'] : null,
                'sentence_net_burn_trend' => $sentenceNetBurn['trend'] ?? null,
                'evidence_error_count' => count($evidenceErrors),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectDbaTelemetry(): array
    {
        /** @var DbaTelemetryReportService $service */
        $service = $this->container->make(DbaTelemetryReportService::class);
        $payload = $service->collect(false, false, false);
        $compact = $service->compactPayload($payload);

        return [
            'status' => $this->normalizeStatus((string) ($compact['status'] ?? $payload['status'] ?? 'observe_warning')),
            'available' => true,
            'summary' => $compact,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectOperatorEvidence(): array
    {
        return [
            'status' => 'skipped',
            'available' => false,
            'summary' => [
                'note' => 'OperatorEvidenceService::collect() is not invoked by this read-only checkpoint because it can populate cache entries. Use ops:operator-evidence for the full operator snapshot.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectSection(string $name, callable $collector): array
    {
        try {
            $section = $collector();

            return array_merge([
                'status' => 'observe_ok',
                'available' => true,
            ], $section);
        } catch (Throwable $e) {
            return [
                'status' => 'review_required',
                'available' => false,
                'summary' => [
                    'error_type' => $e::class,
                    'error_message' => $e->getMessage(),
                ],
                'error' => [
                    'section' => $name,
                    'type' => $e::class,
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function dryRunEvidence(): array
    {
        $evidence = [];

        foreach (self::COLLECTORS as $name => $collector) {
            $class = (string) $collector['class'];
            $method = (string) $collector['method'];

            $evidence[$name] = [
                'status' => 'skipped',
                'available' => false,
                'dry_run' => true,
                'collector' => [
                    'class' => $class,
                    'method' => $method,
                    'resolvable' => class_exists($class) && method_exists($class, $method),
                ],
                'summary' => [
                    'note' => 'Dry run only; collector was not invoked.',
                ],
            ];
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function runtimeSummary(array $payload): array
    {
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $tasks = is_array($result['tasks'] ?? null) ? $result['tasks'] : [];
        $runs = is_array($result['runs'] ?? null) ? $result['runs'] : [];
        $recovery = is_array($result['recovery'] ?? null) ? $result['recovery'] : [];
        $taskCounts = is_array($tasks['counts'] ?? null) ? $tasks['counts'] : [];
        $distribution = is_array($runs['status_distribution'] ?? null) ? $runs['status_distribution'] : [];
        $stale = is_array($recovery['stale_agent_sessions'] ?? null) ? $recovery['stale_agent_sessions'] : [];
        $past = is_array($recovery['past_deadline_jobs'] ?? null) ? $recovery['past_deadline_jobs'] : [];
        $locks = is_array($recovery['locks'] ?? null) ? $recovery['locks'] : [];

        $failedRuns = (int) ($distribution['failed'] ?? 0);
        $timeoutRuns = (int) ($distribution['timeout'] ?? 0);
        $staleSessions = (int) ($stale['count'] ?? 0);
        $pastDeadlineJobs = (int) ($past['count'] ?? 0);
        $queryFailed = in_array('query_failed', [
            $tasks['result'] ?? null,
            $runs['result'] ?? null,
            $recovery['result'] ?? null,
            $stale['result'] ?? null,
            $past['result'] ?? null,
        ], true);

        $status = $queryFailed || $failedRuns > 0 || $timeoutRuns > 0 || $staleSessions > 0 || $pastDeadlineJobs > 0
            ? 'observe_warning'
            : 'observe_ok';

        return [
            'status' => $status,
            'window' => $payload['window'] ?? null,
            'task_result' => $tasks['result'] ?? null,
            'run_result' => $runs['result'] ?? null,
            'recovery_result' => $recovery['result'] ?? null,
            'task_total' => (int) ($taskCounts['total'] ?? 0),
            'task_failed' => (int) ($taskCounts['failed'] ?? 0),
            'task_timeout' => (int) ($taskCounts['timeout'] ?? 0),
            'missing_runtime_metadata_count' => (int) ($tasks['missing_runtime_metadata_count'] ?? 0),
            'run_total' => (int) ($runs['total'] ?? 0),
            'failed_runs' => $failedRuns,
            'timeout_runs' => $timeoutRuns,
            'percent_success' => $runs['percent_success'] ?? null,
            'median_duration_ms' => $runs['median_duration_ms'] ?? null,
            'p95_duration_ms' => $runs['p95_duration_ms'] ?? null,
            'stale_agent_sessions' => $staleSessions,
            'past_deadline_jobs' => $pastDeadlineJobs,
            'ollama_busy_lock' => (bool) ($locks['ollama_busy_lock'] ?? false),
            'whisper_gpu_lock' => (bool) ($locks['whisper_gpu_lock'] ?? false),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $evidence
     * @param  list<array<string, string>>  $evidenceErrors
     * @return array<string, mixed>
     */
    private function buildSummary(array $evidence, array $evidenceErrors): array
    {
        $capacity = $evidence['capacity_report']['summary'] ?? [];
        $scheduler = $evidence['scheduler_optimization']['summary'] ?? [];
        $runtime = $evidence['runtime_diagnostics']['summary'] ?? [];
        $ragKg = $evidence['rag_kg']['summary'] ?? [];
        $dba = $evidence['dba_telemetry']['summary'] ?? [];
        $operator = $evidence['operator_evidence']['summary'] ?? [];
        $headlines = is_array($operator['headlines'] ?? null) ? $operator['headlines'] : [];
        $queue = is_array($headlines['queue'] ?? null) ? $headlines['queue'] : [];
        $kgRag = is_array($headlines['kg_rag'] ?? null) ? $headlines['kg_rag'] : [];

        $statuses = array_map(
            fn (array $section): string => $this->normalizeStatus((string) ($section['status'] ?? 'observe_warning')),
            array_values($evidence)
        );
        if ($evidenceErrors !== []) {
            $statuses[] = 'review_required';
        }

        return [
            'status' => $this->worstStatus($statuses),
            'decision' => 'no_decision',
            'tuning_allowed' => false,
            'capacity_status' => $this->normalizeStatus((string) ($evidence['capacity_report']['status'] ?? 'observe_warning')),
            'capacity_enforcement_ready' => (bool) ($capacity['enforcement_ready'] ?? false),
            'observe_threshold_warning_count' => (int) ($capacity['warning_count'] ?? 0),
            'jobs_captures' => isset($capacity['jobs_captures']) ? (int) $capacity['jobs_captures'] : null,
            'jobs_heavy_window_captures' => isset($capacity['jobs_heavy_window_captures'])
                ? (int) $capacity['jobs_heavy_window_captures']
                : null,
            'jobs_latest_captured_at' => $capacity['jobs_latest_captured_at'] ?? null,
            'jobs_queue_depth_total' => isset($capacity['jobs_queue_depth_total'])
                ? (int) $capacity['jobs_queue_depth_total']
                : null,
            'scheduler_status' => $this->normalizeStatus((string) ($evidence['scheduler_optimization']['status'] ?? 'observe_warning')),
            'scheduler_job_count' => isset($scheduler['job_count']) ? (int) $scheduler['job_count'] : null,
            'scheduler_recommendation_count' => isset($scheduler['recommendation_count'])
                ? (int) $scheduler['recommendation_count']
                : null,
            'scheduler_warning_recommendations' => isset($scheduler['severity_counts']['warning'])
                ? (int) $scheduler['severity_counts']['warning']
                : 0,
            'runtime_status' => $this->normalizeStatus((string) ($runtime['status'] ?? $evidence['runtime_diagnostics']['status'] ?? 'observe_warning')),
            'runtime_run_total' => isset($runtime['run_total']) ? (int) $runtime['run_total'] : null,
            'runtime_failed_runs' => isset($runtime['failed_runs']) ? (int) $runtime['failed_runs'] : null,
            'runtime_timeout_runs' => isset($runtime['timeout_runs']) ? (int) $runtime['timeout_runs'] : null,
            'stale_agent_sessions' => isset($runtime['stale_agent_sessions']) ? (int) $runtime['stale_agent_sessions'] : null,
            'past_deadline_jobs' => isset($runtime['past_deadline_jobs']) ? (int) $runtime['past_deadline_jobs'] : null,
            'dba_status' => $this->normalizeStatus((string) ($evidence['dba_telemetry']['status'] ?? 'observe_warning')),
            'dba_threshold_breach_count' => isset($dba['threshold_breach_count'])
                ? (int) $dba['threshold_breach_count']
                : null,
            'operator_status' => (string) ($operator['status'] ?? $evidence['operator_evidence']['status'] ?? 'skipped'),
            'queue_depth_total' => isset($queue['queue_depth_total'])
                ? (int) $queue['queue_depth_total']
                : (isset($capacity['jobs_queue_depth_total']) ? (int) $capacity['jobs_queue_depth_total'] : null),
            'kg_pending' => isset($ragKg['kg_pending'])
                ? (int) $ragKg['kg_pending']
                : (isset($kgRag['kg_pending']) ? (int) $kgRag['kg_pending'] : null),
            'kg_net_burn_per_day' => $ragKg['kg_net_burn_per_day'] ?? $kgRag['kg_net_burn_per_day'] ?? null,
            'kg_net_burn_trend' => $ragKg['kg_net_burn_trend'] ?? $kgRag['kg_net_burn_trend'] ?? null,
            'raptor_pending' => isset($ragKg['raptor_pending'])
                ? (int) $ragKg['raptor_pending']
                : (isset($kgRag['raptor_pending']) ? (int) $kgRag['raptor_pending'] : null),
            'sentence_pending' => isset($ragKg['sentence_pending'])
                ? (int) $ragKg['sentence_pending']
                : (isset($kgRag['sentence_pending']) ? (int) $kgRag['sentence_pending'] : null),
            'evidence_error_count' => count($evidenceErrors),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $evidence
     * @return list<array<string, string>>
     */
    private function evidenceErrors(array $evidence): array
    {
        $errors = [];

        foreach ($evidence as $name => $section) {
            if (! isset($section['error']) || ! is_array($section['error'])) {
                continue;
            }

            $errors[] = [
                'section' => (string) ($section['error']['section'] ?? $name),
                'type' => (string) ($section['error']['type'] ?? 'unknown'),
                'message' => (string) ($section['error']['message'] ?? ''),
            ];
        }

        return $errors;
    }

    /**
     * @param  list<string>  $statuses
     */
    private function worstStatus(array $statuses): string
    {
        $worst = 'observe_ok';

        foreach ($statuses as $status) {
            $normalized = $this->normalizeStatus($status);
            if (self::STATUS_RANK[$normalized] > self::STATUS_RANK[$worst]) {
                $worst = $normalized;
            }
        }

        return $worst;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'observe_ok', 'healthy', 'ok', 'success', 'skipped' => 'observe_ok',
            'observe_warning', 'watch', 'warning' => 'observe_warning',
            'review_required', 'degraded', 'blocked', 'failed', 'failure', 'query_failed', 'unavailable' => 'review_required',
            default => 'observe_warning',
        };
    }

    private function boolText(mixed $value): string
    {
        return (bool) $value ? 'true' : 'false';
    }

    private function valueOrDash(mixed $value): string
    {
        return $value === null ? '-' : (string) $value;
    }
}
