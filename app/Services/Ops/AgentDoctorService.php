<?php

namespace App\Services\Ops;

use App\Services\DevAgent\TraceEnvelopeService;
use App\Services\SkillLoaderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AgentDoctorService
{
    private const TRACE_READINESS_HOURS = 24;

    private const TRACE_READINESS_LIMIT = 200;

    private const TRACE_READINESS_MAX_SCAN_BYTES = 1048576;

    public function __construct(
        private readonly SkillLoaderService $skillLoader,
        private readonly AgentDoctorRecursionTelemetryService $recursionTelemetry,
        private readonly TraceEnvelopeService $traceEnvelopes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $windowHours = 24, ?string $agent = null, bool $quick = false): array
    {
        $windowHours = max(1, min($windowHours, 168));
        $now = CarbonImmutable::now();
        $since = $now->subHours($windowHours);
        $checks = [];

        $sessions = $this->collectSessions($since, $now, $checks);
        $scheduledJobs = $this->collectScheduledJobs($since, $now, $checks);
        $reviewQueues = $this->collectReviewQueues($now, $checks);
        $memory = $this->mergeMemorySummaries(
            $this->collectMemorySignals($since, $checks),
            $this->collectEpisodeSummarySignals($since, $now, $checks),
            $this->collectProcedureSignals($checks)
        );
        $recursion = $this->recursionTelemetry->collect();
        $trace = $this->collectTraceReadiness();

        $agentIds = collect(array_merge(
            array_keys($sessions),
            array_keys($scheduledJobs),
            array_keys($reviewQueues),
            array_keys($memory),
        ))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($agent !== null && $agent !== '') {
            $agentIds = $agentIds->filter(fn (string $id): bool => $id === $agent)->values();
        }

        $registryCoverage = $this->collectRegistryCoverage($agentIds->all(), $checks);

        $agents = $agentIds
            ->map(fn (string $agentId): array => $this->buildAgentReport(
                $agentId,
                $sessions[$agentId] ?? $this->emptySessionSummary(),
                $scheduledJobs[$agentId] ?? $this->emptyScheduledJobSummary(),
                $reviewQueues[$agentId] ?? $this->emptyReviewQueueSummary(),
                $registryCoverage[$agentId] ?? $this->emptyRegistrySummary(),
                $memory[$agentId] ?? $this->emptyMemorySummary()
            ))
            ->values()
            ->all();

        $checks = array_merge($checks, $this->buildAggregateChecks($agents));
        $overallStatus = $this->overallStatus($checks, $agents);

        return [
            'generated_at' => $now->utc()->toIso8601String(),
            'window_hours' => $windowHours,
            'overall_status' => $overallStatus,
            'summary' => $this->buildSummary($agents, $now),
            'agents' => $agents,
            'recursion' => $recursion,
            'trace' => $trace,
            'quick' => $quick,
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectSessions(CarbonImmutable $since, CarbonImmutable $now, array &$checks): array
    {
        if (! Schema::hasTable('agent_sessions')) {
            $checks[] = $this->check('agent_sessions', 'warning', 'agent_sessions table is missing');

            return [];
        }

        $lockTtl = max(1, (int) config('lock_ttls.agent_session', 600));
        $warningCutoff = $now->subSeconds((int) floor($lockTtl / 2));
        $criticalCutoff = $now->subSeconds($lockTtl * 2);

        $rows = DB::table('agent_sessions')
            ->select('agent_name', 'status', 'last_activity_at', 'expires_at', 'message_count', 'total_tokens', 'created_at')
            ->where(function ($query) use ($since): void {
                $query->where('last_activity_at', '>=', $since->toDateTimeString())
                    ->orWhere(function ($query) use ($since): void {
                        $query->whereNull('last_activity_at')
                            ->where('created_at', '>=', $since->toDateTimeString());
                    });
            })
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row->agent_name ?? ''));
            if ($agentId === '') {
                continue;
            }

            $summary = $summaries[$agentId] ?? $this->emptySessionSummary();
            $status = (string) ($row->status ?? 'unknown');
            $activityAt = $this->parseTime($row->last_activity_at ?? null)
                ?? $this->parseTime($row->created_at ?? null);
            $expiresAt = $this->parseTime($row->expires_at ?? null);

            if ($status === 'active') {
                $summary['active']++;
                if ($activityAt !== null && $activityAt->lessThan($warningCutoff)) {
                    $summary['stalled']++;
                }
                if ($activityAt !== null && $activityAt->lessThan($criticalCutoff)) {
                    $summary['critical_stalled']++;
                }
            }

            if ($status === 'expired' && $expiresAt !== null && $expiresAt->lessThan($now->subHour())) {
                $summary['expired_unreaped']++;
            }

            if ($activityAt === null || $activityAt->greaterThanOrEqualTo($since)) {
                $summary['_window_count']++;
                $summary['_message_total'] += (int) ($row->message_count ?? 0);
                $summary['_token_total'] += (int) ($row->total_tokens ?? 0);
            }

            $summaries[$agentId] = $summary;
        }

        return array_map(fn (array $summary): array => $this->publicSessionSummary($summary), $summaries);
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectScheduledJobs(CarbonImmutable $since, CarbonImmutable $now, array &$checks): array
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            $checks[] = $this->check('scheduled_jobs', 'warning', 'scheduled_jobs table is missing');

            return [];
        }

        $jobs = DB::table('scheduled_jobs')
            ->select('id', 'name', 'command', 'enabled', 'cron_expression', 'timeout_minutes', 'last_run_at', 'last_run_status', 'stall_exempt')
            ->where('job_type', 'agent_task')
            ->get();

        if ($jobs->isEmpty()) {
            $checks[] = $this->check('scheduled_agent_jobs', 'ok', 'No scheduled agent_task jobs registered');

            return [];
        }

        $runsByJob = $this->collectRunsByScheduledJob($jobs->pluck('id'), $since);
        $summaries = [];

        foreach ($jobs as $job) {
            $agentId = trim((string) ($job->command ?? ''));
            if ($agentId === '') {
                continue;
            }

            $runs = $runsByJob[(int) $job->id] ?? collect();
            $lastRun = $runs->first();
            $outputSignals = $this->scheduledOutputSignals($runs, $now);
            $durations = $runs
                ->pluck('duration_seconds')
                ->filter(fn ($value): bool => $value !== null)
                ->map(fn ($value): float => (float) $value)
                ->sort()
                ->values();

            $summaries[$agentId] = [
                'id' => (int) $job->id,
                'name' => (string) $job->name,
                'enabled' => (bool) $job->enabled,
                'cron' => (string) ($job->cron_expression ?? ''),
                'timeout_minutes' => (int) ($job->timeout_minutes ?? 0),
                'last_run_at' => $this->timeString($lastRun->started_at ?? $job->last_run_at ?? null),
                'last_run_status' => (string) ($lastRun->status ?? $job->last_run_status ?? 'unknown'),
                'last_run_duration_s' => $lastRun?->duration_seconds !== null ? (float) $lastRun->duration_seconds : null,
                'consecutive_failures' => $this->consecutiveFailures($runs),
                'p95_runtime_s_24h' => $this->percentile($durations, 0.95),
                'stall_exempt' => (bool) ($job->stall_exempt ?? false),
                ...$outputSignals,
            ];
        }

        return $summaries;
    }

    /**
     * @param  Collection<int, mixed>  $jobIds
     * @return array<int, Collection<int, object>>
     */
    private function collectRunsByScheduledJob(Collection $jobIds, CarbonImmutable $since): array
    {
        if ($jobIds->isEmpty() || ! Schema::hasTable('scheduled_job_runs')) {
            return [];
        }

        $columns = ['scheduled_job_id', 'status', 'started_at', 'completed_at', 'duration_seconds'];
        if (Schema::hasColumn('scheduled_job_runs', 'output')) {
            $columns[] = 'output';
        }

        return DB::table('scheduled_job_runs')
            ->select($columns)
            ->whereIn('scheduled_job_id', $jobIds->all())
            ->where('started_at', '>=', $since->toDateTimeString())
            ->orderByDesc('started_at')
            ->get()
            ->groupBy(fn (object $row): int => (int) $row->scheduled_job_id)
            ->all();
    }

    /**
     * @param  Collection<int, object>  $runs
     * @return array<string, mixed>
     */
    private function scheduledOutputSignals(Collection $runs, CarbonImmutable $now): array
    {
        $successfulRuns = 0;
        $emptySuccessOutputs = 0;
        $cjkOutputRuns = 0;
        $nonAsciiOutputRuns = 0;
        $guardedOutputRuns = 0;
        $latestEmptySuccessOutputAt = null;
        $latestCjkOutputAt = null;
        $latestNonAsciiOutputAt = null;
        $latestGuardedOutputAt = null;

        foreach ($runs as $run) {
            if (($run->status ?? null) !== 'success') {
                continue;
            }

            $successfulRuns++;
            $output = trim((string) ($run->output ?? ''));
            $signalAt = $this->runSignalTime($run);
            if ($output === '') {
                $emptySuccessOutputs++;
                $latestEmptySuccessOutputAt = $this->latestTime($latestEmptySuccessOutputAt, $signalAt);

                continue;
            }

            if ($this->containsCjkScript($output)) {
                $cjkOutputRuns++;
                $latestCjkOutputAt = $this->latestTime($latestCjkOutputAt, $signalAt);
            }

            if ($this->containsNonAsciiMarker($output)) {
                $nonAsciiOutputRuns++;
                $latestNonAsciiOutputAt = $this->latestTime($latestNonAsciiOutputAt, $signalAt);
            }

            if ($this->containsAgentOutputGuard($output)) {
                $guardedOutputRuns++;
                $latestGuardedOutputAt = $this->latestTime($latestGuardedOutputAt, $signalAt);
            }
        }

        return [
            'successful_runs_24h' => $successfulRuns,
            'empty_success_output_runs_24h' => $emptySuccessOutputs,
            'cjk_output_runs_24h' => $cjkOutputRuns,
            'non_ascii_output_runs_24h' => $nonAsciiOutputRuns,
            'guarded_output_runs_24h' => $guardedOutputRuns,
            'latest_empty_success_output_at' => $latestEmptySuccessOutputAt?->toIso8601String(),
            'latest_empty_success_output_age_hours' => $this->ageHours($latestEmptySuccessOutputAt, $now),
            'latest_cjk_output_at' => $latestCjkOutputAt?->toIso8601String(),
            'latest_cjk_output_age_hours' => $this->ageHours($latestCjkOutputAt, $now),
            'latest_non_ascii_output_at' => $latestNonAsciiOutputAt?->toIso8601String(),
            'latest_non_ascii_output_age_hours' => $this->ageHours($latestNonAsciiOutputAt, $now),
            'latest_guarded_output_at' => $latestGuardedOutputAt?->toIso8601String(),
            'latest_guarded_output_age_hours' => $this->ageHours($latestGuardedOutputAt, $now),
        ];
    }

    private function containsCjkScript(string $value): bool
    {
        return preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value) === 1;
    }

    private function containsNonAsciiMarker(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/u', $value) === 1;
    }

    private function containsAgentOutputGuard(string $value): bool
    {
        return str_contains($value, 'Agent Output Guard')
            && str_contains($value, 'Response Suppressed');
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectReviewQueues(CarbonImmutable $now, array &$checks): array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            $checks[] = $this->check('agent_review_queue', 'warning', 'agent_review_queue table is missing');

            return [];
        }

        $rows = DB::table('agent_review_queue')
            ->select('agent_id', 'priority', 'created_at', 'expires_at')
            ->where('status', 'pending')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row->agent_id ?? ''));
            if ($agentId === '') {
                continue;
            }

            $summary = $summaries[$agentId] ?? $this->emptyReviewQueueSummary();
            $createdAt = $this->parseTime($row->created_at ?? null);
            $expiresAt = $this->parseTime($row->expires_at ?? null);
            $ageHours = $createdAt !== null ? round($createdAt->floatDiffInHours($now), 1) : null;

            $summary['pending']++;
            if ((int) ($row->priority ?? 0) > 0) {
                $summary['high_priority']++;
                $summary['oldest_high_priority_age_hours'] = max(
                    (float) ($summary['oldest_high_priority_age_hours'] ?? 0.0),
                    (float) ($ageHours ?? 0.0)
                );
            }
            if ($ageHours !== null) {
                $summary['oldest_age_hours'] = max((float) ($summary['oldest_age_hours'] ?? 0.0), $ageHours);
            }
            if ($expiresAt !== null && $expiresAt->lessThan($now)) {
                $summary['expired']++;
            }

            $summaries[$agentId] = $summary;
        }

        return $summaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectMemorySignals(CarbonImmutable $since, array &$checks): array
    {
        if (! Schema::hasTable('agent_episodes')) {
            $checks[] = $this->check('agent_episodes', 'warning', 'agent_episodes table is missing');

            return [];
        }

        $rows = DB::table('agent_episodes')
            ->select(
                'agent_id',
                DB::raw('COUNT(*) AS episodes_window'),
                DB::raw("SUM(CASE WHEN event_type = 'error' THEN 1 ELSE 0 END) AS error_episodes_window"),
                DB::raw('SUM(COALESCE(tokens_used, 0)) AS tokens_window'),
                DB::raw('MAX(duration_ms) AS max_duration_ms_window'),
                DB::raw('MAX(created_at) AS last_episode_at')
            )
            ->where('created_at', '>=', $since->toDateTimeString())
            ->groupBy('agent_id')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row->agent_id ?? ''));
            if ($agentId === '') {
                continue;
            }

            $summaries[$agentId] = [
                'episodes_window' => (int) ($row->episodes_window ?? 0),
                'error_episodes_window' => (int) ($row->error_episodes_window ?? 0),
                'tokens_window' => (int) ($row->tokens_window ?? 0),
                'max_duration_ms_window' => $row->max_duration_ms_window !== null ? (int) $row->max_duration_ms_window : null,
                'last_episode_at' => $this->timeString($row->last_episode_at ?? null),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectEpisodeSummarySignals(CarbonImmutable $since, CarbonImmutable $now, array &$checks): array
    {
        if (! Schema::hasTable('agent_episode_summaries')) {
            $checks[] = $this->check('agent_episode_summaries', 'warning', 'agent_episode_summaries table is missing');

            return [];
        }

        if (! $this->tableHasColumns('agent_episode_summaries', ['agent_id', 'created_at', 'is_archived'], $checks)) {
            return [];
        }

        $rows = DB::table('agent_episode_summaries')
            ->select(
                'agent_id',
                DB::raw('COUNT(*) AS summaries_window'),
                DB::raw('SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS archived_summaries_window'),
                DB::raw('MAX(created_at) AS last_summary_at')
            )
            ->where('created_at', '>=', $since->toDateTimeString())
            ->groupBy('agent_id')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row->agent_id ?? ''));
            if ($agentId === '') {
                continue;
            }

            $lastSummaryAt = $this->parseTime($row->last_summary_at ?? null);
            $summaries[$agentId] = [
                'summaries_window' => (int) ($row->summaries_window ?? 0),
                'archived_summaries_window' => (int) ($row->archived_summaries_window ?? 0),
                'last_summary_at' => $lastSummaryAt?->toIso8601String(),
                'hours_since_last_summary' => $lastSummaryAt !== null ? round($lastSummaryAt->floatDiffInHours($now), 1) : null,
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectProcedureSignals(array &$checks): array
    {
        if (! Schema::hasTable('agent_procedures')) {
            $checks[] = $this->check('agent_procedures', 'warning', 'agent_procedures table is missing');

            return [];
        }

        if (! $this->tableHasColumns('agent_procedures', ['agent_id', 'is_retired', 'is_canonical', 'success_rate', 'times_used', 'last_used_at'], $checks)) {
            return [];
        }

        $minUses = max(1, (int) config('health_thresholds.agents.procedure_min_uses_for_quality', 3));
        $successThreshold = (float) config('health_thresholds.agents.procedure_min_success_rate', 0.50);

        $rows = DB::table('agent_procedures')
            ->select('agent_id')
            ->selectRaw('COUNT(*) AS procedures_total')
            ->selectRaw('SUM(CASE WHEN is_retired = 0 THEN 1 ELSE 0 END) AS procedures_active')
            ->selectRaw('SUM(CASE WHEN is_canonical = 1 THEN 1 ELSE 0 END) AS procedures_canonical')
            ->selectRaw('AVG(CASE WHEN times_used >= ? THEN success_rate ELSE NULL END) AS procedures_avg_success_rate', [$minUses])
            ->selectRaw(
                'SUM(CASE WHEN times_used >= ? AND success_rate < ? AND is_retired = 0 THEN 1 ELSE 0 END) AS procedures_low_quality',
                [$minUses, $successThreshold]
            )
            ->selectRaw('MAX(last_used_at) AS procedures_last_used_at')
            ->groupBy('agent_id')
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $agentId = trim((string) ($row->agent_id ?? ''));
            if ($agentId === '') {
                continue;
            }

            $summaries[$agentId] = [
                'procedures_total' => (int) ($row->procedures_total ?? 0),
                'procedures_active' => (int) ($row->procedures_active ?? 0),
                'procedures_canonical' => (int) ($row->procedures_canonical ?? 0),
                'procedures_avg_success_rate' => $row->procedures_avg_success_rate !== null
                    ? round((float) $row->procedures_avg_success_rate, 4)
                    : null,
                'procedures_low_quality' => (int) ($row->procedures_low_quality ?? 0),
                'procedures_last_used_at' => $this->timeString($row->procedures_last_used_at ?? null),
            ];
        }

        return $summaries;
    }

    /**
     * @param  array<string, array<string, mixed>>  ...$sources
     * @return array<string, array<string, mixed>>
     */
    private function mergeMemorySummaries(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            foreach ($source as $agentId => $summary) {
                $merged[$agentId] = array_merge($merged[$agentId] ?? $this->emptyMemorySummary(), $summary);
            }
        }

        return $merged;
    }

    /**
     * @param  list<string>  $agentIds
     * @param  array<int, array<string, mixed>>  $checks
     * @return array<string, array<string, mixed>>
     */
    private function collectRegistryCoverage(array $agentIds, array &$checks): array
    {
        if ($agentIds === []) {
            return [];
        }

        $skills = collect($this->skillLoader->getSkillIndex())
            ->filter(fn (array $skill): bool => trim((string) ($skill['name'] ?? '')) !== '')
            ->keyBy(fn (array $skill): string => trim((string) $skill['name']));

        $coverage = [];
        $declaredTools = [];

        foreach ($agentIds as $agentId) {
            $skill = $skills->get($agentId);
            $tools = $this->normalizeToolNames($skill['tools'] ?? []);

            $coverage[$agentId] = [
                'skill_present' => $skill !== null,
                'skill_version' => $skill['version'] ?? null,
                'tools_declared' => count($tools),
                'tools_registered' => 0,
                'tools_enabled' => 0,
                'tools_missing' => $tools,
                'tools_disabled' => [],
                'tools_blocked' => [],
            ];

            foreach ($tools as $tool) {
                $declaredTools[$tool] = true;
            }
        }

        $declaredTools = array_keys($declaredTools);
        if ($declaredTools === []) {
            return $coverage;
        }

        if (! Schema::hasTable('agent_tool_registry')) {
            $checks[] = $this->check('agent_tool_registry', 'warning', 'agent_tool_registry table is missing');

            return $coverage;
        }

        foreach (['name', 'enabled', 'risk_level'] as $column) {
            if (! Schema::hasColumn('agent_tool_registry', $column)) {
                $checks[] = $this->check('agent_tool_registry', 'warning', "agent_tool_registry.{$column} column is missing");

                return $coverage;
            }
        }

        $registryRows = DB::table('agent_tool_registry')
            ->select('name', 'enabled', 'risk_level')
            ->whereIn('name', $declaredTools)
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->name);

        foreach ($coverage as $agentId => $summary) {
            $missing = [];
            $disabled = [];
            $blocked = [];
            $registered = 0;
            $enabled = 0;

            foreach ($summary['tools_missing'] as $tool) {
                $row = $registryRows->get($tool);
                if ($row === null) {
                    $missing[] = $tool;

                    continue;
                }

                $registered++;
                if ((bool) $row->enabled) {
                    $enabled++;
                } else {
                    $disabled[] = $tool;
                }

                if ((string) ($row->risk_level ?? '') === 'blocked') {
                    $blocked[] = $tool;
                }
            }

            $coverage[$agentId]['tools_registered'] = $registered;
            $coverage[$agentId]['tools_enabled'] = $enabled;
            $coverage[$agentId]['tools_missing'] = $this->capToolList($missing);
            $coverage[$agentId]['tools_disabled'] = $this->capToolList($disabled);
            $coverage[$agentId]['tools_blocked'] = $this->capToolList($blocked);
        }

        return $coverage;
    }

    /**
     * @param  array<string, mixed>  $sessions
     * @param  array<string, mixed>  $scheduledJob
     * @param  array<string, mixed>  $reviewQueue
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $memory
     * @return array<string, mixed>
     */
    private function buildAgentReport(string $agentId, array $sessions, array $scheduledJob, array $reviewQueue, array $registry, array $memory): array
    {
        $sessions = $this->publicSessionSummary($sessions);
        $warnings = [];
        $critical = [];

        if (($sessions['critical_stalled'] ?? 0) > 0) {
            $critical[] = "{$sessions['critical_stalled']} active session(s) are critically stale";
        } elseif (($sessions['stalled'] ?? 0) > 0) {
            $warnings[] = "{$sessions['stalled']} active session(s) appear stalled";
        }

        if (($sessions['expired_unreaped'] ?? 0) > 0) {
            $warnings[] = "{$sessions['expired_unreaped']} expired session(s) were not reaped";
        }

        $failureWarn = (int) config('health_thresholds.agents.consecutive_failures_warning', 2);
        $failureCrit = (int) config('health_thresholds.agents.consecutive_failures_critical', 3);
        $consecutiveFailures = (int) ($scheduledJob['consecutive_failures'] ?? 0);
        if ($consecutiveFailures >= $failureCrit) {
            $critical[] = "{$consecutiveFailures} consecutive scheduled-job failures";
        } elseif ($consecutiveFailures >= $failureWarn) {
            $warnings[] = "{$consecutiveFailures} consecutive scheduled-job failures";
        }

        $timeoutSeconds = max(0, (int) ($scheduledJob['timeout_minutes'] ?? 0)) * 60;
        $p95Runtime = $scheduledJob['p95_runtime_s_24h'] ?? null;
        if ($timeoutSeconds > 0 && $p95Runtime !== null) {
            $runtimeWarn = (float) config('health_thresholds.agents.runtime_timeout_warning_ratio', 0.7);
            $runtimeCrit = (float) config('health_thresholds.agents.runtime_timeout_critical_ratio', 1.0);
            if ($p95Runtime >= $timeoutSeconds * $runtimeCrit) {
                $critical[] = "p95 runtime {$p95Runtime}s meets/exceeds timeout";
            } elseif ($p95Runtime >= $timeoutSeconds * $runtimeWarn) {
                $warnings[] = "p95 runtime {$p95Runtime}s is near timeout";
            }
        }

        $reviewExpiryHours = max(1, (int) config('agents.review_expiry_days', 7) * 24);
        $reviewWarnHours = $reviewExpiryHours * (float) config('health_thresholds.agents.review_queue_warning_fraction', 0.5);
        $reviewCritHours = $reviewExpiryHours * (float) config('health_thresholds.agents.review_queue_critical_fraction', 0.9);
        $oldestAge = $reviewQueue['oldest_age_hours'] ?? null;
        if ($oldestAge !== null && $oldestAge >= $reviewCritHours) {
            $critical[] = "oldest pending review is {$oldestAge}h old";
        } elseif ($oldestAge !== null && $oldestAge >= $reviewWarnHours) {
            $warnings[] = "oldest pending review is {$oldestAge}h old";
        }

        $highPriorityAge = (float) ($reviewQueue['oldest_high_priority_age_hours'] ?? 0.0);
        if (($reviewQueue['high_priority'] ?? 0) > 0) {
            $highWarn = (float) config('health_thresholds.agents.high_priority_warning_hours', 6.0);
            $highCrit = (float) config('health_thresholds.agents.high_priority_critical_hours', 24.0);
            if ($highPriorityAge >= $highCrit) {
                $critical[] = "high-priority review has waited {$highPriorityAge}h";
            } elseif ($highPriorityAge >= $highWarn) {
                $warnings[] = "high-priority review has waited {$highPriorityAge}h";
            }
        }

        if (($scheduledJob['enabled'] ?? false) && ($registry['skill_present'] ?? null) === false) {
            $warnings[] = "SKILL.md missing for {$agentId}";
        }

        $cjkOutputs = (int) ($scheduledJob['cjk_output_runs_24h'] ?? 0);
        if ($cjkOutputs > 0) {
            $warnings[] = "{$cjkOutputs} scheduled output(s) contain CJK/non-English script markers";
        }

        $guardedOutputs = (int) ($scheduledJob['guarded_output_runs_24h'] ?? 0);
        if ($guardedOutputs > 0) {
            $warnings[] = "{$guardedOutputs} scheduled output(s) were suppressed by Agent Output Guard";
        }

        $missingTools = count($registry['tools_missing'] ?? []);
        $disabledTools = count($registry['tools_disabled'] ?? []);
        $blockedTools = count($registry['tools_blocked'] ?? []);
        $missingWarn = max(1, (int) config('health_thresholds.agents.tools_missing_warning', 1));
        $blockedCrit = max(1, (int) config('health_thresholds.agents.tools_blocked_critical', 1));

        if ($blockedTools >= $blockedCrit) {
            $critical[] = "{$blockedTools} declared tool(s) are registry-blocked";
        }

        if ($missingTools >= $missingWarn) {
            $warnings[] = "{$missingTools} declared tool(s) missing from registry";
        }

        if ($disabledTools > 0) {
            $warnings[] = "{$disabledTools} declared tool(s) disabled in registry";
        }

        $memoryErrors = (int) ($memory['error_episodes_window'] ?? 0);
        if ($memoryErrors > 0) {
            $warnings[] = "{$memoryErrors} memory/error episode(s) in the window";
        }

        $memoryTokens = (int) ($memory['tokens_window'] ?? 0);
        $memoryTokenWarn = (int) config('health_thresholds.agents.memory_tokens_warning', 100_000);
        if ($memoryTokenWarn > 0 && $memoryTokens >= $memoryTokenWarn) {
            $warnings[] = "{$memoryTokens} memory episode token(s) in the window";
        }

        $episodesWithoutDistillationWarn = (int) config('health_thresholds.agents.episodes_without_distillation_warning', 25);
        $episodes = (int) ($memory['episodes_window'] ?? 0);
        $summaries = (int) ($memory['summaries_window'] ?? 0);
        if ($episodesWithoutDistillationWarn > 0 && $episodes >= $episodesWithoutDistillationWarn && $summaries === 0) {
            $warnings[] = "{$episodes} episode(s) but no distillation in the window";
        }

        $distillationStaleHours = (float) config('health_thresholds.agents.distillation_stale_hours_warning', 48.0);
        $hoursSinceLastSummary = $memory['hours_since_last_summary'] ?? null;
        if ($distillationStaleHours > 0 && $hoursSinceLastSummary !== null && (float) $hoursSinceLastSummary >= $distillationStaleHours) {
            $warnings[] = "distillation stale ({$hoursSinceLastSummary}h since last summary)";
        }

        $lowQualityProcedures = (int) ($memory['procedures_low_quality'] ?? 0);
        if ($lowQualityProcedures > 0) {
            $warnings[] = "{$lowQualityProcedures} low-quality procedure(s)";
        }

        $status = $critical !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'healthy');

        $report = [
            'agent_id' => $agentId,
            'scheduled_job' => $scheduledJob,
            'sessions' => $sessions,
            'review_queue' => $reviewQueue,
            'registry' => $registry,
            'memory' => $memory,
            'status' => $status,
            'warnings' => $warnings,
            'critical' => $critical,
        ];

        $report['issue_codes'] = self::agentReasonCodes($report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{agent_id:string,reason_codes:list<string>}>
     */
    public static function compactAgentReasonSummaries(array $payload, string $status, int $limit = 5): array
    {
        $agents = is_array($payload['agents'] ?? null) ? $payload['agents'] : [];
        $summaries = [];

        foreach ($agents as $agent) {
            if (! is_array($agent) || ($agent['status'] ?? null) !== $status) {
                continue;
            }

            $agentId = self::safeCompactAgentId($agent['agent_id'] ?? null);
            if ($agentId === null) {
                continue;
            }

            $reasonCodes = is_array($agent['issue_codes'] ?? null)
                ? self::sanitizeReasonCodes($agent['issue_codes'])
                : self::agentReasonCodes($agent);

            $summaries[] = [
                'agent_id' => $agentId,
                'reason_codes' => $reasonCodes,
            ];

            if (count($summaries) >= max(1, $limit)) {
                break;
            }
        }

        return $summaries;
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return list<string>
     */
    private static function agentReasonCodes(array $agent): array
    {
        $codes = [];
        $sessions = is_array($agent['sessions'] ?? null) ? $agent['sessions'] : [];
        $scheduledJob = is_array($agent['scheduled_job'] ?? null) ? $agent['scheduled_job'] : [];
        $reviewQueue = is_array($agent['review_queue'] ?? null) ? $agent['review_queue'] : [];
        $registry = is_array($agent['registry'] ?? null) ? $agent['registry'] : [];
        $memory = is_array($agent['memory'] ?? null) ? $agent['memory'] : [];

        if ((int) ($sessions['critical_stalled'] ?? 0) > 0) {
            self::addReasonCode($codes, 'session_critical_stalled');
        } elseif ((int) ($sessions['stalled'] ?? 0) > 0) {
            self::addReasonCode($codes, 'session_stalled');
        }

        if ((int) ($sessions['expired_unreaped'] ?? 0) > 0) {
            self::addReasonCode($codes, 'session_expired_unreaped');
        }

        $failureWarn = (int) config('health_thresholds.agents.consecutive_failures_warning', 2);
        $failureCrit = (int) config('health_thresholds.agents.consecutive_failures_critical', 3);
        $consecutiveFailures = (int) ($scheduledJob['consecutive_failures'] ?? 0);
        if ($consecutiveFailures >= $failureCrit) {
            self::addReasonCode($codes, 'scheduled_job_failures_critical');
        } elseif ($consecutiveFailures >= $failureWarn) {
            self::addReasonCode($codes, 'scheduled_job_failures_warning');
        }

        $timeoutSeconds = max(0, (int) ($scheduledJob['timeout_minutes'] ?? 0)) * 60;
        $p95Runtime = $scheduledJob['p95_runtime_s_24h'] ?? null;
        if ($timeoutSeconds > 0 && $p95Runtime !== null) {
            $runtimeWarn = (float) config('health_thresholds.agents.runtime_timeout_warning_ratio', 0.7);
            $runtimeCrit = (float) config('health_thresholds.agents.runtime_timeout_critical_ratio', 1.0);
            if ((float) $p95Runtime >= $timeoutSeconds * $runtimeCrit) {
                self::addReasonCode($codes, 'scheduled_runtime_timeout');
            } elseif ((float) $p95Runtime >= $timeoutSeconds * $runtimeWarn) {
                self::addReasonCode($codes, 'scheduled_runtime_near_timeout');
            }
        }

        $reviewExpiryHours = max(1, (int) config('agents.review_expiry_days', 7) * 24);
        $reviewWarnHours = $reviewExpiryHours * (float) config('health_thresholds.agents.review_queue_warning_fraction', 0.5);
        $oldestAge = $reviewQueue['oldest_age_hours'] ?? null;
        if ($oldestAge !== null && (float) $oldestAge >= $reviewWarnHours) {
            self::addReasonCode($codes, 'review_queue_aged');
        }

        $highPriorityAge = (float) ($reviewQueue['oldest_high_priority_age_hours'] ?? 0.0);
        $highWarn = (float) config('health_thresholds.agents.high_priority_warning_hours', 6.0);
        if ((int) ($reviewQueue['high_priority'] ?? 0) > 0 && $highPriorityAge >= $highWarn) {
            self::addReasonCode($codes, 'review_queue_high_priority_aged');
        }

        if (($scheduledJob['enabled'] ?? false) && ($registry['skill_present'] ?? null) === false) {
            self::addReasonCode($codes, 'skill_missing');
        }

        if (count((array) ($registry['tools_blocked'] ?? [])) >= max(1, (int) config('health_thresholds.agents.tools_blocked_critical', 1))) {
            self::addReasonCode($codes, 'registry_tools_blocked');
        }

        if (count((array) ($registry['tools_missing'] ?? [])) >= max(1, (int) config('health_thresholds.agents.tools_missing_warning', 1))) {
            self::addReasonCode($codes, 'registry_tools_missing');
        }

        if (count((array) ($registry['tools_disabled'] ?? [])) > 0) {
            self::addReasonCode($codes, 'registry_tools_disabled');
        }

        if ((int) ($scheduledJob['cjk_output_runs_24h'] ?? 0) > 0) {
            self::addReasonCode($codes, 'scheduled_output_cjk');
        }

        if ((int) ($scheduledJob['guarded_output_runs_24h'] ?? 0) > 0) {
            self::addReasonCode($codes, 'scheduled_output_guarded');
        }

        if ((int) ($memory['error_episodes_window'] ?? 0) > 0) {
            self::addReasonCode($codes, 'memory_error_episode');
        }

        $memoryTokenWarn = (int) config('health_thresholds.agents.memory_tokens_warning', 100_000);
        if ($memoryTokenWarn > 0 && (int) ($memory['tokens_window'] ?? 0) >= $memoryTokenWarn) {
            self::addReasonCode($codes, 'memory_token_pressure');
        }

        $episodesWithoutDistillationWarn = (int) config('health_thresholds.agents.episodes_without_distillation_warning', 25);
        $episodes = (int) ($memory['episodes_window'] ?? 0);
        $summaries = (int) ($memory['summaries_window'] ?? 0);
        if ($episodesWithoutDistillationWarn > 0 && $episodes >= $episodesWithoutDistillationWarn && $summaries === 0) {
            self::addReasonCode($codes, 'memory_distillation_missing');
        }

        $distillationStaleHours = (float) config('health_thresholds.agents.distillation_stale_hours_warning', 48.0);
        $hoursSinceLastSummary = $memory['hours_since_last_summary'] ?? null;
        if ($distillationStaleHours > 0 && $hoursSinceLastSummary !== null && (float) $hoursSinceLastSummary >= $distillationStaleHours) {
            self::addReasonCode($codes, 'memory_distillation_stale');
        }

        if ((int) ($memory['procedures_low_quality'] ?? 0) > 0) {
            self::addReasonCode($codes, 'procedure_low_quality');
        }

        return array_slice($codes, 0, 8);
    }

    /**
     * @param  list<string>  $codes
     */
    private static function addReasonCode(array &$codes, string $code): void
    {
        if (! in_array($code, $codes, true)) {
            $codes[] = $code;
        }
    }

    /**
     * @return list<string>
     */
    private static function sanitizeReasonCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_slice(array_filter(
            $codes,
            fn (mixed $code): bool => is_string($code) && preg_match('/^[a-z][a-z0-9_]{1,80}$/', $code) === 1
        ), 0, 8));
    }

    private static function safeCompactAgentId(mixed $value): ?string
    {
        $agentId = trim((string) $value);
        if ($agentId === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,96}$/', $agentId) === 1) {
            return $agentId;
        }

        return 'agent-'.substr(hash('sha256', $agentId), 0, 12);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectTraceReadiness(): array
    {
        $enabled = (bool) config('dev_agent.trace.enabled', true);
        $path = $this->classifyTracePath((string) config('dev_agent.trace.dir', storage_path('app/dev-agent/traces')));
        $dir = (string) $path['effective_path'];
        $directoryExists = is_dir($dir);
        $directoryWritable = $directoryExists && is_writable($dir);
        $retentionDays = max(1, (int) config('dev_agent.trace.retention_days', 14));
        $warnings = [];

        $readiness = [
            'mode' => 'observe',
            'source' => TraceEnvelopeService::class.'+config/dev_agent.php',
            'enabled' => $enabled,
            'retention_days' => $retentionDays,
            'configured_path_class' => $path['configured_path_class'],
            'configured_path_safe' => $path['configured_path_safe'],
            'using_default_path' => $path['using_default_path'],
            'effective_path_class' => $path['effective_path_class'],
            'directory_exists' => $directoryExists,
            'directory_writable' => $directoryWritable,
            'oldest_file_date' => null,
            'files_over_retention' => 0,
            'latest_event_at' => null,
            'events_24h' => null,
            'events_24h_exact' => false,
            'malformed_lines_24h' => null,
            'scan_status' => 'not_run',
            'status' => 'healthy',
            'warnings' => [],
        ];

        if (! $enabled) {
            $readiness['scan_status'] = 'disabled';
            $readiness['status'] = 'disabled';

            return $readiness;
        }

        if (! (bool) $path['configured_path_safe']) {
            $warnings[] = 'configured trace path is outside storage/app; TraceEnvelopeService will use the default trace directory';
        }

        if (! $directoryExists) {
            $warnings[] = 'trace directory does not exist';
            $readiness['scan_status'] = 'unavailable';
        } elseif (! $directoryWritable) {
            $warnings[] = 'trace directory is not writable';
        }

        if ($directoryExists) {
            $retention = $this->traceRetentionSummary($dir, $retentionDays);
            $readiness['oldest_file_date'] = $retention['oldest_file_date'];
            $readiness['files_over_retention'] = $retention['files_over_retention'];
            if ($retention['files_over_retention'] > 0) {
                $warnings[] = "{$retention['files_over_retention']} trace file(s) exceed the configured retention policy";
            }

            $files = $this->candidateTraceFiles($dir, self::TRACE_READINESS_HOURS);
            $scanBytes = $this->traceFileBytes($files);

            if ($scanBytes > self::TRACE_READINESS_MAX_SCAN_BYTES) {
                $readiness['scan_status'] = 'skipped_large';
                $warnings[] = 'trace readiness scan skipped because recent trace files exceed the cheap-read limit';
            } else {
                try {
                    $tail = $this->traceEnvelopes->tail([
                        'since' => self::TRACE_READINESS_HOURS,
                        'limit' => self::TRACE_READINESS_LIMIT,
                    ]);

                    $events = is_array($tail['events'] ?? null) ? $tail['events'] : [];
                    $eventCount = count($events);
                    $readiness['latest_event_at'] = $events[0]['recorded_at'] ?? $events[0]['occurred_at'] ?? null;
                    $readiness['events_24h_exact'] = $eventCount < self::TRACE_READINESS_LIMIT;
                    $readiness['events_24h'] = $readiness['events_24h_exact'] ? $eventCount : null;
                    $readiness['malformed_lines_24h'] = $this->traceWarningCount($tail, 'malformed_json');
                    $readiness['scan_status'] = $readiness['events_24h_exact'] ? 'scanned' : 'scanned_limited';

                    $unreadableFiles = $this->traceWarningCount($tail, 'unreadable');
                    if ($unreadableFiles > 0) {
                        $warnings[] = "{$unreadableFiles} recent trace file(s) were unreadable";
                    }

                    if ((int) $readiness['malformed_lines_24h'] > 0) {
                        $warnings[] = "{$readiness['malformed_lines_24h']} malformed trace line(s) in the last 24h";
                    }
                } catch (\Throwable) {
                    $readiness['scan_status'] = 'failed';
                    $warnings[] = 'trace readiness scan failed';
                }
            }
        }

        $readiness['warnings'] = $warnings;
        $readiness['status'] = $warnings === [] ? 'healthy' : 'warning';

        return $readiness;
    }

    /**
     * @return array{
     *     configured_path_class:string,
     *     configured_path_safe:bool,
     *     using_default_path:bool,
     *     effective_path_class:string,
     *     effective_path:string
     * }
     */
    private function classifyTracePath(string $configured): array
    {
        $configured = trim($configured);
        $defaultRoot = rtrim(storage_path('app/dev-agent/traces'), '/');
        $storageRoot = rtrim(storage_path('app'), '/');

        if ($configured === '') {
            return [
                'configured_path_class' => 'empty_default',
                'configured_path_safe' => true,
                'using_default_path' => true,
                'effective_path_class' => 'default_storage_app',
                'effective_path' => $defaultRoot,
            ];
        }

        $wasAbsolute = str_starts_with($configured, '/');
        $normalized = $this->normalizeTracePath(rtrim($configured, '/'));
        $safe = $normalized !== null && str_starts_with($normalized, $storageRoot.'/');
        $configuredClass = match (true) {
            $normalized === null => 'unresolved',
            ! $safe => 'outside_storage_app',
            ! $wasAbsolute => 'relative_storage_app',
            $normalized === $defaultRoot => 'default_storage_app',
            default => 'storage_app',
        };

        $effectivePath = $safe ? $normalized : $defaultRoot;

        return [
            'configured_path_class' => $configuredClass,
            'configured_path_safe' => $safe,
            'using_default_path' => $effectivePath === $defaultRoot,
            'effective_path_class' => $effectivePath === $defaultRoot ? 'default_storage_app' : 'storage_app',
            'effective_path' => $effectivePath,
        ];
    }

    private function normalizeTracePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (! str_starts_with($path, '/')) {
            $path = storage_path('app/'.$path);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private function candidateTraceFiles(string $dir, int $hours): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $since = CarbonImmutable::now('UTC')->subHours($hours);
        $cursor = CarbonImmutable::now('UTC')->startOfDay();
        $stop = $since->startOfDay();

        while ($cursor->greaterThanOrEqualTo($stop)) {
            $path = $dir.'/'.$cursor->format('Y-m-d').'.ndjson';
            if (is_file($path)) {
                $files[] = $path;
            }

            $cursor = $cursor->subDay();
        }

        return $files;
    }

    /**
     * @return array{oldest_file_date:?string,files_over_retention:int}
     */
    private function traceRetentionSummary(string $dir, int $retentionDays): array
    {
        $oldest = null;
        $overRetention = 0;
        $cutoff = CarbonImmutable::now('UTC')->startOfDay()->subDays($retentionDays);

        foreach (glob($dir.'/*.ndjson') ?: [] as $file) {
            if (! is_string($file) || ! preg_match('/^(\d{4}-\d{2}-\d{2})\.ndjson$/', basename($file), $matches)) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($matches[1], 'UTC')->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            $dateString = $date->toDateString();
            $oldest = $oldest === null || $dateString < $oldest ? $dateString : $oldest;
            if ($date->lessThan($cutoff)) {
                $overRetention++;
            }
        }

        return [
            'oldest_file_date' => $oldest,
            'files_over_retention' => $overRetention,
        ];
    }

    /**
     * @param  list<string>  $files
     */
    private function traceFileBytes(array $files): int
    {
        $bytes = 0;
        foreach ($files as $file) {
            $size = @filesize($file);
            if ($size === false) {
                continue;
            }

            $bytes += $size;
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $tail
     */
    private function traceWarningCount(array $tail, string $warning): int
    {
        return collect($tail['warnings'] ?? [])
            ->filter(fn (mixed $entry): bool => is_array($entry) && ($entry['warning'] ?? null) === $warning)
            ->count();
    }

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<string, mixed>
     */
    private function buildSummary(array $agents, CarbonImmutable $now): array
    {
        $sessionsActive = 0;
        $sessionsStalled = 0;
        $reviewPending = 0;
        $reviewAged = 0;
        $toolsMissing = 0;
        $toolsBlocked = 0;
        $memoryEpisodes = 0;
        $memoryErrors = 0;
        $memoryTokens = 0;
        $memorySummaries = 0;
        $memoryUndistilledEpisodes = 0;
        $proceduresLowQuality = 0;
        $scheduledSuccessRuns = 0;
        $scheduledEmptySuccessOutputs = 0;
        $scheduledCjkOutputRuns = 0;
        $scheduledNonAsciiOutputRuns = 0;
        $scheduledGuardedOutputRuns = 0;
        $latestScheduledEmptySuccessOutputAt = null;
        $latestScheduledCjkOutputAt = null;
        $latestScheduledNonAsciiOutputAt = null;
        $latestScheduledGuardedOutputAt = null;
        $issueCodeCounts = [];

        foreach ($agents as $agent) {
            $sessionsActive += (int) ($agent['sessions']['active'] ?? 0);
            $sessionsStalled += (int) ($agent['sessions']['stalled'] ?? 0);
            $reviewPending += (int) ($agent['review_queue']['pending'] ?? 0);
            $toolsMissing += count($agent['registry']['tools_missing'] ?? []);
            $toolsBlocked += count($agent['registry']['tools_blocked'] ?? []);
            $memoryEpisodes += (int) ($agent['memory']['episodes_window'] ?? 0);
            $memoryErrors += (int) ($agent['memory']['error_episodes_window'] ?? 0);
            $memoryTokens += (int) ($agent['memory']['tokens_window'] ?? 0);
            $memorySummaries += (int) ($agent['memory']['summaries_window'] ?? 0);
            $proceduresLowQuality += (int) ($agent['memory']['procedures_low_quality'] ?? 0);
            $scheduledSuccessRuns += (int) ($agent['scheduled_job']['successful_runs_24h'] ?? 0);
            $scheduledEmptySuccessOutputs += (int) ($agent['scheduled_job']['empty_success_output_runs_24h'] ?? 0);
            $scheduledCjkOutputRuns += (int) ($agent['scheduled_job']['cjk_output_runs_24h'] ?? 0);
            $scheduledNonAsciiOutputRuns += (int) ($agent['scheduled_job']['non_ascii_output_runs_24h'] ?? 0);
            $scheduledGuardedOutputRuns += (int) ($agent['scheduled_job']['guarded_output_runs_24h'] ?? 0);
            $latestScheduledEmptySuccessOutputAt = $this->latestTime(
                $latestScheduledEmptySuccessOutputAt,
                $this->parseTime($agent['scheduled_job']['latest_empty_success_output_at'] ?? null)
            );
            $latestScheduledCjkOutputAt = $this->latestTime(
                $latestScheduledCjkOutputAt,
                $this->parseTime($agent['scheduled_job']['latest_cjk_output_at'] ?? null)
            );
            $latestScheduledNonAsciiOutputAt = $this->latestTime(
                $latestScheduledNonAsciiOutputAt,
                $this->parseTime($agent['scheduled_job']['latest_non_ascii_output_at'] ?? null)
            );
            $latestScheduledGuardedOutputAt = $this->latestTime(
                $latestScheduledGuardedOutputAt,
                $this->parseTime($agent['scheduled_job']['latest_guarded_output_at'] ?? null)
            );
            if (((int) ($agent['memory']['episodes_window'] ?? 0)) > 0 && ((int) ($agent['memory']['summaries_window'] ?? 0)) === 0) {
                $memoryUndistilledEpisodes += (int) ($agent['memory']['episodes_window'] ?? 0);
            }
            if (($agent['review_queue']['oldest_age_hours'] ?? null) !== null && $agent['warnings'] !== []) {
                $reviewAged++;
            }

            foreach (self::sanitizeReasonCodes($agent['issue_codes'] ?? []) as $code) {
                $issueCodeCounts[$code] = ($issueCodeCounts[$code] ?? 0) + 1;
            }
        }

        arsort($issueCodeCounts);

        return [
            'agents_total' => count($agents),
            'agents_enabled' => count(array_filter($agents, fn (array $agent): bool => (bool) ($agent['scheduled_job']['enabled'] ?? false))),
            'agents_with_warnings' => count(array_filter($agents, fn (array $agent): bool => ($agent['status'] ?? '') === 'warning')),
            'agents_with_critical' => count(array_filter($agents, fn (array $agent): bool => ($agent['status'] ?? '') === 'critical')),
            'sessions_active' => $sessionsActive,
            'sessions_stalled' => $sessionsStalled,
            'review_queue_pending' => $reviewPending,
            'review_queue_aged' => $reviewAged,
            'tools_missing_total' => $toolsMissing,
            'tools_blocked_total' => $toolsBlocked,
            'memory_episodes_window' => $memoryEpisodes,
            'memory_error_episodes_window' => $memoryErrors,
            'memory_tokens_window' => $memoryTokens,
            'memory_summaries_window' => $memorySummaries,
            'memory_undistilled_episodes_window' => $memoryUndistilledEpisodes,
            'procedures_low_quality_total' => $proceduresLowQuality,
            'scheduled_success_runs_window' => $scheduledSuccessRuns,
            'scheduled_empty_success_outputs_window' => $scheduledEmptySuccessOutputs,
            'scheduled_cjk_output_runs_window' => $scheduledCjkOutputRuns,
            'scheduled_non_ascii_output_runs_window' => $scheduledNonAsciiOutputRuns,
            'scheduled_guarded_output_runs_window' => $scheduledGuardedOutputRuns,
            'scheduled_latest_empty_success_output_at' => $latestScheduledEmptySuccessOutputAt?->toIso8601String(),
            'scheduled_latest_empty_success_output_age_hours' => $this->ageHours($latestScheduledEmptySuccessOutputAt, $now),
            'scheduled_latest_cjk_output_at' => $latestScheduledCjkOutputAt?->toIso8601String(),
            'scheduled_latest_cjk_output_age_hours' => $this->ageHours($latestScheduledCjkOutputAt, $now),
            'scheduled_latest_non_ascii_output_at' => $latestScheduledNonAsciiOutputAt?->toIso8601String(),
            'scheduled_latest_non_ascii_output_age_hours' => $this->ageHours($latestScheduledNonAsciiOutputAt, $now),
            'scheduled_latest_guarded_output_at' => $latestScheduledGuardedOutputAt?->toIso8601String(),
            'scheduled_latest_guarded_output_age_hours' => $this->ageHours($latestScheduledGuardedOutputAt, $now),
            'issue_code_counts' => $issueCodeCounts,
            'top_issue_codes' => array_slice(array_keys($issueCodeCounts), 0, 8),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $agents
     * @return array<int, array<string, string>>
     */
    private function buildAggregateChecks(array $agents): array
    {
        $sessionsStalled = array_sum(array_map(fn (array $agent): int => (int) ($agent['sessions']['stalled'] ?? 0), $agents));
        $criticalSessions = array_sum(array_map(fn (array $agent): int => (int) ($agent['sessions']['critical_stalled'] ?? 0), $agents));
        $criticalAgents = count(array_filter($agents, fn (array $agent): bool => ($agent['status'] ?? '') === 'critical'));
        $warningAgents = count(array_filter($agents, fn (array $agent): bool => ($agent['status'] ?? '') === 'warning'));
        $toolsMissing = array_sum(array_map(fn (array $agent): int => count($agent['registry']['tools_missing'] ?? []), $agents));
        $toolsBlocked = array_sum(array_map(fn (array $agent): int => count($agent['registry']['tools_blocked'] ?? []), $agents));
        $memoryErrors = array_sum(array_map(fn (array $agent): int => (int) ($agent['memory']['error_episodes_window'] ?? 0), $agents));
        $undistilledEpisodes = array_sum(array_map(function (array $agent): int {
            $memory = $agent['memory'] ?? [];

            return ((int) ($memory['summaries_window'] ?? 0)) === 0
                ? (int) ($memory['episodes_window'] ?? 0)
                : 0;
        }, $agents));
        $staleDistillations = count(array_filter($agents, function (array $agent): bool {
            $hours = $agent['memory']['hours_since_last_summary'] ?? null;

            return $hours !== null && (float) $hours >= (float) config('health_thresholds.agents.distillation_stale_hours_warning', 48.0);
        }));
        $lowQualityProcedures = array_sum(array_map(fn (array $agent): int => (int) ($agent['memory']['procedures_low_quality'] ?? 0), $agents));
        $cjkOutputRuns = array_sum(array_map(fn (array $agent): int => (int) ($agent['scheduled_job']['cjk_output_runs_24h'] ?? 0), $agents));
        $nonAsciiOutputRuns = array_sum(array_map(fn (array $agent): int => (int) ($agent['scheduled_job']['non_ascii_output_runs_24h'] ?? 0), $agents));
        $guardedOutputRuns = array_sum(array_map(fn (array $agent): int => (int) ($agent['scheduled_job']['guarded_output_runs_24h'] ?? 0), $agents));

        return [
            $this->check(
                'stalled_sessions',
                $criticalSessions > 0 ? 'critical' : ($sessionsStalled > 0 ? 'warning' : 'ok'),
                "{$sessionsStalled} stalled active session(s)"
            ),
            $this->check(
                'agent_status',
                $criticalAgents > 0 ? 'critical' : ($warningAgents > 0 ? 'warning' : 'ok'),
                "{$criticalAgents} critical agent(s), {$warningAgents} warning agent(s)"
            ),
            $this->check(
                'registry_coverage',
                $toolsBlocked > 0 ? 'critical' : ($toolsMissing > 0 ? 'warning' : 'ok'),
                "{$toolsMissing} missing declared tool(s); {$toolsBlocked} blocked declared tool(s)"
            ),
            $this->check(
                'memory_errors',
                $memoryErrors > 0 ? 'warning' : 'ok',
                "{$memoryErrors} memory/error episode(s) in the window"
            ),
            $this->check(
                'episodic_distillation',
                $undistilledEpisodes > 0 || $staleDistillations > 0 ? 'warning' : 'ok',
                "{$undistilledEpisodes} undistilled episode(s); {$staleDistillations} stale distillation agent(s)"
            ),
            $this->check(
                'procedural_quality',
                $lowQualityProcedures > 0 ? 'warning' : 'ok',
                "{$lowQualityProcedures} low-quality procedure(s)"
            ),
            $this->check(
                'agent_output_quality',
                $cjkOutputRuns > 0 || $guardedOutputRuns > 0 ? 'warning' : 'ok',
                "{$cjkOutputRuns} scheduled output(s) contain CJK/non-English script markers; {$nonAsciiOutputRuns} non-ASCII output(s); {$guardedOutputRuns} guarded output(s)"
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeToolNames(mixed $tools): array
    {
        if (! is_array($tools)) {
            return [];
        }

        return collect($tools)
            ->map(fn ($tool): string => trim((string) $tool))
            ->filter(fn (string $tool): bool => $tool !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tools
     * @return list<string>
     */
    private function capToolList(array $tools): array
    {
        $limit = max(1, (int) config('health_thresholds.agents.tools_inventory_max_listed', 25));

        return array_slice(array_values($tools), 0, $limit);
    }

    /**
     * @param  Collection<int, object>  $runs
     */
    private function consecutiveFailures(Collection $runs): int
    {
        $count = 0;
        foreach ($runs as $run) {
            if (! in_array((string) ($run->status ?? ''), ['failed', 'timeout'], true)) {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  Collection<int, float>  $values
     */
    private function percentile(Collection $values, float $percentile): ?float
    {
        if ($values->isEmpty()) {
            return null;
        }

        $index = (int) ceil($percentile * $values->count()) - 1;

        return round((float) $values->get(max(0, min($index, $values->count() - 1))), 2);
    }

    private function emptySessionSummary(): array
    {
        return [
            'active' => 0,
            'stalled' => 0,
            'critical_stalled' => 0,
            'expired_unreaped' => 0,
            'avg_msg_count_24h' => 0.0,
            'avg_tokens_24h' => 0.0,
            '_window_count' => 0,
            '_message_total' => 0,
            '_token_total' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function publicSessionSummary(array $summary): array
    {
        if (array_key_exists('_window_count', $summary)) {
            $count = max(1, (int) $summary['_window_count']);
            $summary['avg_msg_count_24h'] = round((int) $summary['_message_total'] / $count, 1);
            $summary['avg_tokens_24h'] = round((int) $summary['_token_total'] / $count, 1);
        }

        unset($summary['_window_count'], $summary['_message_total'], $summary['_token_total']);

        return $summary;
    }

    private function emptyScheduledJobSummary(): array
    {
        return [
            'id' => null,
            'name' => null,
            'enabled' => false,
            'cron' => null,
            'timeout_minutes' => null,
            'last_run_at' => null,
            'last_run_status' => null,
            'last_run_duration_s' => null,
            'consecutive_failures' => 0,
            'p95_runtime_s_24h' => null,
            'stall_exempt' => false,
            'successful_runs_24h' => 0,
            'empty_success_output_runs_24h' => 0,
            'cjk_output_runs_24h' => 0,
            'non_ascii_output_runs_24h' => 0,
            'guarded_output_runs_24h' => 0,
            'latest_empty_success_output_at' => null,
            'latest_empty_success_output_age_hours' => null,
            'latest_cjk_output_at' => null,
            'latest_cjk_output_age_hours' => null,
            'latest_non_ascii_output_at' => null,
            'latest_non_ascii_output_age_hours' => null,
            'latest_guarded_output_at' => null,
            'latest_guarded_output_age_hours' => null,
        ];
    }

    private function emptyReviewQueueSummary(): array
    {
        return [
            'pending' => 0,
            'high_priority' => 0,
            'oldest_age_hours' => null,
            'oldest_high_priority_age_hours' => null,
            'expired' => 0,
        ];
    }

    private function emptyRegistrySummary(): array
    {
        return [
            'skill_present' => null,
            'skill_version' => null,
            'tools_declared' => 0,
            'tools_registered' => 0,
            'tools_enabled' => 0,
            'tools_missing' => [],
            'tools_disabled' => [],
            'tools_blocked' => [],
        ];
    }

    private function emptyMemorySummary(): array
    {
        return [
            'episodes_window' => 0,
            'error_episodes_window' => 0,
            'tokens_window' => 0,
            'max_duration_ms_window' => null,
            'last_episode_at' => null,
            'summaries_window' => 0,
            'archived_summaries_window' => 0,
            'last_summary_at' => null,
            'hours_since_last_summary' => null,
            'procedures_total' => 0,
            'procedures_active' => 0,
            'procedures_canonical' => 0,
            'procedures_avg_success_rate' => null,
            'procedures_low_quality' => 0,
            'procedures_last_used_at' => null,
        ];
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int, array<string, mixed>>  $checks
     */
    private function tableHasColumns(string $table, array $columns, array &$checks): bool
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                $checks[] = $this->check($table, 'warning', "{$table}.{$column} column is missing");

                return false;
            }
        }

        return true;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function timeString(mixed $value): ?string
    {
        return $this->parseTime($value)?->toIso8601String();
    }

    private function runSignalTime(object $run): ?CarbonImmutable
    {
        return $this->parseTime($run->completed_at ?? null)
            ?? $this->parseTime($run->started_at ?? null);
    }

    private function latestTime(?CarbonImmutable $current, ?CarbonImmutable $candidate): ?CarbonImmutable
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null || $candidate->greaterThan($current) ? $candidate : $current;
    }

    private function ageHours(?CarbonImmutable $timestamp, CarbonImmutable $now): ?float
    {
        if ($timestamp === null) {
            return null;
        }

        return round(max(0.0, $timestamp->floatDiffInHours($now)), 1);
    }

    /**
     * @param  array<int, array<string, string>>  $checks
     * @param  array<int, array<string, mixed>>  $agents
     */
    private function overallStatus(array $checks, array $agents): string
    {
        if (
            collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? '') === 'critical')
            || collect($agents)->contains(fn (array $agent): bool => ($agent['status'] ?? '') === 'critical')
        ) {
            return 'critical';
        }

        if (
            collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? '') === 'warning')
            || collect($agents)->contains(fn (array $agent): bool => ($agent['status'] ?? '') === 'warning')
        ) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @return array{id:string,status:string,detail:string}
     */
    private function check(string $id, string $status, string $detail): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
