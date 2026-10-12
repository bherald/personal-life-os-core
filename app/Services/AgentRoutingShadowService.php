<?php

/*
 * Portions adapted from OpenAI Codex fielded_bm25.rs and character_ngram.rs.
 * Copyright 2025 OpenAI. Licensed under Apache-2.0.
 * PLOS modification: native PHP agent-catalog shadow challengers and content-free receipts.
 * Source revisions: a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762 and
 * c983a53f2035bb9b6e999b1355b0056946671e9e.
 */

namespace App\Services;

final class AgentRoutingShadowService
{
    public const SCHEMA = 'plos.agent_routing_shadow.v2';

    public const METHOD_FIELDED_BM25 = 'fielded_bm25_v1';

    public const METHOD_CHARACTER_NGRAM = 'character_ngram_v1';

    private const MAX_QUERY_BYTES = 4096;

    private const MAX_QUERY_TERMS = 64;

    private const MAX_QUERY_GRAMS = 512;

    private const MAX_DOCUMENT_BYTES = 4096;

    private const MAX_DOCUMENT_TERMS = 256;

    private const MAX_DOCUMENT_GRAMS = 512;

    private const MAX_CANDIDATES = 1000;

    private const MAX_RESULTS = 50;

    private const MIN_GRAM_CHARS = 2;

    private const MAX_GRAM_CHARS = 5;

    private const FIELD_WEIGHTS = [8.0, 4.0, 1.0];

    private const K1 = 1.2;

