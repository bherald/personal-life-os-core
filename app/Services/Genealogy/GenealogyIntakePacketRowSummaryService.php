<?php

namespace App\Services\Genealogy;

class GenealogyIntakePacketRowSummaryService
{
    public function __construct(
        private readonly GenealogyIntakeSelectedPacketComposer $composer
    ) {}

    /**
     * Produce compact row summaries for all packets in a run.
     * Preserves packet order from run['packets'].
     */
    public function summarizeAll(array $run, array $workspace): array
    {
        $packets = array_values((array) ($run['packets'] ?? []));
        $rows = [];

        foreach ($packets as $packet) {
            $rows[] = $this->summarizeOne($run, $workspace, $packet);
        }

        return $rows;
    }

    /**
     * Produce a compact row summary for a single packet.
     * Falls back to raw-packet-derived fields when composer returns null.
     */
    public function summarizeOne(array $run, array $workspace, array $packet): array
    {
        $key = (string) ($packet['packet_key'] ?? '');
        $label = (string) ($packet['packet_label'] ?? '');

        $composed = ($key !== '' || $label !== '')
            ? $this->composer->compose($run, $workspace, $key !== '' ? $key : null, $label !== '' ? $label : null)
            : null;

        return self::buildRow($packet, $composed);
    }

    private static function buildRow(array $packet, ?array $composed): array
    {
        $previewState = (array) ($packet['preview_state'] ?? []);
        $copySummary = (array) ($packet['reference_copy_execution']['execution']['summary'] ?? []);
        $applyState = (array) ($packet['approval_apply_state'] ?? []);

        return [
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
            'packet_label' => (string) ($packet['packet_label'] ?? ''),
            'document_count' => count((array) ($packet['documents'] ?? [])),
            'has_preview_state' => $previewState !== [],
            'has_review_decision' => isset($packet['review_decision']),
            'copy_status' => self::deriveCopyStatus($copySummary),
            'stage_status' => (string) ($composed['stage']['status'] ?? 'unknown'),
            'stage_reason' => ($composed['stage']['reason'] ?? null) ?: null,
            'severity' => (string) ($composed['presentation']['severity'] ?? 'unknown'),
            'headline' => (string) ($composed['presentation']['headline'] ?? ''),
            'action_code' => ($composed['action']['action_code'] ?? null) ?: null,
            'action_label' => ($composed['action']['label'] ?? null) ?: null,
            'action_priority' => ($composed['action']['priority'] ?? null) ?: null,
            'question_count' => count((array) ($previewState['questions'] ?? [])),
            'proposal_ready' => ! empty($previewState['proposal_ready']),
            'has_draft_entry' => isset($composed['draft_entry']),
            'has_approval_apply_state' => $applyState !== [],
            'approval_apply_status' => self::normalizeApplyStatus($applyState),
            'approval_apply_updated_at' => self::normalizeApplyUpdatedAt($applyState),
        ];
    }

    private static function deriveCopyStatus(array $copySummary): string
    {
        if ($copySummary === []) {
            return 'missing';
        }

        if (($copySummary['blocked_conflicts'] ?? 0) > 0 || ($copySummary['failed'] ?? 0) > 0) {
            return 'blocked';
        }

        return 'complete';
    }

    private static function normalizeApplyStatus(array $applyState): ?string
    {
        $status = trim((string) ($applyState['status'] ?? ''));

        return $status !== '' ? $status : null;
    }

    private static function normalizeApplyUpdatedAt(array $applyState): ?string
    {
        $updatedAt = trim((string) ($applyState['updated_at'] ?? ''));

        return $updatedAt !== '' ? $updatedAt : null;
    }
}
