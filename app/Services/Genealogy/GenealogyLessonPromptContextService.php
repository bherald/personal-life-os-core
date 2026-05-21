<?php

namespace App\Services\Genealogy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenealogyLessonPromptContextService
{
    private const LESSON_MEMORY_TYPES = [
        'research_process_lesson',
        'document_interpretation_lesson',
        'source_capture_lesson',
        'identity_decision_lesson',
        'offline_workflow_lesson',
    ];

    /**
     * Build a prompt-safe lesson context block.
     *
     * Supported options:
     * - title_limit: max chars for lesson titles (default 100, <=0 disables truncation)
     * - lesson_limit: max chars for lesson text (default 280, <=0 disables truncation)
     * - fallback_limit: fallback row limit when terms produce no matches (default 2)
     */
    public function build(array $context, array $terms = [], int $limit = 4, string $heading = 'Reusable Genea lessons:', array $options = []): string
    {
        $treeId = (int) ($context['tree_id'] ?? $context['family_tree_id'] ?? 0);
        if ($treeId < 1 || ! Schema::hasTable('agent_semantic_memory')) {
            return '';
        }

        $titleLimit = (int) ($options['title_limit'] ?? $options['titleLimit'] ?? 100);
        $lessonLimit = (int) ($options['lesson_limit'] ?? $options['lessonLimit'] ?? 280);
        $fallbackLimit = (int) ($options['fallback_limit'] ?? $options['fallbackLimit'] ?? 2);

        $terms = $this->normalizeTerms($terms);
        $rows = $this->loadRows($treeId, $terms, $limit);
        if ($rows === [] && $terms !== []) {
            $rows = $this->loadRows($treeId, [], max(1, min($fallbackLimit, $limit)));
        }

        if ($rows === []) {
            return '';
        }

        $lines = [
            '',
            $heading,
            'Process guardrails only; do not treat these lessons as source evidence.',
        ];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row->fact_value ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $lessonPayload = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            $title = trim((string) ($payload['title'] ?? $row->fact_key ?? 'Genea lesson'));
            $lesson = trim((string) ($payload['lesson'] ?? ''));
            $tags = is_array($lessonPayload['tags'] ?? null)
                ? array_slice(array_values(array_filter(array_map('strval', $lessonPayload['tags']))), 0, 5)
                : [];

            if ($lesson === '') {
                continue;
            }

            $tagText = $tags !== [] ? ' tags='.implode(',', $tags) : '';

            $titleText = $titleLimit > 0 ? $this->compact($title, $titleLimit) : $title;
            $lessonText = $lessonLimit > 0 ? $this->compact($lesson, $lessonLimit) : $lesson;

            $lines[] = '- ['.(string) ($row->fact_type ?? 'lesson').'] '.$titleText.': '.$lessonText.$tagText;
        }

        return count($lines) > 3 ? "\n".implode("\n", $lines)."\n" : '';
    }

    /**
     * @return list<object>
     */
    private function loadRows(int $treeId, array $terms, int $limit): array
    {
        $limit = max(1, min(8, $limit));
        $where = [
            "entity_type = 'genealogy_tree'",
            'entity_id = ?',
            'fact_type IN ('.implode(', ', array_fill(0, count(self::LESSON_MEMORY_TYPES), '?')).')',
        ];
        $params = [$treeId, ...self::LESSON_MEMORY_TYPES];

        if ($terms !== []) {
            $termClauses = [];
            foreach (array_slice($terms, 0, 10) as $term) {
                $termClauses[] = '(fact_key LIKE ? OR fact_value LIKE ?)';
                $like = '%'.$term.'%';
                $params[] = $like;
                $params[] = $like;
            }
            $where[] = '('.implode(' OR ', $termClauses).')';
        }

        $params[] = $limit;

        return DB::select(
            'SELECT id, fact_type, fact_key, fact_value, confidence, updated_at
             FROM agent_semantic_memory
             WHERE '.implode(' AND ', $where).'
             ORDER BY confidence DESC, updated_at DESC, id DESC
             LIMIT ?',
            $params
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeTerms(array $terms): array
    {
        return array_slice(array_values(array_unique(array_filter(
            array_map(fn (mixed $term): string => $this->compact((string) $term, 80), $terms),
            static fn (string $term): bool => $term !== '' && mb_strlen($term) >= 3
        ))), 0, 12);
    }

    private function compact(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', Str::ascii($text)) ?? '');

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'...' : $text;
    }
}