    private const B = 0.75;

    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'do', 'for', 'from', 'how', 'i', 'in', 'is',
        'it', 'me', 'my', 'of', 'on', 'or', 'please', 'that', 'the', 'this', 'to', 'use', 'we', 'what',
        'when', 'where', 'which', 'with', 'you', 'your',
    ];

    /**
     * Bounded fielded BM25, adapted to native PHP from OpenAI Codex's
     * Apache-2.0 fielded skill selector at revision a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762.
     *
     * @param  array<string,array<string,mixed>>  $agents
     * @return array<string,mixed>
     */
    public function select(string $query, array $agents, ?string $currentAgentId = null, int $limit = 4): array
    {
        $primary = $this->selectByMethod(
            self::METHOD_FIELDED_BM25,
            $query,
            $agents,
            $currentAgentId,
            $limit
        );
        $primary['challengers'] = [
            self::METHOD_CHARACTER_NGRAM => $this->selectByMethod(
                self::METHOD_CHARACTER_NGRAM,
                $query,
                $agents,
                $currentAgentId,
                $limit
            ),
        ];

        return $primary;
    }

    /**
     * @param  array<string,array<string,mixed>>  $agents
     * @return array<string,mixed>
     */
    public function selectByMethod(
        string $method,
        string $query,
        array $agents,
        ?string $currentAgentId = null,
        int $limit = 4
    ): array {
        $documents = $this->documents($agents, $currentAgentId);

        return match ($method) {
            self::METHOD_FIELDED_BM25 => $this->selectFieldedBm25($query, $documents, $limit),
            self::METHOD_CHARACTER_NGRAM => $this->selectCharacterNgram($query, $documents, $limit),
            default => $this->result($method, [], 0, false, false, count($documents)),
        };
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,list<string>>}>  $documents
     * @return array<string,mixed>
     */
    private function selectFieldedBm25(string $query, array $documents, int $limit): array
    {
        [$boundedQuery, $queryBytesTruncated] = $this->bounded($query, self::MAX_QUERY_BYTES);
        [$queryTerms, $queryTermsTruncated] = $this->queryTerms($boundedQuery);
        $candidateSetTruncated = count($documents) > self::MAX_CANDIDATES;
        $documents = array_slice($documents, 0, self::MAX_CANDIDATES);

        if ($queryTerms === [] || $limit < 1 || $documents === []) {
            return $this->result(
                self::METHOD_FIELDED_BM25,
                [],
                count($queryTerms),
                $queryBytesTruncated || $queryTermsTruncated,
                $candidateSetTruncated,
                count($documents)
            );
        }

        $averageLengths = $this->averageFieldLengths($documents);
        $documentFrequencies = $this->documentFrequencies($documents);
        $documentCount = (float) count($documents);
        $scored = [];

        foreach ($documents as $document) {
            $score = $this->scoreDocument(
                $document,
                $queryTerms,
                $documentFrequencies,
                $documentCount,
                $averageLengths
            );
            if ($score > 0.0) {
                $scored[] = [
                    'score' => $score,
                    'id' => $document['id'],
                    'name' => $document['name'],
                ];
            }
        }

        usort($scored, static function (array $left, array $right): int {
            $scoreOrder = $right['score'] <=> $left['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $nameOrder = $left['name'] <=> $right['name'];

            return $nameOrder !== 0 ? $nameOrder : $left['id'] <=> $right['id'];
        });

        return $this->result(
            self::METHOD_FIELDED_BM25,
            array_column(array_slice($scored, 0, min($limit, self::MAX_RESULTS)), 'id'),
            count($queryTerms),
            $queryBytesTruncated || $queryTermsTruncated,
            $candidateSetTruncated,
            count($documents)
        );
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,list<string>>}>  $documents
     * @return array<string,mixed>
     */
    private function selectCharacterNgram(string $query, array $documents, int $limit): array
    {
        [$boundedQuery, $queryBytesTruncated] = $this->bounded($query, self::MAX_QUERY_BYTES);
        [$queryTerms, $queryTermsTruncated] = $this->queryTerms($boundedQuery);
        [$queryGrams, $queryGramsTruncated] = $this->grams($queryTerms, self::MAX_QUERY_GRAMS);
        $candidateSetTruncated = count($documents) > self::MAX_CANDIDATES;
        $documents = array_slice($documents, 0, self::MAX_CANDIDATES);
        $queryTruncated = $queryBytesTruncated || $queryTermsTruncated || $queryGramsTruncated;

        if ($queryGrams === [] || $limit < 1 || $documents === []) {
            return $this->result(
                self::METHOD_CHARACTER_NGRAM,
                [],
                count($queryTerms),
                $queryTruncated,
                $candidateSetTruncated,
                count($documents)
            );
        }

        $prepared = array_map(function (array $document): array {
            return [
                'id' => $document['id'],
                'name' => $document['name'],
                'fields' => array_map(function (array $terms): array {
                    [$grams] = $this->grams($terms, self::MAX_DOCUMENT_GRAMS);

                    return array_fill_keys($grams, true);
                }, $document['fields']),
            ];
        }, $documents);
        $documentFrequencies = $this->characterDocumentFrequencies($prepared);
        $documentCount = (float) count($prepared);
        $minimumMatches = min(count($queryGrams), 3);
        $scored = [];

        foreach ($prepared as $document) {
            [$score, $matchedGrams] = $this->scoreCharacterDocument(
                $document,
                $queryGrams,
                $documentFrequencies,
                $documentCount
            );
            if ($matchedGrams >= $minimumMatches) {
                $scored[] = [
                    'score' => $score,
                    'id' => $document['id'],
                    'name' => $document['name'],
                ];
            }
        }

        usort($scored, static function (array $left, array $right): int {
            $scoreOrder = $right['score'] <=> $left['score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $nameOrder = $left['name'] <=> $right['name'];

            return $nameOrder !== 0 ? $nameOrder : $left['id'] <=> $right['id'];
        });

        return $this->result(
            self::METHOD_CHARACTER_NGRAM,
            array_column(array_slice($scored, 0, min($limit, self::MAX_RESULTS)), 'id'),
            count($queryTerms),
            $queryTruncated,
            $candidateSetTruncated,
            count($documents)
        );
    }

    /**
     * @param  array<string,array<string,mixed>>  $agents
     * @return list<array{id:string,name:string,fields:array<int,list<string>>}>
     */
    private function documents(array $agents, ?string $currentAgentId): array
    {
        $documents = [];
        foreach ($agents as $agentId => $agent) {
            if (! is_array($agent) || ! ($agent['is_active'] ?? false) || (string) $agentId === $currentAgentId) {
                continue;
            }

            $documents[] = [
                'id' => (string) $agentId,
                'name' => trim((string) ($agent['name'] ?? $agentId)),
                'fields' => [
                    $this->documentTerms((string) $agentId.' '.(string) ($agent['name'] ?? '')),
                    $this->documentTerms(implode(' ', array_filter(
                        (array) ($agent['capabilities'] ?? []),
                        'is_string'
                    ))),
                    $this->documentTerms((string) ($agent['description'] ?? '')),
                ],
            ];
        }

        usort($documents, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $documents;
    }

    /** @return array<string,mixed> */
    public function inventory(): array
    {
        return [
            'schema' => self::SCHEMA,
            'method' => self::METHOD_FIELDED_BM25,
            'mode' => 'shadow',
            'selectors' => [
                self::METHOD_FIELDED_BM25 => [
                    'role' => 'primary_shadow',
                    'source_revision' => 'a47c661ea9e226fe65e46cf9dbc5c5ed75c2c762',
                ],
                self::METHOD_CHARACTER_NGRAM => [
                    'role' => 'measured_challenger',
                    'source_revision' => 'c983a53f2035bb9b6e999b1355b0056946671e9e',
                ],
            ],
            'authoritative_routes' => ['explicit_rule', 'legacy_capability_match'],
            'fields' => ['agent_identity', 'capabilities', 'description'],
            'field_weights' => self::FIELD_WEIGHTS,
            'limits' => [
                'query_bytes' => self::MAX_QUERY_BYTES,
                'query_unique_terms' => self::MAX_QUERY_TERMS,
                'query_unique_grams' => self::MAX_QUERY_GRAMS,
                'field_bytes' => self::MAX_DOCUMENT_BYTES,
                'field_terms' => self::MAX_DOCUMENT_TERMS,
                'field_unique_grams' => self::MAX_DOCUMENT_GRAMS,
                'candidates' => self::MAX_CANDIDATES,
                'results' => self::MAX_RESULTS,
            ],
            'raw_task_logged_or_persisted' => false,
            'changes_routing_authority' => false,
            'llm_calls' => false,
            'network_calls' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,list<string>>}>  $documents
     * @param  list<string>  $queryTerms
     * @param  array<string,int>  $documentFrequencies
     * @param  array<int,float>  $averageLengths
     */
    private function scoreDocument(
        array $document,
        array $queryTerms,
        array $documentFrequencies,
        float $documentCount,
        array $averageLengths
    ): float {
        $score = 0.0;

        foreach ($queryTerms as $queryTerm) {
            $frequency = (float) ($documentFrequencies[$queryTerm] ?? 0);
            if ($frequency === 0.0) {
                continue;
            }

            $weightedTermFrequency = 0.0;
            foreach ($document['fields'] as $fieldIndex => $terms) {
                $termFrequency = count(array_filter(
                    $terms,
                    static fn (string $term): bool => $term === $queryTerm
                ));
                if ($termFrequency === 0) {
                    continue;
                }

                $averageLength = $averageLengths[$fieldIndex] ?? 0.0;
                $lengthRatio = $averageLength === 0.0 ? 1.0 : count($terms) / $averageLength;
                $weightedTermFrequency += self::FIELD_WEIGHTS[$fieldIndex]
                    * $termFrequency
                    / (1.0 - self::B + self::B * $lengthRatio);
            }

            if ($weightedTermFrequency === 0.0) {
                continue;
            }

            $inverseDocumentFrequency = log(1.0 + ($documentCount - $frequency + 0.5) / ($frequency + 0.5));
            $score += $inverseDocumentFrequency
                * $weightedTermFrequency
                * (self::K1 + 1.0)
                / ($weightedTermFrequency + self::K1);
        }

        return $score;
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,list<string>>}>  $documents
     * @return array<int,float>
     */
    private function averageFieldLengths(array $documents): array
    {
        $totals = [0, 0, 0];
        foreach ($documents as $document) {
            foreach ($document['fields'] as $index => $field) {
                $totals[$index] += count($field);
            }
        }

        return array_map(
            static fn (int $total): float => $total / count($documents),
            $totals
        );
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,list<string>>}>  $documents
     * @return array<string,int>
     */
    private function documentFrequencies(array $documents): array
    {
        $frequencies = [];
        foreach ($documents as $document) {
            $uniqueTerms = [];
            foreach ($document['fields'] as $field) {
                foreach ($field as $term) {
                    $uniqueTerms[$term] = true;
                }
            }
            foreach (array_keys($uniqueTerms) as $term) {
                $frequencies[$term] = ($frequencies[$term] ?? 0) + 1;
            }
        }

        return $frequencies;
    }

    /**
     * @param  list<array{id:string,name:string,fields:array<int,array<string,bool>>}>  $documents
     * @return array<string,int>
     */
    private function characterDocumentFrequencies(array $documents): array
    {
        $frequencies = [];
        foreach ($documents as $document) {
            $uniqueGrams = [];
            foreach ($document['fields'] as $field) {
                foreach (array_keys($field) as $gram) {
                    $uniqueGrams[$gram] = true;
                }
            }
            foreach (array_keys($uniqueGrams) as $gram) {
                $frequencies[$gram] = ($frequencies[$gram] ?? 0) + 1;
            }
        }

        return $frequencies;
    }

    /**
     * @param  array{id:string,name:string,fields:array<int,array<string,bool>>}  $document
     * @param  list<string>  $queryGrams
     * @param  array<string,int>  $documentFrequencies
     * @return array{0:float,1:int}
     */
    private function scoreCharacterDocument(
        array $document,
        array $queryGrams,
        array $documentFrequencies,
        float $documentCount
    ): array {
        $score = 0.0;
        $matched = 0;

        foreach ($queryGrams as $gram) {
            $frequency = (float) ($documentFrequencies[$gram] ?? 0);
            if ($frequency === 0.0) {
                continue;
            }

            $fieldWeight = 0.0;
            foreach ($document['fields'] as $fieldIndex => $field) {
                if (isset($field[$gram])) {
                    $fieldWeight += self::FIELD_WEIGHTS[$fieldIndex];
                }
            }
            if ($fieldWeight === 0.0) {
                continue;
            }

            $inverseDocumentFrequency = log(1.0 + ($documentCount - $frequency + 0.5) / ($frequency + 0.5));
            $score += $inverseDocumentFrequency * $fieldWeight;
            $matched++;
        }

        return [$score, $matched];
    }

    /** @return array{0:list<string>,1:bool} */
    private function queryTerms(string $query): array
    {
        $terms = [];
        $seen = [];
        foreach ($this->normalizedTerms($query) as $term) {
            if (mb_strlen($term, 'UTF-8') < 2 || in_array($term, self::STOP_WORDS, true) || isset($seen[$term])) {
                continue;
            }
            if (count($terms) === self::MAX_QUERY_TERMS) {
                return [$terms, true];
            }
            $seen[$term] = true;
            $terms[] = $term;
        }

        return [$terms, false];
    }

    /** @return list<string> */
    private function documentTerms(string $value): array
    {
        [$bounded] = $this->bounded($value, self::MAX_DOCUMENT_BYTES);

        return array_slice($this->normalizedTerms($bounded), 0, self::MAX_DOCUMENT_TERMS);
    }

    /** @param list<string> $terms @return array{0:list<string>,1:bool} */
    private function grams(array $terms, int $limit): array
    {
        $grams = [];
        $seen = [];

        foreach ($terms as $term) {
            $characters = mb_str_split($term, 1, 'UTF-8');
            $characterCount = count($characters);
            $minimumGramChars = preg_match('/^[\x00-\x7F]+$/D', $term) === 1
                && $characterCount > self::MIN_GRAM_CHARS
                    ? self::MIN_GRAM_CHARS + 1
                    : self::MIN_GRAM_CHARS;
            $maximumGramChars = min(self::MAX_GRAM_CHARS, $characterCount);
            if ($maximumGramChars < $minimumGramChars) {
                continue;
            }

            for ($gramSize = $minimumGramChars; $gramSize <= $maximumGramChars; $gramSize++) {
                for ($start = 0; $start <= $characterCount - $gramSize; $start++) {
                    $gram = implode('', array_slice($characters, $start, $gramSize));
                    if (isset($seen[$gram])) {
                        continue;
                    }
                    if (count($grams) === $limit) {
                        return [$grams, true];
                    }
                    $seen[$gram] = true;
                    $grams[] = $gram;
                }
            }
        }

        return [$grams, false];
    }

    /** @return list<string> */
    private function normalizedTerms(string $value): array
    {
        $matched = preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($value, 'UTF-8'), $matches);

        return $matched === false ? [] : array_values($matches[0]);
    }

    /** @return array{0:string,1:bool} */
    private function bounded(string $value, int $maxBytes): array
    {
        if (strlen($value) <= $maxBytes) {
            return [$value, false];
        }

        return [mb_strcut($value, 0, $maxBytes, 'UTF-8'), true];
    }

    /**
     * @param  list<string>  $candidateAgentIds
     * @return array<string,mixed>
     */
    private function result(
        string $method,
        array $candidateAgentIds,
        int $queryTermCount,
        bool $queryTruncated,
        bool $candidateSetTruncated,
        int $candidateCount
    ): array {
        return [
            'schema' => self::SCHEMA,
            'method' => $method,
            'mode' => 'shadow',
            'candidate_agent_ids' => $candidateAgentIds,
            'query_term_count' => $queryTermCount,
            'query_truncated' => $queryTruncated,
            'candidate_set_truncated' => $candidateSetTruncated,
            'candidate_count' => $candidateCount,
            'raw_task_included' => false,
            'changes_routing_authority' => false,
        ];
    }
}
