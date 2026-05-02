<?php

namespace App\Services\Genealogy;

/**
 * Pure, read-only signal extractor for genealogy packet text.
 *
 * Takes raw extracted text or a page summary string and derives structured
 * candidate signals — persons, events, relationships, and source type hints —
 * using regex pattern matching only. No DB access, no I/O, no constructor
 * dependencies. Deterministic and side-effect-free.
 *
 * Output person_signals are compatible with the persons[] array expected by
 * GenealogyPacketIntakeOrchestratorService (via toParsedPersons()).
 */
class GenealogyPacketTextSignalService
{
    // Vital-event keywords mapped to canonical event types
    private const BIRTH_KEYWORDS = [
        'born', 'birth', 'baptized', 'christened', 'baptism',
    ];

    private const DEATH_KEYWORDS = [
        'died', 'death', 'deceased', 'buried', 'burial', 'interred',
    ];

    private const MARRIAGE_KEYWORDS = [
        'married', 'marriage',
    ];

    // Relationship phrases → canonical type
    private const RELATIONSHIP_PHRASES = [
        'spouse' => ['wife of', 'husband of', 'married to', 'spouse of'],
        'child' => ['son of', 'daughter of', 'child of'],
        'parent' => ['father of', 'mother of', 'parent of'],
        'sibling' => ['brother of', 'sister of', 'sibling of'],
    ];

    // Source-type hint phrases (first match per type wins)
    private const SOURCE_TYPE_PHRASES = [
        'bible_record' => ['family bible', 'bible record', 'bible register', 'family register'],
        'census' => ['census', 'enumerator'],
        'vital_record' => ['birth certificate', 'death certificate', 'marriage certificate', 'birth record', 'death record'],
        'legal' => ['probate', 'last will', 'deed of trust', 'land deed'],
        'church' => ['baptismal record', 'church record', 'parish register', 'vestry book'],
        'military' => ['pension record', 'muster roll', 'service record', 'enlistment record'],
        'obituary' => ['obituary', 'death notice', 'obit'],
    ];

    // First words that indicate a capitalized phrase is NOT a person name
    private const NAME_STOP_FIRST_WORDS = [
        'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
        'January', 'February', 'March', 'April', 'June', 'July', 'August',
        'September', 'October', 'November', 'December',
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday',
        'The', 'This', 'That', 'These', 'Those', 'His', 'Her', 'Their',
        'New', 'Old', 'East', 'West', 'North', 'South', 'Upper', 'Lower', 'Great',
        'United', 'American', 'National',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Extract all signal types from a block of text.
     *
     * @param  string  $text  Raw extracted text or page/packet summary
     * @param  array  $context  Optional: 'source_name' for additional source hints
     * @return array{
     *     person_signals: array,
     *     event_signals: array,
     *     relationship_signals: array,
     *     source_signals: array,
     * }
     */
    public function extractSignals(string $text, array $context = []): array
    {
        $text = $this->normalizeText($text);
        if ($text === '') {
            return $this->emptySignals();
        }

        $eventSignals = $this->extractEventSignals($text);
        $relationshipSignals = $this->extractRelationshipSignals($text);
        $sourceSignals = $this->extractSourceSignals($text, $context);
        $personSignals = $this->buildPersonSignals($text, $eventSignals, $relationshipSignals);

        return [
            'person_signals' => $personSignals,
            'event_signals' => $eventSignals,
            'relationship_signals' => $relationshipSignals,
            'source_signals' => $sourceSignals,
        ];
    }

    /**
     * Convert person_signals to the persons[] format expected by
     * GenealogyPacketIntakeOrchestratorService::orchestratePacket().
     */
    public function toParsedPersons(array $signals): array
    {
        return array_map(static fn (array $s): array => [
            'name' => $s['name'],
            'role' => $s['role'],
            'facts' => $s['facts'],
            'relationships' => $s['relationships'],
        ], $signals['person_signals'] ?? []);
    }

    // -------------------------------------------------------------------------
    // Event signal extraction
    // -------------------------------------------------------------------------

    private function extractEventSignals(string $text): array
    {
        $keywordMap = [];
        foreach (self::BIRTH_KEYWORDS as $kw) {
            $keywordMap[$kw] = 'birth';
        }
        foreach (self::DEATH_KEYWORDS as $kw) {
            $keywordMap[$kw] = 'death';
        }
        foreach (self::MARRIAGE_KEYWORDS as $kw) {
            $keywordMap[$kw] = 'marriage';
        }

        $events = [];

        foreach ($keywordMap as $keyword => $type) {
            $pattern = '/\b'.preg_quote($keyword, '/').'\b/iu';
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $offset = $match[1];
                $kwLen = strlen($match[0]);
                $preText = substr($text, max(0, $offset - 80), min(80, $offset));
                $postText = substr($text, $offset + $kwLen, 120);

                [$dateRaw, $year] = $this->findDateNear($preText.' '.$postText);
                if ($year === '') {
                    continue; // year required to emit an event signal
                }

                $events[] = [
                    'type' => $type,
                    'date_raw' => $dateRaw,
                    'year' => $year,
                    'place' => $this->findPlaceNear($postText),
                    'person_ref' => $this->extractTrailingName($preText),
                ];
            }
        }

        return $this->deduplicateEventSignals($events);
    }

