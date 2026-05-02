<?php

namespace App\Services\Genealogy;

class GenealogyPacketIntakeOrchestratorService
{
    public function __construct(
        private readonly GenealogyPacketSynthesisService $packetSynthesis
    ) {}

    public function orchestratePacket(array $pages, array $context = []): array
    {
        $normalizedPages = $this->normalizePages($pages);

        if ($normalizedPages === []) {
            return [
                'status' => 'empty_packet',
                'proposal_ready' => false,
                'packet_summary' => '',
                'page_anchors' => [],
                'person_candidates' => [],
                'questions' => [],
            ];
        }

        $synthesis = $this->packetSynthesis->synthesizeFromPageSummaries(
            array_map(static fn (array $page): array => [
                'page_number' => $page['page_number'],
                'summary' => $page['summary'],
            ], $normalizedPages),
            $context
        );

        $personCandidates = $this->buildPersonCandidates($normalizedPages, (array) ($context['ft_candidates'] ?? []));
        $questions = $this->buildQuestions($normalizedPages, $personCandidates, $synthesis);
        $proposalReady = $this->isProposalReady($personCandidates, $questions);

        return [
            'status' => $this->determineStatus($personCandidates, $questions, $proposalReady),
            'proposal_ready' => $proposalReady,
            'packet_summary' => trim((string) ($synthesis['packet_summary'] ?? '')) ?: $this->buildFallbackSummary($normalizedPages),
            'page_anchors' => $this->buildPageAnchors($normalizedPages, (array) ($synthesis['page_anchors'] ?? [])),
            'person_candidates' => $personCandidates,
            'questions' => $questions,
        ];
    }

    private function normalizePages(array $pages): array
    {
        $normalized = [];

        foreach ($pages as $page) {
            $pageNumber = (int) ($page['page_number'] ?? 0);
            $summary = trim((string) ($page['summary'] ?? ''));
            if ($pageNumber <= 0 || $summary === '') {
                continue;
            }

            $normalized[] = [
                'page_number' => $pageNumber,
                'summary' => $summary,
                'persons' => array_values(array_filter(
                    array_map(fn ($person) => $this->normalizePerson((array) $person), (array) ($page['persons'] ?? []))
                )),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['page_number'] <=> $b['page_number']);

        return $normalized;
    }

    private function normalizePerson(array $person): ?array
    {
        $name = trim((string) ($person['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'role' => trim((string) ($person['role'] ?? '')),
            'facts' => array_values((array) ($person['facts'] ?? [])),
            'relationships' => array_values((array) ($person['relationships'] ?? [])),
        ];
    }

    private function buildPersonCandidates(array $pages, array $ftCandidates): array
    {
        $aggregated = [];

        foreach ($pages as $page) {
            foreach ($page['persons'] as $person) {
                $key = $this->normalizeName($person['name']);
                if ($key === '') {
                    continue;
                }

                if (! isset($aggregated[$key])) {
                    $aggregated[$key] = [
                        'name' => $person['name'],
                        'pages' => [],
                        'roles' => [],
                        'fact_count' => 0,
                        'relationship_count' => 0,
                    ];
                }

                $aggregated[$key]['pages'][] = $page['page_number'];
                if ($person['role'] !== '') {
                    $aggregated[$key]['roles'][] = $person['role'];
                }
                $aggregated[$key]['fact_count'] += count($person['facts']);
                $aggregated[$key]['relationship_count'] += count($person['relationships']);
            }
        }

        $results = [];

        foreach ($aggregated as $key => $candidate) {
            $matchedFt = $this->findFtCandidate($key, $ftCandidates);
            $pages = array_values(array_unique($candidate['pages']));
            sort($pages);
            $roles = array_values(array_unique(array_filter($candidate['roles'])));
            sort($roles);

            $matchType = $matchedFt !== null ? 'existing_person' : 'new_person';
            $confidence = $matchedFt !== null
                ? 'high'
                : (($candidate['fact_count'] > 0 || $candidate['relationship_count'] > 0) ? 'medium' : 'low');

            $results[] = [
                'name' => $candidate['name'],
                'match_type' => $matchType,
                'confidence' => $confidence,
                'matched_person_id' => $matchedFt['id'] ?? null,
                'matched_person_name' => $matchedFt['display_name'] ?? null,
                'pages' => $pages,
                'roles' => $roles,
                'fact_count' => $candidate['fact_count'],
                'relationship_count' => $candidate['relationship_count'],
            ];
        }

        usort($results, static fn (array $a, array $b): int => [$a['name']] <=> [$b['name']]);

        return $results;
    }

    private function buildQuestions(array $pages, array $personCandidates, array $synthesis): array
    {
        $questions = [];

        foreach ((array) ($synthesis['unresolved_questions'] ?? []) as $question) {
            $question = trim((string) $question);
            if ($question !== '') {
                $questions[] = $question;
            }
        }

        if ($personCandidates === []) {
            $questions[] = 'Does this packet contain enough named evidence to link to an existing FT person?';
        }

        foreach ($personCandidates as $candidate) {
            if ($candidate['match_type'] === 'new_person' && $candidate['confidence'] === 'medium') {
                $questions[] = sprintf(
                    'Should "%s" be created as a new person, or should this stay as evidence only?',
                    $candidate['name']
                );
            }

            if ($candidate['relationship_count'] > 0) {
                $questions[] = sprintf(
                    'Do the relationship clues for "%s" justify a new family link, or should they remain evidence only?',
                    $candidate['name']
                );
            }
        }

        if (count($pages) > 1 && $this->countPagesWithPeople($pages) === 0) {
            $questions[] = 'Should this packet remain as document evidence only until a clearer transcription is available?';
        }

        return array_values(array_unique($questions));
    }

    private function buildFallbackSummary(array $pages): string
    {
        $snippets = array_slice(array_map(
            static fn (array $page): string => sprintf('page %d: %s', $page['page_number'], $page['summary']),
            $pages
        ), 0, 3);

        return 'Packet evidence summary: '.implode('; ', $snippets);
    }

    private function buildPageAnchors(array $pages, array $synthesisAnchors): array
    {
        $anchors = array_values(array_filter(array_map(
            static fn ($anchor): string => trim((string) $anchor),
            $synthesisAnchors
        )));

        if ($anchors === []) {
            $anchors = array_map(
                static fn (array $page): string => sprintf('page %d summary available', $page['page_number']),
                $pages
            );
        }

        return array_values(array_unique($anchors));
    }

    private function isProposalReady(array $personCandidates, array $questions): bool
    {
        if ($questions !== []) {
            return false;
        }

        foreach ($personCandidates as $candidate) {
            if (in_array($candidate['confidence'], ['high', 'medium'], true)) {
                return true;
            }
        }

        return false;
    }

    private function determineStatus(array $personCandidates, array $questions, bool $proposalReady): string
    {
        if ($proposalReady) {
            return 'proposal_ready';
        }

        if ($questions !== []) {
            return 'review_questions';
        }

        return $personCandidates === [] ? 'evidence_only' : 'review_candidates';
    }

    private function findFtCandidate(string $nameKey, array $ftCandidates): ?array
    {
        foreach ($ftCandidates as $candidate) {
            $displayName = trim((string) ($candidate['display_name'] ?? $candidate['name'] ?? ''));
            if ($displayName === '') {
                continue;
            }

            if ($this->normalizeName($displayName) === $nameKey) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }

    private function countPagesWithPeople(array $pages): int
    {
        return count(array_filter($pages, static fn (array $page): bool => $page['persons'] !== []));
    }
}
