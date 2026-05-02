<?php

namespace App\Services\Genealogy;

class GenealogyIntakeBindingSignalsService
{
    public function __construct(
        private readonly GenealogyIntakeDocumentClassifierService $documentClassifier = new GenealogyIntakeDocumentClassifierService
    ) {}

    public function build(array $packet): array
    {
        $previewState = (array) ($packet['preview_state'] ?? []);
        $personCandidates = array_values((array) ($previewState['person_candidates'] ?? []));
        $summary = mb_strtolower(trim((string) ($previewState['packet_summary'] ?? '')));
        $questions = array_values((array) ($previewState['questions'] ?? []));
        $anchors = array_values((array) ($previewState['page_anchors'] ?? []));
        $documents = array_values((array) ($packet['documents'] ?? []));

        $existingMatches = array_values(array_filter(
            $personCandidates,
            static fn (array $candidate): bool => (string) ($candidate['match_type'] ?? '') === 'existing_person'
        ));
        $newPersonCandidates = array_values(array_filter(
            $personCandidates,
            static fn (array $candidate): bool => (string) ($candidate['match_type'] ?? '') === 'new_person'
        ));

        $eventSignals = $this->collectKeywordSignals($summary, [
            'birth' => ['birth', 'born', 'bapt'],
            'death' => ['death', 'died', 'burial', 'buried', 'obituary'],
            'marriage' => ['marriage', 'married', 'wedding'],
            'residence' => ['residence', 'resident', 'census', 'household', 'lived'],
        ]);

        $relationshipSignals = $this->collectKeywordSignals($summary, [
            'parent_child' => ['mother', 'father', 'son', 'daughter', 'child', 'children', 'parent'],
            'sibling' => ['brother', 'sister', 'sibling'],
            'spouse' => ['wife', 'husband', 'spouse', 'widow', 'widower'],
        ]);

        $sourceSignals = $this->collectKeywordSignals($summary, [
            'bible_record' => ['bible', 'family register'],
            'vital_record' => ['certificate', 'record'],
            'census_record' => ['census'],
            'newspaper_record' => ['obituary', 'newspaper'],
        ]);

        return [
            'has_preview' => $previewState !== [],
            'existing_person_match_count' => count($existingMatches),
            'new_person_candidate_count' => count($newPersonCandidates),
            'question_count' => count($questions),
            'anchor_count' => count($anchors),
            'binding_strength' => $this->deriveBindingStrength($existingMatches, $newPersonCandidates, $questions),
            'primary_binding' => $this->derivePrimaryBinding($existingMatches, $newPersonCandidates, $relationshipSignals, $eventSignals),
            'event_signals' => array_keys($eventSignals),
            'relationship_signals' => array_keys($relationshipSignals),
            'source_signals' => array_keys($sourceSignals),
            'document_classifications' => $this->documentClassifier->summarizeDocuments($documents),
            'matched_people' => array_values(array_map(
                static fn (array $candidate): array => [
                    'name' => (string) ($candidate['name'] ?? ''),
                    'matched_person_id' => $candidate['matched_person_id'] ?? null,
                    'matched_person_name' => $candidate['matched_person_name'] ?? null,
                    'confidence' => (string) ($candidate['confidence'] ?? ''),
                ],
                $existingMatches
            )),
        ];
    }

    private function collectKeywordSignals(string $summary, array $map): array
    {
        $signals = [];

        foreach ($map as $signal => $keywords) {
            foreach ($keywords as $keyword) {
                if ($summary !== '' && str_contains($summary, $keyword)) {
                    $signals[$signal] = true;
                    break;
                }
            }
        }

        return $signals;
    }

    private function deriveBindingStrength(array $existingMatches, array $newPersonCandidates, array $questions): string
    {
        if ($existingMatches !== [] && count($questions) <= 1) {
            return 'strong_existing';
        }

        if ($existingMatches !== [] || $newPersonCandidates !== []) {
            return 'moderate_candidate';
        }

        return 'weak_evidence';
    }

    private function derivePrimaryBinding(
        array $existingMatches,
        array $newPersonCandidates,
        array $relationshipSignals,
        array $eventSignals
    ): string {
        if ($existingMatches !== []) {
            return 'existing_person';
        }

        if ($newPersonCandidates !== []) {
            return 'new_person_candidate';
        }

        if ($relationshipSignals !== []) {
            return 'relationship_evidence';
        }

        if ($eventSignals !== []) {
            return 'event_evidence';
        }

        return 'source_only';
    }
}
