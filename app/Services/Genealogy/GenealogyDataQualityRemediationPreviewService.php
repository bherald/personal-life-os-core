<?php

namespace App\Services\Genealogy;

class GenealogyDataQualityRemediationPreviewService
{
    public function preview(array $payload, int $index = 0): ?array
    {
        if ($this->operationType($payload) === null) {
            return null;
        }

        $treeId = $this->positiveInt($payload['tree_id'] ?? $payload['target_tree_id'] ?? null);
        $personId = $this->positiveInt($payload['person_id'] ?? $payload['target_person_id'] ?? $payload['identity']['person_id'] ?? null);
        $familyId = $this->positiveInt($payload['family_id'] ?? $payload['target_family_id'] ?? $payload['suspect_family_id'] ?? null);
        $sourceId = $this->positiveInt($payload['source_id'] ?? $payload['target_source_id'] ?? null);
        $question = $this->firstText($payload, [
            'research_question',
            'question',
            'todo',
            'task',
            'proposed_value',
            'evidence_summary',
            'summary',
            'claim_text',
            'claim',
            'statement',
        ]);
        $taskType = $this->taskType($payload['task_type'] ?? $payload['research_task_type'] ?? null);
        $priority = $this->priority($payload['priority'] ?? $payload['task_priority'] ?? null);
        $targetContext = [
            'tree_id' => $treeId,
            'person_id' => $personId,
            'family_id' => $familyId,
            'source_id' => $sourceId,
        ];
        $guards = [
            [
                'name' => 'target_context_present',
                'status' => $treeId !== null || $personId !== null || $familyId !== null || $sourceId !== null ? 'pass' : 'fail',
                'message' => 'A tree, person, family, or source context is required before a research-task preview is useful.',
            ],
            [
                'name' => 'research_question_present',
                'status' => $question !== null ? 'pass' : 'fail',
                'message' => 'A concise research question or evidence summary is required.',
            ],
        ];
        $blocked = collect($guards)->contains(fn (array $guard): bool => ($guard['status'] ?? null) === 'fail');
        $state = [
            'target_context' => $targetContext,
            'task_type' => $taskType,
            'priority' => $priority,
            'question_present' => $question !== null,
        ];
        if ($question !== null) {
            $state['research_question'] = $question;
        }

        return [
            'index' => $index,
            'operation' => 'genealogy_todo_create_preview',
            'operation_type' => 'genealogy_todo_create',
            'target_table' => 'genealogy_research_tasks',
            'status' => $blocked ? 'blocked' : 'preview_only',
            'apply_enabled' => false,
            'mutates_accepted_facts' => false,
            'tree_id' => $treeId,
            'person_id' => $personId,
            'family_id' => $familyId,
            'source_id' => $sourceId,
            'guards' => $guards,
            'current_state' => $state,
            'stale_hash' => $this->hash($state),
            'proposed_effect' => [
                'type' => 'create_genealogy_research_task_preview_only',
                'description' => 'Would convert the unresolved data-quality advisory into a genealogy research task only after a later approved apply path exists.',
                'task_type' => $taskType,
                'priority' => $priority,
                'research_question_present' => $question !== null,
                'research_question' => $question,
                'rows_that_would_be_touched' => [],
            ],
        ];
    }

    private function operationType(array $payload): ?string
    {
        foreach (['operation_type', 'operation', 'type'] as $key) {
            $value = $this->text($payload[$key] ?? null);
            if ($value === 'genealogy_todo_create') {
                return 'genealogy_todo_create';
            }
        }

        foreach (['change_type', 'finding_type'] as $key) {
            $value = $this->text($payload[$key] ?? null);
            if (in_array($value, [
                'data_quality_review',
                'date_quality_review',
                'genealogy_data_quality',
                'genealogy_source_quality',
                'source_quality_review',
            ], true)) {
                return 'genealogy_todo_create';
            }
        }

        return null;
    }

    private function firstText(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function taskType(mixed $value): string
    {
        $value = $this->text($value);

        return in_array($value, ['find_records', 'verify_facts', 'find_relatives', 'analyze_dna', 'suggest_sources', 'transcribe_document'], true)
            ? $value
            : 'verify_facts';
    }

    private function priority(mixed $value): string
    {
        $value = $this->text($value);

        return in_array($value, ['low', 'medium', 'high', 'urgent'], true)
            ? $value
            : 'medium';
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function hash(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
