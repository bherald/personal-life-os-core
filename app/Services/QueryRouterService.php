<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * RAG-1 — Adaptive Retrieval Routing
 *
 * Classifies queries and routes them to the optimal retrieval strategy.
 * Replaces the current one-size-fits-all approach where every query goes
 * through the same vector search pipeline.
 *
 * Routes:
 *   - no_retrieval:  Conversational/meta queries that don't need KB search
 *   - single_pass:   Focused factual queries → standard vector search
 *   - multi_step:    Complex research → deepSearch with RAPTOR + graph
 *   - keyword_boost: Short/specific queries → force hybrid (vector + FTS)
 */
class QueryRouterService
{
    /** Query classification routes */
    public const ROUTE_NO_RETRIEVAL = 'no_retrieval';
    public const ROUTE_SINGLE_PASS  = 'single_pass';
    public const ROUTE_MULTI_STEP   = 'multi_step';
    public const ROUTE_KEYWORD_BOOST = 'keyword_boost';

    /** Patterns that indicate no retrieval needed */
    private const NO_RETRIEVAL_PATTERNS = [
        '/^(hi|hello|hey|thanks|thank you|ok|okay|bye|goodbye)\b/i',
        '/^(what can you do|help me|who are you)\b/i',
        '/^(summarize|translate|rewrite|rephrase)\s+(this|the following|below)/i',
    ];

    /** Patterns that indicate complex multi-step research */
    private const MULTI_STEP_PATTERNS = [
        '/\b(compare|contrast|difference between|relationship between)\b/i',
        '/\b(how does .+ relate to|connection between|timeline of)\b/i',
        '/\b(everything about|all about|comprehensive|thorough)\b/i',
        '/\b(across multiple|spanning|throughout|over time)\b/i',
        '/\b(evidence for|proof of|sources? that|documents? showing)\b/i',
    ];

    /** Genealogy-specific multi-step indicators */
    private const GENEALOGY_MULTI_STEP = [
        '/\b(ancestors?|descendants?|lineage|pedigree|family line)\b/i',
        '/\b(migration path|immigration|settlement pattern)\b/i',
        '/\b(brick wall|dead end|can\'t find|no records?)\b/i',
        '/\b(FAN|friends associates neighbors|cluster)\b/i',
    ];

    /**
     * Classify a query and return the optimal retrieval route with parameters.
     *
     * @param string $query The user's search query
     * @param array $context Optional context: ['document_type' => ..., 'agent_id' => ...]
     * @return array ['route' => string, 'params' => array, 'reason' => string]
     */
    public function classify(string $query, array $context = []): array
    {
        $query = trim($query);
        $wordCount = str_word_count($query);
        $charCount = strlen($query);

        // Rule 1: Empty or trivially short
        if ($charCount < 3) {
            return $this->route(self::ROUTE_NO_RETRIEVAL, 'Query too short for retrieval');
        }

        // Rule 2: Conversational/meta queries
        foreach (self::NO_RETRIEVAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                return $this->route(self::ROUTE_NO_RETRIEVAL, 'Conversational/meta query');
            }
        }

        // Rule 3: Very short keyword queries (1-2 words) → keyword boost
        // These produce poor embeddings but great FTS matches
        if ($wordCount <= 2 && $charCount <= 30) {
            return $this->route(self::ROUTE_KEYWORD_BOOST, 'Short query — FTS boost', [
                'use_hyde' => false,
                'force_hybrid' => true,
            ]);
        }

        // Rule 4: Explicit multi-step indicators
        foreach (self::MULTI_STEP_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                return $this->route(self::ROUTE_MULTI_STEP, 'Complex research query', [
                    'use_raptor' => true,
                    'use_graph' => true,
                    'graph_mode' => 'local',
                    'use_hyde' => 'auto',
                ]);
            }
        }

        // Rule 5: Genealogy-specific multi-step (patterns are domain-specific enough to match without context)
        foreach (self::GENEALOGY_MULTI_STEP as $pattern) {
            if (preg_match($pattern, $query)) {
                return $this->route(self::ROUTE_MULTI_STEP, 'Genealogy research query', [
                    'use_raptor' => true,
                    'use_graph' => true,
                    'graph_mode' => 'local',
                    'use_hyde' => 'auto',
                ]);
            }
        }

        // Rule 6: Long natural-language questions (7+ words) → single pass with HyDE
        if ($wordCount >= 7) {
            $hasQuestionWord = preg_match('/^(what|why|how|when|where|who|which|explain|describe)\b/i', $query);
            return $this->route(self::ROUTE_SINGLE_PASS, 'Natural language query', [
                'use_hyde' => $hasQuestionWord ? 'auto' : false,
            ]);
        }

        // Rule 7: Medium queries (3-6 words) → standard single pass
        return $this->route(self::ROUTE_SINGLE_PASS, 'Standard factual query', [
            'use_hyde' => false,
        ]);
    }

    /**
     * Build a route result array.
     */
    private function route(string $route, string $reason, array $params = []): array
    {
        Log::debug('QueryRouter: classified', [
            'route' => $route,
            'reason' => $reason,
        ]);

        return [
            'route' => $route,
            'reason' => $reason,
            'params' => $params,
        ];
    }
}
