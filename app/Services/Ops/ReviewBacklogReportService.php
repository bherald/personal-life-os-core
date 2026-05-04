<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReviewBacklogReportService
{
    /**
     * @var list<string>
     */
    private const REMEDIATION_FINDING_TYPES = [
        'data_quality_review',
        'genealogy_data_quality',
        'genealogy_source_cleanup',
        'source_duplicate_cleanup',
    ];

    /**
     * @var list<string>
     */
    private const SUPPORTED_REMEDIATION_OPERATIONS = [
        'family_duplicate_mark',
        'family_child_unlink',
        'source_duplicate_mark',
    ];

    /**
     * @var array<string, string>
     */
    private const CHANGE_TYPE_TYPO_SUGGESTIONS = [
        'date_quality_review' => 'data_quality_review',
    ];

    public function collect(int $staleDays = 7, int $highPriorityThreshold = 8, bool $dryRun = false): array
    {
        $staleDays = max(1, $staleDays);
        $highPriorityThreshold = max(1, $highPriorityThreshold);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'dry_run' => $dryRun,
            'stale_days' => $staleDays,
            'high_priority_threshold' => $highPriorityThreshold,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'sources' => ['agent_review_queue'],
            'summary' => [],
            'pending_by_age' => [],
            'pending_by_type' => [],
            'pending_by_agent' => [],
            'triage_buckets' => [],
            'next_classification_needed' => [],
            'cleanup_sequence' => [],
            'remediation_readiness' => [],
            'status_counts' => [],
            'recommendations' => [],
        ];

        if ($dryRun) {
            $payload['status'] = 'observe_ok';
            $payload['summary'] = [
                'pending_total' => 0,
                'stale_pending' => 0,
                'high_priority_pending' => 0,
                'oldest_pending_at' => null,
                'newest_pending_at' => null,
            ];
            $payload['recommendations'] = ['Dry run only; no review backlog queries executed.'];

            return $payload;
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'blocked';
            $payload['summary'] = [
                'pending_total' => 0,
                'stale_pending' => 0,
                'high_priority_pending' => 0,
                'oldest_pending_at' => null,
                'newest_pending_at' => null,
            ];
            $payload['recommendations'] = ['agent_review_queue table is missing; run migrations before reviewing backlog.'];

            return $payload;
        }

        $payload['summary'] = $this->summary($staleDays, $highPriorityThreshold);
        $payload['pending_by_age'] = $this->pendingByAge($highPriorityThreshold);
        $payload['pending_by_type'] = $this->pendingByType($highPriorityThreshold);
        $payload['pending_by_agent'] = $this->pendingByAgent($highPriorityThreshold);
        $payload['triage_buckets'] = $this->triageBuckets($payload['pending_by_type']);
        $payload['next_classification_needed'] = $this->nextClassificationNeeded($staleDays, $highPriorityThreshold);
        $payload['remediation_readiness'] = $this->remediationReadiness();
        $payload['cleanup_sequence'] = $this->cleanupSequence($payload);
        $payload['status_counts'] = $this->statusCounts();
        $payload['status'] = $this->status($payload['summary']);
        $payload['recommendations'] = $this->recommendations($payload);

        return $payload;
    }

    public function toMarkdown(array $payload): string
    {
        $summary = $payload['summary'] ?? [];
        $lines = [
            '# Review Backlog Report',
            '',
            '- Mode: `'.($payload['mode'] ?? 'observe').'`',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Captured: `'.($payload['captured_at'] ?? 'unknown').'`',
            '- Dry run: `'.(($payload['dry_run'] ?? false) ? 'true' : 'false').'`',
            '- Pending total: `'.($summary['pending_total'] ?? 0).'`',
            '- Stale pending: `'.($summary['stale_pending'] ?? 0).'`',
            '- High-priority pending: `'.($summary['high_priority_pending'] ?? 0).'`',
            '',
            '## Pending By Age',
            '',
        ];

        foreach (($payload['pending_by_age'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s`: `%d` pending, `%d` high priority, oldest `%s`',
                (string) ($row['bucket'] ?? 'unknown'),
                (int) ($row['pending'] ?? 0),
                (int) ($row['high_priority_pending'] ?? 0),
                (string) ($row['oldest_pending_at'] ?? 'none')
            );
        }
        if (($payload['pending_by_age'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Pending By Type';
        $lines[] = '';

        foreach (($payload['pending_by_type'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s` / `%s`: `%d` pending, oldest `%s`',
                (string) ($row['review_type'] ?? 'unknown'),
                (string) ($row['finding_type'] ?? 'none'),
                (int) ($row['pending'] ?? 0),
                (string) ($row['oldest_pending_at'] ?? 'none')
            );
        }
        if (($payload['pending_by_type'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Triage Buckets';
        $lines[] = '';

        foreach (($payload['triage_buckets'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s`: `%d` pending, `%d` high priority, oldest `%s`; %s',
                (string) ($row['category'] ?? 'unknown'),
                (int) ($row['pending'] ?? 0),
                (int) ($row['high_priority_pending'] ?? 0),
                (string) ($row['oldest_pending_at'] ?? 'none'),
                (string) ($row['next_action'] ?? 'Review one at a time.')
            );
        }
        if (($payload['triage_buckets'] ?? []) === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Next Classification Needed';
        $lines[] = '';

        foreach (($payload['next_classification_needed'] ?? []) as $row) {
            $lines[] = sprintf(
                '- `%s`: `%d` stale pending, `%d` high priority, oldest `%s`; %s',
                (string) ($row['classification'] ?? 'unknown'),
                (int) ($row['stale_pending'] ?? 0),
                (int) ($row['high_priority_pending'] ?? 0),
                (string) ($row['oldest_pending_at'] ?? 'none'),
                (string) ($row['next_action'] ?? 'Classify one at a time.')
            );
        }
        if (($payload['next_classification_needed'] ?? []) === []) {
            $lines[] = '- None.';
        }

        if (($payload['cleanup_sequence'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## One-At-A-Time Cleanup Sequence';
            $lines[] = '';

            foreach (($payload['cleanup_sequence'] ?? []) as $row) {
                $lines[] = sprintf(
                    '- `%d` `%s`: `%d` pending, `%d` stale, `%d` high priority; %s',
                    (int) ($row['rank'] ?? 0),
                    (string) ($row['focus'] ?? 'unknown'),
                    (int) ($row['pending'] ?? 0),
                    (int) ($row['stale_pending'] ?? 0),
                    (int) ($row['high_priority_pending'] ?? 0),
                    (string) ($row['next_action'] ?? 'Review one at a time.')
                );
                if (($row['evidence'] ?? null) !== null) {
                    $lines[] = '  - Evidence: '.(string) $row['evidence'];
                }
            }
        }

        if (($payload['remediation_readiness'] ?? []) !== []) {
            $readiness = $payload['remediation_readiness'];
            $lines[] = '';
            $lines[] = '## Remediation Readiness';
            $lines[] = '';
            $lines[] = sprintf(
                '- `%d` pending typed remediation row(s); `%d` apply-preview row(s); `%d` preview-only row(s); `%d` supported operation row(s); `%d` context-ready row(s) still need preview materialization; `%d` row(s) still need materialized IDs.',
                (int) ($readiness['pending_typed_remediation_rows'] ?? 0),
                (int) ($readiness['apply_preview_rows'] ?? 0),
                (int) ($readiness['preview_only_rows'] ?? 0),
                (int) ($readiness['supported_preview_operation_rows'] ?? 0),
                (int) ($readiness['context_ready_without_preview_rows'] ?? 0),
                (int) ($readiness['without_materialized_ids'] ?? 0),
            );
            $lines[] = sprintf(
                '- Candidate IDs: `%d` family duplicate row(s), `%d` source duplicate row(s); source proposed-change context: `%d`; family context: `%d` (`%d` id-key / `%d` family_ids / `%d` comparison); malformed details: `%d`.',
                (int) ($readiness['family_duplicate_id_candidates'] ?? 0),
                (int) ($readiness['source_duplicate_id_candidates'] ?? 0),
                (int) ($readiness['source_proposed_change_id_rows'] ?? 0),
                (int) ($readiness['family_context_rows'] ?? 0),
                (int) ($readiness['family_id_key_context_rows'] ?? 0),
                (int) ($readiness['family_ids_context_rows'] ?? 0),
                (int) ($readiness['family_comparison_context_rows'] ?? 0),
                (int) ($readiness['malformed_details'] ?? 0),
            );
            foreach (($readiness['possible_change_type_typos'] ?? []) as $changeType => $typo) {
                if (! is_array($typo)) {
                    continue;
                }

                $lines[] = sprintf(
                    '- Possible change-type typo: `%s` -> `%s` on `%d` row(s).',
                    (string) $changeType,
                    (string) ($typo['suggested_change_type'] ?? 'unknown'),
                    (int) ($typo['rows'] ?? 0),
                );
            }
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $lines[] = '';
        foreach (($payload['recommendations'] ?? []) as $recommendation) {
            $lines[] = '- '.$recommendation;
        }
        if (($payload['recommendations'] ?? []) === []) {
            $lines[] = '- No human action recommended from this observe-only sample.';
        }

        return implode("\n", $lines)."\n";
    }

    public function compactPayload(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $readiness = is_array($payload['remediation_readiness'] ?? null) ? $payload['remediation_readiness'] : [];

        return [
            'version' => 1,
            'mode' => $payload['mode'] ?? 'observe',
            'compact' => true,
            'status' => $payload['status'] ?? 'unknown',
            'captured_at' => $payload['captured_at'] ?? null,
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'stale_days' => $payload['stale_days'] ?? null,
            'high_priority_threshold' => $payload['high_priority_threshold'] ?? null,
            'summary' => [
                'pending_total' => (int) ($summary['pending_total'] ?? 0),
                'stale_pending' => (int) ($summary['stale_pending'] ?? 0),
                'high_priority_pending' => (int) ($summary['high_priority_pending'] ?? 0),
                'oldest_pending_at' => $summary['oldest_pending_at'] ?? null,
                'newest_pending_at' => $summary['newest_pending_at'] ?? null,
            ],
            'triage_buckets' => array_map(
                fn (array $row): array => [
                    'category' => $row['category'] ?? 'unknown',
                    'pending' => (int) ($row['pending'] ?? 0),
                    'high_priority_pending' => (int) ($row['high_priority_pending'] ?? 0),
                    'oldest_pending_at' => $row['oldest_pending_at'] ?? null,
                ],
                array_values(array_filter($payload['triage_buckets'] ?? [], 'is_array'))
            ),
            'cleanup_sequence' => array_map(
                fn (array $row): array => [
                    'rank' => (int) ($row['rank'] ?? 0),
                    'focus' => $row['focus'] ?? 'unknown',
                    'pending' => (int) ($row['pending'] ?? 0),
                    'stale_pending' => (int) ($row['stale_pending'] ?? 0),
                    'high_priority_pending' => (int) ($row['high_priority_pending'] ?? 0),
                    'next_action' => $row['next_action'] ?? 'Review one at a time.',
                ],
                array_slice(array_values(array_filter($payload['cleanup_sequence'] ?? [], 'is_array')), 0, 5)
            ),
            'remediation_readiness' => [
                'pending_typed_remediation_rows' => (int) ($readiness['pending_typed_remediation_rows'] ?? 0),
                'apply_preview_rows' => (int) ($readiness['apply_preview_rows'] ?? 0),
                'preview_only_rows' => (int) ($readiness['preview_only_rows'] ?? 0),
                'supported_preview_operation_rows' => (int) ($readiness['supported_preview_operation_rows'] ?? 0),
                'context_ready_without_preview_rows' => (int) ($readiness['context_ready_without_preview_rows'] ?? 0),
                'without_materialized_ids' => (int) ($readiness['without_materialized_ids'] ?? 0),
                'source_duplicate_id_candidates' => (int) ($readiness['source_duplicate_id_candidates'] ?? 0),
                'family_duplicate_id_candidates' => (int) ($readiness['family_duplicate_id_candidates'] ?? 0),
                'source_proposed_change_id_rows' => (int) ($readiness['source_proposed_change_id_rows'] ?? 0),
                'family_context_rows' => (int) ($readiness['family_context_rows'] ?? 0),
                'family_id_key_context_rows' => (int) ($readiness['family_id_key_context_rows'] ?? 0),
                'family_ids_context_rows' => (int) ($readiness['family_ids_context_rows'] ?? 0),
                'family_comparison_context_rows' => (int) ($readiness['family_comparison_context_rows'] ?? 0),
                'malformed_details' => (int) ($readiness['malformed_details'] ?? 0),
                'possible_change_type_typos' => $readiness['possible_change_type_typos'] ?? [],
            ],
            'recommendation_count' => count($payload['recommendations'] ?? []),
            'recommendations' => array_values(array_filter($payload['recommendations'] ?? [], 'is_string')),
        ];
    }

    public function toCompactText(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['remediation_readiness'];
        $lines = [
            sprintf(
                'Review backlog compact: %s captured=%s pending=%s stale=%s high_priority=%s',
                $compact['status'],
                $compact['captured_at'] ?? '-',
                $summary['pending_total'],
                $summary['stale_pending'],
                $summary['high_priority_pending']
            ),
            sprintf(
                'remediation: typed=%s apply_preview=%s preview_only=%s supported_preview=%s context_without_preview=%s without_ids=%s source_context=%s family_context=%s family_signals=%s/%s/%s',
                $readiness['pending_typed_remediation_rows'],
                $readiness['apply_preview_rows'],
                $readiness['preview_only_rows'],
                $readiness['supported_preview_operation_rows'],
                $readiness['context_ready_without_preview_rows'],
                $readiness['without_materialized_ids'],
                $readiness['source_proposed_change_id_rows'],
                $readiness['family_context_rows'],
                $readiness['family_id_key_context_rows'],
                $readiness['family_ids_context_rows'],
                $readiness['family_comparison_context_rows']
            ),
        ];

        foreach ($compact['triage_buckets'] as $row) {
            $lines[] = sprintf(
                'triage=%s pending=%s high_priority=%s oldest=%s',
                $row['category'],
                $row['pending'],
                $row['high_priority_pending'],
                $row['oldest_pending_at'] ?? 'none'
            );
        }

        foreach ($compact['cleanup_sequence'] as $row) {
            $lines[] = sprintf(
                'cleanup_step=%s focus=%s pending=%s stale=%s high_priority=%s',
                $row['rank'],
                $row['focus'],
                $row['pending'],
                $row['stale_pending'],
                $row['high_priority_pending']
            );
        }

        foreach ($readiness['possible_change_type_typos'] as $changeType => $typo) {
            if (! is_array($typo)) {
                continue;
            }

            $lines[] = sprintf(
                'remediation_change_type_typo=%s suggested=%s rows=%s',
                $changeType,
                $typo['suggested_change_type'] ?? 'unknown',
                $typo['rows'] ?? 0
            );
        }

        return implode("\n", $lines)."\n";
    }

    public function toCompactMarkdown(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['remediation_readiness'];
        $lines = [
            '# Review Backlog Compact Report',
            '',
            '- Status: `'.$compact['status'].'`',
            '- Captured: `'.($compact['captured_at'] ?? 'unknown').'`',
            '- Pending total: `'.$summary['pending_total'].'`',
            '- Stale pending: `'.$summary['stale_pending'].'`',
            '- High-priority pending: `'.$summary['high_priority_pending'].'`',
            '- Typed remediation rows: `'.$readiness['pending_typed_remediation_rows'].'`',
            '- Apply-preview rows: `'.$readiness['apply_preview_rows'].'`',
            '- Context-ready rows without preview: `'.$readiness['context_ready_without_preview_rows'].'`',
            '- Family context signals: `'.$readiness['family_id_key_context_rows'].'` id-key / `'.$readiness['family_ids_context_rows'].'` family_ids / `'.$readiness['family_comparison_context_rows'].'` comparison',
            '- Rows without materialized IDs: `'.$readiness['without_materialized_ids'].'`',
            '',
            '## Triage Buckets',
            '',
        ];

        foreach ($compact['triage_buckets'] as $row) {
            $lines[] = sprintf(
                '- `%s`: `%d` pending, `%d` high priority, oldest `%s`',
                (string) $row['category'],
                (int) $row['pending'],
                (int) $row['high_priority_pending'],
                (string) ($row['oldest_pending_at'] ?? 'none')
            );
        }
        if ($compact['triage_buckets'] === []) {
            $lines[] = '- None.';
        }

        $lines[] = '';
        $lines[] = '## Cleanup Sequence';
        $lines[] = '';

        foreach ($compact['cleanup_sequence'] as $row) {
            $lines[] = sprintf(
                '- `%d` `%s`: `%d` pending, `%d` stale, `%d` high priority',
                (int) $row['rank'],
                (string) $row['focus'],
                (int) $row['pending'],
                (int) $row['stale_pending'],
                (int) $row['high_priority_pending']
            );
        }
        if ($compact['cleanup_sequence'] === []) {
            $lines[] = '- None.';
        }

        if ($readiness['possible_change_type_typos'] !== []) {
            $lines[] = '';
            $lines[] = '## Change-Type Typos';
            $lines[] = '';
            foreach ($readiness['possible_change_type_typos'] as $changeType => $typo) {
                if (! is_array($typo)) {
                    continue;
                }

                $lines[] = sprintf(
                    '- `%s` -> `%s` on `%d` row(s)',
                    (string) $changeType,
                    (string) ($typo['suggested_change_type'] ?? 'unknown'),
                    (int) ($typo['rows'] ?? 0)
                );
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function summary(int $staleDays, int $highPriorityThreshold): array
    {
        $staleCutoff = now()->subDays($staleDays)->toDateTimeString();

        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS pending_total,
                SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) AS stale_pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?',
            [$staleCutoff, $highPriorityThreshold, 'pending']
        );

        return [
            'pending_total' => (int) ($row->pending_total ?? 0),
            'stale_pending' => (int) ($row->stale_pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ];
    }

    private function pendingByAge(int $highPriorityThreshold): array
    {
        $now = now();
        $oneDayAgo = $now->copy()->subDay()->toDateTimeString();
        $sevenDaysAgo = $now->copy()->subDays(7)->toDateTimeString();
        $thirtyDaysAgo = $now->copy()->subDays(30)->toDateTimeString();

        $definitions = [
            ['bucket' => '0_24h', 'where' => 'created_at >= ?', 'params' => [$oneDayAgo]],
            ['bucket' => '1_7d', 'where' => 'created_at < ? AND created_at >= ?', 'params' => [$oneDayAgo, $sevenDaysAgo]],
            ['bucket' => '8_30d', 'where' => 'created_at < ? AND created_at >= ?', 'params' => [$sevenDaysAgo, $thirtyDaysAgo]],
            ['bucket' => '31d_plus', 'where' => 'created_at < ?', 'params' => [$thirtyDaysAgo]],
            ['bucket' => 'unknown_created_at', 'where' => 'created_at IS NULL', 'params' => []],
        ];

        $rows = [];
        foreach ($definitions as $definition) {
            $row = DB::selectOne(
                'SELECT
                    COUNT(*) AS pending,
                    SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                    MIN(created_at) AS oldest_pending_at,
                    MAX(created_at) AS newest_pending_at
                 FROM agent_review_queue
                 WHERE status = ? AND '.$definition['where'],
                array_merge([$highPriorityThreshold, 'pending'], $definition['params'])
            );

            $pending = (int) ($row->pending ?? 0);
            if ($pending === 0) {
                continue;
            }

            $rows[] = [
                'bucket' => $definition['bucket'],
                'pending' => $pending,
                'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
                'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
                'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
            ];
        }

        return $rows;
    }

    private function pendingByType(int $highPriorityThreshold): array
    {
        $rows = DB::select(
            'SELECT
                review_type,
                finding_type,
                COUNT(*) AS pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?
             GROUP BY review_type, finding_type
             ORDER BY pending DESC, oldest_pending_at ASC
             LIMIT 50',
            [$highPriorityThreshold, 'pending']
        );

        return array_map(fn (object $row): array => [
            'review_type' => (string) ($row->review_type ?? 'unknown'),
            'finding_type' => $this->nullableString($row->finding_type ?? null),
            'pending' => (int) ($row->pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ], $rows);
    }

    private function pendingByAgent(int $highPriorityThreshold): array
    {
        $rows = DB::select(
            'SELECT
                agent_id,
                COUNT(*) AS pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ?
             GROUP BY agent_id
             ORDER BY pending DESC, oldest_pending_at ASC
             LIMIT 50',
            [$highPriorityThreshold, 'pending']
        );

        return array_map(fn (object $row): array => [
            'agent_id' => $this->nullableString($row->agent_id ?? null) ?? 'unknown',
            'pending' => (int) ($row->pending ?? 0),
            'high_priority_pending' => (int) ($row->high_priority_pending ?? 0),
            'oldest_pending_at' => $this->nullableString($row->oldest_pending_at ?? null),
            'newest_pending_at' => $this->nullableString($row->newest_pending_at ?? null),
        ], $rows);
    }

    private function statusCounts(): array
    {
        $rows = DB::select(
            'SELECT status, COUNT(*) AS rows_count
             FROM agent_review_queue
             GROUP BY status
             ORDER BY rows_count DESC'
        );

        return array_map(fn (object $row): array => [
            'status' => (string) ($row->status ?? 'unknown'),
            'rows' => (int) ($row->rows_count ?? 0),
        ], $rows);
    }

    private function nextClassificationNeeded(int $staleDays, int $highPriorityThreshold): array
    {
        $staleCutoff = now()->subDays($staleDays)->toDateTimeString();
        $rows = DB::select(
            'SELECT
                review_type,
                finding_type,
                COUNT(*) AS stale_pending,
                SUM(CASE WHEN priority >= ? THEN 1 ELSE 0 END) AS high_priority_pending,
                MIN(created_at) AS oldest_pending_at,
                MAX(created_at) AS newest_pending_at
             FROM agent_review_queue
             WHERE status = ? AND created_at < ?
             GROUP BY review_type, finding_type
             ORDER BY stale_pending DESC, oldest_pending_at ASC
             LIMIT 50',
            [$highPriorityThreshold, 'pending', $staleCutoff]
        );

        $buckets = [];
        foreach ($rows as $row) {
            $reviewType = $this->nullableString($row->review_type ?? null) ?? 'unknown';
            $findingType = $this->nullableString($row->finding_type ?? null);
            [$classification, $nextAction] = $this->classificationNeeded($reviewType, $findingType);

            if (! isset($buckets[$classification])) {
                $buckets[$classification] = [
                    'classification' => $classification,
                    'stale_pending' => 0,
                    'high_priority_pending' => 0,
                    'oldest_pending_at' => null,
                    'newest_pending_at' => null,
                    'review_types' => [],
                    'next_action' => $nextAction,
                ];
            }

            $buckets[$classification]['stale_pending'] += (int) ($row->stale_pending ?? 0);
            $buckets[$classification]['high_priority_pending'] += (int) ($row->high_priority_pending ?? 0);
            $buckets[$classification]['oldest_pending_at'] = $this->olderDate(
                $buckets[$classification]['oldest_pending_at'],
                $this->nullableString($row->oldest_pending_at ?? null)
            );
            $buckets[$classification]['newest_pending_at'] = $this->newerDate(
                $buckets[$classification]['newest_pending_at'],
                $this->nullableString($row->newest_pending_at ?? null)
            );
            $buckets[$classification]['review_types'][] = $findingType === null
                ? $reviewType
                : $reviewType.'/'.$findingType;
        }

        foreach ($buckets as &$bucket) {
            $bucket['review_types'] = array_values(array_unique($bucket['review_types']));
            sort($bucket['review_types']);
        }
        unset($bucket);

        $rank = [
            'stale_infrastructure_relevance_check' => 0,
            'typed_preview_needed' => 1,
            'source_backed_packet_needed' => 2,
            'actionable_or_obsolete_triage' => 3,
            'routine_stale_review' => 4,
        ];

        usort($buckets, function (array $left, array $right) use ($rank): int {
            $leftRank = $rank[$left['classification']] ?? 99;
            $rightRank = $rank[$right['classification']] ?? 99;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            if ((int) $left['high_priority_pending'] !== (int) $right['high_priority_pending']) {
                return (int) $right['high_priority_pending'] <=> (int) $left['high_priority_pending'];
            }

            return (int) $right['stale_pending'] <=> (int) $left['stale_pending'];
        });

        return array_values($buckets);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function cleanupSequence(array $payload): array
    {
        $summary = $payload['summary'] ?? [];
        $sequence = [];

        $staleHighPriority = array_sum(array_map(
            fn (array $row): int => (int) ($row['high_priority_pending'] ?? 0),
            array_filter($payload['next_classification_needed'] ?? [], 'is_array')
        ));

        if ((int) ($summary['high_priority_pending'] ?? 0) > 0) {
            $highPriorityBuckets = [];
            foreach (($payload['triage_buckets'] ?? []) as $row) {
                if (! is_array($row) || (int) ($row['high_priority_pending'] ?? 0) <= 0) {
                    continue;
                }

                $highPriorityBuckets[] = (string) ($row['category'] ?? 'unknown');
            }

            $sequence[] = [
                'rank' => count($sequence) + 1,
                'focus' => 'high_priority_pending_review',
                'pending' => (int) ($summary['high_priority_pending'] ?? 0),
                'stale_pending' => $staleHighPriority,
                'high_priority_pending' => (int) ($summary['high_priority_pending'] ?? 0),
                'evidence_groups' => array_values(array_unique($highPriorityBuckets)),
                'evidence' => $highPriorityBuckets === []
                    ? 'High-priority pending rows exist; inspect one aggregate review bucket at a time.'
                    : 'High-priority rows appear in aggregate triage bucket(s): '.implode(', ', array_values(array_unique($highPriorityBuckets))).'.',
                'next_action' => 'Review one high-priority pending row first; classify only, and do not bulk approve or reject.',
            ];
        }

        foreach (($payload['next_classification_needed'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $stalePending = (int) ($row['stale_pending'] ?? 0);
            if ($stalePending <= 0) {
                continue;
            }

            $sequence[] = [
                'rank' => count($sequence) + 1,
                'focus' => (string) ($row['classification'] ?? 'unknown'),
                'pending' => $stalePending,
                'stale_pending' => $stalePending,
                'high_priority_pending' => (int) ($row['high_priority_pending'] ?? 0),
                'oldest_pending_at' => $this->nullableString($row['oldest_pending_at'] ?? null),
                'newest_pending_at' => $this->nullableString($row['newest_pending_at'] ?? null),
                'review_types' => array_values(array_filter(
                    (array) ($row['review_types'] ?? []),
                    fn (mixed $value): bool => $this->nullableString($value) !== null
                )),
                'evidence' => sprintf(
                    'Stale aggregate bucket `%s` has %d row(s), %d high priority, oldest `%s`.',
                    (string) ($row['classification'] ?? 'unknown'),
                    $stalePending,
                    (int) ($row['high_priority_pending'] ?? 0),
                    (string) ($row['oldest_pending_at'] ?? 'none')
                ),
                'next_action' => (string) ($row['next_action'] ?? 'Classify one at a time.'),
            ];
        }

        return $sequence;
    }

    private function remediationReadiness(): array
    {
        $rows = DB::table('agent_review_queue')
            ->select(['finding_type', 'details'])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_finding')
            ->whereIn('finding_type', self::REMEDIATION_FINDING_TYPES)
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $readiness = [
            'sample_limit' => 200,
            'pending_typed_remediation_rows' => $rows->count(),
            'apply_preview_rows' => 0,
            'preview_only_rows' => 0,
            'supported_preview_operation_rows' => 0,
            'context_ready_without_preview_rows' => 0,
            'source_duplicate_id_candidates' => 0,
            'family_duplicate_id_candidates' => 0,
            'source_proposed_change_id_rows' => 0,
            'family_context_rows' => 0,
            'family_id_key_context_rows' => 0,
            'family_ids_context_rows' => 0,
            'family_comparison_context_rows' => 0,
            'without_materialized_ids' => 0,
            'malformed_details' => 0,
            'change_types' => [],
            'possible_change_type_typos' => [],
            'supported_operations' => array_fill_keys(self::SUPPORTED_REMEDIATION_OPERATIONS, 0),
        ];

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                $readiness['malformed_details']++;
                $readiness['without_materialized_ids']++;

                continue;
            }

            foreach ($this->changeTypes($details) as $changeType) {
                $readiness['change_types'][$changeType] = (int) ($readiness['change_types'][$changeType] ?? 0) + 1;
                if (isset(self::CHANGE_TYPE_TYPO_SUGGESTIONS[$changeType])) {
                    $readiness['possible_change_type_typos'][$changeType] ??= [
                        'suggested_change_type' => self::CHANGE_TYPE_TYPO_SUGGESTIONS[$changeType],
                        'rows' => 0,
                    ];
                    $readiness['possible_change_type_typos'][$changeType]['rows']++;
                }
            }

            $hasApplyPreview = is_array($details['apply_preview'] ?? null);
            if ($hasApplyPreview) {
                $readiness['apply_preview_rows']++;
            }

            if ($this->isPreviewOnly($details)) {
                $readiness['preview_only_rows']++;
            }

            $supportedOperations = $this->supportedPreviewOperations($details);
            if ($supportedOperations !== []) {
                $readiness['supported_preview_operation_rows']++;
                foreach ($supportedOperations as $operation) {
                    $readiness['supported_operations'][$operation]++;
                }
            }

            $hasSourceIds = $this->hasSourceDuplicateIds($details);
            $hasFamilyIds = $this->hasFamilyRemediationIds($details);
            $hasSourceContext = $this->hasSourceProposedChangeIds($details);
            $familyContextSignals = $this->familyContextSignals($details);
            $hasFamilyContext = in_array(true, $familyContextSignals, true);

            if ($hasSourceIds) {
                $readiness['source_duplicate_id_candidates']++;
            }
            if ($hasFamilyIds) {
                $readiness['family_duplicate_id_candidates']++;
            }
            if ($hasSourceContext) {
                $readiness['source_proposed_change_id_rows']++;
            }
            if ($hasFamilyContext) {
                $readiness['family_context_rows']++;
            }
            if ($familyContextSignals['family_id_key']) {
                $readiness['family_id_key_context_rows']++;
            }
            if ($familyContextSignals['family_ids']) {
                $readiness['family_ids_context_rows']++;
            }
            if ($familyContextSignals['family_comparison']) {
                $readiness['family_comparison_context_rows']++;
            }
            if (($hasSourceContext || $hasFamilyContext) && ! $hasApplyPreview) {
                $readiness['context_ready_without_preview_rows']++;
            }
            if (! $hasSourceIds && ! $hasFamilyIds) {
                $readiness['without_materialized_ids']++;
            }
        }

        ksort($readiness['change_types']);
        ksort($readiness['possible_change_type_typos']);

        return $readiness;
    }

    /**
     * @param  list<array<string, mixed>>  $pendingByType
     * @return list<array<string, mixed>>
     */
    private function triageBuckets(array $pendingByType): array
    {
        $buckets = [];

        foreach ($pendingByType as $row) {
            $pending = (int) ($row['pending'] ?? 0);
            if ($pending <= 0) {
                continue;
            }

            $reviewType = $this->nullableString($row['review_type'] ?? null) ?? 'unknown';
            $findingType = $this->nullableString($row['finding_type'] ?? null);
            [$category, $nextAction] = $this->triageCategory($reviewType, $findingType);

            if (! isset($buckets[$category])) {
                $buckets[$category] = [
                    'category' => $category,
                    'pending' => 0,
                    'high_priority_pending' => 0,
                    'oldest_pending_at' => null,
                    'newest_pending_at' => null,
                    'review_types' => [],
                    'next_action' => $nextAction,
                ];
            }

            $buckets[$category]['pending'] += $pending;
            $buckets[$category]['high_priority_pending'] += (int) ($row['high_priority_pending'] ?? 0);
            $buckets[$category]['oldest_pending_at'] = $this->olderDate(
                $buckets[$category]['oldest_pending_at'],
                $this->nullableString($row['oldest_pending_at'] ?? null)
            );
            $buckets[$category]['newest_pending_at'] = $this->newerDate(
                $buckets[$category]['newest_pending_at'],
                $this->nullableString($row['newest_pending_at'] ?? null)
            );
            $buckets[$category]['review_types'][] = $findingType === null
                ? $reviewType
                : $reviewType.'/'.$findingType;
        }

        foreach ($buckets as &$bucket) {
            $bucket['review_types'] = array_values(array_unique($bucket['review_types']));
            sort($bucket['review_types']);
        }
        unset($bucket);

        $rank = [
            'operator_infrastructure_review' => 0,
            'typed_remediation_preview_needed' => 1,
            'source_backed_packet_review' => 2,
            'general_finding_triage' => 3,
            'routine_operator_review' => 4,
        ];

        usort($buckets, function (array $left, array $right) use ($rank): int {
            $leftRank = $rank[$left['category']] ?? 99;
            $rightRank = $rank[$right['category']] ?? 99;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            if ((int) $left['high_priority_pending'] !== (int) $right['high_priority_pending']) {
                return (int) $right['high_priority_pending'] <=> (int) $left['high_priority_pending'];
            }

            return (int) $right['pending'] <=> (int) $left['pending'];
        });

        return array_values($buckets);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function triageCategory(string $reviewType, ?string $findingType): array
    {
        $findingType = $findingType ?? '';

        if (in_array($reviewType, ['system_alert', 'ai_model_update'], true)) {
            return [
                'operator_infrastructure_review',
                'Review stale infrastructure/system items one at a time; resolve or dismiss only after confirming current relevance.',
            ];
        }

        if ($reviewType === 'genealogy_finding'
            && in_array($findingType, [
                'data_quality_review',
                'genealogy_data_quality',
                'genealogy_source_cleanup',
                'source_duplicate_cleanup',
            ], true)) {
            return [
                'typed_remediation_preview_needed',
                'Convert into a typed remediation or source-cleanup preview before any canonical data change.',
            ];
        }

        if ($reviewType === 'genealogy_finding'
            || $reviewType === 'source_add'
            || str_contains($findingType, 'genealogy')) {
            return [
                'source_backed_packet_review',
                'Review with source context and materialize source-backed packet content before data changes.',
            ];
        }

        if ($reviewType === 'finding') {
            return [
                'general_finding_triage',
                'Classify as actionable, obsolete, or needs owner review; avoid bulk approval.',
            ];
        }

        return [
            'routine_operator_review',
            'Keep on normal one-at-a-time operator review cadence.',
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function classificationNeeded(string $reviewType, ?string $findingType): array
    {
        $findingType = $findingType ?? '';

        if (in_array($reviewType, ['system_alert', 'ai_model_update'], true)) {
            return [
                'stale_infrastructure_relevance_check',
                'Decide whether the stale infrastructure item is still actionable or obsolete before clearing it.',
            ];
        }

        if ($reviewType === 'genealogy_finding'
            && in_array($findingType, [
                'data_quality_review',
                'genealogy_data_quality',
                'genealogy_source_cleanup',
                'source_duplicate_cleanup',
            ], true)) {
            return [
                'typed_preview_needed',
                'Classify as typed-preview-needed unless a materialized read-only preview already exists.',
            ];
        }

        if ($reviewType === 'genealogy_finding'
            || $reviewType === 'source_add'
            || str_contains($findingType, 'genealogy')) {
            return [
                'source_backed_packet_needed',
                'Classify whether the stale row needs source-backed packet materialization before operator decision.',
            ];
        }

        if ($reviewType === 'finding') {
            return [
                'actionable_or_obsolete_triage',
                'Classify as actionable, obsolete, or owner-review-needed before any final decision.',
            ];
        }

        return [
            'routine_stale_review',
            'Classify on the normal one-at-a-time operator review path.',
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function status(array $summary): string
    {
        if ((int) ($summary['high_priority_pending'] ?? 0) > 0 || (int) ($summary['stale_pending'] ?? 0) > 0) {
            return 'review_required';
        }

        return (int) ($summary['pending_total'] ?? 0) > 0 ? 'observe_warning' : 'observe_ok';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function recommendations(array $payload): array
    {
        $summary = $payload['summary'] ?? [];
        $readiness = $payload['remediation_readiness'] ?? [];
        $recommendations = [];

        if ((int) ($summary['high_priority_pending'] ?? 0) > 0) {
            $recommendations[] = 'Review high-priority pending rows one at a time before clearing Agent Doctor critical status.';
        }

        if ((int) ($summary['stale_pending'] ?? 0) > 0) {
            $recommendations[] = 'Classify stale pending rows as actionable, obsolete, or typed-preview-needed; do not bulk approve or reject.';
        }

        foreach (($payload['pending_by_type'] ?? []) as $row) {
            if (($row['review_type'] ?? null) === 'genealogy_finding') {
                $recommendations[] = 'For genealogy_finding rows, prefer source-backed packets and typed remediation previews before any canonical data changes.';
                break;
            }
        }

        if ((int) ($readiness['without_materialized_ids'] ?? 0) > 0) {
            $recommendations[] = 'Materialize source/family IDs for typed remediation candidates before asking the operator to approve repair work.';
        }

        if ((int) ($readiness['supported_preview_operation_rows'] ?? 0) > 0) {
            $recommendations[] = 'Review supported preview-only remediation packets in the UI; apply paths remain disabled until a separate approval-gated implementation exists.';
        }

        if ($recommendations === [] && (int) ($summary['pending_total'] ?? 0) > 0) {
            $recommendations[] = 'Pending rows exist but are not stale or high priority; keep routine operator review cadence.';
        }

        return $recommendations;
    }

    /**
     * @return list<string>
     */
    private function changeTypes(array $details): array
    {
        $types = [];

        $this->walkArrays($details, function (array $payload) use (&$types): void {
            $type = $this->nullableString($payload['change_type'] ?? null);
            if ($type !== null) {
                $types[] = $type;
            }
        });

        return array_values(array_unique($types));
    }

    private function isPreviewOnly(array $details): bool
    {
        $preview = $details['apply_preview'] ?? null;

        return is_array($preview)
            && ($preview['mutates_accepted_facts'] ?? null) === false
            && in_array($this->nullableString($preview['status'] ?? $preview['mode'] ?? null), ['preview_only', null], true);
    }

    /**
     * @return list<string>
     */
    private function supportedPreviewOperations(array $details): array
    {
        $operations = [];
        $preview = $details['apply_preview'] ?? null;
        $previewOperations = is_array($preview) && is_array($preview['operations'] ?? null)
            ? $this->listItems($preview['operations'])
            : [];

        foreach ($previewOperations as $operation) {
            foreach (['operation_type', 'operation', 'type', 'change_type'] as $key) {
                $operationType = $this->normalizeOperationType($operation[$key] ?? null);
                if ($operationType !== null) {
                    $operations[] = $operationType;
                    break;
                }
            }
        }

        return array_values(array_unique($operations));
    }

    private function hasSourceProposedChangeIds(array $details): bool
    {
        $found = false;

        $this->walkArrays($details, function (array $payload) use (&$found): void {
            if ($found) {
                return;
            }

            $ids = $payload['proposed_change_ids'] ?? null;
            if (is_array($ids) && $ids !== []) {
                $found = true;
            }
        });

        return $found;
    }

    /**
     * @return array{family_id_key: bool, family_ids: bool, family_comparison: bool}
     */
    private function familyContextSignals(array $details): array
    {
        $signals = [
            'family_id_key' => false,
            'family_ids' => false,
            'family_comparison' => false,
        ];

        $this->walkArrays($details, function (array $payload) use (&$signals): void {
            if (! in_array(false, $signals, true)) {
                return;
            }

            if ($this->firstPositiveInt($payload, [
                'family_id',
                'suspect_family_id',
                'duplicate_family_id',
                'retained_family_id',
                'canonical_family_id',
                'primary_family_id',
            ]) !== null) {
                $signals['family_id_key'] = true;
            }

            if (is_array($payload['family_ids'] ?? null) && $payload['family_ids'] !== []) {
                $signals['family_ids'] = true;
            }

            if (is_array($payload['family_comparison'] ?? null) && $payload['family_comparison'] !== []) {
                $signals['family_comparison'] = true;
            }
        });

        return $signals;
    }

    private function hasSourceDuplicateIds(array $details): bool
    {
        $found = false;

        $this->walkArrays($details, function (array $payload) use (&$found): void {
            if ($found) {
                return;
            }

            $suspect = $this->firstPositiveInt($payload, ['suspect_source_id', 'duplicate_source_id', 'source_id']);
            $retained = $this->firstPositiveInt($payload, ['retained_source_id', 'canonical_source_id', 'target_source_id']);
            $operation = $this->normalizeOperationType($payload['operation_type'] ?? $payload['operation'] ?? $payload['type'] ?? $payload['change_type'] ?? null);

            if ($suspect !== null && $retained !== null && ($operation === null || $operation === 'source_duplicate_mark')) {
                $found = true;
            }
        });

        return $found;
    }

    private function hasFamilyRemediationIds(array $details): bool
    {
        $found = false;

        $this->walkArrays($details, function (array $payload) use (&$found): void {
            if ($found) {
                return;
            }

            $suspect = $this->firstPositiveInt($payload, ['suspect_family_id', 'duplicate_family_id', 'family_id']);
            $retained = $this->firstPositiveInt($payload, ['retained_family_id', 'canonical_family_id', 'primary_family_id']);
            $operation = $this->normalizeOperationType($payload['operation_type'] ?? $payload['operation'] ?? $payload['type'] ?? $payload['change_type'] ?? null);

            if ($suspect !== null && $retained !== null && ($operation === null || in_array($operation, ['family_duplicate_mark', 'family_child_unlink'], true))) {
                $found = true;
            }
        });

        return $found;
    }

    private function normalizeOperationType(mixed $value): ?string
    {
        $type = $this->nullableString($value);
        if ($type === null) {
            return null;
        }

        return match ($type) {
            'family_duplicate_mark', 'family_duplicate_mark_preview' => 'family_duplicate_mark',
            'family_child_unlink', 'family_child_unlink_preview' => 'family_child_unlink',
            'source_duplicate_mark', 'source_duplicate_mark_preview', 'source_duplicate_cleanup' => 'source_duplicate_mark',
            default => null,
        };
    }

    private function firstPositiveInt(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listItems(array $value): array
    {
        if ($value === [] || array_keys($value) === range(0, count($value) - 1)) {
            return array_values(array_filter($value, 'is_array'));
        }

        return [$value];
    }

    private function walkArrays(mixed $value, callable $visitor): void
    {
        if (! is_array($value)) {
            return;
        }

        $visitor($value);

        foreach ($value as $child) {
            $this->walkArrays($child, $visitor);
        }
    }

    private function olderDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return strcmp($candidate, $current) < 0 ? $candidate : $current;
    }

    private function newerDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null) {
            return $current;
        }

        if ($current === null) {
            return $candidate;
        }

        return strcmp($candidate, $current) > 0 ? $candidate : $current;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
