<?php

namespace App\Services\Genealogy;

use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GenealogyEvidenceSprintReadinessService
{
    private const REVIEW_TYPE = 'genealogy_review_packet';

    private const TARGET_PACKETS = 5;

    private ?GenealogyReviewPacketValidatorService $packetValidator = null;

    public function collect(int $days = 30, int $limit = 500): array
    {
        $days = max(1, min(365, $days));
        $limit = max(25, min(2000, $limit));

        $generatedAt = now();
        $errors = [];

        if (! Schema::hasTable('agent_review_queue')) {
            return $this->emptyPayload(
                generatedAt: $generatedAt,
                days: $days,
                errors: ['agent_review_queue table is missing.'],
                status: 'blocked',
            );
        }

        $totalRows = (int) DB::table('agent_review_queue')
            ->where('review_type', self::REVIEW_TYPE)
            ->count();

        $cutoff = $generatedAt->copy()->subDays($days);
        $rows = DB::table('agent_review_queue')
            ->select($this->reviewQueueSelectColumns())
            ->where('review_type', self::REVIEW_TYPE)
            ->where('created_at', '>=', $cutoff->toDateTimeString())
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $truncated = $rows->count() > $limit;
        if ($truncated) {
            $rows = $rows->take($limit)->values();
            $errors[] = "Window result set exceeded {$limit} rows; newest rows only.";
        }

        $summary = $this->summarizeRows($rows);
        $summary['packet_rows_total'] = $totalRows;
        $summary['packet_rows_window'] = $rows->count();

        $remaining = max(0, self::TARGET_PACKETS - (int) $summary['source_backed_packets']);
        $remainingReviewable = max(0, self::TARGET_PACKETS - (int) $summary['reviewable_pending_packets']);
        $operatorOutcomePackets = $summary['terminal_outcome_packets'] + $summary['followup_outcome_packets'];
        $operatorPassRecorded = $summary['operator_touched_preview_only_packets'] >= self::TARGET_PACKETS
            && $operatorOutcomePackets >= self::TARGET_PACKETS
            && $summary['operator_boundary_packets'] >= self::TARGET_PACKETS
            && $summary['packets_missing_boundary'] === 0
            && $summary['boundary_label_count'] === 1
            && $summary['boundary_mismatch_packets'] === 0
            && $summary['mutating_preview_packets'] === 0
            && $summary['malformed_details'] === 0;
        $readiness = [
            'target_packet_count' => self::TARGET_PACKETS,
            'remaining_to_target' => $remaining,
            'reviewable_pending_packets' => $summary['reviewable_pending_packets'],
            'remaining_reviewable_to_target' => $remainingReviewable,
            'has_operator_boundary' => $summary['operator_boundary_packets'] > 0,
            'boundary_consistent' => $summary['packet_rows_window'] > 0
                && $summary['packets_missing_boundary'] === 0
                && $summary['boundary_label_count'] === 1
                && $summary['boundary_mismatch_packets'] === 0,
            'needs_operator_boundary' => $totalRows === 0 || $summary['operator_boundary_packets'] === 0,
            'needs_reviewable_packet_details' => $summary['source_backed_pending'] >= self::TARGET_PACKETS
                && $summary['reviewable_pending_packets'] < self::TARGET_PACKETS,
            'mutation_guard_ok' => $summary['mutating_preview_packets'] === 0,
            'ready_for_five_packet_review' => $summary['reviewable_pending_packets'] >= self::TARGET_PACKETS
                && $summary['operator_boundary_packets'] >= self::TARGET_PACKETS
                && $summary['packets_missing_boundary'] === 0
                && $summary['boundary_label_count'] === 1
                && $summary['boundary_mismatch_packets'] === 0
                && $summary['mutating_preview_packets'] === 0
                && $summary['malformed_details'] === 0,
            'operator_pass_recorded' => $operatorPassRecorded,
        ];

        if ($summary['malformed_details'] > 0) {
            $errors[] = "{$summary['malformed_details']} packet row(s) have malformed details JSON.";
        }

        if ($summary['mutating_preview_packets'] > 0) {
            $errors[] = "{$summary['mutating_preview_packets']} packet row(s) advertise unsafe apply_preview metadata.";
        }

        $status = $this->statusFor($summary, $readiness, $errors);

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => $generatedAt->toIso8601String(),
            'window_days' => $days,
            'target_packets' => self::TARGET_PACKETS,
            'status' => $status,
            'next_review_target' => $this->nextReviewTarget($rows),
            'summary' => $summary,
            'status_counts' => $summary['_status_counts'],
            'packet_status_counts' => $summary['_packet_status_counts'],
            'top_agents' => $summary['_top_agents'],
            'top_reason_codes' => $summary['_top_reason_codes'],
            'readiness' => $readiness,
            'recommendations' => $this->recommendations($status, $summary, $readiness),
            'evidence_errors' => $errors,
            'truncated' => $truncated,
        ];
    }

    public function toMarkdown(array $payload): string
    {
        $lines = [
            '# Genealogy Evidence Sprint Readiness',
            '',
            '- Mode: '.$payload['mode'],
            '- Status: '.$payload['status'],
            '- Generated: '.$payload['generated_at'],
            '- Window: '.$payload['window_days'].' day(s)',
            '- Target packets: '.$payload['target_packets'],
            '',
            '## Summary',
            '',
        ];

        foreach ($payload['summary'] as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            $lines[] = '- '.$key.': '.$value;
        }

        $lines[] = '';
        $lines[] = '## Readiness';
        $lines[] = '';

        foreach ($payload['readiness'] as $key => $value) {
            $lines[] = '- '.$key.': '.$this->stringValue($value);
        }

        if (is_array($payload['next_review_target'] ?? null)) {
            $target = $payload['next_review_target'];
            $lines[] = '';
            $lines[] = '## Next Review Target';
            $lines[] = '';
            $lines[] = '- target_ref: '.$target['target_ref'];
            $lines[] = '- selection_reason: '.$target['selection_reason'];
            $lines[] = '- review_ready: '.$this->stringValue($target['review_ready'] ?? false);
            $lines[] = '- source_backed: '.$this->stringValue($target['source_backed'] ?? false);
            $lines[] = '- preview_only: '.$this->stringValue($target['preview_only'] ?? false);
            $lines[] = '- canonical_write_allowed: '.$this->stringValue($target['canonical_write_allowed'] ?? false);
            $lines[] = '- batch_review_allowed: '.$this->stringValue($target['batch_review_allowed'] ?? false);
            $lines[] = '- details_included: '.$this->stringValue($target['details_included'] ?? false);
        }

        if (($payload['recommendations'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Recommendations';
            $lines[] = '';
            foreach ($payload['recommendations'] as $recommendation) {
                $lines[] = '- '.$recommendation;
            }
        }

        if (($payload['evidence_errors'] ?? []) !== []) {
            $lines[] = '';
            $lines[] = '## Evidence Errors';
            $lines[] = '';
            foreach ($payload['evidence_errors'] as $error) {
                $lines[] = '- '.$error;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    public function compactPayload(array $payload): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $readiness = is_array($payload['readiness'] ?? null) ? $payload['readiness'] : [];

        return [
            'version' => 1,
            'mode' => $payload['mode'] ?? 'observe',
            'compact' => true,
            'status' => $payload['status'] ?? 'unknown',
            'generated_at' => $payload['generated_at'] ?? null,
            'window_days' => (int) ($payload['window_days'] ?? 0),
            'target_packets' => (int) ($payload['target_packets'] ?? self::TARGET_PACKETS),
            'truncated' => (bool) ($payload['truncated'] ?? false),
            'next_review_target' => $this->compactNextReviewTarget($payload['next_review_target'] ?? null),
            'operator_pass_gaps' => $this->operatorPassGapCounters(
                $summary,
                $readiness,
                (int) ($payload['target_packets'] ?? self::TARGET_PACKETS)
            ),
            'summary' => [
                'packet_rows_total' => (int) ($summary['packet_rows_total'] ?? 0),
                'packet_rows_window' => (int) ($summary['packet_rows_window'] ?? 0),
                'pending_packets' => (int) ($summary['pending_packets'] ?? 0),
                'source_backed_packets' => (int) ($summary['source_backed_packets'] ?? 0),
                'source_backed_pending' => (int) ($summary['source_backed_pending'] ?? 0),
                'reviewable_pending_packets' => (int) ($summary['reviewable_pending_packets'] ?? 0),
                'source_backed_pending_not_packet_pending' => (int) ($summary['source_backed_pending_not_packet_pending'] ?? 0),
                'source_backed_pending_missing_preview_only' => (int) ($summary['source_backed_pending_missing_preview_only'] ?? 0),
                'source_backed_pending_missing_identity' => (int) ($summary['source_backed_pending_missing_identity'] ?? 0),
                'source_backed_pending_missing_privacy_clearance' => (int) ($summary['source_backed_pending_missing_privacy_clearance'] ?? 0),
                'source_backed_pending_missing_claims' => (int) ($summary['source_backed_pending_missing_claims'] ?? 0),
                'source_backed_pending_missing_validation' => (int) ($summary['source_backed_pending_missing_validation'] ?? 0),
                'source_backed_pending_missing_boundary' => (int) ($summary['source_backed_pending_missing_boundary'] ?? 0),
                'source_locator_required_packets' => (int) ($summary['source_locator_required_packets'] ?? 0),
                'non_public_source_packets' => (int) ($summary['non_public_source_packets'] ?? 0),
                'manual_only_source_packets' => (int) ($summary['manual_only_source_packets'] ?? 0),
                'source_realism_blocked_packets' => (int) ($summary['source_realism_blocked_packets'] ?? 0),
                'operator_touched_packets' => (int) ($summary['operator_touched_packets'] ?? 0),
                'operator_touched_preview_only_packets' => (int) ($summary['operator_touched_preview_only_packets'] ?? 0),
                'terminal_outcome_packets' => (int) ($summary['terminal_outcome_packets'] ?? 0),
                'followup_outcome_packets' => (int) ($summary['followup_outcome_packets'] ?? 0),
                'reviewed_preview_only' => (int) ($summary['reviewed_preview_only'] ?? 0),
                'rejected_packets' => (int) ($summary['rejected_packets'] ?? 0),
                'deferred_packets' => (int) ($summary['deferred_packets'] ?? 0),
                'clarification_requested' => (int) ($summary['clarification_requested'] ?? 0),
                'preview_only_packets' => (int) ($summary['preview_only_packets'] ?? 0),
                'operator_boundary_packets' => (int) ($summary['operator_boundary_packets'] ?? 0),
                'packets_missing_boundary' => (int) ($summary['packets_missing_boundary'] ?? 0),
                'boundary_label_count' => (int) ($summary['boundary_label_count'] ?? 0),
                'boundary_mismatch_packets' => (int) ($summary['boundary_mismatch_packets'] ?? 0),
                'mutating_preview_packets' => (int) ($summary['mutating_preview_packets'] ?? 0),
                'malformed_details' => (int) ($summary['malformed_details'] ?? 0),
            ],
            'readiness' => [
                'remaining_to_target' => (int) ($readiness['remaining_to_target'] ?? self::TARGET_PACKETS),
                'remaining_reviewable_to_target' => (int) ($readiness['remaining_reviewable_to_target'] ?? self::TARGET_PACKETS),
                'has_operator_boundary' => (bool) ($readiness['has_operator_boundary'] ?? false),
                'boundary_consistent' => (bool) ($readiness['boundary_consistent'] ?? false),
                'needs_operator_boundary' => (bool) ($readiness['needs_operator_boundary'] ?? true),
                'needs_reviewable_packet_details' => (bool) ($readiness['needs_reviewable_packet_details'] ?? false),
                'mutation_guard_ok' => (bool) ($readiness['mutation_guard_ok'] ?? true),
                'ready_for_five_packet_review' => (bool) ($readiness['ready_for_five_packet_review'] ?? false),
                'operator_pass_recorded' => (bool) ($readiness['operator_pass_recorded'] ?? false),
            ],
            'recommendation_count' => count($payload['recommendations'] ?? []),
            'recommendations' => array_values(array_filter($payload['recommendations'] ?? [], 'is_string')),
            'evidence_error_count' => count($payload['evidence_errors'] ?? []),
            'evidence_errors' => array_values(array_filter($payload['evidence_errors'] ?? [], 'is_string')),
        ];
    }

    public function toCompactText(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['readiness'];
        $operatorPassGaps = $compact['operator_pass_gaps'];
        $target = is_array($compact['next_review_target'] ?? null) ? $compact['next_review_target'] : null;

        return implode(PHP_EOL, [
            sprintf(
                'Genealogy evidence sprint compact: %s generated=%s window=%sd target=%s',
                $compact['status'],
                $compact['generated_at'] ?? '-',
                $compact['window_days'],
                $compact['target_packets']
            ),
            sprintf(
                'packets: total=%s window=%s source_backed=%s reviewable=%s remaining=%s remaining_reviewable=%s',
                $summary['packet_rows_total'],
                $summary['packet_rows_window'],
                $summary['source_backed_packets'],
                $summary['reviewable_pending_packets'],
                $readiness['remaining_to_target'],
                $readiness['remaining_reviewable_to_target']
            ),
            sprintf(
                'reviewability_gaps: not_packet_pending=%s missing_preview_only=%s missing_identity=%s missing_privacy=%s missing_claims=%s missing_validation=%s missing_boundary=%s needs_details=%s',
                $summary['source_backed_pending_not_packet_pending'],
                $summary['source_backed_pending_missing_preview_only'],
                $summary['source_backed_pending_missing_identity'],
                $summary['source_backed_pending_missing_privacy_clearance'],
                $summary['source_backed_pending_missing_claims'],
                $summary['source_backed_pending_missing_validation'],
                $summary['source_backed_pending_missing_boundary'],
                $this->boolValue($readiness['needs_reviewable_packet_details'])
            ),
            sprintf(
                'source_realism: missing_locator=%s non_public=%s manual_only=%s blocked=%s',
                $summary['source_locator_required_packets'],
                $summary['non_public_source_packets'],
                $summary['manual_only_source_packets'],
                $summary['source_realism_blocked_packets']
            ),
            sprintf(
                'next_review: target_ref=%s reason=%s review_ready=%s source_backed=%s preview_only=%s canonical_write_allowed=%s batch_review_allowed=%s details_included=%s',
                $target['target_ref'] ?? 'none',
                $target['selection_reason'] ?? 'none',
                $this->boolValue((bool) ($target['review_ready'] ?? false)),
                $this->boolValue((bool) ($target['source_backed'] ?? false)),
                $this->boolValue((bool) ($target['preview_only'] ?? false)),
                $this->boolValue((bool) ($target['canonical_write_allowed'] ?? false)),
                $this->boolValue((bool) ($target['batch_review_allowed'] ?? false)),
                $this->boolValue((bool) ($target['details_included'] ?? false))
            ),
            sprintf(
                'outcomes: touched=%s touched_preview_only=%s terminal=%s followup=%s reviewed=%s rejected=%s deferred=%s clarify=%s operator_pass_recorded=%s',
                $summary['operator_touched_packets'],
                $summary['operator_touched_preview_only_packets'],
                $summary['terminal_outcome_packets'],
                $summary['followup_outcome_packets'],
                $summary['reviewed_preview_only'],
                $summary['rejected_packets'],
                $summary['deferred_packets'],
                $summary['clarification_requested'],
                $this->boolValue($readiness['operator_pass_recorded'])
            ),
            sprintf(
                'operator_pass_gaps: target=%s outcome_packets=%s terminal=%s followup=%s reviewed=%s rejected=%s deferred=%s clarify=%s remaining_outcomes=%s remaining_touched_preview_only=%s remaining_boundary=%s remaining_to_five_packet_pass=%s pass_recorded=%s',
                $operatorPassGaps['target_packet_count'],
                $operatorPassGaps['operator_outcome_packets'],
                $operatorPassGaps['terminal_outcome_packets'],
                $operatorPassGaps['followup_outcome_packets'],
                $operatorPassGaps['reviewed_preview_only'],
                $operatorPassGaps['rejected_packets'],
                $operatorPassGaps['deferred_packets'],
                $operatorPassGaps['clarification_requested'],
                $operatorPassGaps['remaining_outcome_packets_to_pass'],
                $operatorPassGaps['remaining_touched_preview_only_to_pass'],
                $operatorPassGaps['remaining_boundary_packets_to_pass'],
                $operatorPassGaps['remaining_to_five_packet_pass'],
                $this->boolValue($operatorPassGaps['operator_pass_recorded'])
            ),
            sprintf(
                'boundary: has=%s consistent=%s missing=%s label_count=%s mismatch=%s needs_operator_boundary=%s',
                $this->boolValue($readiness['has_operator_boundary']),
                $this->boolValue($readiness['boundary_consistent']),
                $summary['packets_missing_boundary'],
                $summary['boundary_label_count'],
                $summary['boundary_mismatch_packets'],
                $this->boolValue($readiness['needs_operator_boundary'])
            ),
            sprintf(
                'guards: mutation_guard_ok=%s mutating_preview=%s malformed=%s evidence_errors=%s',
                $this->boolValue($readiness['mutation_guard_ok']),
                $summary['mutating_preview_packets'],
                $summary['malformed_details'],
                $compact['evidence_error_count']
            ),
        ]).PHP_EOL;
    }

    public function toCompactMarkdown(array $payload): string
    {
        $compact = $this->compactPayload($payload);
        $summary = $compact['summary'];
        $readiness = $compact['readiness'];
        $operatorPassGaps = $compact['operator_pass_gaps'];
        $target = is_array($compact['next_review_target'] ?? null) ? $compact['next_review_target'] : null;
        $lines = [
            '# Genealogy Evidence Sprint Compact Readiness',
            '',
            '- Status: `'.$compact['status'].'`',
            '- Generated: `'.($compact['generated_at'] ?? 'unknown').'`',
            '- Window days: `'.$compact['window_days'].'`',
            '- Target packets: `'.$compact['target_packets'].'`',
            '- Packet rows total: `'.$summary['packet_rows_total'].'`',
            '- Source-backed packets: `'.$summary['source_backed_packets'].'`',
            '- Reviewable pending packets: `'.$summary['reviewable_pending_packets'].'`',
            '- Remaining to target: `'.$readiness['remaining_to_target'].'`',
            '- Remaining reviewable to target: `'.$readiness['remaining_reviewable_to_target'].'`',
            '- Reviewability gaps: `'.implode(', ', [
                'not_packet_pending='.$summary['source_backed_pending_not_packet_pending'],
                'missing_preview_only='.$summary['source_backed_pending_missing_preview_only'],
                'missing_identity='.$summary['source_backed_pending_missing_identity'],
                'missing_privacy='.$summary['source_backed_pending_missing_privacy_clearance'],
                'missing_claims='.$summary['source_backed_pending_missing_claims'],
                'missing_validation='.$summary['source_backed_pending_missing_validation'],
                'missing_boundary='.$summary['source_backed_pending_missing_boundary'],
            ]).'`',
            '- Source realism blocks: `'.implode(', ', [
                'missing_locator='.$summary['source_locator_required_packets'],
                'non_public='.$summary['non_public_source_packets'],
                'manual_only='.$summary['manual_only_source_packets'],
                'blocked='.$summary['source_realism_blocked_packets'],
            ]).'`',
            '- Next review target: `'.($target['target_ref'] ?? 'none').'`',
            '- Next review target safety: `'.implode(', ', [
                'reason='.($target['selection_reason'] ?? 'none'),
                'review_ready='.$this->boolValue((bool) ($target['review_ready'] ?? false)),
                'source_backed='.$this->boolValue((bool) ($target['source_backed'] ?? false)),
                'preview_only='.$this->boolValue((bool) ($target['preview_only'] ?? false)),
                'canonical_write_allowed='.$this->boolValue((bool) ($target['canonical_write_allowed'] ?? false)),
                'batch_review_allowed='.$this->boolValue((bool) ($target['batch_review_allowed'] ?? false)),
                'details_included='.$this->boolValue((bool) ($target['details_included'] ?? false)),
            ]).'`',
            '- Outcomes: `'.implode(', ', [
                'touched='.$summary['operator_touched_packets'],
                'touched_preview_only='.$summary['operator_touched_preview_only_packets'],
                'terminal='.$summary['terminal_outcome_packets'],
                'followup='.$summary['followup_outcome_packets'],
                'reviewed='.$summary['reviewed_preview_only'],
                'rejected='.$summary['rejected_packets'],
                'deferred='.$summary['deferred_packets'],
                'clarify='.$summary['clarification_requested'],
            ]).'`',
            '- Operator pass gaps: `'.implode(', ', [
                'target='.$operatorPassGaps['target_packet_count'],
                'outcome_packets='.$operatorPassGaps['operator_outcome_packets'],
                'terminal='.$operatorPassGaps['terminal_outcome_packets'],
                'followup='.$operatorPassGaps['followup_outcome_packets'],
                'reviewed='.$operatorPassGaps['reviewed_preview_only'],
                'rejected='.$operatorPassGaps['rejected_packets'],
                'deferred='.$operatorPassGaps['deferred_packets'],
                'clarify='.$operatorPassGaps['clarification_requested'],
                'remaining_outcomes='.$operatorPassGaps['remaining_outcome_packets_to_pass'],
                'remaining_touched_preview_only='.$operatorPassGaps['remaining_touched_preview_only_to_pass'],
                'remaining_boundary='.$operatorPassGaps['remaining_boundary_packets_to_pass'],
                'remaining_to_five_packet_pass='.$operatorPassGaps['remaining_to_five_packet_pass'],
                'pass_recorded='.$this->boolValue($operatorPassGaps['operator_pass_recorded']),
            ]).'`',
            '- Needs reviewable packet details: `'.$this->boolValue($readiness['needs_reviewable_packet_details']).'`',
            '- Boundary consistent: `'.$this->boolValue($readiness['boundary_consistent']).'`',
            '- Mutation guard ok: `'.$this->boolValue($readiness['mutation_guard_ok']).'`',
            '- Operator pass recorded: `'.$this->boolValue($readiness['operator_pass_recorded']).'`',
            '- Evidence error count: `'.$compact['evidence_error_count'].'`',
        ];

        if ($compact['recommendations'] !== []) {
            $lines[] = '';
            $lines[] = '## Recommendations';
            $lines[] = '';
            foreach ($compact['recommendations'] as $recommendation) {
                $lines[] = '- '.$recommendation;
            }
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    private function emptyPayload(Carbon $generatedAt, int $days, array $errors, string $status): array
    {
        $summary = $this->initialSummary();
        $readiness = [
            'target_packet_count' => self::TARGET_PACKETS,
            'remaining_to_target' => self::TARGET_PACKETS,
            'reviewable_pending_packets' => 0,
            'remaining_reviewable_to_target' => self::TARGET_PACKETS,
            'has_operator_boundary' => false,
            'boundary_consistent' => false,
            'needs_operator_boundary' => true,
            'needs_reviewable_packet_details' => false,
            'mutation_guard_ok' => true,
            'ready_for_five_packet_review' => false,
            'operator_pass_recorded' => false,
        ];

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => $generatedAt->toIso8601String(),
            'window_days' => $days,
            'target_packets' => self::TARGET_PACKETS,
            'status' => $status,
            'summary' => $summary,
            'status_counts' => [],
            'packet_status_counts' => [],
            'top_agents' => [],
            'top_reason_codes' => [],
            'next_review_target' => null,
            'readiness' => $readiness,
            'recommendations' => $this->recommendations($status, $summary, $readiness),
            'evidence_errors' => $errors,
            'truncated' => false,
        ];
    }

    private function summarizeRows(Collection $rows): array
    {
        $summary = $this->initialSummary();
        $statusCounts = [];
        $packetStatusCounts = [];
        $agentCounts = [];
        $reasonCounts = [];
        $boundaryCounts = [];

        foreach ($rows as $row) {
            $rowStatus = $this->cleanKey($row->status ?? 'unknown');
            $statusCounts[$rowStatus] = ($statusCounts[$rowStatus] ?? 0) + 1;

            $agentId = $this->cleanKey($row->agent_id ?? 'unknown');
            $agentCounts[$agentId] = ($agentCounts[$agentId] ?? 0) + 1;

            [$details, $malformed] = $this->decodeDetails($row->details ?? null);
            if ($malformed) {
                $summary['malformed_details']++;
            } else {
                $validation = $this->packetValidator()->validate($details);
                $sourceLocatorMissing = $this->validationHasError($validation, 'source_locator_required');
                $nonPublicSource = $this->validationHasError($validation, 'non_public_source_locator_blocked');
                $manualOnlySource = $this->validationHasError($validation, 'manual_source_as_evidence_blocked');

                if ($sourceLocatorMissing) {
                    $summary['source_locator_required_packets']++;
                }

                if ($nonPublicSource) {
                    $summary['non_public_source_packets']++;
                }

                if ($manualOnlySource) {
                    $summary['manual_only_source_packets']++;
                }

                if ($sourceLocatorMissing || $nonPublicSource || $manualOnlySource) {
                    $summary['source_realism_blocked_packets']++;
                }
            }

            $packetStatus = $this->cleanKey($details['packet_status'] ?? $rowStatus);
            $packetStatusCounts[$packetStatus] = ($packetStatusCounts[$packetStatus] ?? 0) + 1;

            $sourceBacked = $this->hasSourceEvidence($details);
            $previewOnly = $this->isPreviewOnly($details);
            $hasIdentity = $this->hasIdentity($details);
            $hasPrivacyClearance = ($details['privacy']['cleared'] ?? null) === true;
            $hasClaims = $this->hasClaims($details);
            $validationAllowsReview = $this->validationAllowsReview($details);
            $boundaryLabel = $this->operatorBoundaryLabel($details);
            $hasBoundary = $boundaryLabel !== null;

            if ($sourceBacked) {
                $summary['source_backed_packets']++;
            }

            if ($rowStatus === 'pending') {
                $summary['pending_packets']++;
                if ($sourceBacked) {
                    $summary['source_backed_pending']++;
                    if ($packetStatus === 'pending'
                        && $previewOnly
                        && $hasIdentity
                        && $hasPrivacyClearance
                        && $hasClaims
                        && $validationAllowsReview
                        && $hasBoundary
                    ) {
                        $summary['reviewable_pending_packets']++;
                    } else {
                        if ($packetStatus !== 'pending') {
                            $summary['source_backed_pending_not_packet_pending']++;
                        }

                        if (! $previewOnly) {
                            $summary['source_backed_pending_missing_preview_only']++;
                        }

                        if (! $hasIdentity) {
                            $summary['source_backed_pending_missing_identity']++;
                        }

                        if (! $hasPrivacyClearance) {
                            $summary['source_backed_pending_missing_privacy_clearance']++;
                        }

                        if (! $hasClaims) {
                            $summary['source_backed_pending_missing_claims']++;
                        }

                        if (! $validationAllowsReview) {
                            $summary['source_backed_pending_missing_validation']++;
                        }

                        if (! $hasBoundary) {
                            $summary['source_backed_pending_missing_boundary']++;
                        }
                    }
                }
            } elseif ($sourceBacked) {
                $summary['source_backed_decided']++;
            }

            if ($packetStatus === 'reviewed_preview_only') {
                $summary['reviewed_preview_only']++;
            } elseif ($packetStatus === 'rejected') {
                $summary['rejected_packets']++;
            } elseif ($packetStatus === 'deferred') {
                $summary['deferred_packets']++;
            } elseif ($packetStatus === 'clarification_requested') {
                $summary['clarification_requested']++;
            }

            if ($sourceBacked && in_array($packetStatus, ['reviewed_preview_only', 'rejected'], true)) {
                $summary['terminal_outcome_packets']++;
            } elseif ($sourceBacked && in_array($packetStatus, ['deferred', 'clarification_requested'], true)) {
                $summary['followup_outcome_packets']++;
            }

            if ($previewOnly) {
                $summary['preview_only_packets']++;
            }

            if ($this->hasUnsafeApplyPreviewMetadata($details)) {
                $summary['mutating_preview_packets']++;
            }

            if ($hasIdentity) {
                $summary['packets_with_identity']++;
            }

            if ($hasPrivacyClearance) {
                $summary['packets_with_privacy_clearance']++;
            }

            if ($hasClaims) {
                $summary['packets_with_claims']++;
            }

            $hasDecisionLog = $this->hasDecisionLog($details);
            if ($hasDecisionLog) {
                $summary['packets_with_decision_log']++;
                if ($sourceBacked) {
                    $summary['operator_touched_packets']++;
                    if ($previewOnly) {
                        $summary['operator_touched_preview_only_packets']++;
                    }
                }
                foreach ($this->reasonCodes($details['decision_log']) as $reasonCode) {
                    $reasonCounts[$reasonCode] = ($reasonCounts[$reasonCode] ?? 0) + 1;
                }
            }

            if ($hasBoundary) {
                $summary['operator_boundary_packets']++;
                $boundaryKey = strtolower($boundaryLabel);
                $boundaryCounts[$boundaryKey] = ($boundaryCounts[$boundaryKey] ?? 0) + 1;
            } else {
                $summary['packets_missing_boundary']++;
            }
        }

        arsort($statusCounts);
        arsort($packetStatusCounts);
        arsort($agentCounts);
        arsort($reasonCounts);

        $summary['_status_counts'] = $statusCounts;
        $summary['_packet_status_counts'] = $packetStatusCounts;
        $summary['_top_agents'] = array_slice($agentCounts, 0, 10, true);
        $summary['_top_reason_codes'] = array_slice($reasonCounts, 0, 10, true);
        $summary['boundary_label_count'] = count($boundaryCounts);
        $summary['boundary_mismatch_packets'] = $boundaryCounts === []
            ? 0
            : $summary['operator_boundary_packets'] - max($boundaryCounts);

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextReviewTarget(Collection $rows): ?array
    {
        $targetRow = $rows
            ->filter(function (object $row): bool {
                $rowStatus = $this->cleanKey($row->status ?? 'unknown');
                if ($rowStatus !== 'pending') {
                    return false;
                }

                [$details, $malformed] = $this->decodeDetails($row->details ?? null);
                if ($malformed || $details === []) {
                    return false;
                }

                return $this->isReviewReadyPendingPacket($details);
            })
            ->sort(function (object $left, object $right): int {
                $createdAt = $this->createdAtTimestamp($left->created_at ?? null)
                    <=> $this->createdAtTimestamp($right->created_at ?? null);

                if ($createdAt !== 0) {
                    return $createdAt;
                }

                return $this->rowId($left) <=> $this->rowId($right);
            })
            ->first();

        if ($targetRow === null) {
            return null;
        }

        [$details] = $this->decodeDetails($targetRow->details ?? null);

        return [
            'schema' => 'genealogy_evidence_sprint_next_review_target.v1',
            'target_ref' => app(ReviewTargetReferenceService::class)->forReviewRow($targetRow, self::REVIEW_TYPE),
            'review_type' => self::REVIEW_TYPE,
            'selection_reason' => 'oldest_ready_source_backed_packet',
            'review_ready' => true,
            'source_backed' => true,
            'preview_only' => true,
            'claim_count' => $this->safeCount($details['claims'] ?? []),
            'source_count' => $this->safeCount($this->packetValidator()->collectSourceLocators($details)),
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'details_included' => false,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function compactNextReviewTarget(mixed $target): ?array
    {
        if (! is_array($target)) {
            return null;
        }

        $targetRef = $target['target_ref'] ?? null;
        if (! is_string($targetRef) || preg_match('/^genealogy_review_packet:target-[a-f0-9]{12}$/', $targetRef) !== 1) {
            return null;
        }

        return [
            'schema' => 'genealogy_evidence_sprint_next_review_target.v1',
            'target_ref' => $targetRef,
            'selection_reason' => $this->safeToken($target['selection_reason'] ?? null) ?? 'oldest_ready_source_backed_packet',
            'review_ready' => (bool) ($target['review_ready'] ?? false),
            'source_backed' => (bool) ($target['source_backed'] ?? false),
            'preview_only' => (bool) ($target['preview_only'] ?? false),
            'claim_count' => (int) ($target['claim_count'] ?? 0),
            'source_count' => (int) ($target['source_count'] ?? 0),
            'canonical_write_allowed' => false,
            'batch_review_allowed' => false,
            'details_included' => false,
        ];
    }

    private function isReviewReadyPendingPacket(array $details): bool
    {
        return $this->hasSourceEvidence($details)
            && $this->isPreviewOnly($details)
            && $this->hasIdentity($details)
            && ($details['privacy']['cleared'] ?? null) === true
            && $this->hasClaims($details)
            && $this->validationAllowsReview($details)
            && $this->operatorBoundaryLabel($details) !== null;
    }

    /**
     * @return list<string>
     */
    private function reviewQueueSelectColumns(): array
    {
        $columns = ['id', 'agent_id', 'status', 'details', 'created_at', 'updated_at', 'reviewed_at'];

        foreach (['token', 'review_type'] as $optionalColumn) {
            if (Schema::hasColumn('agent_review_queue', $optionalColumn)) {
                $columns[] = $optionalColumn;
            }
        }

        return $columns;
    }

    private function initialSummary(): array
    {
        return [
            'packet_rows_total' => 0,
            'packet_rows_window' => 0,
            'pending_packets' => 0,
            'reviewed_preview_only' => 0,
            'rejected_packets' => 0,
            'deferred_packets' => 0,
            'clarification_requested' => 0,
            'source_backed_packets' => 0,
            'preview_only_packets' => 0,
            'mutating_preview_packets' => 0,
            'packets_with_identity' => 0,
            'packets_with_privacy_clearance' => 0,
            'packets_with_claims' => 0,
            'packets_with_decision_log' => 0,
            'operator_touched_packets' => 0,
            'operator_touched_preview_only_packets' => 0,
            'terminal_outcome_packets' => 0,
            'followup_outcome_packets' => 0,
            'source_backed_pending' => 0,
            'source_backed_decided' => 0,
            'reviewable_pending_packets' => 0,
            'source_backed_pending_not_packet_pending' => 0,
            'source_backed_pending_missing_preview_only' => 0,
            'source_backed_pending_missing_identity' => 0,
            'source_backed_pending_missing_privacy_clearance' => 0,
            'source_backed_pending_missing_claims' => 0,
            'source_backed_pending_missing_validation' => 0,
            'source_backed_pending_missing_boundary' => 0,
            'source_locator_required_packets' => 0,
            'non_public_source_packets' => 0,
            'manual_only_source_packets' => 0,
            'source_realism_blocked_packets' => 0,
            'operator_boundary_packets' => 0,
            'packets_missing_boundary' => 0,
            'boundary_label_count' => 0,
            'boundary_mismatch_packets' => 0,
            'malformed_details' => 0,
            '_status_counts' => [],
            '_packet_status_counts' => [],
            '_top_agents' => [],
            '_top_reason_codes' => [],
        ];
    }

    private function operatorPassGapCounters(array $summary, array $readiness, int $targetPackets): array
    {
        $targetPackets = max(1, $targetPackets);
        $terminalOutcomePackets = (int) ($summary['terminal_outcome_packets'] ?? 0);
        $followupOutcomePackets = (int) ($summary['followup_outcome_packets'] ?? 0);
        $operatorOutcomePackets = $terminalOutcomePackets + $followupOutcomePackets;
        $touchedPreviewOnlyPackets = (int) ($summary['operator_touched_preview_only_packets'] ?? 0);
        $operatorBoundaryPackets = (int) ($summary['operator_boundary_packets'] ?? 0);
        $remainingOutcomePackets = max(0, $targetPackets - $operatorOutcomePackets);
        $remainingTouchedPreviewOnlyPackets = max(0, $targetPackets - $touchedPreviewOnlyPackets);
        $remainingBoundaryPackets = max(0, $targetPackets - $operatorBoundaryPackets);

        return [
            'target_packet_count' => $targetPackets,
            'operator_pass_recorded' => (bool) ($readiness['operator_pass_recorded'] ?? false),
            'operator_touched_packets' => (int) ($summary['operator_touched_packets'] ?? 0),
            'operator_touched_preview_only_packets' => $touchedPreviewOnlyPackets,
            'operator_outcome_packets' => $operatorOutcomePackets,
            'terminal_outcome_packets' => $terminalOutcomePackets,
            'followup_outcome_packets' => $followupOutcomePackets,
            'reviewed_preview_only' => (int) ($summary['reviewed_preview_only'] ?? 0),
            'rejected_packets' => (int) ($summary['rejected_packets'] ?? 0),
            'deferred_packets' => (int) ($summary['deferred_packets'] ?? 0),
            'clarification_requested' => (int) ($summary['clarification_requested'] ?? 0),
            'operator_boundary_packets' => $operatorBoundaryPackets,
            'packets_missing_boundary' => (int) ($summary['packets_missing_boundary'] ?? 0),
            'boundary_label_count' => (int) ($summary['boundary_label_count'] ?? 0),
            'boundary_mismatch_packets' => (int) ($summary['boundary_mismatch_packets'] ?? 0),
            'mutating_preview_packets' => (int) ($summary['mutating_preview_packets'] ?? 0),
            'malformed_details' => (int) ($summary['malformed_details'] ?? 0),
            'remaining_outcome_packets_to_pass' => $remainingOutcomePackets,
            'remaining_touched_preview_only_to_pass' => $remainingTouchedPreviewOnlyPackets,
            'remaining_boundary_packets_to_pass' => $remainingBoundaryPackets,
            'remaining_to_five_packet_pass' => max(
                $remainingOutcomePackets,
                $remainingTouchedPreviewOnlyPackets,
                $remainingBoundaryPackets
            ),
        ];
    }

    private function statusFor(array $summary, array $readiness, array $errors): string
    {
        if ($errors !== []) {
            return 'blocked';
        }

        if ($summary['packet_rows_total'] === 0) {
            return 'ready_for_operator_boundary';
        }

        if (($readiness['operator_pass_recorded'] ?? false) === true) {
            return 'operator_pass_recorded';
        }

        if ($readiness['ready_for_five_packet_review']) {
            return 'ready_for_review';
        }

        if ($summary['source_backed_pending'] >= self::TARGET_PACKETS && ! $readiness['boundary_consistent']) {
            return 'needs_boundary_consistency';
        }

        if (($readiness['needs_reviewable_packet_details'] ?? false) === true) {
            return 'needs_reviewable_packet_details';
        }

        if ($summary['source_backed_packets'] > 0) {
            return 'in_progress';
        }

        return 'needs_source_backed_packets';
    }

    private function recommendations(string $status, array $summary, array $readiness): array
    {
        $recommendations = [];

        if ($status === 'ready_for_operator_boundary') {
            $recommendations[] = 'Choose the first sprint boundary and source material, then materialize exactly five source-backed packets.';
        }

        if ($status === 'needs_source_backed_packets') {
            $recommendations[] = 'Materialize source-backed packets before asking an operator to review the sprint.';
        }

        if ($readiness['remaining_to_target'] > 0 && $summary['source_backed_packets'] > 0) {
            $recommendations[] = "Add {$readiness['remaining_to_target']} more source-backed packet(s) to reach the sprint target.";
        }

        if ($summary['mutating_preview_packets'] > 0) {
            $recommendations[] = 'Fix packet apply_preview metadata before review; sprint packets must remain preview-only.';
        }

        if ($summary['malformed_details'] > 0) {
            $recommendations[] = 'Repair malformed packet details JSON before using the sprint counts as evidence.';
        }

        if (! $readiness['has_operator_boundary']) {
            $recommendations[] = 'Record a boundary label in packet details so the five-packet sprint remains auditable.';
        }

        if (($summary['packets_missing_boundary'] ?? 0) > 0) {
            $recommendations[] = 'Add the operator boundary label to every packet before treating the sprint as review-ready.';
        }

        if (($summary['boundary_mismatch_packets'] ?? 0) > 0) {
            $recommendations[] = 'Keep the sprint to one boundary label; split mixed-boundary packets into separate sprints.';
        }

        if (($readiness['needs_reviewable_packet_details'] ?? false) === true) {
            $recommendations[] = 'Complete pending packet status, identity, privacy, claims, validation, preview-only, and boundary metadata before treating the sprint as review-ready.';
        }

        if ($status === 'ready_for_review') {
            $recommendations[] = 'Run the operator review pass in the Review Packet UX and keep decisions preview-only.';
        }

        if ($status === 'operator_pass_recorded') {
            $recommendations[] = 'Record the one-at-a-time packet outcomes in the sprint checkpoint and keep any follow-up packets preview-only.';
        }

        return array_values(array_unique($recommendations));
    }

    private function decodeDetails(mixed $raw): array
    {
        if (is_array($raw)) {
            return [$raw, false];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [[], false];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return [[], true];
        }

        return [$decoded, false];
    }

    private function hasSourceEvidence(array $details): bool
    {
        if ($this->packetValidator()->collectSourceLocators($details) === []) {
            return false;
        }

        $validation = $this->packetValidator()->validate($details);

        return ! $this->validationHasError($validation, 'manual_source_as_evidence_blocked')
            && ! $this->validationHasError($validation, 'non_public_source_locator_blocked');
    }

    private function packetValidator(): GenealogyReviewPacketValidatorService
    {
        return $this->packetValidator ??= new GenealogyReviewPacketValidatorService;
    }

    private function validationAllowsReview(array $details): bool
    {
        $validation = $details['validation'] ?? null;
        if (! is_array($validation)) {
            return false;
        }

        if (($validation['valid'] ?? null) !== true) {
            return false;
        }

        $errors = $validation['errors'] ?? [];

        return ! is_array($errors) || $errors === [];
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function validationHasError(array $validation, string $code): bool
    {
        foreach ((array) ($validation['errors'] ?? []) as $error) {
            if (is_array($error) && ($error['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    private function isPreviewOnly(array $details): bool
    {
        $preview = $details['apply_preview'] ?? null;
        if (! is_array($preview)) {
            return false;
        }

        return ($preview['mutates_accepted_facts'] ?? null) === false
            && ! $this->hasUnsafeApplyPreviewMetadata($details);
    }

    private function hasUnsafeApplyPreviewMetadata(array $details): bool
    {
        $preview = $details['apply_preview'] ?? null;
        if (! is_array($preview)) {
            return false;
        }

        if ($this->previewFlagEnabled($preview['mutates_accepted_facts'] ?? null)) {
            return true;
        }

        if ($this->hasAcceptedFactMutations($preview['accepted_fact_mutations'] ?? [])) {
            return true;
        }

        $operations = $preview['operations'] ?? [];
        if (! is_array($operations)) {
            return false;
        }

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            if ($this->previewFlagEnabled($operation['mutates_accepted_facts'] ?? null)) {
                return true;
            }

            if ($this->previewFlagEnabled($operation['apply_enabled'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function hasAcceptedFactMutations(mixed $acceptedFactMutations): bool
    {
        if (is_array($acceptedFactMutations)) {
            return $acceptedFactMutations !== [];
        }

        return $acceptedFactMutations !== null
            && $acceptedFactMutations !== false
            && $acceptedFactMutations !== '';
    }

    private function previewFlagEnabled(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === '') {
            return false;
        }

        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
        }

        return true;
    }

    private function hasIdentity(array $details): bool
    {
        return is_array($details['identity'] ?? null) && $details['identity'] !== [];
    }

    private function hasClaims(array $details): bool
    {
        return is_array($details['claims'] ?? null) && $details['claims'] !== [];
    }

    private function operatorBoundaryLabel(array $details): ?string
    {
        $boundary = $details['sprint']['boundary_label']
            ?? $details['operator_boundary']
            ?? $details['boundary_label']
            ?? null;

        if (! is_string($boundary)) {
            return null;
        }

        $boundary = trim($boundary);

        return $boundary === '' ? null : $boundary;
    }

    private function reasonCodes(mixed $decisionLog): array
    {
        if (! is_array($decisionLog)) {
            return [];
        }

        $reasonCodes = [];
        foreach ($decisionLog as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $reasonCode = $entry['meta']['reason_code'] ?? $entry['reason_code'] ?? null;
            if (is_string($reasonCode) && trim($reasonCode) !== '') {
                $reasonCodes[] = $reasonCode;
            }
        }

        return $reasonCodes;
    }

    private function hasDecisionLog(array $details): bool
    {
        $decisionLog = $details['decision_log'] ?? null;
        if (! is_array($decisionLog)) {
            return false;
        }

        foreach ($decisionLog as $entry) {
            if (is_array($entry) && $entry !== []) {
                return true;
            }
        }

        return false;
    }

    private function createdAtTimestamp(mixed $value): int
    {
        if (! is_scalar($value)) {
            return PHP_INT_MAX;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? PHP_INT_MAX : $timestamp;
    }

    private function rowId(object $row): int
    {
        $id = (int) ($row->id ?? 0);

        return $id > 0 ? $id : PHP_INT_MAX;
    }

    private function safeCount(mixed $value): int
    {
        return is_countable($value) ? count($value) : 0;
    }

    private function safeToken(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || preg_match('/^[a-z0-9_-]{1,80}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function cleanKey(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'unknown';
        }

        return trim($value);
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $this->boolValue($value);
        }

        return (string) $value;
    }

    private function boolValue(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
