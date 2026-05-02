<?php

namespace App\Services\Genealogy;

class GenealogyIntakeWorkspaceOverviewService
{
    public function __construct(
        private readonly GenealogyIntakePacketRowSummaryService $rowService
    ) {}

    private const NEEDS_REVIEW_ACTIONS = [
        'record_review_decision',
        'answer_packet_questions',
        'finish_packet_review',
        'do_followup_review',
        'revisit_deferred_packet',
    ];

    private const NEEDS_COPY_WORK_ACTIONS = [
        'run_reference_copy',
        'resolve_copy_conflicts',
        'rerun_or_fix_copy',
        'generate_packet_preview',
        'verify_packet_contents',
    ];

    /**
     * Build a grouped overview of packet row summaries with counts, priority buckets,
     * and attention buckets. Pure, deterministic, non-mutating.
     */
    public function buildOverview(array $run, array $workspace): array
    {
        $rows = $this->rowService->summarizeAll($run, $workspace);

        $counts = self::buildCounts($rows);
        $priorityBuckets = self::buildPriorityBuckets($rows);
        $attentionBuckets = self::buildAttentionBuckets($rows);

        return [
            'counts' => $counts,
            'priority_buckets' => $priorityBuckets,
            'attention_buckets' => $attentionBuckets,
        ];
    }

    private static function buildCounts(array $rows): array
    {
        $counts = [
            'total_packets' => count($rows),
            'ready' => 0,
            'blocked' => 0,
            'pending' => 0,
            'unknown' => 0,
            'high_priority_actions' => 0,
            'medium_priority_actions' => 0,
            'low_priority_actions' => 0,
            'with_questions' => 0,
            'proposal_ready' => 0,
            'apply_success' => 0,
            'apply_partial' => 0,
            'apply_failed' => 0,
            'apply_empty' => 0,
            'apply_pending' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['stage_status'] ?? 'unknown');
            match ($status) {
                'ready' => $counts['ready']++,
                'blocked' => $counts['blocked']++,
                'pending' => $counts['pending']++,
                default => $counts['unknown']++,
            };

            $priority = (string) ($row['action_priority'] ?? '');
            match ($priority) {
                'high' => $counts['high_priority_actions']++,
                'medium' => $counts['medium_priority_actions']++,
                'low' => $counts['low_priority_actions']++,
                default => null,
            };

            if (($row['question_count'] ?? 0) > 0) {
                $counts['with_questions']++;
            }

            if (! empty($row['proposal_ready'])) {
                $counts['proposal_ready']++;
            }

            $applyStatus = (string) ($row['approval_apply_status'] ?? '');
            match ($applyStatus) {
                'success' => $counts['apply_success']++,
                'partial' => $counts['apply_partial']++,
                'failed' => $counts['apply_failed']++,
                'empty' => $counts['apply_empty']++,
                default => $counts['apply_pending']++,
            };
        }

        return $counts;
    }

    private static function buildPriorityBuckets(array $rows): array
    {
        $buckets = ['high' => [], 'medium' => [], 'low' => [], 'none' => []];

        foreach ($rows as $row) {
            $priority = (string) ($row['action_priority'] ?? '');
            $bucket = isset($buckets[$priority]) ? $priority : 'none';
            $buckets[$bucket][] = $row;
        }

        return $buckets;
    }

    private static function buildAttentionBuckets(array $rows): array
    {
        $buckets = [
            'needs_apply_review' => [],
            'needs_review' => [],
            'needs_copy_work' => [],
            'ready_for_proposals' => [],
            'other' => [],
        ];

        foreach ($rows as $row) {
            $actionCode = (string) ($row['action_code'] ?? '');
            $applyStatus = (string) ($row['approval_apply_status'] ?? '');

            if (in_array($applyStatus, ['partial', 'failed'], true)) {
                $buckets['needs_apply_review'][] = $row;
            } elseif (in_array($actionCode, self::NEEDS_REVIEW_ACTIONS, true)) {
                $buckets['needs_review'][] = $row;
            } elseif (in_array($actionCode, self::NEEDS_COPY_WORK_ACTIONS, true)) {
                $buckets['needs_copy_work'][] = $row;
            } elseif ($actionCode === 'generate_proposals' && $applyStatus !== 'success') {
                $buckets['ready_for_proposals'][] = $row;
            } else {
                $buckets['other'][] = $row;
            }
        }

        return $buckets;
    }
}
