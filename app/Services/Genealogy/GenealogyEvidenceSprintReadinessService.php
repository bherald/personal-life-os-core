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
        $readiness = [
            'target_packet_count' => self::TARGET_PACKETS,
            'remaining_to_target' => $remaining,
            'has_operator_boundary' => $summary['operator_boundary_packets'] > 0,
            'needs_operator_boundary' => $totalRows === 0 || $summary['operator_boundary_packets'] === 0,
            'mutation_guard_ok' => $summary['mutating_preview_packets'] === 0,
            'ready_for_five_packet_review' => $summary['source_backed_pending'] >= self::TARGET_PACKETS
                && $summary['mutating_preview_packets'] === 0
                && $summary['malformed_details'] === 0,
        ];

        if ($summary['malformed_details'] > 0) {
            $errors[] = "{$summary['malformed_details']} packet row(s) have malformed details JSON.";
        }

        if ($summary['mutating_preview_packets'] > 0) {
            $errors[] = "{$summary['mutating_preview_packets']} packet row(s) advertise mutating apply_preview behavior.";
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

    private function emptyPayload(Carbon $generatedAt, int $days, array $errors, string $status): array
    {
        $summary = $this->initialSummary();
        $readiness = [
            'target_packet_count' => self::TARGET_PACKETS,
            'remaining_to_target' => self::TARGET_PACKETS,
            'has_operator_boundary' => false,
            'needs_operator_boundary' => true,
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
            if ($sourceBacked) {
                $summary['source_backed_packets']++;
            }

            if ($rowStatus === 'pending') {
                $summary['pending_packets']++;
                if ($sourceBacked) {
                    $summary['source_backed_pending']++;
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

            if ($this->isPreviewOnly($details)) {
                $summary['preview_only_packets']++;
            }

            if (($details['apply_preview']['mutates_accepted_facts'] ?? null) === true) {
                $summary['mutating_preview_packets']++;
            }

            if ($this->hasIdentity($details)) {
                $summary['packets_with_identity']++;
            }

            if (($details['privacy']['cleared'] ?? null) === true) {
                $summary['packets_with_privacy_clearance']++;
            }

            if ($this->hasClaims($details)) {
                $summary['packets_with_claims']++;
            }

            if (($details['decision_log'] ?? []) !== []) {
                $summary['packets_with_decision_log']++;
                foreach ($this->reasonCodes($details['decision_log']) as $reasonCode) {
                    $reasonCounts[$reasonCode] = ($reasonCounts[$reasonCode] ?? 0) + 1;
                }
            }

            if ($this->hasOperatorBoundary($details)) {
                $summary['operator_boundary_packets']++;
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
            'operator_boundary_packets' => 0,
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
        foreach (['sources', 'source_locators', 'source_locator', 'evidence_sources'] as $key) {
            $value = $details[$key] ?? null;
            if (is_array($value) && $value !== []) {
                return true;
            }

            if (is_string($value) && trim($value) !== '') {
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
            || ($preview['mode'] ?? null) === 'preview_only'
            || ($preview['mutation_mode'] ?? null) === 'preview_only';
    }

    private function hasIdentity(array $details): bool
    {
        return is_array($details['identity'] ?? null) && $details['identity'] !== [];
    }

    private function hasClaims(array $details): bool
    {
        return is_array($details['claims'] ?? null) && $details['claims'] !== [];
    }

    private function hasOperatorBoundary(array $details): bool
    {
        $boundary = $details['sprint']['boundary_label']
            ?? $details['operator_boundary']
            ?? $details['boundary_label']
            ?? null;

        return is_string($boundary) && trim($boundary) !== '';
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
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
