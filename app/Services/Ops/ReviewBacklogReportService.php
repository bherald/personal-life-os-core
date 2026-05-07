<?php

namespace App\Services\Ops;

use App\Services\Genealogy\GenealogyReviewPacketFocusService;
use App\Services\Genealogy\GenealogyReviewPacketValidatorService;
use App\Services\Genealogy\GenealogyTypedRemediationMaterializationService;
use App\Services\Review\ReviewTargetReferenceService;
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
        'source-backed-packet',
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
        'genealogy_todo_create',
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
            'packet_readiness' => [],
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
        $payload['packet_readiness'] = $this->packetReadiness();
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

        $query = DB::table('agent_review_queue')
            ->select(['id', 'token', 'review_type', 'finding_type', 'priority', 'status', 'created_at', 'details'])
            ->where('status', 'pending');

        if ($focus === 'source-backed-packet') {
            $query->where('review_type', 'genealogy_review_packet');
        } elseif ($focus === 'typed-remediation' || $focus === 'materializable-remediation') {
            $query
                ->where('review_type', 'genealogy_finding')
                ->whereIn('finding_type', self::REMEDIATION_FINDING_TYPES);
        }

        $rows = $query
            ->orderByRaw('CASE WHEN priority >= ? THEN 0 ELSE 1 END', [$highPriorityThreshold])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = $this->nextTargetCandidate($row, $staleDays, $highPriorityThreshold);
        }
        $candidates = array_values(array_filter($candidates));
        if ($focus !== null) {
            $payload['focus_summary'] = $this->nextTargetFocusSummary($candidates, $focus);
        }
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
        unset($target['_sort_id']);

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
            $focusSummary = $this->nextTargetFocusSummaryText($payload);

            return sprintf(
                'Review backlog next target: %s query_state=%s focus=%s target=none captured=%s',
                $payload['status'] ?? 'unknown',
                $payload['query_state'] ?? 'unknown',
                $payload['focus'] ?? 'global',
                $payload['captured_at'] ?? '-',
            ).$focusSummary."\n";
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

        $reviewPass = $this->nextTargetReviewPassText($target);
        $materialization = $this->nextTargetMaterializationText($target);

        return sprintf(
            'Review backlog next target: %s focus=%s target_ref=%s type=%s finding=%s classification=%s selection_reason=%s priority=%s age_days=%s age_bucket=%s stale_over=%s created=%s action=%s',
            $payload['status'] ?? 'unknown',
            $payload['focus'] ?? 'global',
            $target['target_ref'] ?? 'unknown',
            $target['review_type'] ?? 'unknown',
            $target['finding_type'] ?? 'none',
            $target['classification'] ?? 'unknown',
            $target['selection_reason'] ?? 'unspecified',
            $target['priority'] ?? 0,
            $target['age_days'] ?? 'unknown',
            $target['age_bucket'] ?? 'unknown',
            $target['stale_days_over_threshold'] ?? 'unknown',
            $target['created_at'] ?? 'unknown',
            $target['next_action'] ?? 'Review one at a time.',
        ).$underlying.$reviewPass.$materialization."\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function toNextTargetMarkdown(array $payload): string
    {
        $target = is_array($payload['next_target'] ?? null) ? $payload['next_target'] : null;
        if ($target === null) {
            $lines = [
                '# Review Backlog Next Target',
                '',
                '- Status: `'.($payload['status'] ?? 'unknown').'`',
                '- Query state: `'.($payload['query_state'] ?? 'unknown').'`',
                '- Focus: `'.($payload['focus'] ?? 'global').'`',
                '- Target: `none`',
            ];

            $this->appendNoTargetFocusSummaryMarkdown($lines, $payload);
            $lines[] = '';

            return implode("\n", $lines);
        }

        $lines = [
            '# Review Backlog Next Target',
            '',
            '- Status: `'.($payload['status'] ?? 'unknown').'`',
            '- Focus: `'.($payload['focus'] ?? 'global').'`',
            '- Target ref: `'.($target['target_ref'] ?? 'unknown').'`',
            '- Review type: `'.($target['review_type'] ?? 'unknown').'`',
            '- Finding type: `'.($target['finding_type'] ?? 'none').'`',
            '- Classification: `'.($target['classification'] ?? 'unknown').'`',
            '- Selection reason: `'.($target['selection_reason'] ?? 'unspecified').'`',
            '- Priority: `'.($target['priority'] ?? 0).'`',
            '- Age: `'.($target['age_days'] ?? 'unknown').'` days',
            '- Age bucket: `'.($target['age_bucket'] ?? 'unknown').'`',
            '- Stale days over threshold: `'.($target['stale_days_over_threshold'] ?? 'unknown').'`',
            '- Created: `'.($target['created_at'] ?? 'unknown').'`',
            '- Next action: '.($target['next_action'] ?? 'Review one at a time.'),
        ];

        if (($target['underlying_classification'] ?? null) !== null
            && ($target['underlying_classification'] ?? null) !== ($target['classification'] ?? null)) {
            $lines[] = '- Underlying classification: `'.$target['underlying_classification'].'`';
            $lines[] = '- Underlying next action: '.($target['underlying_next_action'] ?? 'Review one at a time.');
        }

        $this->appendNextTargetReviewPassMarkdown($lines, $target);
        $this->appendNextTargetMaterializationMarkdown($lines, $target);

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function nextTargetReviewPassText(array $target): string
    {
        $reviewPass = is_array($target['review_pass'] ?? null) ? $target['review_pass'] : null;
        if ($reviewPass === null) {
            return '';
        }

        $signals = is_array($reviewPass['signals'] ?? null) ? $reviewPass['signals'] : [];
        $posture = is_array($reviewPass['posture'] ?? null) ? $reviewPass['posture'] : [];

        return sprintf(
            ' review_pass=%s source_backed=%s preview_only=%s canonical_mutation=%s identity_present=%s privacy_present=%s privacy_cleared=%s validation_present=%s validation_valid=%s conflict_evidence_present=%s negative_evidence_present=%s locator_present=%s extracted_claim_present=%s canonical_write_allowed=%s batch_review_allowed=%s automation_allowed=%s details_included=%s raw_identifiers_included=%s tokens_included=%s locators_included=%s',
            (string) ($reviewPass['state'] ?? 'unknown'),
            ($signals['source_backed'] ?? false) ? 'true' : 'false',
            ($signals['preview_only'] ?? false) ? 'true' : 'false',
            ($signals['canonical_mutation'] ?? false) ? 'true' : 'false',
            ($signals['identity_present'] ?? false) ? 'true' : 'false',
            ($signals['privacy_present'] ?? false) ? 'true' : 'false',
            ($signals['privacy_cleared'] ?? false) ? 'true' : 'false',
            ($signals['validation_present'] ?? false) ? 'true' : 'false',
            ($signals['validation_valid'] ?? false) ? 'true' : 'false',
            ($signals['conflict_evidence_present'] ?? false) ? 'true' : 'false',
            ($signals['negative_evidence_present'] ?? false) ? 'true' : 'false',
            ($signals['locator_present'] ?? false) ? 'true' : 'false',
            ($signals['extracted_claim_present'] ?? false) ? 'true' : 'false',
            ($posture['canonical_write_allowed'] ?? false) ? 'true' : 'false',
            ($posture['batch_review_allowed'] ?? false) ? 'true' : 'false',
            ($posture['automation_allowed'] ?? false) ? 'true' : 'false',
            ($posture['details_included'] ?? false) ? 'true' : 'false',
            ($posture['raw_identifiers_included'] ?? false) ? 'true' : 'false',
            ($posture['tokens_included'] ?? false) ? 'true' : 'false',
            ($posture['locators_included'] ?? false) ? 'true' : 'false',
        );
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $target
     */
    private function appendNextTargetReviewPassMarkdown(array &$lines, array $target): void
    {
        $reviewPass = is_array($target['review_pass'] ?? null) ? $target['review_pass'] : null;
        if ($reviewPass === null) {
            return;
        }

        $signals = is_array($reviewPass['signals'] ?? null) ? $reviewPass['signals'] : [];
        $posture = is_array($reviewPass['posture'] ?? null) ? $reviewPass['posture'] : [];

        $lines[] = sprintf(
            '- Review pass: `state=%s`, `source_backed=%s`, `preview_only=%s`, `canonical_mutation=%s`',
            (string) ($reviewPass['state'] ?? 'unknown'),
            ($signals['source_backed'] ?? false) ? 'true' : 'false',
            ($signals['preview_only'] ?? false) ? 'true' : 'false',
            ($signals['canonical_mutation'] ?? false) ? 'true' : 'false',
        );
        $lines[] = sprintf(
            '- Review pass signals: `identity_present=%s`, `privacy_present=%s`, `privacy_cleared=%s`, `validation_present=%s`, `validation_valid=%s`, `conflict_evidence_present=%s`, `negative_evidence_present=%s`, `locator_present=%s`, `extracted_claim_present=%s`',
            ($signals['identity_present'] ?? false) ? 'true' : 'false',
            ($signals['privacy_present'] ?? false) ? 'true' : 'false',
            ($signals['privacy_cleared'] ?? false) ? 'true' : 'false',
            ($signals['validation_present'] ?? false) ? 'true' : 'false',
            ($signals['validation_valid'] ?? false) ? 'true' : 'false',
            ($signals['conflict_evidence_present'] ?? false) ? 'true' : 'false',
            ($signals['negative_evidence_present'] ?? false) ? 'true' : 'false',
            ($signals['locator_present'] ?? false) ? 'true' : 'false',
            ($signals['extracted_claim_present'] ?? false) ? 'true' : 'false',
        );
        $lines[] = sprintf(
            '- Review pass posture: `canonical_write_allowed=%s`, `batch_review_allowed=%s`, `automation_allowed=%s`, `details_included=%s`, `raw_identifiers_included=%s`, `tokens_included=%s`, `locators_included=%s`',
            ($posture['canonical_write_allowed'] ?? false) ? 'true' : 'false',
            ($posture['batch_review_allowed'] ?? false) ? 'true' : 'false',
            ($posture['automation_allowed'] ?? false) ? 'true' : 'false',
            ($posture['details_included'] ?? false) ? 'true' : 'false',
            ($posture['raw_identifiers_included'] ?? false) ? 'true' : 'false',
            ($posture['tokens_included'] ?? false) ? 'true' : 'false',
            ($posture['locators_included'] ?? false) ? 'true' : 'false',
        );
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
            $safety = is_array($materialization['safety'] ?? null) ? $materialization['safety'] : [];

            return sprintf(
                ' materialization=%s available=false reason=%s dry_run_available=%s no_canonical_write=%s apply_enabled=%s apply_held=%s%s',
                (string) ($materialization['status'] ?? 'unavailable'),
                (string) ($materialization['reason'] ?? 'not_materializable'),
                ($materialization['dry_run_available'] ?? false) ? 'true' : 'false',
                ($safety['no_canonical_write'] ?? false) ? 'true' : 'false',
                ($safety['apply_enabled'] ?? true) ? 'true' : 'false',
                ($safety['apply_held'] ?? false) ? 'true' : 'false',
                $missing !== '' ? ' missing_inputs='.$missing : ''
            );
        }

        $safety = is_array($materialization['safety'] ?? null) ? $materialization['safety'] : [];

        return sprintf(
            ' materialization=%s dry_run_available=%s operator_action=%s no_canonical_write=%s apply_enabled=%s apply_held=%s',
            (string) ($materialization['status'] ?? 'unknown'),
            ($materialization['dry_run_available'] ?? false) ? 'true' : 'false',
            (string) ($materialization['operator_action'] ?? 'review_private_target_details'),
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
            $lines[] = '- Materialization status: `'.($materialization['status'] ?? 'unknown').'`';
            $lines[] = '- Materialization dry run available: `'.(($materialization['dry_run_available'] ?? false) ? 'true' : 'false').'`';
            $lines[] = '- Materialization operator action: `'.($materialization['operator_action'] ?? 'review_private_target_details').'`';
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
    private function missingMaterializationInputsText(array $materialization): string
    {
        $missing = is_array($materialization['missing_materialization_inputs'] ?? null)
            ? $materialization['missing_materialization_inputs']
            : [];
        $missing = array_values(array_filter($missing, 'is_string'));

        return implode(',', $missing);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function nextTargetFocusSummary(array $candidates, string $focus): array
    {
        $summary = [
            'schema' => 'review_backlog_focus_summary.v1',
            'scope' => 'aggregate_only',
            'focus' => $focus,
            'candidate_rows' => count($candidates),
            'row_pointers_included' => false,
            'auth_markers_included' => false,
            'raw_ids_included' => false,
            'source_refs_included' => false,
            'commands_included' => false,
            'apply_controls_included' => false,
        ];

        if ($focus === 'source-backed-packet') {
            return array_merge($summary, $this->sourcePacketFocusSummary($candidates));
        }

        if ($focus === 'typed-remediation' || $focus === 'materializable-remediation') {
            return array_merge($summary, $this->typedRemediationFocusSummary($candidates));
        }

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function sourcePacketFocusSummary(array $candidates): array
    {
        $summary = [
            'source_backed_rows' => 0,
            'review_ready_rows' => 0,
            'preview_only_rows' => 0,
            'canonical_mutation_rows' => 0,
            'packet_pass_state_counts' => [],
            'packet_pass_blocker_code_counts' => [],
        ];

        foreach ($candidates as $candidate) {
            $flags = is_array($candidate['evidence_flags'] ?? null) ? $candidate['evidence_flags'] : [];
            $reviewPass = is_array($candidate['review_pass'] ?? null) ? $candidate['review_pass'] : [];

            if (($flags['packet_source_backed'] ?? false) === true) {
                $summary['source_backed_rows']++;
            }
            if (($flags['packet_review_ready'] ?? false) === true) {
                $summary['review_ready_rows']++;
            }
            if (($flags['packet_preview_only'] ?? false) === true) {
                $summary['preview_only_rows']++;
            }
            if (($flags['packet_canonical_mutation'] ?? false) === true) {
                $summary['canonical_mutation_rows']++;
            }

            $state = $this->safeReviewPassCode($reviewPass['state'] ?? null) ?? 'unknown';
            $this->incrementCount($summary['packet_pass_state_counts'], $state);

            foreach ($this->reviewPassBlockerCodes($reviewPass) as $code) {
                $this->incrementCount($summary['packet_pass_blocker_code_counts'], $code);
            }
        }

        ksort($summary['packet_pass_state_counts']);
        ksort($summary['packet_pass_blocker_code_counts']);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function typedRemediationFocusSummary(array $candidates): array
    {
        $summary = [
            'typed_remediation_rows' => 0,
            'materializable_rows' => 0,
            'dry_run_available_rows' => 0,
            'validation_blocked_rows' => 0,
            'unsupported_operation_rows' => 0,
            'without_materialized_ids_rows' => 0,
            'context_ready_without_preview_rows' => 0,
            'malformed_details_rows' => 0,
            'possible_change_type_typo_rows' => 0,
            'materialization_status_counts' => [],
            'materialization_reason_counts' => [],
            'missing_materialization_input_counts' => [],
            'validation_blocker_code_counts' => [],
        ];

        foreach ($candidates as $candidate) {
            $flags = is_array($candidate['evidence_flags'] ?? null) ? $candidate['evidence_flags'] : [];
            if (($candidate['review_type'] ?? null) !== 'genealogy_finding'
                || ($flags['typed_remediation'] ?? false) !== true) {
                continue;
            }

            $summary['typed_remediation_rows']++;
            if (($flags['materializable_remediation'] ?? false) === true) {
                $summary['materializable_rows']++;
            }
            if (($flags['without_materialized_ids'] ?? false) === true) {
                $summary['without_materialized_ids_rows']++;
            }
            if (($flags['context_ready_without_preview'] ?? false) === true) {
                $summary['context_ready_without_preview_rows']++;
            }
            if (($flags['malformed_details'] ?? false) === true) {
                $summary['malformed_details_rows']++;
            }
            if (($flags['possible_change_type_typo'] ?? false) === true) {
                $summary['possible_change_type_typo_rows']++;
            }

            $materialization = is_array($candidate['remediation_materialization'] ?? null)
                ? $candidate['remediation_materialization']
                : [];
            $status = $this->safeReviewPassCode($materialization['status'] ?? null) ?? 'unknown';
            $reason = $this->safeReviewPassCode($materialization['reason'] ?? null) ?? null;
            $this->incrementCount($summary['materialization_status_counts'], $status);
            if ($reason !== null) {
                $this->incrementCount($summary['materialization_reason_counts'], $reason);
            }

            if (($materialization['dry_run_available'] ?? false) === true) {
                $summary['dry_run_available_rows']++;
            }
            if ($status === 'validation_blocked') {
                $summary['validation_blocked_rows']++;
            }
            if ($status === 'unavailable'
                && (($materialization['dry_run_available'] ?? false) !== true
                    || $reason === 'no_supported_remediation_operation')) {
                $summary['unsupported_operation_rows']++;
            }

            $candidateBlockerCodes = [];
            foreach ($this->materializationMissingInputCodes($materialization) as $code) {
                $this->incrementCount($summary['missing_materialization_input_counts'], $code);
                $candidateBlockerCodes[] = $code;
            }

            $readiness = is_array($materialization['readiness_flags'] ?? null)
                ? $materialization['readiness_flags']
                : [];
            foreach ($this->materializationPacketValidationErrorCodes($readiness) as $code) {
                $candidateBlockerCodes[] = $code;
            }
            foreach (array_values(array_unique($candidateBlockerCodes)) as $code) {
                $this->incrementCount($summary['validation_blocker_code_counts'], $code);
            }
        }

        foreach ([
            'materialization_status_counts',
            'materialization_reason_counts',
            'missing_materialization_input_counts',
            'validation_blocker_code_counts',
        ] as $key) {
            ksort($summary[$key]);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function nextTargetFocusSummaryText(array $payload): string
    {
        $summary = is_array($payload['focus_summary'] ?? null) ? $payload['focus_summary'] : null;
        if ($summary === null) {
            return '';
        }

        $text = sprintf(' focus_candidates=%d', (int) ($summary['candidate_rows'] ?? 0));
        if (($summary['typed_remediation_rows'] ?? null) !== null) {
            $text .= sprintf(
                ' typed_rows=%d materializable_rows=%d validation_blocked=%d unsupported_operation=%d without_ids=%d context_without_preview=%d malformed=%d typo_signals=%d missing_inputs=%s validation_blockers=%s',
                (int) ($summary['typed_remediation_rows'] ?? 0),
                (int) ($summary['materializable_rows'] ?? 0),
                (int) ($summary['validation_blocked_rows'] ?? 0),
                (int) ($summary['unsupported_operation_rows'] ?? 0),
                (int) ($summary['without_materialized_ids_rows'] ?? 0),
                (int) ($summary['context_ready_without_preview_rows'] ?? 0),
                (int) ($summary['malformed_details_rows'] ?? 0),
                (int) ($summary['possible_change_type_typo_rows'] ?? 0),
                $this->inlineCountMap($summary['missing_materialization_input_counts'] ?? []),
                $this->inlineCountMap($summary['validation_blocker_code_counts'] ?? []),
            );
        } elseif (($summary['source_backed_rows'] ?? null) !== null) {
            $text .= sprintf(
                ' source_backed=%d review_ready=%d preview_only=%d canonical_mutation=%d packet_pass=%s blockers=%s',
                (int) ($summary['source_backed_rows'] ?? 0),
                (int) ($summary['review_ready_rows'] ?? 0),
                (int) ($summary['preview_only_rows'] ?? 0),
                (int) ($summary['canonical_mutation_rows'] ?? 0),
                $this->inlineCountMap($summary['packet_pass_state_counts'] ?? []),
                $this->inlineCountMap($summary['packet_pass_blocker_code_counts'] ?? []),
            );
        }

        return $text;
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $payload
     */
    private function appendNoTargetFocusSummaryMarkdown(array &$lines, array $payload): void
    {
        $summary = is_array($payload['focus_summary'] ?? null) ? $payload['focus_summary'] : null;
        if ($summary === null) {
            return;
        }

        $lines[] = '';
        $lines[] = '## Focus Summary';
        $lines[] = '';
        $lines[] = '- Scope: `aggregate_only`';
        $lines[] = '- Candidate rows: `'.((int) ($summary['candidate_rows'] ?? 0)).'`';

        if (($summary['typed_remediation_rows'] ?? null) !== null) {
            $lines[] = '- Typed remediation rows: `'.((int) ($summary['typed_remediation_rows'] ?? 0)).'`';
            $lines[] = '- Materializable rows: `'.((int) ($summary['materializable_rows'] ?? 0)).'`';
            $lines[] = '- Validation-blocked rows: `'.((int) ($summary['validation_blocked_rows'] ?? 0)).'`';
            $lines[] = '- Unsupported-operation rows: `'.((int) ($summary['unsupported_operation_rows'] ?? 0)).'`';
            $lines[] = '- Rows without materialized IDs: `'.((int) ($summary['without_materialized_ids_rows'] ?? 0)).'`';
            $lines[] = '- Context-ready without preview rows: `'.((int) ($summary['context_ready_without_preview_rows'] ?? 0)).'`';
            $lines[] = '- Missing materialization inputs: `'.$this->inlineCountMap($summary['missing_materialization_input_counts'] ?? []).'`';
            $lines[] = '- Validation blocker codes: `'.$this->inlineCountMap($summary['validation_blocker_code_counts'] ?? []).'`';
        } elseif (($summary['source_backed_rows'] ?? null) !== null) {
            $lines[] = '- Source-backed rows: `'.((int) ($summary['source_backed_rows'] ?? 0)).'`';
            $lines[] = '- Review-ready rows: `'.((int) ($summary['review_ready_rows'] ?? 0)).'`';
            $lines[] = '- Preview-only rows: `'.((int) ($summary['preview_only_rows'] ?? 0)).'`';
            $lines[] = '- Canonical-mutation rows: `'.((int) ($summary['canonical_mutation_rows'] ?? 0)).'`';
            $lines[] = '- Packet pass states: `'.$this->inlineCountMap($summary['packet_pass_state_counts'] ?? []).'`';
            $lines[] = '- Packet blockers: `'.$this->inlineCountMap($summary['packet_pass_blocker_code_counts'] ?? []).'`';
        }
    }

    private function inlineCountMap(mixed $counts): string
    {
        $safe = $this->safeReviewPassCountMap($counts);
        if ($safe === []) {
            return 'none';
        }

        $parts = [];
        foreach ($safe as $key => $count) {
            $parts[] = $key.':'.$count;
        }

        return implode(',', $parts);
    }

    /**
     * @return list<string>
     */
    private function reviewPassBlockerCodes(array $reviewPass): array
    {
        $codes = [];
        foreach (['reason_code_counts', 'blocker_code_counts'] as $key) {
            foreach ($this->safeReviewPassCountMap($reviewPass[$key] ?? []) as $code => $count) {
                if ($count > 0 && ! in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function materializationMissingInputCodes(array $materialization): array
    {
        $codes = [];
        foreach ((array) ($materialization['missing_materialization_inputs'] ?? []) as $input) {
            $code = $this->safeReviewPassCode($input);
            if ($code !== null && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<string>
     */
    private function materializationPacketValidationErrorCodes(array $readiness): array
    {
        $codes = [];
        foreach ((array) ($readiness['packet_validation_errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->safeReviewPassCode($error['code'] ?? null);
            if ($code !== null && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $codes;
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

        if (($payload['packet_readiness'] ?? []) !== []) {
            $packetReadiness = $payload['packet_readiness'];
            $reviewPass = is_array($packetReadiness['review_pass'] ?? null) ? $packetReadiness['review_pass'] : [];
            $lines[] = '';
            $lines[] = '## Packet Readiness';
            $lines[] = '';
            $lines[] = sprintf(
                '- `%d` pending packet row(s); `%d` ready; `%d` blocked; `%d` source-backed; `%d` boundary-labeled; `%d` preview-only; `%d` canonical-mutation row(s).',
                (int) ($packetReadiness['pending_packet_rows'] ?? 0),
                (int) ($packetReadiness['ready_rows'] ?? 0),
                (int) ($packetReadiness['blocked_rows'] ?? 0),
                (int) ($packetReadiness['source_backed_rows'] ?? 0),
                (int) ($packetReadiness['boundary_labeled_rows'] ?? 0),
                (int) ($packetReadiness['preview_only_rows'] ?? 0),
                (int) ($packetReadiness['canonical_mutation_rows'] ?? 0),
            );
            $lines[] = sprintf(
                '- Blocker inputs: `%d` missing preview, `%d` malformed preview, `%d` non-preview-only preview, `%d` missing validation, `%d` invalid validation, `%d` validation-error row(s), `%d` malformed details.',
                (int) ($packetReadiness['apply_preview_missing_rows'] ?? 0),
                (int) ($packetReadiness['persisted_apply_preview_not_array_rows'] ?? 0),
                (int) ($packetReadiness['preview_not_preview_only_rows'] ?? 0),
                (int) ($packetReadiness['validation_missing_rows'] ?? 0),
                (int) ($packetReadiness['validation_not_valid_rows'] ?? 0),
                (int) ($packetReadiness['validation_errors_rows'] ?? 0),
                (int) ($packetReadiness['malformed_details'] ?? 0),
            );
            if ($reviewPass !== []) {
                $lines[] = sprintf(
                    '- Review pass: `%s`; `%d` ready row(s), `%d` blocked row(s), `%d` canonical-mutation row(s); details included: `%s`.',
                    (string) ($reviewPass['state'] ?? 'unknown'),
                    (int) ($reviewPass['counts']['ready_rows'] ?? 0),
                    (int) ($reviewPass['counts']['blocked_rows'] ?? 0),
                    (int) ($reviewPass['counts']['canonical_mutation_rows'] ?? 0),
                    (($reviewPass['posture']['details_included'] ?? true) === true) ? 'true' : 'false',
                );
            }
            foreach (($packetReadiness['reason_code_counts'] ?? []) as $reasonCode => $count) {
                $lines[] = sprintf('- Packet reason `%s`: `%d` row(s).', (string) $reasonCode, (int) $count);
            }
            foreach (($packetReadiness['blocker_code_counts'] ?? []) as $blockerCode => $count) {
                $lines[] = sprintf('- Packet blocker `%s`: `%d` row(s).', (string) $blockerCode, (int) $count);
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
        $packetReadiness = is_array($payload['packet_readiness'] ?? null) ? $payload['packet_readiness'] : [];

        $packetReadinessCompact = [
            'pending_packet_rows' => (int) ($packetReadiness['pending_packet_rows'] ?? 0),
            'ready_rows' => (int) ($packetReadiness['ready_rows'] ?? 0),
            'blocked_rows' => (int) ($packetReadiness['blocked_rows'] ?? 0),
            'source_backed_rows' => (int) ($packetReadiness['source_backed_rows'] ?? 0),
            'boundary_labeled_rows' => (int) ($packetReadiness['boundary_labeled_rows'] ?? 0),
            'source_locator_rows' => (int) ($packetReadiness['source_locator_rows'] ?? 0),
            'claim_rows' => (int) ($packetReadiness['claim_rows'] ?? 0),
            'preview_only_rows' => (int) ($packetReadiness['preview_only_rows'] ?? 0),
            'canonical_mutation_rows' => (int) ($packetReadiness['canonical_mutation_rows'] ?? 0),
            'apply_preview_missing_rows' => (int) ($packetReadiness['apply_preview_missing_rows'] ?? 0),
            'persisted_apply_preview_not_array_rows' => (int) ($packetReadiness['persisted_apply_preview_not_array_rows'] ?? 0),
            'preview_not_preview_only_rows' => (int) ($packetReadiness['preview_not_preview_only_rows'] ?? 0),
            'validation_missing_rows' => (int) ($packetReadiness['validation_missing_rows'] ?? 0),
            'validation_not_valid_rows' => (int) ($packetReadiness['validation_not_valid_rows'] ?? 0),
            'validation_errors_rows' => (int) ($packetReadiness['validation_errors_rows'] ?? 0),
            'malformed_details' => (int) ($packetReadiness['malformed_details'] ?? 0),
            'reason_code_counts' => $this->integerCountMap($packetReadiness['reason_code_counts'] ?? []),
            'blocker_code_counts' => $this->integerCountMap($packetReadiness['blocker_code_counts'] ?? []),
        ];
        $packetReadinessCompact['review_pass'] = $this->packetReadinessReviewPass($packetReadinessCompact);

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
            'packet_readiness' => $packetReadinessCompact,
            'recommendation_count' => count($payload['recommendations'] ?? []),
            'recommendations' => array_values(array_filter($payload['recommendations'] ?? [], 'is_string')),
        ];
    }

    public function toCompactText(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['remediation_readiness'];
        $packetReadiness = $compact['packet_readiness'];
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
            sprintf(
                'packets: pending=%s ready=%s blocked=%s source_backed=%s boundary=%s locators=%s claims=%s preview_only=%s canonical_mutation=%s missing_preview=%s malformed_preview=%s invalid_preview=%s validation_missing=%s validation_not_valid=%s validation_errors=%s',
                $packetReadiness['pending_packet_rows'],
                $packetReadiness['ready_rows'],
                $packetReadiness['blocked_rows'],
                $packetReadiness['source_backed_rows'],
                $packetReadiness['boundary_labeled_rows'],
                $packetReadiness['source_locator_rows'],
                $packetReadiness['claim_rows'],
                $packetReadiness['preview_only_rows'],
                $packetReadiness['canonical_mutation_rows'],
                $packetReadiness['apply_preview_missing_rows'],
                $packetReadiness['persisted_apply_preview_not_array_rows'],
                $packetReadiness['preview_not_preview_only_rows'],
                $packetReadiness['validation_missing_rows'],
                $packetReadiness['validation_not_valid_rows'],
                $packetReadiness['validation_errors_rows']
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

        foreach ($packetReadiness['reason_code_counts'] as $reasonCode => $count) {
            $lines[] = sprintf('packet_reason=%s rows=%s', $reasonCode, $count);
        }

        foreach ($packetReadiness['blocker_code_counts'] as $blockerCode => $count) {
            $lines[] = sprintf('packet_blocker=%s rows=%s', $blockerCode, $count);
        }

        $reviewPass = is_array($packetReadiness['review_pass'] ?? null) ? $packetReadiness['review_pass'] : [];
        if ($reviewPass !== []) {
            $lines[] = sprintf(
                'packet_review_pass state=%s ready=%s blocked=%s canonical_mutation=%s details_included=%s tokens_included=%s locators_included=%s',
                $reviewPass['state'] ?? 'unknown',
                $reviewPass['counts']['ready_rows'] ?? 0,
                $reviewPass['counts']['blocked_rows'] ?? 0,
                $reviewPass['counts']['canonical_mutation_rows'] ?? 0,
                (($reviewPass['posture']['details_included'] ?? true) === true) ? 'true' : 'false',
                (($reviewPass['posture']['tokens_included'] ?? true) === true) ? 'true' : 'false',
                (($reviewPass['posture']['locators_included'] ?? true) === true) ? 'true' : 'false',
            );
        }

        return implode("\n", $lines)."\n";
    }

    public function toCompactMarkdown(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['remediation_readiness'];
        $packetReadiness = $compact['packet_readiness'];
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
            '- Packet rows: `'.$packetReadiness['pending_packet_rows'].'`',
            '- Packet ready rows: `'.$packetReadiness['ready_rows'].'`',
            '- Packet blocked rows: `'.$packetReadiness['blocked_rows'].'`',
            '- Packet preview-only rows: `'.$packetReadiness['preview_only_rows'].'`',
            '- Packet canonical-mutation rows: `'.$packetReadiness['canonical_mutation_rows'].'`',
            '- Packet review-pass state: `'.($packetReadiness['review_pass']['state'] ?? 'unknown').'`',
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

        if ($packetReadiness['reason_code_counts'] !== [] || $packetReadiness['blocker_code_counts'] !== []) {
            $lines[] = '';
            $lines[] = '## Packet Readiness';
            $lines[] = '';

            foreach ($packetReadiness['reason_code_counts'] as $reasonCode => $count) {
                $lines[] = sprintf('- Reason `%s`: `%d` row(s)', (string) $reasonCode, (int) $count);
            }

            foreach ($packetReadiness['blocker_code_counts'] as $blockerCode => $count) {
                $lines[] = sprintf('- Blocker `%s`: `%d` row(s)', (string) $blockerCode, (int) $count);
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
            $details = $this->withFindingTypeContext($details, $this->nullableString($row->finding_type ?? null));

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
            $hasTodoContext = $this->hasGenealogyTodoContext($details);

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
            if (! $hasSourceIds && ! $hasFamilyIds && ! $hasTodoContext) {
                $readiness['without_materialized_ids']++;
            }
        }

        ksort($readiness['change_types']);
        ksort($readiness['possible_change_type_typos']);

        return $readiness;
    }

    private function packetReadiness(): array
    {
        $rows = DB::table('agent_review_queue')
            ->select(['details'])
            ->where('status', 'pending')
            ->where('review_type', 'genealogy_review_packet')
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        $readiness = [
            'sample_limit' => 500,
            'pending_packet_rows' => $rows->count(),
            'ready_rows' => 0,
            'blocked_rows' => 0,
            'source_backed_rows' => 0,
            'boundary_labeled_rows' => 0,
            'source_locator_rows' => 0,
            'claim_rows' => 0,
            'preview_only_rows' => 0,
            'canonical_mutation_rows' => 0,
            'apply_preview_missing_rows' => 0,
            'persisted_apply_preview_not_array_rows' => 0,
            'preview_not_preview_only_rows' => 0,
            'validation_missing_rows' => 0,
            'validation_not_valid_rows' => 0,
            'validation_errors_rows' => 0,
            'malformed_details' => 0,
            'reason_code_counts' => [],
            'blocker_code_counts' => [],
            'safety' => [
                'scope' => 'review_packet_readiness_aggregate_only',
                'canonical_write_allowed' => false,
                'automation_allowed' => false,
                'batch_review_allowed' => false,
                'details_included' => false,
            ],
        ];

        $focusService = new GenealogyReviewPacketFocusService;

        foreach ($rows as $row) {
            $details = json_decode((string) ($row->details ?? ''), true);
            if (! is_array($details)) {
                $readiness['malformed_details']++;
                $readiness['blocked_rows']++;
                $this->incrementCount($readiness['reason_code_counts'], 'malformed_details');
                $this->incrementCount($readiness['blocker_code_counts'], 'malformed_details');

                continue;
            }

            $focus = $focusService->fromPersistedDetails($details);
            $reviewReadiness = is_array($focus['review_readiness'] ?? null) ? $focus['review_readiness'] : [];
            $state = $this->nullableString($reviewReadiness['state'] ?? null);

            if ($state === 'ready') {
                $readiness['ready_rows']++;
            } else {
                $readiness['blocked_rows']++;
                $reasonCode = $this->nullableString($reviewReadiness['reason_code'] ?? null) ?? 'approval_ready_unknown';
                $this->incrementCount($readiness['reason_code_counts'], $reasonCode);
            }

            if (($focus['source_backed'] ?? null) === true) {
                $readiness['source_backed_rows']++;
            }
            if ($this->nullableString($focus['boundary_label'] ?? null) !== null) {
                $readiness['boundary_labeled_rows']++;
            }
            if ($this->nullableString($focus['source_locator'] ?? null) !== null) {
                $readiness['source_locator_rows']++;
            }
            if ((int) ($focus['claim_count'] ?? 0) > 0) {
                $readiness['claim_rows']++;
            }
            if (($focus['preview_only'] ?? null) === true) {
                $readiness['preview_only_rows']++;
            }
            if (($focus['canonical_mutation'] ?? null) === true) {
                $readiness['canonical_mutation_rows']++;
            }

            if (! array_key_exists('apply_preview', $details)) {
                $readiness['apply_preview_missing_rows']++;
            } elseif (! is_array($details['apply_preview'])) {
                $readiness['persisted_apply_preview_not_array_rows']++;
            }

            if (array_key_exists('apply_preview', $details)
                && is_array($details['apply_preview'])
                && ($focus['preview_only'] ?? null) !== true) {
                $readiness['preview_not_preview_only_rows']++;
            }

            $validation = $details['validation'] ?? null;
            if (! is_array($validation)) {
                $readiness['validation_missing_rows']++;
            } else {
                if (($validation['valid'] ?? null) !== true) {
                    $readiness['validation_not_valid_rows']++;
                }

                $validationErrors = $validation['errors'] ?? [];
                if (is_array($validationErrors) && $validationErrors !== []) {
                    $readiness['validation_errors_rows']++;
                }
            }

            foreach ((array) ($focus['approval_blockers'] ?? []) as $blocker) {
                if (! is_array($blocker)) {
                    continue;
                }

                $code = $this->nullableString($blocker['code'] ?? null);
                if ($code !== null) {
                    $this->incrementCount($readiness['blocker_code_counts'], $code);
                }
            }
        }

        ksort($readiness['reason_code_counts']);
        ksort($readiness['blocker_code_counts']);
        $readiness['review_pass'] = $this->packetReadinessReviewPass($readiness);

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
        $ageDays = $this->ageDays($createdAt);
        $isHighPriority = $priority >= $highPriorityThreshold;
        $isStale = $this->isStaleCreatedAt($createdAt, $staleDays);
        [$classification, $nextAction] = $this->classificationNeeded($reviewType, $findingType);
        $underlyingClassification = $classification;
        $underlyingNextAction = $nextAction;

        $details = json_decode((string) ($row->details ?? ''), true);
        $malformedDetails = ! is_array($details);
        $details = is_array($details) ? $details : [];
        $details = $this->withFindingTypeContext($details, $findingType);
        $packetTarget = $reviewType === 'genealogy_review_packet'
            ? $this->packetTargetSummary($details, $malformedDetails)
            : null;
        if ($packetTarget !== null) {
            $classification = 'source_backed_packet_review';
            $nextAction = 'Open the source-backed packet in Review Hub and decide one packet at a time; do not batch review or mutate canonical genealogy facts.';
            $underlyingClassification = $classification;
            $underlyingNextAction = $nextAction;
        }
        if ($isHighPriority) {
            $classification = 'high_priority_pending_review';
            $nextAction = 'Review one high-priority pending row first; classify only, and do not bulk approve or reject.';
        }
        $hasApplyPreview = is_array($details['apply_preview'] ?? null);
        $familySignals = $this->familyContextSignals($details);
        $hasFamilyContext = in_array(true, $familySignals, true);
        $hasSourceContext = $this->hasSourceProposedChangeIds($details);
        $hasSourceIds = $this->hasSourceDuplicateIds($details);
        $hasFamilyIds = $this->hasFamilyRemediationIds($details);
        $hasTodoContext = $this->hasGenealogyTodoContext($details);
        $isTypedRemediation = in_array($findingType, self::REMEDIATION_FINDING_TYPES, true);
        $supportedPreviewOperations = $this->supportedPreviewOperations($details);
        $materializableOperationTypes = $isTypedRemediation
            ? $this->materializableOperationTypes($details)
            : [];
        $materializationInspection = ($isTypedRemediation && $reviewType === 'genealogy_finding' && $materializableOperationTypes !== [])
            ? (new GenealogyTypedRemediationMaterializationService)->inspectQueueRow($row)
            : null;
        $materializationReady = is_array($materializationInspection)
            && ($materializationInspection['success'] ?? false) === true;
        $packetValidation = is_array($materializationInspection)
            ? ($materializationInspection['validation'] ?? null)
            : null;
        $hasChangeTypeTypo = false;
        foreach ($this->changeTypes($details) as $changeType) {
            if (isset(self::CHANGE_TYPE_TYPO_SUGGESTIONS[$changeType])) {
                $hasChangeTypeTypo = true;
                break;
            }
        }

        $candidate = [
            'target_ref' => $this->targetReference($row, $reviewType, $findingType),
            'review_type' => $reviewType,
            'finding_type' => $findingType,
            'classification' => $classification,
            'underlying_classification' => $underlyingClassification,
            'selection_reason' => $this->selectionReason($classification, $underlyingClassification, $isHighPriority, $isStale),
            'created_at' => $createdAt,
            'age_days' => $ageDays,
            'age_bucket' => $this->ageBucket($ageDays),
            'stale_days_over_threshold' => $ageDays === null ? null : max(0, $ageDays - $staleDays),
            'priority' => $priority,
            'next_action' => $nextAction,
            'underlying_next_action' => $underlyingNextAction,
            'evidence_flags' => [
                'stale' => $isStale,
                'high_priority' => $isHighPriority,
                'age_bucket' => $this->ageBucket($ageDays),
                'stale_days_over_threshold' => $ageDays === null ? null : max(0, $ageDays - $staleDays),
                'typed_remediation' => $isTypedRemediation,
                'source_backed_context' => $reviewType === 'genealogy_finding'
                    || $reviewType === 'source_add'
                    || ($findingType !== null && str_contains($findingType, 'genealogy'))
                    || (($packetTarget['source_backed'] ?? false) === true),
                'genealogy_review_packet' => $reviewType === 'genealogy_review_packet',
                'packet_source_backed' => ($packetTarget['source_backed'] ?? false) === true,
                'packet_review_ready' => ($packetTarget['review_ready'] ?? false) === true,
                'packet_preview_only' => ($packetTarget['preview_only'] ?? false) === true,
                'packet_boundary_labeled' => ($packetTarget['boundary_labeled'] ?? false) === true,
                'packet_canonical_mutation' => ($packetTarget['canonical_mutation'] ?? false) === true,
                'has_apply_preview' => $hasApplyPreview,
                'preview_only' => $this->isPreviewOnly($details),
                'supported_preview_operation' => $supportedPreviewOperations !== [],
                'materializable_remediation' => $materializationReady,
                'context_ready_without_preview' => ($hasSourceContext || $hasFamilyContext) && ! $hasApplyPreview,
                'without_materialized_ids' => $isTypedRemediation && ! $hasSourceIds && ! $hasFamilyIds && ! $hasTodoContext,
                'malformed_details' => $malformedDetails,
                'possible_change_type_typo' => $hasChangeTypeTypo,
            ],
            '_sort_rank' => $this->nextTargetRank($classification, $isHighPriority, $isStale),
            '_sort_created_ts' => $createdAt !== null ? strtotime($createdAt) ?: PHP_INT_MAX : PHP_INT_MAX,
            '_sort_priority' => $priority,
            '_sort_id' => $this->rowId($row),
        ];

        if ($packetTarget !== null) {
            $candidate['packet_review'] = $packetTarget;
            $candidate['review_pass'] = $this->packetTargetReviewPass(
                $this->nullableString($candidate['target_ref'] ?? null),
                $packetTarget
            );
        }

        if ($isTypedRemediation && $reviewType === 'genealogy_finding') {
            $candidate['remediation_materialization'] = $this->remediationMaterializationHint(
                $row,
                $materializableOperationTypes,
                $this->materializationReadinessPayload(
                    malformedDetails: $malformedDetails,
                    hasApplyPreview: $hasApplyPreview,
                    previewOnly: $this->isPreviewOnly($details),
                    supportedPreviewOperation: $supportedPreviewOperations !== [],
                    materializableRemediation: $materializationReady,
                    hasSourceDuplicateIds: $hasSourceIds,
                    hasFamilyRemediationIds: $hasFamilyIds,
                    hasGenealogyTodoContext: $hasTodoContext,
                    hasSourceProposedChangeIds: $hasSourceContext,
                    hasFamilyContext: $hasFamilyContext,
                    familyContextSignals: $familySignals,
                    contextReadyWithoutPreview: ($hasSourceContext || $hasFamilyContext) && ! $hasApplyPreview,
                    possibleChangeTypeTypo: $hasChangeTypeTypo,
                    packetValidation: $packetValidation,
                )
            );
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>|null
     */
    private function packetTargetSummary(array $details, bool $malformedDetails): ?array
    {
        if ($malformedDetails) {
            return [
                'schema' => 'review_backlog_packet_target.v1',
                'source_backed' => false,
                'review_ready' => false,
                'readiness_state' => 'blocked',
                'readiness_reason_code' => 'malformed_details',
                'approval_blocker_count' => 1,
                'preview_only' => false,
                'boundary_labeled' => false,
                'claim_count' => 0,
                'source_count' => 0,
                'media_resolved_count' => 0,
                'media_missing_count' => 0,
                'canonical_mutation' => false,
                'canonical_write_allowed' => false,
                'batch_review_allowed' => false,
                'details_included' => false,
            ];
        }

        $focus = (new GenealogyReviewPacketFocusService)->fromPersistedDetails($details);
        $validator = new GenealogyReviewPacketValidatorService;
        $readiness = is_array($focus['review_readiness'] ?? null) ? $focus['review_readiness'] : [];
        $validation = is_array($details['validation'] ?? null) ? $details['validation'] : null;
        $sourceLocatorCount = count($validator->collectSourceLocators($details));
        $sourceCount = (int) ($focus['source_count'] ?? 0);
        if ($sourceCount <= 0) {
            $sources = $details['source_locators'] ?? $details['sources'] ?? [];
            $sourceCount = is_array($sources) ? count($sources) : 0;
        }

        return [
            'schema' => 'review_backlog_packet_target.v1',
            'source_backed' => ($focus['source_backed'] ?? null) === true,
            'review_ready' => ($readiness['state'] ?? null) === 'ready',
            'readiness_state' => $this->nullableString($readiness['state'] ?? null) ?? 'unknown',
            'readiness_reason_code' => $this->nullableString($readiness['reason_code'] ?? null),
            'approval_blocker_count' => (int) ($readiness['blocker_count'] ?? 0),
            'preview_only' => ($focus['preview_only'] ?? null) === true,
            'boundary_labeled' => $this->nullableString($focus['boundary_label'] ?? null) !== null,
            'claim_count' => (int) ($focus['claim_count'] ?? 0),
            'source_count' => $sourceCount,
            'media_resolved_count' => (int) ($focus['media_resolved_count'] ?? 0),
            'media_missing_count' => (int) ($focus['media_missing_count'] ?? 0),
            'canonical_mutation' => ($focus['canonical_mutation'] ?? null) === true,
            'identity_present' => $this->packetIdentityPresent($details),
            'privacy_present' => is_array($details['privacy'] ?? null) && $details['privacy'] !== [],
            'privacy_cleared' => $this->packetPrivacyCleared($details),
            'validation_present' => $validation !== null,
            'validation_valid' => $this->packetValidationValid($validation),
            'conflict_evidence_present' => $this->packetConflictEvidencePresent($details),
            'negative_evidence_present' => $this->packetNegativeEvidencePresent($details),
            'locator_present' => $sourceLocatorCount > 0,
            'extracted_claim_present' => $this->packetExtractedClaimPresent($validator->collectClaims($details)),
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'details_included' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $packetTarget
     * @return array<string, mixed>
     */
    private function packetTargetReviewPass(?string $targetRef, array $packetTarget): array
    {
        $state = $this->safeReviewPassCode($packetTarget['readiness_state'] ?? null) ?? 'unknown';
        $reasonCode = $this->safeReviewPassCode($packetTarget['readiness_reason_code'] ?? null);
        $blockerCodes = $reasonCode !== null ? [$reasonCode] : [];

        return [
            'schema' => 'genealogy_review_packet_review_pass.v1',
            'mode' => 'display_only',
            'derived' => true,
            'target_ref' => $this->safePacketTargetRef($targetRef),
            'state' => $state,
            'label' => $this->reviewPassLabel($state, $reasonCode),
            'reason_code' => $reasonCode,
            'blocker_count' => (int) ($packetTarget['approval_blocker_count'] ?? count($blockerCodes)),
            'blocker_codes' => $blockerCodes,
            'counts' => [
                'claim_count' => (int) ($packetTarget['claim_count'] ?? 0),
                'source_count' => (int) ($packetTarget['source_count'] ?? 0),
                'resolved_media_count' => (int) ($packetTarget['media_resolved_count'] ?? 0),
                'missing_media_count' => (int) ($packetTarget['media_missing_count'] ?? 0),
            ],
            'signals' => [
                'review_ready' => ($packetTarget['review_ready'] ?? null) === true,
                'source_backed' => ($packetTarget['source_backed'] ?? null) === true,
                'boundary_present' => ($packetTarget['boundary_labeled'] ?? null) === true,
                'identity_present' => ($packetTarget['identity_present'] ?? null) === true,
                'privacy_present' => ($packetTarget['privacy_present'] ?? null) === true,
                'privacy_cleared' => ($packetTarget['privacy_cleared'] ?? null) === true,
                'preview_only' => ($packetTarget['preview_only'] ?? null) === true,
                'canonical_mutation' => ($packetTarget['canonical_mutation'] ?? null) === true,
                'validation_present' => ($packetTarget['validation_present'] ?? null) === true,
                'validation_valid' => ($packetTarget['validation_valid'] ?? null) === true,
                'conflict_evidence_present' => ($packetTarget['conflict_evidence_present'] ?? null) === true,
                'negative_evidence_present' => ($packetTarget['negative_evidence_present'] ?? null) === true,
                'extracted_claim_present' => ($packetTarget['extracted_claim_present'] ?? null) === true,
                'validation_state' => null,
                'outcome_state' => null,
                'locator_present' => ($packetTarget['locator_present'] ?? null) === true,
            ],
            'posture' => $this->reviewPassPosture(),
        ];
    }

    private function packetIdentityPresent(array $details): bool
    {
        if ($this->firstPositiveInt($details, ['person_id', 'target_person_id']) !== null) {
            return true;
        }

        $identity = is_array($details['identity'] ?? null)
            ? $details['identity']
            : (is_array($details['target_identity'] ?? null) ? $details['target_identity'] : []);

        if ($identity === []) {
            return false;
        }

        if ($this->firstPositiveInt($identity, ['person_id', 'target_person_id']) !== null) {
            return true;
        }

        if (($identity['resolved'] ?? null) === true || ($identity['is_resolved'] ?? null) === true) {
            return true;
        }

        $status = strtolower((string) ($this->nullableString($identity['status'] ?? $identity['resolution_status'] ?? null) ?? ''));

        return in_array($status, ['resolved', 'matched', 'confirmed', 'accepted'], true);
    }

    private function packetPrivacyCleared(array $details): bool
    {
        $privacy = is_array($details['privacy'] ?? null) ? $details['privacy'] : [];
        if ($privacy === []) {
            return false;
        }

        $status = strtolower((string) ($this->nullableString($privacy['status'] ?? $privacy['review_status'] ?? null) ?? ''));

        return $this->packetTruthy($privacy['cleared'] ?? null)
            || $this->packetTruthy($privacy['privacy_cleared'] ?? null)
            || in_array($status, ['cleared', 'approved', 'public', 'not_private', 'no_risk'], true);
    }

    private function packetValidationValid(?array $validation): bool
    {
        if ($validation === null || ($validation['valid'] ?? null) !== true) {
            return false;
        }

        $errors = $validation['errors'] ?? [];

        return ! is_array($errors) || $errors === [];
    }

    private function packetConflictEvidencePresent(array $details): bool
    {
        return $this->packetDetailSignalPresent($details, [
            'conflict',
            'conflicts',
            'conflict_found',
            'conflicts_found',
            'conflict_present',
            'has_conflict',
            'conflicting_evidence',
            'conflicting_evidence_present',
            'source_conflicts',
        ], ['conflict', 'conflicts', 'conflicting']);
    }

    private function packetNegativeEvidencePresent(array $details): bool
    {
        return $this->packetDetailSignalPresent($details, [
            'negative_evidence',
            'negative_evidence_present',
            'negative_result',
            'negative_results',
            'negative_search',
            'negative_searches',
            'negative_finding',
            'negative_findings',
            'no_records_found',
        ], ['negative', 'no_records', 'no_results']);
    }

    /**
     * @param  list<array<string, mixed>>  $claims
     */
    private function packetExtractedClaimPresent(array $claims): bool
    {
        foreach ($claims as $claim) {
            if ($this->firstNonEmptyString($claim, [
                'claim',
                'claim_text',
                'statement',
                'extracted_claim',
                'extracted_text',
                'text',
                'value',
                'proposed_value',
                'proposed_name',
            ]) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $stateValues
     */
    private function packetDetailSignalPresent(array $details, array $keys, array $stateValues): bool
    {
        $found = false;

        $this->walkArrays($details, function (array $payload) use (&$found, $keys, $stateValues): void {
            if ($found) {
                return;
            }

            foreach ($keys as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                $value = $payload[$key];
                if (is_bool($value)) {
                    $found = $value;
                } elseif (is_array($value)) {
                    $found = $value !== [];
                } elseif (is_numeric($value)) {
                    $found = (int) $value > 0;
                } else {
                    $found = $this->packetSignalStringPresent($value);
                }

                if ($found) {
                    return;
                }
            }

            foreach (['outcome_state', 'outcome_reason', 'status', 'state'] as $stateKey) {
                $state = strtolower((string) ($this->nullableString($payload[$stateKey] ?? null) ?? ''));
                if ($state !== '' && in_array($state, $stateValues, true)) {
                    $found = true;

                    return;
                }
            }
        });

        return $found;
    }

    private function packetTruthy(mixed $value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'cleared', 'approved'], true);
        }

        return false;
    }

    private function packetSignalStringPresent(mixed $value): bool
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return false;
        }

        return ! in_array(strtolower($string), ['0', 'false', 'no', 'off', 'none', 'null', 'absent'], true);
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    private function packetReadinessReviewPass(array $readiness): array
    {
        $pending = (int) ($readiness['pending_packet_rows'] ?? 0);
        $ready = (int) ($readiness['ready_rows'] ?? 0);
        $blocked = (int) ($readiness['blocked_rows'] ?? 0);
        $canonicalMutation = (int) ($readiness['canonical_mutation_rows'] ?? 0);
        $state = match (true) {
            $pending === 0 => 'empty',
            $blocked === 0 && $ready === $pending => 'ready',
            default => 'blocked',
        };
        $reasonCounts = $this->safeReviewPassCountMap($readiness['reason_code_counts'] ?? []);
        $blockerCounts = $this->safeReviewPassCountMap($readiness['blocker_code_counts'] ?? []);
        $firstReasonCode = array_key_first($reasonCounts);

        return [
            'schema' => 'genealogy_review_packet_review_pass_aggregate.v1',
            'mode' => 'display_only',
            'derived' => true,
            'scope' => 'aggregate',
            'state' => $state,
            'label' => $this->reviewPassLabel($state, is_string($firstReasonCode) ? $firstReasonCode : null),
            'reason_code' => is_string($firstReasonCode) ? $firstReasonCode : null,
            'counts' => [
                'pending_packet_rows' => $pending,
                'ready_rows' => $ready,
                'blocked_rows' => $blocked,
                'source_backed_rows' => (int) ($readiness['source_backed_rows'] ?? 0),
                'boundary_labeled_rows' => (int) ($readiness['boundary_labeled_rows'] ?? 0),
                'locator_present_rows' => (int) ($readiness['source_locator_rows'] ?? 0),
                'claim_rows' => (int) ($readiness['claim_rows'] ?? 0),
                'preview_only_rows' => (int) ($readiness['preview_only_rows'] ?? 0),
                'canonical_mutation_rows' => $canonicalMutation,
                'malformed_details_rows' => (int) ($readiness['malformed_details'] ?? 0),
            ],
            'signals' => [
                'all_pending_ready' => $pending > 0 && $ready === $pending && $blocked === 0,
                'has_blockers' => $blocked > 0,
                'has_canonical_mutation' => $canonicalMutation > 0,
                'has_malformed_details' => (int) ($readiness['malformed_details'] ?? 0) > 0,
            ],
            'reason_code_counts' => $reasonCounts,
            'blocker_code_counts' => $blockerCounts,
            'posture' => $this->reviewPassPosture(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function safeReviewPassCountMap(mixed $counts): array
    {
        $safe = [];
        foreach ($this->integerCountMap($counts) as $code => $count) {
            $code = $this->safeReviewPassCode($code);
            if ($code !== null) {
                $safe[$code] = $count;
            }
        }

        ksort($safe);

        return $safe;
    }

    private function reviewPassLabel(string $state, ?string $reasonCode): string
    {
        if ($state === 'ready') {
            return 'Ready for review';
        }
        if ($state === 'empty') {
            return 'No pending review packets';
        }

        return match ($reasonCode) {
            'apply_preview_missing' => 'Blocked: Apply preview missing',
            'preview_not_preview_only' => 'Blocked: Preview is not preview-only',
            'canonical_mutation_possible' => 'Blocked: Canonical mutation possible',
            'validation_missing' => 'Blocked: Validation missing',
            'validation_not_valid' => 'Blocked: Validation is not valid',
            'validation_errors' => 'Blocked: Validation errors present',
            'malformed_details' => 'Blocked: Malformed packet details',
            default => 'Blocked: Review readiness unknown',
        };
    }

    private function safeReviewPassCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || preg_match('/^[a-z0-9_:-]{1,80}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function safePacketTargetRef(?string $targetRef): ?string
    {
        if ($targetRef === null || preg_match('/^genealogy_review_packet:target-[a-f0-9]{12}$/', $targetRef) !== 1) {
            return null;
        }

        return $targetRef;
    }

    /**
     * @return array<string, bool>
     */
    private function reviewPassPosture(): array
    {
        return [
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'automation_allowed' => false,
            'details_included' => false,
            'raw_identifiers_included' => false,
            'tokens_included' => false,
            'locators_included' => false,
        ];
    }

    /**
     * @param  list<string>  $operationTypes
     * @return array<string, mixed>
     */
    private function remediationMaterializationHint(object $row, array $operationTypes, array $readiness): array
    {
        $hasOperation = $operationTypes !== [];
        $available = $hasOperation && ($readiness['materializable_remediation'] ?? false) === true;
        $status = $available ? 'dry_run_ready' : 'unavailable';
        $reason = $available ? null : 'no_supported_remediation_operation';
        if ($hasOperation && ! $available) {
            $status = 'validation_blocked';
            $reason = 'packet_validation_failed';
        }

        $hint = [
            'available' => $available,
            'status' => $status,
            'reason' => $reason,
            'missing_materialization_inputs' => $this->missingMaterializationInputs($readiness),
            'readiness_flags' => $readiness,
            'source' => 'agent_review_queue',
            'source_review_type' => 'genealogy_finding',
            'target_review_type' => 'genealogy_review_packet',
            'default_mode' => 'dry_run',
            'dry_run_available' => $hasOperation,
            'dry_run_first' => true,
            'operator_action' => 'materialize_typed_remediation_dry_run',
            'selector_required' => $hasOperation,
            'selector_redacted' => true,
            'execute_effect' => 'create_or_reuse_pending_genealogy_review_packet_only',
            'operation_types' => $operationTypes,
            'safety' => $this->materializationSafetyPayload(),
        ];

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
        bool $hasGenealogyTodoContext,
        bool $hasSourceProposedChangeIds,
        bool $hasFamilyContext,
        array $familyContextSignals,
        bool $contextReadyWithoutPreview,
        bool $possibleChangeTypeTypo,
        mixed $packetValidation = null,
    ): array {
        $validationErrors = $this->validationErrors($packetValidation);

        return [
            'malformed_details' => $malformedDetails,
            'has_apply_preview' => $hasApplyPreview,
            'preview_only' => $previewOnly,
            'supported_preview_operation' => $supportedPreviewOperation,
            'materializable_remediation' => $materializableRemediation,
            'has_source_duplicate_ids' => $hasSourceDuplicateIds,
            'has_family_remediation_ids' => $hasFamilyRemediationIds,
            'has_genealogy_todo_context' => $hasGenealogyTodoContext,
            'has_source_proposed_change_ids' => $hasSourceProposedChangeIds,
            'has_family_context' => $hasFamilyContext,
            'family_context_signals' => [
                'family_id_key' => (bool) ($familyContextSignals['family_id_key'] ?? false),
                'family_ids' => (bool) ($familyContextSignals['family_ids'] ?? false),
                'family_comparison' => (bool) ($familyContextSignals['family_comparison'] ?? false),
            ],
            'context_ready_without_preview' => $contextReadyWithoutPreview,
            'possible_change_type_typo' => $possibleChangeTypeTypo,
            'packet_validation_ready' => is_array($packetValidation) ? (($packetValidation['valid'] ?? false) === true) : null,
            'packet_validation_error_count' => count($validationErrors),
            'packet_validation_errors' => $validationErrors,
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

        if (($readiness['materializable_remediation'] ?? false) !== true
            && ($readiness['packet_validation_ready'] ?? null) !== false) {
            $missing[] = 'supported_operation_type';
        }

        foreach ((array) ($readiness['packet_validation_errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->nullableString($error['code'] ?? null);
            if ($code !== null) {
                $missing[] = $code;
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * @return list<array{gate:string,code:string}>
     */
    private function validationErrors(mixed $validation): array
    {
        if (! is_array($validation)) {
            return [];
        }

        $errors = [];
        foreach ((array) ($validation['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $gate = $this->safeValidationToken($error['gate'] ?? null);
            $code = $this->safeValidationToken($error['code'] ?? null);
            if ($gate !== null && $code !== null) {
                $errors[] = [
                    'gate' => $gate,
                    'code' => $code,
                ];
            }
        }

        return $errors;
    }

    private function safeValidationToken(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null || preg_match('/^[a-z0-9_:-]{1,80}$/', $value) !== 1) {
            return null;
        }

        return $value;
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

        $priority = ((int) ($right['_sort_priority'] ?? 0)) <=> ((int) ($left['_sort_priority'] ?? 0));
        if ($priority !== 0) {
            return $priority;
        }

        return ((int) ($left['_sort_id'] ?? PHP_INT_MAX)) <=> ((int) ($right['_sort_id'] ?? PHP_INT_MAX));
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

        if ($focus === 'source-backed-packet') {
            return array_values(array_filter($candidates, function (array $candidate): bool {
                $flags = is_array($candidate['evidence_flags'] ?? null) ? $candidate['evidence_flags'] : [];

                return ($candidate['review_type'] ?? null) === 'genealogy_review_packet'
                    && ($flags['packet_source_backed'] ?? false) === true
                    && ($flags['packet_review_ready'] ?? false) === true
                    && ($flags['packet_preview_only'] ?? false) === true
                    && ($flags['packet_canonical_mutation'] ?? true) === false;
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
            'source_backed_packet_review',
            'source_backed_packet_needed' => 3,
            'actionable_or_obsolete_triage' => 4,
            'routine_stale_review' => 5,
            default => 10,
        };
    }

    private function selectionReason(
        string $classification,
        string $underlyingClassification,
        bool $isHighPriority,
        bool $isStale
    ): string {
        if ($isHighPriority) {
            return 'high_priority_first';
        }

        if ($isStale && $classification === 'stale_infrastructure_relevance_check') {
            return 'oldest_stale_infrastructure';
        }

        if ($classification === 'typed_preview_needed' || $underlyingClassification === 'typed_preview_needed') {
            return 'typed_preview_needed';
        }

        if ($classification === 'source_backed_packet_review') {
            return 'source_backed_packet_review';
        }

        if ($isStale) {
            return 'oldest_stale_review';
        }

        return 'routine_pending_review';
    }

    private function ageDays(?string $createdAt): ?int
    {
        if ($createdAt === null) {
            return null;
        }

        $createdTs = strtotime($createdAt);
        if ($createdTs === false) {
            return null;
        }

        return max(0, (int) floor((now()->getTimestamp() - $createdTs) / 86400));
    }

    private function ageBucket(?int $ageDays): string
    {
        if ($ageDays === null) {
            return 'unknown_created_at';
        }

        return match (true) {
            $ageDays < 1 => '0_24h',
            $ageDays <= 7 => '1_7d',
            $ageDays <= 30 => '8_30d',
            default => '31d_plus',
        };
    }

    private function targetReference(object $row, string $reviewType, ?string $findingType): string
    {
        return app(ReviewTargetReferenceService::class)->forReviewRow($row, $reviewType, $findingType);
    }

    private function rowId(object $row): int
    {
        $id = (int) ($row->id ?? 0);

        return $id > 0 ? $id : PHP_INT_MAX;
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
            foreach (['operation_type', 'operation', 'type', 'change_type', 'finding_type'] as $key) {
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

    private function hasGenealogyTodoContext(array $details): bool
    {
        $found = false;

        $this->walkArrays($details, function (array $payload) use (&$found): void {
            if ($found) {
                return;
            }

            $operation = $this->normalizeOperationType($payload['operation_type'] ?? $payload['operation'] ?? $payload['type'] ?? $payload['change_type'] ?? $payload['finding_type'] ?? null);
            if ($operation !== 'genealogy_todo_create') {
                return;
            }

            $hasTarget = $this->firstPositiveInt($payload, [
                'tree_id',
                'target_tree_id',
                'person_id',
                'target_person_id',
                'family_id',
                'target_family_id',
                'source_id',
                'target_source_id',
                'suspect_family_id',
            ]) !== null;
            $hasQuestion = $this->firstNonEmptyString($payload, [
                'research_question',
                'question',
                'todo',
                'task',
                'proposed_value',
                'evidence_summary',
                'summary',
                'claim_text',
                'claim',
                'statement',
            ]) !== null;

            $found = $hasTarget && $hasQuestion;
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
            'genealogy_todo_create', 'genealogy_todo_create_preview', 'data_quality_review', 'genealogy_data_quality' => 'genealogy_todo_create',
            default => null,
        };
    }

    private function withFindingTypeContext(array $details, ?string $findingType): array
    {
        if ($findingType !== null && ! isset($details['finding_type'])) {
            $details['finding_type'] = $findingType;
        }

        return $details;
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

    private function firstNonEmptyString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
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

    /**
     * @param  array<string, int>  $counts
     */
    private function incrementCount(array &$counts, string $key): void
    {
        $counts[$key] = (int) ($counts[$key] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    private function integerCountMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $counts = [];
        foreach ($value as $key => $count) {
            $label = $this->nullableString($key);
            if ($label === null) {
                continue;
            }

            $counts[$label] = (int) $count;
        }

        ksort($counts);

        return $counts;
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
