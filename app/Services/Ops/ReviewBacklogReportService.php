<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReviewBacklogReportService
{
    /**
     * @var list<string>
     */
    public const NEXT_TARGET_FOCI = [
        'typed-remediation',
        'materializable-remediation',
    ];

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
            'queries_executed' => false,
            'query_state' => $dryRun ? 'dry_run_no_queries' : 'not_started',
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
            $payload['query_state'] = 'blocked_missing_table';
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

        $payload['queries_executed'] = true;
        $payload['query_state'] = 'executed';
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

    public function nextTarget(int $staleDays = 7, int $highPriorityThreshold = 8, bool $dryRun = false, ?string $focus = null): array
    {
        $staleDays = max(1, $staleDays);
        $highPriorityThreshold = max(1, $highPriorityThreshold);
        $focus = $this->normalizeNextTargetFocus($focus);

        $payload = [
            'version' => 1,
            'mode' => 'observe',
            'status' => 'observe_ok',
            'dry_run' => $dryRun,
            'queries_executed' => false,
            'query_state' => $dryRun ? 'dry_run_no_queries' : 'not_started',
            'stale_days' => $staleDays,
            'high_priority_threshold' => $highPriorityThreshold,
            'focus' => $focus ?? 'global',
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'next_target' => null,
        ];

        if ($dryRun) {
            return $payload;
        }

        if (! Schema::hasTable('agent_review_queue')) {
            $payload['status'] = 'blocked';
            $payload['query_state'] = 'blocked_missing_table';

            return $payload;
        }

        $payload['queries_executed'] = true;

        $rows = DB::table('agent_review_queue')
            ->select(['id', 'token', 'review_type', 'finding_type', 'priority', 'created_at', 'details'])
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = $this->nextTargetCandidate($row, $staleDays, $highPriorityThreshold);
        }
        $candidates = array_values(array_filter($candidates));
        $candidates = $this->filterNextTargetCandidates($candidates, $focus);

        if ($candidates === []) {
            $payload['query_state'] = $focus === null
                ? 'no_pending_review_rows'
                : 'no_focus_candidates';

            return $payload;
        }

        usort($candidates, fn (array $left, array $right): int => $this->compareNextTargetCandidates($left, $right));
        $target = $candidates[0];

        unset($target['_sort_rank'], $target['_sort_created_ts'], $target['_sort_priority']);

        $payload['status'] = 'review_required';
        $payload['query_state'] = 'next_target_selected';
        $payload['next_target'] = $target;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toNextTargetText(array $payload): string
    {
        $target = is_array($payload['next_target'] ?? null) ? $payload['next_target'] : null;
        if ($target === null) {
            return sprintf(
                'Review backlog next target: %s query_state=%s focus=%s target=none captured=%s',
                $payload['status'] ?? 'unknown',
                $payload['query_state'] ?? 'unknown',
                $payload['focus'] ?? 'global',
                $payload['captured_at'] ?? '-',
            )."\n";
        }

        $underlying = '';
        if (($target['underlying_classification'] ?? null) !== null
            && ($target['underlying_classification'] ?? null) !== ($target['classification'] ?? null)) {
            $underlying = sprintf(
                ' underlying_classification=%s underlying_action=%s',
                $target['underlying_classification'],
                $target['underlying_next_action'] ?? 'Review one at a time.',
            );
        }

        $materialization = $this->nextTargetMaterializationText($target);

        return sprintf(
            'Review backlog next target: %s focus=%s unified_id=%s type=%s finding=%s classification=%s priority=%s created=%s action=%s',
            $payload['status'] ?? 'unknown',
            $payload['focus'] ?? 'global',
            $target['unified_id'] ?? 'unknown',
            $target['review_type'] ?? 'unknown',
            $target['finding_type'] ?? 'none',
            $target['classification'] ?? 'unknown',
            $target['priority'] ?? 0,
            $target['created_at'] ?? 'unknown',
            $target['next_action'] ?? 'Review one at a time.',
        ).$underlying.$materialization."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toNextTargetMarkdown(array $payload): string
    {
        $target = is_array($payload['next_target'] ?? null) ? $payload['next_target'] : null;
        if ($target === null) {
            return implode("\n", [
                '# Review Backlog Next Target',
                '',
                '- Status: `'.($payload['status'] ?? 'unknown').'`',
                '- Query state: `'.($payload['query_state'] ?? 'unknown').'`',
                '- Focus: `'.($payload['focus'] ?? 'global').'`',
                '- Target: `none`',
                '',
            ]);
        }

        $lines = [
            '# Review Backlog Next Target',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Focus: `'.($payload['focus'] ?? 'global').'`',
            '- Unified ID: `'.($target['unified_id'] ?? 'unknown').'`',
            '- Review type: `'.($target['review_type'] ?? 'unknown').'`',
            '- Finding type: `'.($target['finding_type'] ?? 'none').'`',
            '- Classification: `'.($target['classification'] ?? 'unknown').'`',
            '- Priority: `'.($target['priority'] ?? 0).'`',
            '- Created: `'.($target['created_at'] ?? 'unknown').'`',
            '- Next action: '.($target['next_action'] ?? 'Review one at a time.'),
        ];

        if (($target['underlying_classification'] ?? null) !== null
            && ($target['underlying_classification'] ?? null) !== ($target['classification'] ?? null)) {
            $lines[] = '- Underlying classification: `'.$target['underlying_classification'].'`';
            $lines[] = '- Underlying next action: '.($target['underlying_next_action'] ?? 'Review one at a time.');
        }

        $this->appendNextTargetMaterializationMarkdown($lines, $target);

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function nextTargetMaterializationText(array $target): string
    {
        $materialization = is_array($target['remediation_materialization'] ?? null)
            ? $target['remediation_materialization']
            : null;

        if ($materialization === null) {
            return '';
        }

        if (($materialization['available'] ?? false) !== true) {
            $missing = $this->missingMaterializationInputsText($materialization);

            return sprintf(
                ' materialization=%s reason=%s%s',
                (string) ($materialization['status'] ?? 'unavailable'),
                (string) ($materialization['reason'] ?? 'not_materializable'),
                $missing !== '' ? ' missing_inputs='.$missing : ''
            );
        }

        $safety = is_array($materialization['safety'] ?? null) ? $materialization['safety'] : [];
        $command = $this->materializationDryRunCommand($materialization);

        return sprintf(
            ' materialization_command="%s" no_canonical_write=%s apply_enabled=%s apply_held=%s',
            $command ?? 'unavailable',
            ($safety['no_canonical_write'] ?? false) ? 'true' : 'false',
            ($safety['apply_enabled'] ?? true) ? 'true' : 'false',
            ($safety['apply_held'] ?? false) ? 'true' : 'false',
        );
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $target
     */
    private function appendNextTargetMaterializationMarkdown(array &$lines, array $target): void
    {
        $materialization = is_array($target['remediation_materialization'] ?? null)
            ? $target['remediation_materialization']
            : null;

        if ($materialization === null) {
            return;
        }

        $lines[] = '- Materialization available: `'.(($materialization['available'] ?? false) ? 'true' : 'false').'`';

        if (($materialization['available'] ?? false) === true) {
            $lines[] = '- Materialization dry run: `'.($this->materializationDryRunCommand($materialization) ?? 'unavailable').'`';
        } else {
            $lines[] = '- Materialization reason: `'.($materialization['reason'] ?? 'not_materializable').'`';
            $missing = $this->missingMaterializationInputsText($materialization);
            if ($missing !== '') {
                $lines[] = '- Missing materialization inputs: `'.$missing.'`';
            }
        }

        $safety = is_array($materialization['safety'] ?? null) ? $materialization['safety'] : [];
        $lines[] = sprintf(
            '- Materialization safety: `no_canonical_write=%s`, `canonical_write_allowed=%s`, `apply_enabled=%s`, `apply_held=%s`',
            ($safety['no_canonical_write'] ?? false) ? 'true' : 'false',
            ($safety['canonical_write_allowed'] ?? true) ? 'true' : 'false',
            ($safety['apply_enabled'] ?? true) ? 'true' : 'false',
            ($safety['apply_held'] ?? false) ? 'true' : 'false',
        );
    }

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function materializationDryRunCommand(array $materialization): ?string
    {
        $selector = is_array($materialization['selector'] ?? null) ? $materialization['selector'] : [];
        $option = $this->nullableString($selector['option'] ?? null);
        $value = $selector['value'] ?? null;
        $command = $this->nullableString($materialization['command'] ?? null);

        if ($option === null || ! is_scalar($value) || $command === null) {
            return null;
        }

        $options = $this->materializationDryRunOptions($materialization);

        return sprintf(
            'php artisan %s %s %s %s',
            $command,
            $option,
            escapeshellarg((string) $value),
            implode(' ', $options)
        );
    }

    /**
     * @param  array<string, mixed>  $materialization
     * @return list<string>
     */
    private function materializationDryRunOptions(array $materialization): array
    {
        $options = is_array($materialization['dry_run_options'] ?? null)
            ? $materialization['dry_run_options']
            : ['--json', '--compact'];

        $options = array_values(array_filter($options, function (mixed $option): bool {
            return is_string($option) && preg_match('/^--[a-z0-9-]+(?:=[a-z0-9_.:-]+)?$/', $option) === 1;
        }));

        return $options !== [] ? $options : ['--json', '--compact'];
    }

    /**
     * @param  array<string, mixed>  $materialization
     */
    private function missingMaterializationInputsText(array $materialization): string
    {
        $missing = is_array($materialization['missing_materialization_inputs'] ?? null)
            ? $materialization['missing_materialization_inputs']
            : [];
        $missing = array_values(array_filter($missing, 'is_string'));

        return implode(',', $missing);
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
            '- Queries executed: `'.(($payload['queries_executed'] ?? false) ? 'true' : 'false').'`',
            '- Query state: `'.($payload['query_state'] ?? 'unknown').'`',
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
            'queries_executed' => (bool) ($payload['queries_executed'] ?? false),
            'query_state' => $payload['query_state'] ?? 'unknown',
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
                'Review backlog compact: %s captured=%s pending=%s stale=%s high_priority=%s dry_run=%s queries_executed=%s query_state=%s',
                $compact['status'],
                $compact['captured_at'] ?? '-',
                $summary['pending_total'],
                $summary['stale_pending'],
                $summary['high_priority_pending'],
                $compact['dry_run'] ? 'true' : 'false',
                $compact['queries_executed'] ? 'true' : 'false',
                $compact['query_state']
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
            '- Dry run: `'.($compact['dry_run'] ? 'true' : 'false').'`',
            '- Queries executed: `'.($compact['queries_executed'] ? 'true' : 'false').'`',
            '- Query state: `'.$compact['query_state'].'`',
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
     * @return array<string, mixed>
     */
    private function nextTargetCandidate(object $row, int $staleDays, int $highPriorityThreshold): array
    {
        $reviewType = $this->nullableString($row->review_type ?? null) ?? 'unknown';
        $findingType = $this->nullableString($row->finding_type ?? null);
        $priority = (int) ($row->priority ?? 0);
        $createdAt = $this->nullableString($row->created_at ?? null);
        $isHighPriority = $priority >= $highPriorityThreshold;
        $isStale = $this->isStaleCreatedAt($createdAt, $staleDays);
        [$classification, $nextAction] = $this->classificationNeeded($reviewType, $findingType);
        $underlyingClassification = $classification;
        $underlyingNextAction = $nextAction;

        if ($isHighPriority) {
            $classification = 'high_priority_pending_review';
            $nextAction = 'Review one high-priority pending row first; classify only, and do not bulk approve or reject.';
        }

        $details = json_decode((string) ($row->details ?? ''), true);
        $malformedDetails = ! is_array($details);
        $details = is_array($details) ? $details : [];
        $hasApplyPreview = is_array($details['apply_preview'] ?? null);
        $familySignals = $this->familyContextSignals($details);
        $hasFamilyContext = in_array(true, $familySignals, true);
        $hasSourceContext = $this->hasSourceProposedChangeIds($details);
        $hasSourceIds = $this->hasSourceDuplicateIds($details);
        $hasFamilyIds = $this->hasFamilyRemediationIds($details);
        $isTypedRemediation = in_array($findingType, self::REMEDIATION_FINDING_TYPES, true);
        $supportedPreviewOperations = $this->supportedPreviewOperations($details);
        $materializableOperationTypes = $isTypedRemediation
            ? $this->materializableOperationTypes($details)
            : [];
        $hasChangeTypeTypo = false;
        foreach ($this->changeTypes($details) as $changeType) {
            if (isset(self::CHANGE_TYPE_TYPO_SUGGESTIONS[$changeType])) {
                $hasChangeTypeTypo = true;
                break;
            }
        }

        $candidate = [
            'unified_id' => $this->unifiedReviewId($row, $reviewType),
            'review_type' => $reviewType,
            'finding_type' => $findingType,
            'classification' => $classification,
            'underlying_classification' => $underlyingClassification,
            'created_at' => $createdAt,
            'priority' => $priority,
            'next_action' => $nextAction,
            'underlying_next_action' => $underlyingNextAction,
            'evidence_flags' => [
                'stale' => $isStale,
                'high_priority' => $isHighPriority,
                'typed_remediation' => $isTypedRemediation,
                'source_backed_context' => $reviewType === 'genealogy_finding'
                    || $reviewType === 'source_add'
                    || ($findingType !== null && str_contains($findingType, 'genealogy')),
                'has_apply_preview' => $hasApplyPreview,
                'preview_only' => $this->isPreviewOnly($details),
                'supported_preview_operation' => $supportedPreviewOperations !== [],
                'materializable_remediation' => $materializableOperationTypes !== [],
                'context_ready_without_preview' => ($hasSourceContext || $hasFamilyContext) && ! $hasApplyPreview,
                'without_materialized_ids' => $isTypedRemediation && ! $hasSourceIds && ! $hasFamilyIds,
                'malformed_details' => $malformedDetails,
                'possible_change_type_typo' => $hasChangeTypeTypo,
            ],
            '_sort_rank' => $this->nextTargetRank($classification, $isHighPriority, $isStale),
            '_sort_created_ts' => $createdAt !== null ? strtotime($createdAt) ?: PHP_INT_MAX : PHP_INT_MAX,
            '_sort_priority' => $priority,
        ];

        if ($isTypedRemediation && $reviewType === 'genealogy_finding') {
            $candidate['remediation_materialization'] = $this->remediationMaterializationHint(
                $row,
                $materializableOperationTypes,
                $this->materializationReadinessPayload(
                    malformedDetails: $malformedDetails,
                    hasApplyPreview: $hasApplyPreview,
                    previewOnly: $this->isPreviewOnly($details),
                    supportedPreviewOperation: $supportedPreviewOperations !== [],
                    materializableRemediation: $materializableOperationTypes !== [],
                    hasSourceDuplicateIds: $hasSourceIds,
                    hasFamilyRemediationIds: $hasFamilyIds,
                    hasSourceProposedChangeIds: $hasSourceContext,
                    hasFamilyContext: $hasFamilyContext,
                    familyContextSignals: $familySignals,
                    contextReadyWithoutPreview: ($hasSourceContext || $hasFamilyContext) && ! $hasApplyPreview,
                    possibleChangeTypeTypo: $hasChangeTypeTypo,
                )
            );
        }

        return $candidate;
    }

    /**
     * @param  list<string>  $operationTypes
     * @return array<string, mixed>
     */
    private function remediationMaterializationHint(object $row, array $operationTypes, array $readiness): array
    {
        $available = $operationTypes !== [];
        $hint = [
            'available' => $available,
            'status' => $available ? 'dry_run_ready' : 'unavailable',
            'reason' => $available ? null : 'no_supported_remediation_operation',
            'missing_materialization_inputs' => $this->missingMaterializationInputs($readiness),
            'readiness_flags' => $readiness,
            'source' => 'agent_review_queue',
            'source_review_type' => 'genealogy_finding',
            'target_review_type' => 'genealogy_review_packet',
            'command' => 'genealogy:materialize-typed-remediation',
            'default_mode' => 'dry_run',
            'dry_run_first' => true,
            'execute_effect' => 'create_or_reuse_pending_genealogy_review_packet_only',
            'operation_types' => $operationTypes,
            'safety' => $this->materializationSafetyPayload(),
        ];

        if ($available) {
            $hint['selector'] = $this->reviewQueueSelector($row);
            $hint['dry_run_options'] = ['--json', '--compact'];
            $hint['execute_options'] = ['--execute', '--json'];
        }

        return $hint;
    }

    /**
     * @param  array{family_id_key: bool, family_ids: bool, family_comparison: bool}  $familyContextSignals
     * @return array<string, mixed>
     */
    private function materializationReadinessPayload(
        bool $malformedDetails,
        bool $hasApplyPreview,
        bool $previewOnly,
        bool $supportedPreviewOperation,
        bool $materializableRemediation,
        bool $hasSourceDuplicateIds,
        bool $hasFamilyRemediationIds,
        bool $hasSourceProposedChangeIds,
        bool $hasFamilyContext,
        array $familyContextSignals,
        bool $contextReadyWithoutPreview,
        bool $possibleChangeTypeTypo,
    ): array {
        return [
            'malformed_details' => $malformedDetails,
            'has_apply_preview' => $hasApplyPreview,
            'preview_only' => $previewOnly,
            'supported_preview_operation' => $supportedPreviewOperation,
            'materializable_remediation' => $materializableRemediation,
            'has_source_duplicate_ids' => $hasSourceDuplicateIds,
            'has_family_remediation_ids' => $hasFamilyRemediationIds,
            'has_source_proposed_change_ids' => $hasSourceProposedChangeIds,
            'has_family_context' => $hasFamilyContext,
            'family_context_signals' => [
                'family_id_key' => (bool) ($familyContextSignals['family_id_key'] ?? false),
                'family_ids' => (bool) ($familyContextSignals['family_ids'] ?? false),
                'family_comparison' => (bool) ($familyContextSignals['family_comparison'] ?? false),
            ],
            'context_ready_without_preview' => $contextReadyWithoutPreview,
            'possible_change_type_typo' => $possibleChangeTypeTypo,
        ];
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<string>
     */
    private function missingMaterializationInputs(array $readiness): array
    {
        $missing = [];

        if (($readiness['malformed_details'] ?? false) === true) {
            $missing[] = 'parseable_details';
        }

        if (($readiness['materializable_remediation'] ?? false) !== true) {
            $missing[] = 'supported_operation_type';
        }

        return $missing;
    }

    /**
     * @return array{type:string,option:string,value:int|string}
     */
    private function reviewQueueSelector(object $row): array
    {
        $token = $this->nullableString($row->token ?? null);
        if ($token !== null) {
            return [
                'type' => 'token',
                'option' => '--token',
                'value' => $token,
            ];
        }

        return [
            'type' => 'id',
            'option' => '--id',
            'value' => (int) ($row->id ?? 0),
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    private function materializationSafetyPayload(): array
    {
        return [
            'scope' => 'review_packet_materialization_only',
            'preview_only' => true,
            'creates_review_packet_only' => true,
            'no_canonical_write' => true,
            'canonical_write_allowed' => false,
            'canonical_writes_performed' => false,
            'apply_held' => true,
            'apply_enabled' => false,
            'apply_performed' => false,
        ];
    }

    private function compareNextTargetCandidates(array $left, array $right): int
    {
        foreach (['_sort_rank', '_sort_created_ts'] as $key) {
            $comparison = ((int) ($left[$key] ?? 0)) <=> ((int) ($right[$key] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return ((int) ($right['_sort_priority'] ?? 0)) <=> ((int) ($left['_sort_priority'] ?? 0));
    }

    private function normalizeNextTargetFocus(?string $focus): ?string
    {
        $focus = $this->nullableString($focus);

        return $focus === 'global' ? null : $focus;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function filterNextTargetCandidates(array $candidates, ?string $focus): array
    {
        if ($focus === null) {
            return $candidates;
        }

        if ($focus === 'typed-remediation') {
            return array_values(array_filter($candidates, function (array $candidate): bool {
                $flags = is_array($candidate['evidence_flags'] ?? null) ? $candidate['evidence_flags'] : [];

                return ($candidate['review_type'] ?? null) === 'genealogy_finding'
                    && ($flags['typed_remediation'] ?? false) === true;
            }));
        }

        if ($focus === 'materializable-remediation') {
            return array_values(array_filter($candidates, function (array $candidate): bool {
                $flags = is_array($candidate['evidence_flags'] ?? null) ? $candidate['evidence_flags'] : [];

                return ($candidate['review_type'] ?? null) === 'genealogy_finding'
                    && ($flags['typed_remediation'] ?? false) === true
                    && ($flags['materializable_remediation'] ?? false) === true;
            }));
        }

        return [];
    }

    private function nextTargetRank(string $classification, bool $isHighPriority, bool $isStale): int
    {
        if ($isHighPriority) {
            return 0;
        }

        if (! $isStale) {
            return 50;
        }

        return match ($classification) {
            'stale_infrastructure_relevance_check' => 1,
            'typed_preview_needed' => 2,
            'source_backed_packet_needed' => 3,
            'actionable_or_obsolete_triage' => 4,
            'routine_stale_review' => 5,
            default => 10,
        };
    }

    private function unifiedReviewId(object $row, string $reviewType): string
    {
        $token = $this->nullableString($row->token ?? null);
        if ($token !== null) {
            return $reviewType.':'.$token;
        }

        return $reviewType.':'.(int) ($row->id ?? 0);
    }

    private function isStaleCreatedAt(?string $createdAt, int $staleDays): bool
    {
        if ($createdAt === null) {
            return false;
        }

        $createdTs = strtotime($createdAt);
        if ($createdTs === false) {
            return false;
        }

        return $createdTs <= now()->subDays($staleDays)->getTimestamp();
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

    /**
     * @return list<string>
     */
    private function materializableOperationTypes(array $details): array
    {
        $operations = $this->supportedPreviewOperations($details);

        $this->walkArrays($details, function (array $payload) use (&$operations): void {
            foreach (['operation_type', 'operation', 'type', 'change_type'] as $key) {
                $operationType = $this->normalizeOperationType($payload[$key] ?? null);
                if ($operationType !== null) {
                    $operations[] = $operationType;
                    break;
                }
            }
        });

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
