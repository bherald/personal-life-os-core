<?php

namespace App\Services\Genealogy;

class GenealogyIntakeApprovalDraftPreviewService
{
    public function __construct(
        private readonly GenealogyIntakeProposalPersistencePlannerService $planner,
        private readonly GenealogyIntakeApprovalDraftFormatterService $formatter
    ) {}

    /**
     * Build a read-only approval-draft preview from one ready packet plus human-selected context.
     * Pure, deterministic, and non-mutating.
     */
    public function preview(array $proposalPreview, array $draftInput, array $context = []): array
    {
        $normalizedContext = [
            'approved_sections' => $this->normalizeSections((array) ($context['approved_sections'] ?? [])),
            'person_id' => $this->normalizePositiveInt($context['person_id'] ?? null),
            'tree_id' => $this->normalizePositiveInt($context['tree_id'] ?? null),
            'relationship_type' => trim((string) ($context['relationship_type'] ?? '')),
            'related_person_id' => $this->normalizePositiveInt($context['related_person_id'] ?? null),
        ];

        $plan = $this->planner->plan($proposalPreview, $draftInput, $normalizedContext);

        return [
            'packet' => [
                'packet_key' => (string) ($draftInput['packet_key'] ?? $proposalPreview['packet']['packet_key'] ?? ''),
                'packet_label' => (string) ($draftInput['packet_label'] ?? $proposalPreview['packet']['packet_label'] ?? 'unknown'),
            ],
            'context' => $normalizedContext,
            'plan' => $plan,
            'formatted' => $this->formatter->format($plan),
        ];
    }

    private function normalizeSections(array $sections): array
    {
        $normalized = [];

        foreach ($sections as $section) {
            $value = trim((string) $section);
            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }

        return $normalized;
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
