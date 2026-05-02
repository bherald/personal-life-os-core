<?php

namespace App\Services\Genealogy;

class GenealogyIntakeProposalPreviewService
{
    private const KINSHIP_TERMS = [
        'parent', 'mother', 'father', 'sibling', 'spouse',
        'son', 'daughter', 'wife', 'husband',
        'brother', 'sister', 'child', 'family',
    ];

    private const EVENT_TERMS = [
        'birth', 'death', 'marriage', 'burial',
        'census', 'military', 'baptism', 'christening',
    ];

    /**
     * Convert a ready packet draft_input into a human-review preview.
     * Pure, side-effect free, deterministic.
     */
    public function preview(array $draftInput): array
    {
        $packetKey = (string) ($draftInput['packet_key'] ?? '');
        $packetLabel = (string) ($draftInput['packet_label'] ?? 'unknown');
        $summaryText = (string) ($draftInput['packet_summary'] ?? '');
        $questions = array_values((array) ($draftInput['questions'] ?? []));
        $anchors = array_values((array) ($draftInput['page_anchors'] ?? []));

        $copySummary = (array) ($draftInput['copy_summary'] ?? []);
        $copied = (int) ($copySummary['copied'] ?? 0);
        $alreadyInPlace = (int) ($copySummary['already_in_place'] ?? 0);
        $blockedConflicts = (int) ($copySummary['blocked_conflicts'] ?? 0);
        $failed = (int) ($copySummary['failed'] ?? 0);

        $reviewDecision = (array) ($draftInput['review_decision'] ?? []);
        $decision = (string) ($reviewDecision['decision'] ?? '');
        $reviewedBy = $reviewDecision['reviewed_by'] ?? null;
        $notes = $reviewDecision['notes'] ?? null;
        $hasNotes = $notes !== null && (string) $notes !== '';

        $questionCount = count($questions);
        $anchorCount = count($anchors);

        $blockingReasons = $this->computeBlockingReasons($decision, $questionCount, $blockedConflicts, $failed);
        $canGenerate = $blockingReasons === [];

        return [
            'packet' => [
                'packet_key' => $packetKey,
                'packet_label' => $packetLabel,
                'is_ready' => $canGenerate,
            ],
            'review_context' => [
                'decision' => $decision,
                'reviewed_by' => $reviewedBy,
                'has_review_notes' => $hasNotes,
                'question_count' => $questionCount,
                'anchor_count' => $anchorCount,
            ],
            'evidence' => [
                'summary_text' => $summaryText,
                'anchors' => $anchors,
                'copy_counts' => [
                    'copied' => $copied,
                    'already_in_place' => $alreadyInPlace,
                    'blocked_conflicts' => $blockedConflicts,
                    'failed' => $failed,
                ],
            ],
            'proposal_outline' => [
                'can_generate' => $canGenerate,
                'blocking_reasons' => $blockingReasons,
                'suggested_sections' => $this->computeSuggestedSections($summaryText, $hasNotes),
            ],
        ];
    }

    private function computeBlockingReasons(string $decision, int $questionCount, int $blockedConflicts, int $failed): array
    {
        $reasons = [];

        if ($decision !== 'approved') {
            $reasons[] = 'review_not_approved';
        }
        if ($questionCount > 0) {
            $reasons[] = 'unresolved_questions';
        }
        if ($blockedConflicts > 0) {
            $reasons[] = 'copy_conflicts';
        }
        if ($failed > 0) {
            $reasons[] = 'copy_failures';
        }

        return $reasons;
    }

    private function computeSuggestedSections(string $summaryText, bool $hasNotes): array
    {
        $sections = [];
        $lowerSummary = mb_strtolower($summaryText);

        if ($summaryText !== '') {
            $sections[] = 'identity';
        }

        if ($this->containsAnyTerm($lowerSummary, self::KINSHIP_TERMS)) {
            $sections[] = 'relationships';
        }

        if ($this->containsAnyTerm($lowerSummary, self::EVENT_TERMS)) {
            $sections[] = 'events';
        }

        $sections[] = 'sources';

        if ($hasNotes) {
            $sections[] = 'notes';
        }

        return $sections;
    }

    private function containsAnyTerm(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }
}
