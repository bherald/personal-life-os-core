<?php

namespace App\Services\AgentMetrics;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AwoReplayService
{
    public function __construct(
        private readonly AwoDecisionEnvelopeBuilder $envelopes = new AwoDecisionEnvelopeBuilder,
        private readonly AwoScoringService $scoring = new AwoScoringService,
    ) {}

    public function collect(string $window = '7d', int $limit = 500): array
    {
        $cutoff = $this->cutoffForWindow($window);
        $limit = max(1, min(5000, $limit));
        $recording = $this->recordingPosture();
        $rows = DB::table('agent_review_queue')
            ->select([
                'id',
                'agent_id',
                'review_type',
                'status',
                'details',
                'reviewer_notes',
                'reviewed_at',
                'created_at',
                'updated_at',
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where('created_at', '>=', $cutoff)
                    ->orWhere('reviewed_at', '>=', $cutoff);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $storedEnvelope = $this->storedDecisionEnvelope($row);
            $envelope = $this->decisionEnvelopeForRow($row, $storedEnvelope);
            $score = $this->scoring->score(
                $this->envelopes->qualityGateFromReviewRow($row),
                $envelope
            );

            $items[] = [
                'review_queue_id' => $envelope['review_queue_id'],
                'agent_id' => $envelope['agent_id'],
                'review_type' => $envelope['review_type'],
                'operator_decision' => $envelope['operator_decision'],
                'score' => $score['score'],
                'approval_worthy' => $score['approval_worthy'],
                'hard_fail' => $score['hard_fail'],
                'rework_required' => $envelope['rework_required'],
                'recorded_at' => $envelope['recorded_at'],
                'envelope_source' => $storedEnvelope === null ? 'rebuilt' : 'stored',
            ];
        }

        $summary = $this->summarize($items);

        return [
            'version' => 1,
            'mode' => 'observe',
            'window' => $window,
            'cutoff' => $cutoff->toDateTimeString(),
            'limit' => $limit,
            'status' => $this->status($summary),
            'awo_recording' => $recording,
            'recording_enabled' => $recording['recording_enabled'],
            'recording_source' => $recording['recording_source'],
            'summary' => $summary,
            'by_agent' => $this->byAgent($items),
            'items' => $items,
            'promotion_decisions' => [],
            'note' => 'Replay is read-only and does not promote, disable, approve, reject, or write agent state.',
        ];
    }

    public function collectScheduledComparison(
        string $window = '7d',
        int $limit = 500,
        string $jobName = 'awo_replay_weekly_report',
        ?array $currentReplay = null
    ): array {
        $current = $currentReplay ?? $this->collect($window, $limit);
        $job = DB::table('scheduled_jobs')
            ->select(['id', 'name', 'command', 'enabled', 'next_run_at', 'last_run_at', 'last_completed_at', 'last_run_status'])
            ->where('name', $jobName)
            ->first();

        $run = null;
        if ($job !== null) {
            $run = DB::table('scheduled_job_runs')
                ->select(['id', 'started_at', 'completed_at', 'status', 'duration_seconds', 'output'])
                ->where('scheduled_job_id', (int) $job->id)
                ->where('status', 'success')
                ->whereNotNull('output')
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->first();
        }

        $scheduled = $run === null ? null : $this->parseScheduledMarkdown((string) $run->output);
        $fieldMatches = $scheduled === null ? [] : $this->compareSummaries($current['summary'], $scheduled['summary'] ?? []);

        $status = match (true) {
            $job === null => 'observe_warning',
            $run === null => 'observe_pending',
            $fieldMatches !== [] && collect($fieldMatches)->every(fn (array $row): bool => $row['matches']) => 'observe_ok',
            default => 'observe_warning',
        };

        return [
            'version' => 1,
            'mode' => 'observe',
            'type' => 'scheduled_report_comparison',
            'status' => $status,
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'job' => $job === null ? null : [
                'name' => (string) $job->name,
                'command' => (string) $job->command,
                'enabled' => (bool) $job->enabled,
                'next_run_at' => $this->nullableString($job->next_run_at ?? null),
                'last_run_at' => $this->nullableString($job->last_run_at ?? null),
                'last_completed_at' => $this->nullableString($job->last_completed_at ?? null),
                'last_run_status' => $this->nullableString($job->last_run_status ?? null),
            ],
            'latest_scheduled_run' => $run === null ? null : [
                'id' => (int) $run->id,
                'started_at' => $this->nullableString($run->started_at ?? null),
                'completed_at' => $this->nullableString($run->completed_at ?? null),
                'status' => (string) $run->status,
                'duration_seconds' => $run->duration_seconds === null ? null : (float) $run->duration_seconds,
                'parsed' => $scheduled,
            ],
            'current_replay' => [
                'window' => $current['window'],
                'cutoff' => $current['cutoff'],
                'limit' => $current['limit'],
                'status' => $current['status'],
                'awo_recording' => $this->payloadRecordingPosture($current),
                'summary' => $current['summary'],
            ],
            'field_matches' => $fieldMatches,
            'stop_rules' => [
                'Do not enable awo.recording_enabled from this report.',
                'Do not promote, disable, approve, reject, or mutate agent/review state from this report.',
                'Treat mismatches as evidence to review timing/window differences, not as an automation trigger.',
            ],
            'note' => 'Comparison is read-only and uses retained scheduled_job_runs output plus a current replay only.',
        ];
    }

    public function toMarkdown(array $payload): string
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $lines = [
            '# AWO Replay Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Window: `'.($payload['window'] ?? 'unknown').'`',
            '- Cutoff: `'.($payload['cutoff'] ?? 'unknown').'`',
            '- Limit: `'.($payload['limit'] ?? 'unknown').'`',
            '- AWO recording enabled: `'.($this->payloadRecordingPosture($payload)['recording_enabled'] ? 'yes' : 'no').'`',
            '- AWO recording source: `'.$this->payloadRecordingPosture($payload)['recording_source'].'`',
            '',
            '## Summary',
            '',
            '- Rows scanned: `'.(int) ($summary['rows_scanned'] ?? 0).'`',
            '- Completed reviews: `'.(int) ($summary['completed_reviews'] ?? 0).'`',
            '- Approval-worthy reviews: `'.(int) ($summary['approval_worthy_reviews'] ?? 0).'`',
            '- Approval-worthy rate: `'.$this->percent($summary['approval_worthy_rate'] ?? null).'`',
            '- Review approval yield: `'.$this->percent($summary['review_approval_yield'] ?? null).'`',
            '- Operator rework rate: `'.$this->percent($summary['operator_rework_rate'] ?? null).'`',
            '- Hard fail count: `'.(int) ($summary['hard_fail_count'] ?? 0).'`',
            '- Completed hard fails: `'.(int) ($summary['completed_hard_fail_count'] ?? $summary['hard_fail_count'] ?? 0).'`',
            '- Pending hard-fail signals: `'.(int) ($summary['pending_hard_fail_signal_count'] ?? 0).'`',
            '- Scanned hard-fail signals: `'.(int) ($summary['scanned_hard_fail_signal_count'] ?? $summary['hard_fail_count'] ?? 0).'`',
            '- Insufficient data: `'.(($summary['insufficient_data'] ?? true) ? 'yes' : 'no').'`',
            '',
            '## Guardrail',
            '',
            '- Replay is read-only and does not promote, disable, approve, reject, or write agent state.',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        $summary = $this->compactSummary(is_array($payload['summary'] ?? null) ? $payload['summary'] : []);
        $byAgent = is_array($payload['by_agent'] ?? null) ? $payload['by_agent'] : [];
        $promotionDecisions = is_array($payload['promotion_decisions'] ?? null) ? $payload['promotion_decisions'] : [];

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'compact' => true,
            'mode' => $payload['mode'] ?? 'observe',
            'window' => $payload['window'] ?? 'unknown',
            'cutoff' => $payload['cutoff'] ?? null,
            'limit' => isset($payload['limit']) && is_numeric($payload['limit']) ? (int) $payload['limit'] : null,
            'status' => $payload['status'] ?? 'unknown',
            'awo_recording' => $this->payloadRecordingPosture($payload),
            'summary' => $summary,
            'agent_rollup' => $this->compactAgentRollup($byAgent),
            'promotion_decisions' => $this->compactPromotionDecisions($promotionDecisions),
            'stop_rules' => $this->replayStopRules(),
            'note' => 'Compact replay is read-only and omits row-level review items, review queue ids, agent identifiers, promotion item details, details, reviewer notes, titles, summaries, and tokens.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toCompactText(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = is_array($compact['summary'] ?? null) ? $compact['summary'] : [];
        $agentRollup = is_array($compact['agent_rollup'] ?? null) ? $compact['agent_rollup'] : [];
        $promotion = is_array($compact['promotion_decisions'] ?? null) ? $compact['promotion_decisions'] : [];

        return sprintf(
            'AWO replay compact: %s window=%s rows=%d completed=%d approval_worthy=%d approval_rate=%s hard_fails=%d pending_hard_fail_signals=%d agents=%d promotion_decisions=%d recording_enabled=%s read_only=true',
            $compact['status'] ?? 'unknown',
            $compact['window'] ?? 'unknown',
            (int) ($summary['rows_scanned'] ?? 0),
            (int) ($summary['completed_reviews'] ?? 0),
            (int) ($summary['approval_worthy_reviews'] ?? 0),
            $this->percent($summary['approval_worthy_rate'] ?? null),
            (int) ($summary['completed_hard_fail_count'] ?? $summary['hard_fail_count'] ?? 0),
            (int) ($summary['pending_hard_fail_signal_count'] ?? 0),
            (int) ($agentRollup['agent_count'] ?? 0),
            (int) ($promotion['count'] ?? 0),
            ($compact['awo_recording']['recording_enabled'] ?? false) ? 'true' : 'false',
        )."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toCompactMarkdown(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = is_array($compact['summary'] ?? null) ? $compact['summary'] : [];
        $agentRollup = is_array($compact['agent_rollup'] ?? null) ? $compact['agent_rollup'] : [];
        $promotion = is_array($compact['promotion_decisions'] ?? null) ? $compact['promotion_decisions'] : [];

        $lines = [
            '# AWO Replay Compact Report',
            '',
            '- Mode: `'.($compact['mode'] ?? 'observe').'`',
            '- Status: `'.($compact['status'] ?? 'unknown').'`',
            '- Window: `'.($compact['window'] ?? 'unknown').'`',
            '- Cutoff: `'.($compact['cutoff'] ?? 'unknown').'`',
            '- Limit: `'.($compact['limit'] ?? 'unknown').'`',
            '- AWO recording enabled: `'.(($compact['awo_recording']['recording_enabled'] ?? false) ? 'yes' : 'no').'`',
            '- AWO recording source: `'.($compact['awo_recording']['recording_source'] ?? 'unknown').'`',
            '- Rows scanned: `'.(int) ($summary['rows_scanned'] ?? 0).'`',
            '- Completed reviews: `'.(int) ($summary['completed_reviews'] ?? 0).'`',
            '- Approval-worthy reviews: `'.(int) ($summary['approval_worthy_reviews'] ?? 0).'`',
            '- Approval-worthy rate: `'.$this->percent($summary['approval_worthy_rate'] ?? null).'`',
            '- Completed hard fails: `'.(int) ($summary['completed_hard_fail_count'] ?? $summary['hard_fail_count'] ?? 0).'`',
            '- Pending hard-fail signals: `'.(int) ($summary['pending_hard_fail_signal_count'] ?? 0).'`',
            '- Scanned hard-fail signals: `'.(int) ($summary['scanned_hard_fail_signal_count'] ?? 0).'`',
            '- Agents represented: `'.(int) ($agentRollup['agent_count'] ?? 0).'`',
            '- Promotion decisions: `'.(int) ($promotion['count'] ?? 0).'`',
            '',
            '## Guardrail',
            '',
        ];

        foreach (($compact['stop_rules'] ?? []) as $rule) {
            $lines[] = '- '.$rule;
        }

        return implode("\n", $lines)."\n";
    }

    public function comparisonToMarkdown(array $payload): string
    {
        $current = is_array($payload['current_replay'] ?? null) ? $payload['current_replay'] : [];
        $currentSummary = is_array($current['summary'] ?? null) ? $current['summary'] : [];
        $run = is_array($payload['latest_scheduled_run'] ?? null) ? $payload['latest_scheduled_run'] : null;
        $scheduled = is_array($run['parsed'] ?? null) ? $run['parsed'] : null;
        $scheduledSummary = is_array($scheduled['summary'] ?? null) ? $scheduled['summary'] : [];

        $lines = [
            '# AWO Scheduled Report Comparison',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Generated: `'.($payload['generated_at'] ?? 'unknown').'`',
            '- Job: `'.($payload['job']['name'] ?? 'missing').'`',
            '- Latest scheduled run: `'.($run['completed_at'] ?? 'none').'`',
            '',
            '## Current Replay',
            '',
            '- Window: `'.($current['window'] ?? 'unknown').'`',
            '- Cutoff: `'.($current['cutoff'] ?? 'unknown').'`',
            '- Status: `'.($current['status'] ?? 'unknown').'`',
            '- AWO recording enabled: `'.(($current['awo_recording']['recording_enabled'] ?? false) ? 'yes' : 'no').'`',
            '- Rows scanned: `'.(int) ($currentSummary['rows_scanned'] ?? 0).'`',
            '- Completed reviews: `'.(int) ($currentSummary['completed_reviews'] ?? 0).'`',
            '- Approval-worthy reviews: `'.(int) ($currentSummary['approval_worthy_reviews'] ?? 0).'`',
            '- Hard fail count: `'.(int) ($currentSummary['hard_fail_count'] ?? 0).'`',
            '- Completed hard fails: `'.(int) ($currentSummary['completed_hard_fail_count'] ?? $currentSummary['hard_fail_count'] ?? 0).'`',
            '- Pending hard-fail signals: `'.(int) ($currentSummary['pending_hard_fail_signal_count'] ?? 0).'`',
            '- Scanned hard-fail signals: `'.(int) ($currentSummary['scanned_hard_fail_signal_count'] ?? $currentSummary['hard_fail_count'] ?? 0).'`',
            '',
            '## Scheduled Report',
            '',
        ];

        if ($scheduled === null) {
            $lines[] = '- No successful scheduled report output is available yet.';
        } else {
            $lines[] = '- Window: `'.($scheduled['window'] ?? 'unknown').'`';
            $lines[] = '- Cutoff: `'.($scheduled['cutoff'] ?? 'unknown').'`';
            $lines[] = '- Status: `'.($scheduled['status'] ?? 'unknown').'`';
            $lines[] = '- Rows scanned: `'.(int) ($scheduledSummary['rows_scanned'] ?? 0).'`';
            $lines[] = '- Completed reviews: `'.(int) ($scheduledSummary['completed_reviews'] ?? 0).'`';
            $lines[] = '- Approval-worthy reviews: `'.(int) ($scheduledSummary['approval_worthy_reviews'] ?? 0).'`';
            $lines[] = '- Hard fail count: `'.(int) ($scheduledSummary['hard_fail_count'] ?? 0).'`';
            $lines[] = '- Completed hard fails: `'.(int) ($scheduledSummary['completed_hard_fail_count'] ?? $scheduledSummary['hard_fail_count'] ?? 0).'`';
            $lines[] = '- Pending hard-fail signals: `'.(int) ($scheduledSummary['pending_hard_fail_signal_count'] ?? 0).'`';
            $lines[] = '- Scanned hard-fail signals: `'.(int) ($scheduledSummary['scanned_hard_fail_signal_count'] ?? $scheduledSummary['hard_fail_count'] ?? 0).'`';
        }

        $lines[] = '';
        $lines[] = '## Field Matches';
        $lines[] = '';
        foreach (($payload['field_matches'] ?? []) as $field => $row) {
            if (! is_array($row)) {
                continue;
            }

            $lines[] = '- `'.$field.'`: `'.($row['matches'] ? 'match' : 'mismatch').'` current=`'.($row['current'] ?? 'null').'` scheduled=`'.($row['scheduled'] ?? 'null').'`';
        }
        if (($payload['field_matches'] ?? []) === []) {
            $lines[] = '- No scheduled report comparison is available yet.';
        }

        $lines[] = '';
        $lines[] = '## Stop Rules';
        $lines[] = '';
        foreach (($payload['stop_rules'] ?? []) as $rule) {
            $lines[] = '- '.$rule;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Prefer the decision-time envelope recorded by UnifiedReviewService when
     * it is present and belongs to this row. Legacy rows fall back to a live
     * envelope rebuilt from the review row.
     *
     * @return array<string, mixed>
     */
    private function decisionEnvelopeForRow(object $row, ?array $stored = null): array
    {
        $fallback = $this->envelopes->fromReviewRow($row);

        if ($stored === null) {
            return $fallback;
        }

        return array_replace($fallback, $stored);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedDecisionEnvelope(object $row): ?array
    {
        $details = json_decode($row->details ?? '{}', true);
        if (! is_array($details)) {
            return null;
        }

        $stored = $details['awo_decision'] ?? null;
        if (! is_array($stored)) {
            return null;
        }

        if ((int) ($stored['version'] ?? 0) !== 1) {
            return null;
        }

        if (($stored['source'] ?? null) !== 'agent_review_queue') {
            return null;
        }

        if (! isset($stored['review_queue_id']) || (int) $stored['review_queue_id'] !== (int) $row->id) {
            return null;
        }

        if (! in_array($stored['operator_decision'] ?? null, ['approved', 'approved_with_notes', 'rejected', 'pending', 'unknown'], true)) {
            return null;
        }

        return $stored;
    }

    private function cutoffForWindow(string $window): Carbon
    {
        if (! preg_match('/^(\d+)([mhd])$/', trim($window), $matches)) {
            throw new \InvalidArgumentException('Invalid window. Use Nm, Nh, or Nd.');
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'm' => now()->subMinutes($amount),
            'h' => now()->subHours($amount),
            'd' => now()->subDays($amount),
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function summarize(array $items): array
    {
        $completed = array_values(array_filter($items, fn (array $item): bool => in_array(
            $item['operator_decision'],
            ['approved', 'approved_with_notes', 'rejected'],
            true
        )));
        $completedCount = count($completed);
        $approvalWorthyCount = count(array_filter($completed, fn (array $item): bool => $item['approval_worthy'] === true));
        $approvedCount = count(array_filter($completed, fn (array $item): bool => in_array($item['operator_decision'], ['approved', 'approved_with_notes'], true)));
        $hardFailCount = count(array_filter($completed, fn (array $item): bool => $item['hard_fail'] === true));
        $scannedHardFailSignalCount = count(array_filter($items, fn (array $item): bool => $item['hard_fail'] === true));
        $pendingHardFailSignalCount = count(array_filter($items, fn (array $item): bool => $item['hard_fail'] === true
            && ! in_array($item['operator_decision'], ['approved', 'approved_with_notes', 'rejected'], true)));
        $reworkCount = count(array_filter($completed, fn (array $item): bool => $item['rework_required'] === true));

        return [
            'rows_scanned' => count($items),
            'completed_reviews' => $completedCount,
            'approval_worthy_reviews' => $approvalWorthyCount,
            'approval_worthy_rate' => $completedCount >= 10 ? round($approvalWorthyCount / $completedCount, 4) : null,
            'review_approval_yield' => $completedCount > 0 ? round($approvedCount / $completedCount, 4) : null,
            'operator_rework_rate' => $completedCount > 0 ? round($reworkCount / $completedCount, 4) : null,
            'hard_fail_count' => $hardFailCount,
            'completed_hard_fail_count' => $hardFailCount,
            'pending_hard_fail_signal_count' => $pendingHardFailSignalCount,
            'scanned_hard_fail_signal_count' => $scannedHardFailSignalCount,
            'insufficient_data' => $completedCount < 10,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function status(array $summary): string
    {
        if (($summary['insufficient_data'] ?? true) === true) {
            return 'insufficient_data';
        }

        return ((int) ($summary['hard_fail_count'] ?? 0)) > 0 ? 'observe_warning' : 'observe_ok';
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function byAgent(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $agentId = (string) ($item['agent_id'] ?? 'unknown');
            $groups[$agentId] ??= [
                'agent_id' => $agentId,
                'completed_reviews' => 0,
                'approval_worthy_reviews' => 0,
                'hard_fail_count' => 0,
                'completed_hard_fail_count' => 0,
                'pending_hard_fail_signal_count' => 0,
                'scanned_hard_fail_signal_count' => 0,
                'rework_count' => 0,
            ];

            if ($item['hard_fail'] === true) {
                $groups[$agentId]['scanned_hard_fail_signal_count']++;
            }

            if (! in_array($item['operator_decision'], ['approved', 'approved_with_notes', 'rejected'], true)) {
                if ($item['hard_fail'] === true) {
                    $groups[$agentId]['pending_hard_fail_signal_count']++;
                }

                continue;
            }

            $groups[$agentId]['completed_reviews']++;
            $groups[$agentId]['approval_worthy_reviews'] += $item['approval_worthy'] ? 1 : 0;
            $groups[$agentId]['hard_fail_count'] += $item['hard_fail'] ? 1 : 0;
            $groups[$agentId]['completed_hard_fail_count'] += $item['hard_fail'] ? 1 : 0;
            $groups[$agentId]['rework_count'] += $item['rework_required'] ? 1 : 0;
        }

        return array_values($groups);
    }

    /**
     * @return array{status:?string,window:?string,cutoff:?string,limit:int|null,summary:array<string, mixed>}
     */
    private function parseScheduledMarkdown(string $markdown): array
    {
        $patterns = [
            'status' => '/^- Status: `([^`]+)`/m',
            'window' => '/^- Window: `([^`]+)`/m',
            'cutoff' => '/^- Cutoff: `([^`]+)`/m',
            'limit' => '/^- Limit: `([^`]+)`/m',
            'rows_scanned' => '/^- Rows scanned: `(\d+)`/m',
            'completed_reviews' => '/^- Completed reviews: `(\d+)`/m',
            'approval_worthy_reviews' => '/^- Approval-worthy reviews: `(\d+)`/m',
            'hard_fail_count' => '/^- Hard fail count: `(\d+)`/m',
            'completed_hard_fail_count' => '/^- Completed hard fails: `(\d+)`/m',
            'pending_hard_fail_signal_count' => '/^- Pending hard-fail signals: `(\d+)`/m',
            'scanned_hard_fail_signal_count' => '/^- Scanned hard-fail signals: `(\d+)`/m',
            'insufficient_data' => '/^- Insufficient data: `(yes|no)`/m',
        ];

        $parsed = [];
        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $markdown, $matches) === 1) {
                $parsed[$key] = $matches[1];
            }
        }

        return [
            'status' => $parsed['status'] ?? null,
            'window' => $parsed['window'] ?? null,
            'cutoff' => $parsed['cutoff'] ?? null,
            'limit' => isset($parsed['limit']) && is_numeric($parsed['limit']) ? (int) $parsed['limit'] : null,
            'summary' => [
                'rows_scanned' => isset($parsed['rows_scanned']) ? (int) $parsed['rows_scanned'] : null,
                'completed_reviews' => isset($parsed['completed_reviews']) ? (int) $parsed['completed_reviews'] : null,
                'approval_worthy_reviews' => isset($parsed['approval_worthy_reviews']) ? (int) $parsed['approval_worthy_reviews'] : null,
                'hard_fail_count' => isset($parsed['hard_fail_count']) ? (int) $parsed['hard_fail_count'] : null,
                'completed_hard_fail_count' => isset($parsed['completed_hard_fail_count'])
                    ? (int) $parsed['completed_hard_fail_count']
                    : (isset($parsed['hard_fail_count']) ? (int) $parsed['hard_fail_count'] : null),
                'pending_hard_fail_signal_count' => isset($parsed['pending_hard_fail_signal_count'])
                    ? (int) $parsed['pending_hard_fail_signal_count']
                    : (isset($parsed['hard_fail_count']) ? 0 : null),
                'scanned_hard_fail_signal_count' => isset($parsed['scanned_hard_fail_signal_count'])
                    ? (int) $parsed['scanned_hard_fail_signal_count']
                    : (isset($parsed['hard_fail_count']) ? (int) $parsed['hard_fail_count'] : null),
                'insufficient_data' => isset($parsed['insufficient_data']) ? $parsed['insufficient_data'] === 'yes' : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $scheduled
     * @return array<string, array{current:mixed,scheduled:mixed,matches:bool}>
     */
    private function compareSummaries(array $current, array $scheduled): array
    {
        $fields = [
            'rows_scanned',
            'completed_reviews',
            'approval_worthy_reviews',
            'hard_fail_count',
            'completed_hard_fail_count',
            'pending_hard_fail_signal_count',
            'scanned_hard_fail_signal_count',
            'insufficient_data',
        ];

        $matches = [];
        foreach ($fields as $field) {
            $matches[$field] = [
                'current' => $current[$field] ?? null,
                'scheduled' => $scheduled[$field] ?? null,
                'matches' => ($current[$field] ?? null) === ($scheduled[$field] ?? null),
            ];
        }

        return $matches;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function percent(mixed $value): string
    {
        if (! is_numeric($value)) {
            return 'unknown';
        }

        return (string) round(((float) $value) * 100).'%';
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function compactSummary(array $summary): array
    {
        return [
            'rows_scanned' => (int) ($summary['rows_scanned'] ?? 0),
            'completed_reviews' => (int) ($summary['completed_reviews'] ?? 0),
            'approval_worthy_reviews' => (int) ($summary['approval_worthy_reviews'] ?? 0),
            'approval_worthy_rate' => $summary['approval_worthy_rate'] ?? null,
            'review_approval_yield' => $summary['review_approval_yield'] ?? null,
            'operator_rework_rate' => $summary['operator_rework_rate'] ?? null,
            'hard_fail_count' => (int) ($summary['hard_fail_count'] ?? 0),
            'completed_hard_fail_count' => (int) ($summary['completed_hard_fail_count'] ?? $summary['hard_fail_count'] ?? 0),
            'pending_hard_fail_signal_count' => (int) ($summary['pending_hard_fail_signal_count'] ?? 0),
            'scanned_hard_fail_signal_count' => (int) ($summary['scanned_hard_fail_signal_count'] ?? $summary['hard_fail_count'] ?? 0),
            'insufficient_data' => (bool) ($summary['insufficient_data'] ?? true),
        ];
    }

    /**
     * @param  array<int, mixed>  $byAgent
     * @return array<string, mixed>
     */
    private function compactAgentRollup(array $byAgent): array
    {
        $agentIds = [];
        $completed = 0;
        $approvalWorthy = 0;
        $hardFails = 0;
        $pendingHardFailSignals = 0;
        $rework = 0;

        foreach ($byAgent as $row) {
            if (! is_array($row)) {
                continue;
            }

            $agentId = trim((string) ($row['agent_id'] ?? ''));
            if ($agentId !== '') {
                $agentIds[] = $agentId;
            }

            $completed += (int) ($row['completed_reviews'] ?? 0);
            $approvalWorthy += (int) ($row['approval_worthy_reviews'] ?? 0);
            $hardFails += (int) ($row['completed_hard_fail_count'] ?? $row['hard_fail_count'] ?? 0);
            $pendingHardFailSignals += (int) ($row['pending_hard_fail_signal_count'] ?? 0);
            $rework += (int) ($row['rework_count'] ?? 0);
        }

        $agentIds = array_values(array_unique($agentIds));
        sort($agentIds);

        return [
            'agent_count' => count($agentIds),
            'completed_reviews' => $completed,
            'approval_worthy_reviews' => $approvalWorthy,
            'completed_hard_fail_count' => $hardFails,
            'pending_hard_fail_signal_count' => $pendingHardFailSignals,
            'rework_count' => $rework,
            'agent_identifiers_included' => false,
        ];
    }

    /**
     * @param  array<int, mixed>  $decisions
     * @return array<string, mixed>
     */
    private function compactPromotionDecisions(array $decisions): array
    {
        $count = 0;
        $actionCounts = [];
        $statusCounts = [];

        foreach ($decisions as $decision) {
            if (! is_array($decision)) {
                continue;
            }

            $count++;
            $this->incrementCodeCount($actionCounts, $decision['action'] ?? null);
            $this->incrementCodeCount($statusCounts, $decision['status'] ?? null);
        }

        ksort($actionCounts);
        ksort($statusCounts);

        return [
            'count' => $count,
            'action_counts' => $actionCounts,
            'status_counts' => $statusCounts,
            'agent_identifiers_included' => false,
            'item_details_included' => false,
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function incrementCodeCount(array &$counts, mixed $value): void
    {
        $code = $this->safeAggregateCode($value);
        $counts[$code] = ($counts[$code] ?? 0) + 1;
    }

    private function safeAggregateCode(mixed $value): string
    {
        $code = strtolower((string) ($this->nullableString($value) ?? 'unknown'));
        $code = preg_replace('/[^a-z0-9_-]+/', '_', $code) ?? 'unknown';
        $code = trim($code, '_');

        return $code !== '' ? $code : 'unknown';
    }

    /**
     * @return array{recording_enabled: bool, recording_source: string}
     */
    private function recordingPosture(): array
    {
        try {
            $row = DB::table('system_configs')
                ->select(['config_value'])
                ->where('section', 'awo')
                ->where('config_key', 'recording_enabled')
                ->first();

            if ($row === null) {
                return [
                    'recording_enabled' => false,
                    'recording_source' => 'system_config_default',
                ];
            }

            return [
                'recording_enabled' => filter_var($row->config_value, FILTER_VALIDATE_BOOLEAN),
                'recording_source' => 'system_config',
            ];
        } catch (\Throwable) {
            return [
                'recording_enabled' => false,
                'recording_source' => 'unavailable',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{recording_enabled: bool, recording_source: string}
     */
    private function payloadRecordingPosture(array $payload): array
    {
        $recording = is_array($payload['awo_recording'] ?? null) ? $payload['awo_recording'] : [];

        return [
            'recording_enabled' => filter_var(
                $recording['recording_enabled'] ?? $payload['recording_enabled'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ),
            'recording_source' => $this->nullableString($recording['recording_source'] ?? $payload['recording_source'] ?? null)
                ?? 'unknown',
        ];
    }

    /**
     * @return list<string>
     */
    private function replayStopRules(): array
    {
        return [
            'Replay is read-only and does not promote, disable, approve, reject, or write agent state.',
            'Do not enable AWO recording or agent promotion from compact output alone.',
            'Use compact output for aggregate evidence only; inspect approved private details separately when needed.',
        ];
    }
}
