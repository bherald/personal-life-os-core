<?php

namespace App\Services\AgentMetrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyAgentTriageService
{
    private const TARGETS = [
        [
            'job_name' => 'genealogy_analyst',
            'agent_id' => 'genealogy-analyst',
            'role' => 'Evidence analysis and conflict resolution',
        ],
        [
            'job_name' => 'genealogy_auto_research',
            'agent_id' => null,
            'role' => 'Missing-data topic discovery command',
        ],
        [
            'job_name' => 'genealogy_newspaper_research',
            'agent_id' => 'genealogy-newspapers',
            'role' => 'Newspaper and obituary research',
        ],
        [
            'job_name' => 'genealogy_research_colonial_fan',
            'agent_id' => 'genealogy-web',
            'role' => 'Colonial FAN cluster and web research',
        ],
    ];

    public function __construct(
        private readonly AwoReplayService $awoReplay,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(int $windowDays = 30): array
    {
        $windowDays = max(1, min(365, $windowDays));
        $now = CarbonImmutable::now('UTC');
        $since = $now->subDays($windowDays);
        $missingTables = $this->missingTables([
            'scheduled_jobs',
            'agent_sessions',
            'agent_episodes',
            'agent_review_queue',
        ]);

        if ($missingTables !== []) {
            return [
                'version' => 1,
                'mode' => 'observe',
                'generated_at' => $now->toIso8601String(),
                'window_days' => $windowDays,
                'status' => 'blocked',
                'summary' => [
                    'targets_total' => count(self::TARGETS),
                    'missing_tables' => $missingTables,
                ],
                'targets' => [],
                'recommendations' => ['Restore required agent evidence tables before evaluating genealogy agent triage.'],
                'note' => 'Read-only triage only; this command does not enable, disable, approve, reject, or schedule agents.',
            ];
        }

        $jobs = $this->scheduledJobsByName();
        $sessions = $this->sessionsByAgent($since);
        $episodes = $this->episodesByAgent($since);
        $reviews = $this->reviewsByAgent($since);
        $awo = $this->awoByAgent($windowDays);

        $targets = array_map(
            fn (array $target): array => $this->targetPayload($target, $jobs, $sessions, $episodes, $reviews, $awo),
            self::TARGETS
        );

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => $now->toIso8601String(),
            'window_days' => $windowDays,
            'status' => $this->overallStatus($targets),
            'summary' => $this->summary($targets),
            'targets' => $targets,
            'recommendations' => $this->recommendations($targets),
            'note' => 'Read-only triage only; this command does not enable, disable, approve, reject, or schedule agents.',
        ];
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    /**
     * @return array<string, object>
     */
    private function scheduledJobsByName(): array
    {
        $rows = DB::select(
            'SELECT name, command, job_type, enabled, cron_expression, last_run_status,
                    last_run_at, last_completed_at, run_count, fail_count, timeout_minutes, notes
             FROM scheduled_jobs
             WHERE name IN ('.$this->placeholders(self::TARGETS).')
             ORDER BY name',
            array_column(self::TARGETS, 'job_name')
        );

        $byName = [];
        foreach ($rows as $row) {
            $byName[(string) $row->name] = $row;
        }

        return $byName;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sessionsByAgent(CarbonImmutable $since): array
    {
        $agentIds = $this->agentIds();
        if ($agentIds === []) {
            return [];
        }

        $sql = "SELECT agent_name AS agent_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status IN ('active', 'running') THEN 1 ELSE 0 END) AS active,
                    SUM(COALESCE(total_tokens, 0)) AS total_tokens,
                    MAX(COALESCE(last_activity_at, updated_at, created_at)) AS latest_at
             FROM agent_sessions
             WHERE agent_name IN (".$this->placeholders($agentIds).')
               AND created_at >= ?
             GROUP BY agent_name';

        $rows = DB::select(
            $sql,
            [...$agentIds, $since->toDateTimeString()]
        );

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(string) $row->agent_id] = [
                'total' => (int) ($row->total ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'active' => (int) ($row->active ?? 0),
                'total_tokens' => (int) ($row->total_tokens ?? 0),
                'latest_at' => $this->nullableString($row->latest_at ?? null),
            ];
        }

        return $byAgent;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function episodesByAgent(CarbonImmutable $since): array
    {
        $agentIds = $this->agentIds();
        if ($agentIds === []) {
            return [];
        }

        $sql = "SELECT agent_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN event_type = 'error' THEN 1 ELSE 0 END) AS errors,
                    MAX(created_at) AS latest_at
             FROM agent_episodes
             WHERE agent_id IN (".$this->placeholders($agentIds).')
               AND created_at >= ?
             GROUP BY agent_id';

        $rows = DB::select(
            $sql,
            [...$agentIds, $since->toDateTimeString()]
        );

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(string) $row->agent_id] = [
                'total' => (int) ($row->total ?? 0),
                'errors' => (int) ($row->errors ?? 0),
                'latest_at' => $this->nullableString($row->latest_at ?? null),
            ];
        }

        return $byAgent;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reviewsByAgent(CarbonImmutable $since): array
    {
        $agentIds = $this->agentIds();
        if ($agentIds === []) {
            return [];
        }

        $sql = "SELECT agent_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('approved', 'reviewed', 'rejected') THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status IN ('approved', 'reviewed') THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    MAX(COALESCE(reviewed_at, updated_at, created_at)) AS latest_at
             FROM agent_review_queue
             WHERE agent_id IN (".$this->placeholders($agentIds).')
               AND (created_at >= ? OR reviewed_at >= ?)
             GROUP BY agent_id';

        $rows = DB::select(
            $sql,
            [...$agentIds, $since->toDateTimeString(), $since->toDateTimeString()]
        );

        $byAgent = [];
        foreach ($rows as $row) {
            $byAgent[(string) $row->agent_id] = [
                'total' => (int) ($row->total ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'approved' => (int) ($row->approved ?? 0),
                'rejected' => (int) ($row->rejected ?? 0),
                'latest_at' => $this->nullableString($row->latest_at ?? null),
            ];
        }

        return $byAgent;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function awoByAgent(int $windowDays): array
    {
        try {
            $payload = $this->awoReplay->collect($windowDays.'d', 5000);
        } catch (\Throwable) {
            return [];
        }

        $byAgent = [];
        foreach (($payload['by_agent'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $agentId = (string) ($row['agent_id'] ?? '');
            if ($agentId === '') {
                continue;
            }

            $completed = (int) ($row['completed_reviews'] ?? 0);
            $approvalWorthy = (int) ($row['approval_worthy_reviews'] ?? 0);
            $byAgent[$agentId] = [
                'completed_reviews' => $completed,
                'approval_worthy_reviews' => $approvalWorthy,
                'approval_worthy_rate' => $completed > 0 ? round($approvalWorthy / $completed, 4) : null,
                'hard_fail_count' => (int) ($row['hard_fail_count'] ?? 0),
                'rework_count' => (int) ($row['rework_count'] ?? 0),
                'insufficient_data' => $completed < 10,
            ];
        }

        return $byAgent;
    }

    /**
     * @param  array<string, object>  $jobs
     * @param  array<string, array<string, mixed>>  $sessions
     * @param  array<string, array<string, mixed>>  $episodes
     * @param  array<string, array<string, mixed>>  $reviews
     * @param  array<string, array<string, mixed>>  $awo
     * @return array<string, mixed>
     */
    private function targetPayload(array $target, array $jobs, array $sessions, array $episodes, array $reviews, array $awo): array
    {
        $jobName = (string) $target['job_name'];
        $agentId = $target['agent_id'];
        $job = $jobs[$jobName] ?? null;
        $agentKey = is_string($agentId) ? $agentId : null;
        $sessionSummary = $agentKey ? ($sessions[$agentKey] ?? $this->emptySessions()) : $this->emptySessions();
        $episodeSummary = $agentKey ? ($episodes[$agentKey] ?? $this->emptyEpisodes()) : $this->emptyEpisodes();
        $reviewSummary = $agentKey ? ($reviews[$agentKey] ?? $this->emptyReviews()) : $this->emptyReviews();
        $awoSummary = $agentKey ? ($awo[$agentKey] ?? $this->emptyAwo()) : $this->emptyAwo();
        [$status, $triageState, $nextAction] = $this->targetStatus($job, $sessionSummary, $episodeSummary, $reviewSummary, $awoSummary);

        return [
            'job_name' => $jobName,
            'agent_id' => $agentKey,
            'role' => $target['role'],
            'status' => $status,
            'triage_state' => $triageState,
            'enabled' => $job ? (bool) ($job->enabled ?? false) : false,
            'command' => $this->nullableString($job->command ?? null),
            'job_type' => $this->nullableString($job->job_type ?? null),
            'schedule' => [
                'cron_expression' => $this->nullableString($job->cron_expression ?? null),
                'last_run_status' => $this->nullableString($job->last_run_status ?? null),
                'last_run_at' => $this->nullableString($job->last_run_at ?? null),
                'last_completed_at' => $this->nullableString($job->last_completed_at ?? null),
                'run_count' => (int) ($job->run_count ?? 0),
                'fail_count' => (int) ($job->fail_count ?? 0),
                'timeout_minutes' => isset($job->timeout_minutes) ? (int) $job->timeout_minutes : null,
                'notes_present' => trim((string) ($job->notes ?? '')) !== '',
            ],
            'sessions' => $sessionSummary,
            'episodes' => $episodeSummary,
            'reviews' => $reviewSummary,
            'awo' => $awoSummary,
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string, mixed>  $sessions
     * @param  array<string, mixed>  $episodes
     * @param  array<string, mixed>  $reviews
     * @param  array<string, mixed>  $awo
     * @return array{0:string,1:string,2:string}
     */
    private function targetStatus(?object $job, array $sessions, array $episodes, array $reviews, array $awo): array
    {
        if ($job === null) {
            return ['blocked', 'missing_scheduler_row', 'Restore or intentionally retire this scheduler row before re-enablement review.'];
        }

        if ((int) ($job->enabled ?? 0) === 0) {
            return ['watch', 'deferred_disabled', 'Keep disabled until review-packet and AWO evidence gates have scenario coverage.'];
        }

        if (in_array($job->last_run_status ?? null, ['failed', 'timeout'], true) || (int) ($episodes['errors'] ?? 0) > 0) {
            return ['degraded', 'recent_failure', 'Inspect failures before changing cadence or scope.'];
        }

        if ((int) ($awo['completed_reviews'] ?? 0) >= 10 && (int) ($awo['approval_worthy_reviews'] ?? 0) === 0) {
            return ['degraded', 'low_awo_yield', 'Review output quality before increasing autonomy.'];
        }

        if ((bool) ($awo['insufficient_data'] ?? true)) {
            return ['watch', 'insufficient_awo_sample', 'Collect at least 10 AWO-scored reviews before treating this agent as expansion-ready.'];
        }

        if ((int) ($sessions['completed'] ?? 0) === 0 && (int) ($job->run_count ?? 0) > 0) {
            return ['watch', 'no_recent_completed_sessions', 'Collect recent smoke evidence before re-enablement decisions.'];
        }

        if ((int) ($reviews['total'] ?? 0) === 0 && (int) ($sessions['completed'] ?? 0) >= 3) {
            return ['watch', 'no_review_output', 'Confirm the agent produces source-backed review packets before expansion.'];
        }

        return ['healthy', 'observe_only', 'No scheduler action recommended from this read-only sample.'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     * @return array<string, mixed>
     */
    private function summary(array $targets): array
    {
        $completedSessions = array_sum(array_map(fn (array $target): int => (int) ($target['sessions']['completed'] ?? 0), $targets));
        $reviewOutputs = array_sum(array_map(fn (array $target): int => (int) ($target['reviews']['total'] ?? 0), $targets));
        $awoCompleted = array_sum(array_map(fn (array $target): int => (int) ($target['awo']['completed_reviews'] ?? 0), $targets));
        $awoWorthy = array_sum(array_map(fn (array $target): int => (int) ($target['awo']['approval_worthy_reviews'] ?? 0), $targets));

        return [
            'targets_total' => count($targets),
            'configured_targets' => count(array_filter($targets, fn (array $target): bool => $target['triage_state'] !== 'missing_scheduler_row')),
            'enabled_targets' => count(array_filter($targets, fn (array $target): bool => (bool) ($target['enabled'] ?? false))),
            'disabled_targets' => count(array_filter($targets, fn (array $target): bool => $target['triage_state'] === 'deferred_disabled')),
            'missing_targets' => count(array_filter($targets, fn (array $target): bool => $target['triage_state'] === 'missing_scheduler_row')),
            'blocked_targets' => count(array_filter($targets, fn (array $target): bool => $target['status'] === 'blocked')),
            'degraded_targets' => count(array_filter($targets, fn (array $target): bool => $target['status'] === 'degraded')),
            'watch_targets' => count(array_filter($targets, fn (array $target): bool => $target['status'] === 'watch')),
            'completed_sessions_window' => $completedSessions,
            'review_outputs_window' => $reviewOutputs,
            'awo_completed_reviews_window' => $awoCompleted,
            'awo_approval_worthy_reviews_window' => $awoWorthy,
            'awo_approval_worthy_rate' => $awoCompleted > 0 ? round($awoWorthy / $awoCompleted, 4) : null,
            'targets_needing_review' => array_values(array_map(
                fn (array $target): string => (string) $target['job_name'],
                array_filter($targets, fn (array $target): bool => $target['status'] !== 'healthy')
            )),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     */
    private function overallStatus(array $targets): string
    {
        if ($targets === []) {
            return 'blocked';
        }

        if (count(array_filter($targets, fn (array $target): bool => $target['status'] === 'blocked')) > 0) {
            return 'blocked';
        }

        if (count(array_filter($targets, fn (array $target): bool => $target['status'] === 'degraded')) > 0) {
            return 'degraded';
        }

        if (count(array_filter($targets, fn (array $target): bool => $target['status'] === 'watch')) > 0) {
            return 'watch';
        }

        return 'healthy';
    }

    /**
     * @param  array<int, array<string, mixed>>  $targets
     * @return array<int, string>
     */
    private function recommendations(array $targets): array
    {
        $recommendations = [];
        foreach ($targets as $target) {
            if (($target['status'] ?? 'healthy') === 'healthy') {
                continue;
            }

            $recommendations[] = sprintf(
                '%s: %s',
                (string) $target['job_name'],
                (string) ($target['next_action'] ?? 'Review before re-enabling.')
            );
        }

        return $recommendations;
    }

    /**
     * @return array<int, string>
     */
    private function agentIds(): array
    {
        return array_values(array_filter(
            array_map(fn (array $target): ?string => $target['agent_id'], self::TARGETS),
            fn (?string $value): bool => is_string($value) && $value !== ''
        ));
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function placeholders(array $items): string
    {
        return implode(',', array_fill(0, count($items), '?'));
    }

    private function emptySessions(): array
    {
        return ['total' => 0, 'completed' => 0, 'active' => 0, 'total_tokens' => 0, 'latest_at' => null];
    }

    private function emptyEpisodes(): array
    {
        return ['total' => 0, 'errors' => 0, 'latest_at' => null];
    }

    private function emptyReviews(): array
    {
        return ['total' => 0, 'completed' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'latest_at' => null];
    }

    private function emptyAwo(): array
    {
        return [
            'completed_reviews' => 0,
            'approval_worthy_reviews' => 0,
            'approval_worthy_rate' => null,
            'hard_fail_count' => 0,
            'rework_count' => 0,
            'insufficient_data' => true,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
