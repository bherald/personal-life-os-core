<?php

namespace App\Services\Genealogy;

class GenealogyResearchPlaybookService
{
    /**
     * Choose the playbook that should guide a queue-generated research task.
     *
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    public function chooseForQueueCandidate(array $person, string $questionType, string $researchQuestion): array
    {
        if (! $this->enabled()) {
            return $this->disabledPlaybook();
        }

        $text = $this->normalizedText($questionType.' '.$researchQuestion);
        $pendingHints = (int) ($person['pending_hints'] ?? 0);
        $exhaustion = (float) ($person['research_exhaustion'] ?? $person['research_exhaustion_score'] ?? 0);
        $birthGap = empty($person['birth_date']) || empty($person['birth_place']);
        $deathGap = empty($person['death_date']) || empty($person['death_place']);

        $key = match (true) {
            $this->mentionsIdentityProblem($text) => 'homonym_disambiguation',
            $pendingHints > 0 => 'pending_hint_triage',
            $birthGap || $deathGap => 'vital_record_gap',
            $exhaustion >= 0.65 => 'negative_search_coverage',
            str_contains($text, 'gedcom') || str_contains($text, 'writeback') => 'gedcom_write_safety',
            default => (string) config('genealogy.research_playbooks.default', 'entity_source_audit'),
        };

        return $this->lane($key);
    }

    /**
     * Rebuild playbook context for older queue rows or manually-created tasks.
     *
     * @return array<string, mixed>
     */
    public function fromQueueContract(string $questionType, string $researchQuestion, string $selectionReason): array
    {
        if (! $this->enabled()) {
            return $this->disabledPlaybook();
        }

        if (preg_match('/(?:^|[\s,;])playbook=([a-z0-9_:-]+)/i', $selectionReason, $matches) === 1) {
            return $this->lane(strtolower($matches[1]));
        }

        $text = $this->normalizedText($questionType.' '.$researchQuestion.' '.$selectionReason);

        $key = match (true) {
            $this->mentionsIdentityProblem($text) => 'homonym_disambiguation',
            str_contains($text, 'pending hint') || str_contains($text, 'source lead') => 'pending_hint_triage',
            str_contains($text, 'birth') || str_contains($text, 'death') || str_contains($text, 'marriage') => 'vital_record_gap',
            str_contains($text, 'negative') || str_contains($text, 'exhaustion') => 'negative_search_coverage',
            str_contains($text, 'gedcom') || str_contains($text, 'writeback') => 'gedcom_write_safety',
            default => (string) config('genealogy.research_playbooks.default', 'entity_source_audit'),
        };

        return $this->lane($key);
    }

    /**
     * Append concise lane guidance to the stored research question.
     *
     * @param  array<string, mixed>  $playbook
     */
    public function appendGuidance(string $researchQuestion, array $playbook): string
    {
        if (empty($playbook['enabled']) || empty($playbook['key'])) {
            return trim($researchQuestion);
        }

        $guidance = implode(' ', array_filter([
            'Playbook: '.$this->text($playbook['label'] ?? $playbook['key']).' ('.$this->text($playbook['key']).').',
            'Objective: '.$this->text($playbook['objective'] ?? ''),
            $this->listSentence('First pass tools', $playbook['first_tools'] ?? []),
            $this->listSentence('Required anchors', $playbook['required_anchors'] ?? []),
            'Stop rule: '.$this->text($playbook['stop_rule'] ?? ''),
            'Report rule: '.$this->text($playbook['report_rule'] ?? ''),
        ]));

        return $this->limit(trim($researchQuestion.' '.$guidance), 1800);
    }

    /**
     * Render a prompt block for a queue-mode agent run.
     *
     * @param  array<string, mixed>  $playbook
     */
    public function promptBlock(array $playbook): string
    {
        if (empty($playbook['enabled']) || empty($playbook['key'])) {
            return '';
        }

        $lines = [
            'Research playbook: '.$this->text($playbook['label'] ?? $playbook['key']).' ('.$this->text($playbook['key']).')',
            '- Objective: '.$this->text($playbook['objective'] ?? ''),
            '- First pass tools: '.$this->commaList($playbook['first_tools'] ?? []),
            '- Required anchors: '.$this->commaList($playbook['required_anchors'] ?? []),
            '- Stop rule: '.$this->text($playbook['stop_rule'] ?? ''),
            '- Report rule: '.$this->text($playbook['report_rule'] ?? ''),
            '- Pattern sources: '.$this->commaList($playbook['upstream_sources'] ?? []),
        ];

        return implode("\n", array_filter($lines, static fn (string $line): bool => ! str_ends_with($line, ': ')));
    }

    /**
     * Context payload safe to pass into search planning prompts.
     *
     * @param  array<string, mixed>  $playbook
     * @return array<string, mixed>
     */
    public function contextPayload(array $playbook): array
    {
        if (empty($playbook['enabled']) || empty($playbook['key'])) {
            return [];
        }

        return [
            'key' => $this->text($playbook['key']),
            'label' => $this->text($playbook['label'] ?? $playbook['key']),
            'objective' => $this->text($playbook['objective'] ?? ''),
            'first_tools' => $this->stringList($playbook['first_tools'] ?? [], 8),
            'required_anchors' => $this->stringList($playbook['required_anchors'] ?? [], 8),
            'stop_rule' => $this->text($playbook['stop_rule'] ?? ''),
            'report_rule' => $this->text($playbook['report_rule'] ?? ''),
            'upstream_sources' => $this->stringList($playbook['upstream_sources'] ?? [], 8),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lane(string $key): array
    {
        $key = strtolower(trim($key));
        $lanes = (array) config('genealogy.research_playbooks.lanes', []);
        $lane = is_array($lanes[$key] ?? null) ? $lanes[$key] : null;

        if ($lane === null) {
            $fallback = (string) config('genealogy.research_playbooks.default', 'entity_source_audit');
            $key = isset($lanes[$fallback]) ? $fallback : array_key_first($lanes);
            $lane = is_string($key) && is_array($lanes[$key] ?? null) ? $lanes[$key] : [];
        }

        if ($lane === []) {
            return $this->disabledPlaybook();
        }

        return array_merge($lane, [
            'key' => (string) $key,
            'enabled' => true,
        ]);
    }

    private function enabled(): bool
    {
        return (bool) config('genealogy.research_playbooks.enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    private function disabledPlaybook(): array
    {
        return [
            'key' => null,
            'enabled' => false,
        ];
    }

    private function mentionsIdentityProblem(string $text): bool
    {
        foreach (['identity', 'same person', 'same-person', 'homonym', 'duplicate', 'merge', 'distinguish', 'disambiguat'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizedText(string $text): string
    {
        return strtolower(preg_replace('/\s+/', ' ', trim($text)) ?: '');
    }

    private function listSentence(string $label, mixed $items): ?string
    {
        $values = $this->stringList($items, 8);

        return $values === [] ? null : $label.': '.implode(', ', $values).'.';
    }

    private function commaList(mixed $items): string
    {
        $values = $this->stringList($items, 8);

        return $values === [] ? 'none' : implode(', ', $values);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $items, int $limit): array
    {
        if (! is_array($items)) {
            return [];
        }

        $values = [];
        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }
            $text = $this->text($item);
            if ($text !== '') {
                $values[] = $text;
            }
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    private function text(mixed $value): string
    {
        return $this->limit(preg_replace('/\s+/', ' ', trim((string) ($value ?? ''))) ?: '', 240);
    }

    private function limit(string $text, int $limit): string
    {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) : $text;
    }
}
