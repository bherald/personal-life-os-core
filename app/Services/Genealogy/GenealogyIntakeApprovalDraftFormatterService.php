<?php

namespace App\Services\Genealogy;

class GenealogyIntakeApprovalDraftFormatterService
{
    /**
     * Convert a conservative persistence plan into a compact operator-facing summary.
     * Pure formatting only: no DB, no side effects, no mutation.
     */
    public function format(array $plan): array
    {
        $counts = [
            'existing_person_changes' => count((array) ($plan['existing_person_changes'] ?? [])),
            'relationship_proposals' => count((array) ($plan['relationship_proposals'] ?? [])),
            'skipped' => count((array) ($plan['skipped'] ?? [])),
            'blocked' => count((array) ($plan['blocked'] ?? [])),
        ];

        $status = $this->deriveStatus($plan, $counts);

        return [
            'status' => $status,
            'summary' => $this->buildSummary($status, $counts),
            'counts' => $counts,
            'highlights' => $this->buildHighlights($plan),
            'next_action' => $this->buildNextAction($status, $counts),
        ];
    }

    private function deriveStatus(array $plan, array $counts): string
    {
        $ready = (bool) ($plan['ready'] ?? false);
        $blockedReasons = array_values((array) ($plan['blocked_reasons'] ?? []));

        if (! $ready && $blockedReasons !== []) {
            return 'blocked';
        }

        if (
            $counts['existing_person_changes'] === 0
            && $counts['relationship_proposals'] === 0
            && $counts['skipped'] === 0
            && $counts['blocked'] === 0
        ) {
            return 'empty';
        }

        return 'ready';
    }

    private function buildSummary(string $status, array $counts): string
    {
        if ($status === 'blocked') {
            return 'Planning is blocked until prerequisite issues are resolved.';
        }

        if ($status === 'empty') {
            return 'No persistence actions are currently planned.';
        }

        $parts = [];
        if ($counts['existing_person_changes'] > 0) {
            $parts[] = $counts['existing_person_changes'].' existing-person change'.($counts['existing_person_changes'] === 1 ? '' : 's');
        }
        if ($counts['relationship_proposals'] > 0) {
            $parts[] = $counts['relationship_proposals'].' relationship proposal'.($counts['relationship_proposals'] === 1 ? '' : 's');
        }
        if ($counts['blocked'] > 0) {
            $parts[] = $counts['blocked'].' blocked item'.($counts['blocked'] === 1 ? '' : 's');
        }
        if ($counts['skipped'] > 0) {
            $parts[] = $counts['skipped'].' skipped item'.($counts['skipped'] === 1 ? '' : 's');
        }

        return 'Planned: '.implode(', ', $parts).'.';
    }

    private function buildHighlights(array $plan): array
    {
        $highlights = [];

        foreach ((array) ($plan['existing_person_changes'] ?? []) as $change) {
            $personId = (int) ($change['person_id'] ?? 0);
            $changeType = (string) ($change['change_type'] ?? 'change');
            $highlights[] = 'Existing person #'.$personId.': '.$changeType;
        }

        foreach ((array) ($plan['relationship_proposals'] ?? []) as $proposal) {
            $type = (string) ($proposal['relationship_type'] ?? 'relationship');
            $relatedPersonId = (int) ($proposal['related_person_id'] ?? 0);
            $highlights[] = 'Relationship proposal: '.$type.' -> person #'.$relatedPersonId;
        }

        foreach ((array) ($plan['blocked'] ?? []) as $blocked) {
            $section = trim((string) ($blocked['section'] ?? ''));
            $reason = trim((string) ($blocked['reason'] ?? ($blocked['type'] ?? 'blocked')));
            $highlights[] = $section !== ''
                ? 'Blocked '.$section.': '.$reason
                : 'Blocked: '.$reason;
        }

        foreach ((array) ($plan['skipped'] ?? []) as $skipped) {
            $section = trim((string) ($skipped['section'] ?? ''));
            $reason = trim((string) ($skipped['reason'] ?? ($skipped['type'] ?? 'skipped')));
            $highlights[] = $section !== ''
                ? 'Skipped '.$section.': '.$reason
                : 'Skipped: '.$reason;
        }

        foreach ((array) ($plan['blocked_reasons'] ?? []) as $reason) {
            $value = trim((string) $reason);
            if ($value !== '') {
                $highlights[] = 'Run blocked: '.$value;
            }
        }

        return array_values(array_slice(array_unique($highlights), 0, 6));
    }

    private function buildNextAction(string $status, array $counts): string
    {
        if ($status === 'blocked') {
            return 'Resolve blocking issues before previewing persistence actions.';
        }

        if ($status === 'empty') {
            return 'Select approved sections or targets to build a persistence plan.';
        }

        if ($counts['blocked'] > 0) {
            return 'Review blocked sections before applying any approved changes.';
        }

        if ($counts['existing_person_changes'] > 0 || $counts['relationship_proposals'] > 0) {
            return 'Review the planned changes and relationships before apply is enabled.';
        }

        return 'Review skipped items and refine the approval draft if needed.';
    }
}