    // -------------------------------------------------------------------------
    // Relationship signal extraction
    // -------------------------------------------------------------------------

    private function extractRelationshipSignals(string $text): array
    {
        $signals = [];

        foreach (self::RELATIONSHIP_PHRASES as $type => $phrases) {
            foreach ($phrases as $phrase) {
                $pattern = '/(?P<before>.{0,80})\b'.preg_quote($phrase, '/').'\s+(?P<after>.{0,80})/iu';
                preg_match_all($pattern, $text, $allMatches, PREG_SET_ORDER);

                foreach ($allMatches as $m) {
                    $personA = $this->extractTrailingName($m['before']);
                    $personB = $this->extractLeadingName($m['after']);

                    if ($personA !== '' && $personB !== '') {
                        $signals[] = [
                            'type' => $type,
                            'person_a' => $personA,
                            'person_b' => $personB,
                            'context_phrase' => $phrase,
                        ];
                    }
                }
            }
        }

        return $signals;
    }

    // -------------------------------------------------------------------------
    // Source signal extraction
    // -------------------------------------------------------------------------

    private function extractSourceSignals(string $text, array $context = []): array
    {
        $lower = mb_strtolower($text);
        $signals = [];

        foreach (self::SOURCE_TYPE_PHRASES as $docType => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($lower, $phrase)) {
                    $signals[] = [
                        'document_type_hint' => $docType,
                        'confidence' => 'medium',
                        'matched_phrase' => $phrase,
                    ];
                    break; // one signal per document type
                }
            }
        }

        // Source name context hint: if caller passes 'source_name', check extension
        $sourceName = (string) ($context['source_name'] ?? '');
        if ($sourceName !== '') {
            $ext = strtolower(pathinfo($sourceName, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'gif'], true)) {
                if (! $this->hasSourceType('photograph', $signals)) {
                    $signals[] = [
                        'document_type_hint' => 'photograph',
                        'confidence' => 'low',
                        'matched_phrase' => 'image file extension',
                    ];
                }
            }
        }

        return $signals;
    }

    // -------------------------------------------------------------------------
    // Person signal assembly
    // -------------------------------------------------------------------------

    private function buildPersonSignals(string $text, array $eventSignals, array $relationshipSignals): array
    {
        $nameMap = [];

        // Collect names anchored by events
        foreach ($eventSignals as $event) {
            if ($event['person_ref'] !== '') {
                $key = $this->normalizeName($event['person_ref']);
                $nameMap[$key] ??= $event['person_ref'];
            }
        }

        // Collect names anchored by relationships
        foreach ($relationshipSignals as $rel) {
            foreach (['person_a', 'person_b'] as $field) {
                if ($rel[$field] !== '') {
                    $key = $this->normalizeName($rel[$field]);
                    $nameMap[$key] ??= $rel[$field];
                }
            }
        }

        // Boost: standalone capitalized name pairs that appear 2+ times in the text
        if (preg_match_all('/\b([A-Z][a-z]{1,20}(?:\s+[A-Z][a-z]{0,20}){1,3})\b/u', $text, $m)) {
            foreach (array_count_values($m[1]) as $candidate => $count) {
                if ($count >= 2 && ! $this->isNameStopCandidate($candidate)) {
                    $key = $this->normalizeName($candidate);
                    $nameMap[$key] ??= $candidate;
                }
            }
        }

        $signals = [];
        foreach ($nameMap as $key => $name) {
            $facts = $this->buildPersonFacts($key, $eventSignals);
            $relationships = $this->buildPersonRelationships($key, $relationshipSignals);

            $signals[] = [
                'name' => $name,
                'role' => $this->inferPersonRole($key, $relationshipSignals),
                'facts' => $facts,
                'relationships' => $relationships,
                'confidence' => $this->scoreConfidence($facts, $relationships),
            ];
        }

        // Sort by descending signal density so highest-evidence persons appear first
        usort($signals, static fn (array $a, array $b): int => (count($b['facts']) + count($b['relationships'])) <=> (count($a['facts']) + count($a['relationships']))
        );

        return $signals;
    }

    // -------------------------------------------------------------------------
    // Name / date / place helpers
    // -------------------------------------------------------------------------

    private function findDateNear(string $text): array
    {
        // Full calendar date: "March 5, 1889" or "5 March 1889"
        $monthNames = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
        if (preg_match('/\b(?:'.$monthNames.')\s+\d{1,2}[,\s]+(\d{4})/i', $text, $m)) {
            return [$m[0], $m[1]];
        }
        if (preg_match('/\b\d{1,2}\s+(?:'.$monthNames.')\s+(\d{4})\b/i', $text, $m)) {
            return [$m[0], $m[1]];
        }

        // GEDCOM qualifier: "ABT 1812", "BEF 1800", "AFT 1850", "BET 1800 AND 1850"
        if (preg_match('/\b(?:ABT|AFT|BEF|BET|CAL|EST)\s+(\d{4})\b/i', $text, $m)) {
            return [$m[0], $m[1]];
        }

        // Bare 4-digit year (1300–2029)
        if (preg_match('/\b(1[3-9]\d{2}|20[0-2]\d)\b/', $text, $m)) {
            return [$m[0], $m[0]];
        }

        return ['', ''];
    }

    private function findPlaceNear(string $postText): string
    {
        // "in <Capitalized Place>" pattern immediately following the event keyword + date
        if (preg_match('/\bin\s+([A-Z][a-zA-Z\s,]+?)(?:\.|;|,\s*\d|\s+(?:and|the|born|died|married|age)\b)/iu', $postText, $m)) {
            $place = trim($m[1], " \t,.");
            if (mb_strlen($place) >= 3 && mb_strlen($place) <= 60) {
                return $place;
            }
        }

        return '';
    }

    /**
     * Extract the last proper-name sequence at the END of a text segment.
     * Used to find the subject BEFORE a keyword or relationship phrase.
     */
    private function extractTrailingName(string $text): string
    {
        $text = rtrim($text, " \t,;.");
        if (! preg_match('/\b([A-Z][a-z]{1,20}(?:\s+[A-Z][a-z]{0,20}){1,3})\s*$/u', $text, $m)) {
            return '';
        }

        return $this->isNameStopCandidate($m[1]) ? '' : $m[1];
    }

    /**
     * Extract the first proper-name sequence at the START of a text segment.
     * Used to find the object AFTER a relationship phrase.
     */
    private function extractLeadingName(string $text): string
    {
        $text = ltrim($text, " \t,;.");
        if (! preg_match('/^([A-Z][a-z]{1,20}(?:\s+[A-Z][a-z]{0,20}){1,3})\b/u', $text, $m)) {
            return '';
        }

        return $this->isNameStopCandidate($m[1]) ? '' : $m[1];
    }

    private function isNameStopCandidate(string $name): bool
    {
        if (mb_strlen($name) < 4) {
            return true;
        }
        $firstWord = explode(' ', $name)[0];

        return in_array($firstWord, self::NAME_STOP_FIRST_WORDS, true);
    }

    // -------------------------------------------------------------------------
    // Person signal assembly helpers
    // -------------------------------------------------------------------------

    private function buildPersonFacts(string $nameKey, array $eventSignals): array
    {
        $facts = [];
        $fieldMap = ['birth' => 'birth_year', 'death' => 'death_year', 'marriage' => 'marriage_year'];

        foreach ($eventSignals as $event) {
            if ($this->normalizeName($event['person_ref']) !== $nameKey || $event['year'] === '') {
                continue;
            }
            $field = $fieldMap[$event['type']] ?? null;
            if ($field === null) {
                continue;
            }
            $facts[] = ['field' => $field, 'value' => $event['year']];
            if ($event['place'] !== '') {
                $facts[] = ['field' => str_replace('_year', '_place', $field), 'value' => $event['place']];
            }
        }

        return $facts;
    }

    private function buildPersonRelationships(string $nameKey, array $relSignals): array
    {
        $rels = [];

        foreach ($relSignals as $rel) {
            if ($this->normalizeName($rel['person_a']) === $nameKey) {
                $rels[] = ['type' => $rel['type'], 'name' => $rel['person_b']];
            } elseif ($this->normalizeName($rel['person_b']) === $nameKey) {
                $rels[] = ['type' => $this->invertRelType($rel['type']), 'name' => $rel['person_a']];
            }
        }

        return $rels;
    }

    private function inferPersonRole(string $nameKey, array $relSignals): string
    {
        foreach ($relSignals as $rel) {
            if ($this->normalizeName($rel['person_b']) === $nameKey) {
                return match ($rel['type']) {
                    'parent', 'child', 'spouse', 'sibling' => $rel['type'],
                    default => '',
                };
            }
        }

        return '';
    }

    private function invertRelType(string $type): string
    {
        return match ($type) {
            'parent' => 'child',
            'child' => 'parent',
            default => $type,
        };
    }

    private function scoreConfidence(array $facts, array $rels): string
    {
        $score = count($facts) + count($rels);

        return match (true) {
            $score >= 3 => 'high',
            $score >= 1 => 'medium',
            default => 'low',
        };
    }

    // -------------------------------------------------------------------------
    // Deduplication / utilities
    // -------------------------------------------------------------------------

    private function deduplicateEventSignals(array $events): array
    {
        $seen = [];
        $out = [];

        foreach ($events as $event) {
            $key = $event['type'].':'.$event['year'].':'.$this->normalizeName($event['person_ref']);
            if (! array_key_exists($key, $seen)) {
                $seen[$key] = true;
                $out[] = $event;
            }
        }

        return $out;
    }

    private function hasSourceType(string $type, array $signals): bool
    {
        foreach ($signals as $signal) {
            if ($signal['document_type_hint'] === $type) {
                return true;
            }
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower(trim($name))) ?? '');
    }

    private function emptySignals(): array
    {
        return [
            'person_signals' => [],
            'event_signals' => [],
            'relationship_signals' => [],
            'source_signals' => [],
        ];
    }
}
