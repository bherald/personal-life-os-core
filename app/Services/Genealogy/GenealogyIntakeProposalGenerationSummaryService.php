<?php

namespace App\Services\Genealogy;

class GenealogyIntakeProposalGenerationSummaryService
{
    public function summarize(array $result): array
    {
        $persistedPersonChanges = count((array) ($result['persisted_person_changes'] ?? []));
        $persistedRelationships = count((array) ($result['persisted_relationships'] ?? []));
        $failed = count((array) ($result['failed'] ?? []));
        $skipped = count((array) ($result['skipped'] ?? []));
        $errors = count((array) ($result['errors'] ?? []));
        $success = (bool) ($result['success'] ?? false);

        $status = $this->resolveStatus($success, $persistedPersonChanges, $persistedRelationships, $failed, $skipped, $errors);

        return [
            'status' => $status,
            'summary' => $this->buildSummary($status, $persistedPersonChanges, $persistedRelationships, $failed, $skipped, $errors),
            'counts' => [
                'persisted_person_changes' => $persistedPersonChanges,
                'persisted_relationships' => $persistedRelationships,
                'failed' => $failed,
                'skipped' => $skipped,
            ],
            'highlights' => $this->buildHighlights($result),
            'next_action' => $this->buildNextAction($status, $persistedPersonChanges, $persistedRelationships, $failed, $skipped, $errors),
        ];
    }

    private function resolveStatus(bool $success, int $generatedPersonChanges, int $generatedRelationships, int $failed, int $skipped, int $errors): string
    {
        $totalGenerated = $generatedPersonChanges + $generatedRelationships;

        if ($totalGenerated === 0 && $failed === 0 && $skipped === 0 && $errors === 0) {
            return 'empty';
        }

        if (! $success && $totalGenerated === 0) {
            return 'failed';
        }

        if ($totalGenerated > 0 && ($failed > 0 || $errors > 0 || $skipped > 0)) {
            return 'partial';
        }

        if ($totalGenerated > 0 && $failed === 0 && $errors === 0) {
            return 'success';
        }

        return 'failed';
    }

    private function buildSummary(string $status, int $generatedPersonChanges, int $generatedRelationships, int $failed, int $skipped, int $errors): string
    {
        return match ($status) {
            'empty' => 'No proposal rows were generated.',
            'failed' => sprintf('Proposal generation failed. %d error(s).', $errors),
            'partial' => sprintf(
                'Partial generation: %d person proposal(s), %d relationship proposal(s). %d failed, %d skipped, %d error(s).',
                $generatedPersonChanges,
                $generatedRelationships,
                $failed,
                $skipped,
                $errors
            ),
            'success' => sprintf(
                'Generated %d person proposal(s) and %d relationship proposal(s).',
                $generatedPersonChanges,
                $generatedRelationships
            ),
        };
    }

    private function buildHighlights(array $result): array
    {
        $highlights = [];

        foreach (array_slice((array) ($result['persisted_person_changes'] ?? []), 0, 3) as $change) {
            $proposalId = (int) ($change['proposal_id'] ?? 0);
            $changeType = (string) ($change['change_type'] ?? 'change');
            $suffix = ! empty($change['deduplicated']) ? ' (reused)' : '';
            $highlights[] = sprintf('Person proposal #%d: %s%s', $proposalId, $changeType, $suffix);
        }

        foreach (array_slice((array) ($result['failed'] ?? []), 0, 2) as $failed) {
            $highlights[] = (string) ($failed['reason'] ?? ($failed['type'] ?? 'failed'));
        }

        foreach (array_slice((array) ($result['skipped'] ?? []), 0, 2) as $skipped) {
            $highlights[] = (string) ($skipped['reason'] ?? ($skipped['type'] ?? 'skipped'));
        }

        return array_values(array_slice(array_filter($highlights, fn ($value) => trim((string) $value) !== ''), 0, 6));
    }

    private function buildNextAction(string $status, int $generatedPersonChanges, int $generatedRelationships, int $failed, int $skipped, int $errors): string
    {
        return match ($status) {
            'empty' => 'Review the approval draft and selected sections before generating proposals.',
            'failed' => 'Resolve blocking issues before trying proposal generation again.',
            'partial' => $failed > 0 || $errors > 0
                ? 'Review failed and skipped items before sending proposals for human review.'
                : 'Review generated proposals before human approval.',
            'success' => ($generatedPersonChanges + $generatedRelationships) > 0
                ? 'Review the generated proposal rows in the genealogy review queue.'
                : 'Review the approval draft and selected sections before generating proposals.',
        };
    }
}
