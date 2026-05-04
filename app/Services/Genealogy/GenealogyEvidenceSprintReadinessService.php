<?php

namespace App\Services\Genealogy;

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
            ->select(['id', 'agent_id', 'status', 'details', 'created_at', 'updated_at', 'reviewed_at'])
            ->where('review_type', self::REVIEW_TYPE)
            ->where('created_at', '>=', $cutoff->toDateTimeString())
            ->orderByDesc('created_at')
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
                'source_backed_pending_missing_boundary' => (int) ($summary['source_backed_pending_missing_boundary'] ?? 0),
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
                'reviewability_gaps: not_packet_pending=%s missing_preview_only=%s missing_identity=%s missing_privacy=%s missing_claims=%s missing_boundary=%s needs_details=%s',
                $summary['source_backed_pending_not_packet_pending'],
                $summary['source_backed_pending_missing_preview_only'],
                $summary['source_backed_pending_missing_identity'],
                $summary['source_backed_pending_missing_privacy_clearance'],
                $summary['source_backed_pending_missing_claims'],
                $summary['source_backed_pending_missing_boundary'],
                $this->boolValue($readiness['needs_reviewable_packet_details'])
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
                'missing_boundary='.$summary['source_backed_pending_missing_boundary'],
            ]).'`',
            '- Needs reviewable packet details: `'.$this->boolValue($readiness['needs_reviewable_packet_details']).'`',
            '- Boundary consistent: `'.$this->boolValue($readiness['boundary_consistent']).'`',
            '- Mutation guard ok: `'.$this->boolValue($readiness['mutation_guard_ok']).'`',
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
            }

            $packetStatus = $this->cleanKey($details['packet_status'] ?? $rowStatus);
            $packetStatusCounts[$packetStatus] = ($packetStatusCounts[$packetStatus] ?? 0) + 1;

            $sourceBacked = $this->hasSourceEvidence($details);
            $previewOnly = $this->isPreviewOnly($details);
            $hasIdentity = $this->hasIdentity($details);
            $hasPrivacyClearance = ($details['privacy']['cleared'] ?? null) === true;
            $hasClaims = $this->hasClaims($details);
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

            if (($details['decision_log'] ?? []) !== []) {
                $summary['packets_with_decision_log']++;
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
            'source_backed_pending' => 0,
            'source_backed_decided' => 0,
            'reviewable_pending_packets' => 0,
            'source_backed_pending_not_packet_pending' => 0,
            'source_backed_pending_missing_preview_only' => 0,
            'source_backed_pending_missing_identity' => 0,
            'source_backed_pending_missing_privacy_clearance' => 0,
            'source_backed_pending_missing_claims' => 0,
            'source_backed_pending_missing_boundary' => 0,
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

    private function statusFor(array $summary, array $readiness, array $errors): string
    {
        if ($errors !== []) {
            return 'blocked';
        }

        if ($summary['packet_rows_total'] === 0) {
            return 'ready_for_operator_boundary';
        }

        if ($readiness['ready_for_five_packet_review']) {
            return 'ready_for_review';
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
            $recommendations[] = 'Complete pending packet status, identity, privacy, claims, preview-only, and boundary metadata before treating the sprint as review-ready.';
        }

        if ($status === 'ready_for_review') {
            $recommendations[] = 'Run the operator review pass in the Review Packet UX and keep decisions preview-only.';
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
        return $this->packetValidator()->collectSourceLocators($details) !== [];
    }

    private function packetValidator(): GenealogyReviewPacketValidatorService
    {
        return $this->packetValidator ??= new GenealogyReviewPacketValidatorService;
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
