<?php

namespace App\Services\Genealogy;

class GenealogyIntakeApprovalApplySummaryService
{
    public function summarize(array $result): array
    {
        $appliedPersonChanges = count($result['applied_person_changes'] ?? []);
        $appliedRelationships = count($result['applied_relationships'] ?? []);
        $failed = count($result['failed'] ?? []);
        $skipped = count($result['skipped'] ?? []);
        $errors = $result['errors'] ?? [];
        $errorCount = count($errors);
        $success = $result['success'] ?? false;

        $totalApplied = $appliedPersonChanges + $appliedRelationships;
        $totalAll = $totalApplied + $failed + $skipped;

        $status = $this->resolveStatus($success, $totalApplied, $failed, $skipped, $errorCount, $totalAll);

        return [
            'status' => $status,
            'summary' => $this->buildSummary($status, $appliedPersonChanges, $appliedRelationships, $failed, $skipped, $errorCount),
            'counts' => [
                'applied_person_changes' => $appliedPersonChanges,
                'applied_relationships' => $appliedRelationships,
                'failed' => $failed,
                'skipped' => $skipped,
            ],
            'highlights' => $this->buildHighlights($result, $errors, $failed, $skipped),
            'next_action' => $this->resolveNextAction($status, $failed, $errorCount, $skipped),
        ];
    }

    private function resolveStatus(bool $success, int $totalApplied, int $failed, int $skipped, int $errorCount, int $totalAll): string
    {
        if ($totalAll === 0 && $errorCount === 0) {
            return 'empty';
        }

        if (! $success && $totalApplied === 0) {
            return 'failed';
        }

        if ($totalApplied > 0 && ($failed > 0 || $errorCount > 0 || $skipped > 0)) {
            return 'partial';
        }

        if ($totalApplied > 0 && $failed === 0 && $errorCount === 0) {
            return 'success';
        }

        return 'failed';
    }

    private function buildSummary(string $status, int $personChanges, int $relationships, int $failed, int $skipped, int $errorCount): string
    {
        return match ($status) {
            'empty' => 'No changes to apply.',
            'failed' => sprintf('Apply failed. %d error(s).', $errorCount),
            'partial' => sprintf(
                'Partial apply: %d person change(s), %d relationship(s) applied. %d failed, %d skipped, %d error(s).',
                $personChanges, $relationships, $failed, $skipped, $errorCount
            ),
            'success' => sprintf(
                'Apply complete: %d person change(s), %d relationship(s) applied.',
                $personChanges, $relationships
            ),
        };
    }

    private function buildHighlights(array $result, array $errors, int $failed, int $skipped): array
    {
        $highlights = [];

        if ($failed > 0) {
            $highlights[] = sprintf('%d item(s) failed to apply.', $failed);
        }

        if ($skipped > 0) {
            $highlights[] = sprintf('%d item(s) skipped.', $skipped);
        }

        foreach (array_slice($errors, 0, 3) as $error) {
            $highlights[] = is_string($error) ? $error : (string) ($error['message'] ?? json_encode($error));
        }

        if (! empty($result['audit'])) {
            $highlights[] = 'Audit trail recorded.';
        }

        return $highlights;
    }

    private function resolveNextAction(string $status, int $failed, int $errorCount, int $skipped): string
    {
        return match ($status) {
            'empty' => 'No action needed.',
            'success' => 'Review applied changes for accuracy.',
            'partial' => $failed > 0 || $errorCount > 0
                ? 'Investigate failures and retry.'
                : 'Review skipped items.',
            'failed' => 'Check error details and retry or escalate.',
        };
    }
}
