<?php

namespace App\Services;

/**
 * RAG-12: Adaptive Strategy Selection
 *
 * Classifies a query into a retrieval strategy and returns the optimal set
 * of deepSearch() flags for that strategy. Pure rule-based — no LLM, no DB.
 *
 * Strategy priority (first match wins):
 *   1. genealogy   — domain keywords: born, census, marriage, ancestors, etc.
 *                    Enables HyDE, RAPTOR, Graph (local), ColBERT, RelevanceGating
 *   2. temporal    — recency keywords: latest, current, recent, 2025, etc.
 *                    Enables CRAG (web fallback), Temporal scoring; disables RAPTOR
 *   3. complex     — long multi-part query (≥ 12 words) or explicit sub-questions
 *                    Enables HyDE, RAPTOR, Iterative retrieval, RelevanceGating
 *   4. factual     — short specific question (≤ 7 words, ends with ?)
 *                    Enables HyDE, ColBERT, RelevanceGating
 *   5. default     — standard single-hop query
 *                    Enables RAPTOR only (baseline)
 *
 * Usage in deepSearch(): pass useAutoStrategy=true and all strategy flags
 * will be derived from the query. Explicit per-flag params are replaced by
 * the strategy config when auto-mode is active.
 */
class RAGStrategyService
{
    // =========================================================================
    // Keyword lists (pure — no I/O)
    // =========================================================================

    private const GENEALOGY_KEYWORDS = [
        'born', 'birth', 'died', 'death', 'married', 'marriage', 'census',
        'family', 'ancestry', 'ancestor', 'ancestors', 'descendant', 'descendants',
        'genealogy', 'genealogical', 'baptism', 'burial', 'christening',
        'spouse', 'husband', 'wife', 'parents', 'children', 'sibling',
        'immigration', 'emigration', 'naturalization', 'probate', 'obituary',
        'grave', 'gravestone', 'headstone', 'cemetery', 'vital records',
        'birth record', 'death record', 'marriage record', 'passenger list',
        'homestead', 'deed', 'will', 'estate', 'indenture',
    ];

    private const TEMPORAL_KEYWORDS = [
        'latest', 'current', 'recent', 'now', 'today', 'this week',
        'this month', 'this year', 'last year', 'breaking', 'news',
        'update', 'updated', '2025', '2024', 'just announced',
    ];

    private const COMPLEX_MARKERS = [
        // Conjunctions that signal multiple sub-topics
        ' and ', ' also ', ' as well as ', ' furthermore ', ' additionally ',
        // Sub-question markers
        'why', 'how', 'what', 'when', 'where', 'who',
    ];

    // =========================================================================
    // Strategy presets
    // =========================================================================

    /**
     * Strategy configuration presets — all deepSearch() boolean flags + graph params.
     */
    private const PRESETS = [
        'genealogy' => [
            'useHyde'            => true,
            'useRaptor'          => true,
            'useGraph'           => true,
            'graphMode'          => 'local',
            'graphAlpha'         => 0.5,
            'useHype'            => false,
            'useCrag'            => false,
            'useTemporal'        => false,
            'useRelevanceGating' => true,
            'useColbert'         => true,
            'useIterative'       => false,
        ],
        'temporal' => [
            'useHyde'            => false,
            'useRaptor'          => false,
            'useGraph'           => false,
            'graphMode'          => 'local',
            'graphAlpha'         => 0.5,
            'useHype'            => false,
            'useCrag'            => true,
            'useTemporal'        => true,
            'useRelevanceGating' => false,
            'useColbert'         => false,
            'useIterative'       => false,
        ],
        'complex' => [
            'useHyde'            => true,
            'useRaptor'          => true,
            'useGraph'           => false,
            'graphMode'          => 'local',
            'graphAlpha'         => 0.5,
            'useHype'            => false,
            'useCrag'            => false,
            'useTemporal'        => false,
            'useRelevanceGating' => true,
            'useColbert'         => false,
            'useIterative'       => true,
        ],
        'factual' => [
            'useHyde'            => true,
            'useRaptor'          => false,
            'useGraph'           => false,
            'graphMode'          => 'local',
            'graphAlpha'         => 0.5,
            'useHype'            => false,
            'useCrag'            => false,
            'useTemporal'        => false,
            'useRelevanceGating' => true,
            'useColbert'         => true,
            'useIterative'       => false,
        ],
        'default' => [
            'useHyde'            => false,
            'useRaptor'          => true,
            'useGraph'           => false,
            'graphMode'          => 'local',
            'graphAlpha'         => 0.5,
            'useHype'            => false,
            'useCrag'            => false,
            'useTemporal'        => false,
            'useRelevanceGating' => false,
            'useColbert'         => false,
            'useIterative'       => false,
        ],
    ];

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Select the best retrieval strategy for the query and return a full
     * deepSearch() configuration array.
     *
     * @param  string      $query
     * @param  string|null $documentType  Optional hint (e.g. 'genealogy', 'news')
     * @return array{
     *   strategy_name: string,
     *   rationale: string,
     *   useHyde: bool,
     *   useRaptor: bool,
     *   useGraph: bool,
     *   graphMode: string,
     *   graphAlpha: float,
     *   useHype: bool,
     *   useCrag: bool,
     *   useTemporal: bool,
     *   useRelevanceGating: bool,
     *   useColbert: bool,
     *   useIterative: bool
     * }
     */
    public function selectStrategy(string $query, ?string $documentType = null): array
    {
        $strategyName = $this->classifyQuery($query, $documentType);
        $rationale    = $this->buildRationale($query, $strategyName);
        $preset       = self::PRESETS[$strategyName] ?? self::PRESETS['default'];

        return array_merge($preset, [
            'strategy_name' => $strategyName,
            'rationale'     => $rationale,
        ]);
    }

    // =========================================================================
    // Query classification (pure — unit-testable)
    // =========================================================================

    /**
     * Classify the query into a strategy name (first-match priority).
     */
    public function classifyQuery(string $query, ?string $documentType = null): string
    {
        $lower = mb_strtolower(trim($query));

        // Document type hint overrides detection for known domains
        if ($documentType !== null) {
            $docLower = mb_strtolower($documentType);
            if (str_contains($docLower, 'genealog') || str_contains($docLower, 'family')) {
                return 'genealogy';
            }
            if (str_contains($docLower, 'news') || str_contains($docLower, 'current')) {
                return 'temporal';
            }
        }

        // 1. Genealogy — domain keywords take priority
        foreach (self::GENEALOGY_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return 'genealogy';
            }
        }

        // 2. Temporal — recency signals
        foreach (self::TEMPORAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) {
                return 'temporal';
            }
        }

        // 3. Complex — long query or multiple sub-questions
        $wordCount        = count(preg_split('/\s+/', trim($query)));
        $subQuestionCount = $this->countSubQuestions($lower);
        if ($wordCount >= 12 || $subQuestionCount >= 2) {
            return 'complex';
        }

        // 4. Factual — short specific question
        if ($wordCount <= 7 && str_ends_with(trim($query), '?')) {
            return 'factual';
        }

        return 'default';
    }

    /**
     * Count how many sub-question words appear in the query.
     * Used to detect multi-part complex queries.
     */
    public function countSubQuestions(string $lowerQuery): int
    {
        $questionWords = ['why', 'how', 'what', 'when', 'where', 'who'];
        $count         = 0;
        foreach ($questionWords as $w) {
            // Match as whole word to avoid partial matches
            if (preg_match('/\b' . preg_quote($w, '/') . '\b/', $lowerQuery)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Get all available strategy names.
     *
     * @return string[]
     */
    public function getStrategyNames(): array
    {
        return array_keys(self::PRESETS);
    }

    /**
     * Get the configuration preset for a named strategy.
     * Returns 'default' preset if name is unknown.
     */
    public function getPreset(string $strategyName): array
    {
        return self::PRESETS[$strategyName] ?? self::PRESETS['default'];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function buildRationale(string $query, string $strategyName): string
    {
        $lower = mb_strtolower($query);

        return match ($strategyName) {
            'genealogy' => 'Genealogy domain keywords detected — deep historical document retrieval',
            'temporal'  => 'Recency keywords detected — CRAG web fallback + temporal decay enabled',
            'complex'   => 'Long or multi-part query (' . count(preg_split('/\s+/', trim($query))) . ' words) — iterative gap-fill enabled',
            'factual'   => 'Short specific question — HyDE expansion + ColBERT reranking',
            default     => 'Standard query — baseline RAPTOR retrieval',
        };
    }
}
