<?php

namespace App\Services\Research;

use App\Services\AIService;
use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Traits\RecursionAware;
use Exception;

/**
 * LLMKnowledgeVettingService - Extract and verify LLM knowledge externally
 *
 * Implements multi-layer verification:
 * 1. RAG Cross-Reference - Check against existing verified knowledge
 * 2. External Source Verification - Confirm via web sources
 * 3. LLM Confidence Assessment - Use AI's own confidence scoring
 *
 * All three methods are applied when possible to maximize confidence.
 * External verification is always preferred when available.
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class LLMKnowledgeVettingService
{
    use RecursionAware;

    private AIService $aiService;
    private string $connection = 'pgsql_rag';

    // Verification thresholds
    private const STRICT_MIN_EXTERNAL_CONFIRMATIONS = 2;
    private const STANDARD_MIN_EXTERNAL_CONFIRMATIONS = 1;
    private const HIGH_LLM_CONFIDENCE_THRESHOLD = 0.90;
    private const RAG_MATCH_THRESHOLD = 0.75;

    // Confidence weights for combined scoring
    private const WEIGHT_EXTERNAL_VERIFICATION = 0.50;
    private const WEIGHT_RAG_CROSS_REFERENCE = 0.30;
    private const WEIGHT_LLM_CONFIDENCE = 0.20;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Extract knowledge from LLM about a topic
     *
     * @param string $query The research query
     * @param string $category Domain category for context
     * @return array Extracted facts with confidence scores
     */
    public function extractLLMKnowledge(string $query, string $category = 'general'): array
    {
        $cacheKey = "llm_knowledge:" . md5("{$query}:{$category}");
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $prompt = $this->buildExtractionPrompt($query, $category);

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 2000,
                'factual_mode' => true,
                'temperature' => 0.1,
            ]);

            if (empty($result['response'])) {
                return ['facts' => [], 'error' => 'Empty response from LLM'];
            }

            // Parse the structured response
            $facts = $this->parseExtractedFacts($result['response']);

            // Add LLM model info to each fact
            $modelUsed = $result['provider'] ?? 'unknown';
            foreach ($facts as &$fact) {
                $fact['llm_model'] = $modelUsed;
                $fact['llm_stated'] = true;
                $fact['extracted_at'] = now()->toIso8601String();
            }

            $response = [
                'facts' => $facts,
                'query' => $query,
                'category' => $category,
                'llm_model' => $modelUsed,
            ];

            Cache::put($cacheKey, $response, now()->addHours(6));
            return $response;

        } catch (Exception $e) {
            Log::error('LLM knowledge extraction failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return ['facts' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the extraction prompt for structured fact output
     */
    private function buildExtractionPrompt(string $query, string $category): string
    {
        return <<<PROMPT
You are a meticulous research assistant extracting factual knowledge.

Query: {$query}
Category: {$category}

Extract specific, verifiable FACTS about this topic. Each fact must be:
- A single, specific claim (not general statements)
- Potentially verifiable via external sources
- Includes any relevant dates, names, locations, or quantities

Rate your confidence in each fact from 0.0 to 1.0:
- 0.9-1.0: Absolutely certain, widely documented
- 0.7-0.89: Highly confident, well-established
- 0.5-0.69: Moderately confident, may need verification
- 0.3-0.49: Lower confidence, should verify
- 0.0-0.29: Uncertain, speculation

Return ONLY valid JSON array:
[
  {
    "statement": "Specific factual claim here",
    "confidence": 0.85,
    "fact_type": "date|event|relationship|location|quantity|definition|attribution",
    "entities": ["Person Name", "Place", "Date if any"],
    "verification_hint": "Where this fact could be verified (e.g., 'census records', 'academic papers', 'official government data')",
    "source_hint": "If you know the original source, cite it"
  }
]

IMPORTANT:
- Only include facts you are reasonably confident about
- Do NOT make up facts or speculate
- If you don't know something, don't include it
- Prefer well-documented, verifiable facts over obscure claims
PROMPT;
    }

    /**
     * Parse extracted facts from LLM response
     */
    private function parseExtractedFacts(string $content): array
    {
        // Extract JSON array from response
        if (preg_match('/\[[\s\S]*\]/m', $content, $matches)) {
            $facts = json_decode($matches[0], true);
            if (is_array($facts)) {
                // Validate and normalize each fact
                return array_filter(array_map(function ($fact) {
                    if (empty($fact['statement'])) return null;

                    return [
                        'statement' => trim($fact['statement']),
                        'confidence' => min(1.0, max(0.0, (float)($fact['confidence'] ?? 0.5))),
                        'fact_type' => $fact['fact_type'] ?? 'unknown',
                        'entities' => $fact['entities'] ?? [],
                        'verification_hint' => $fact['verification_hint'] ?? null,
                        'source_hint' => $fact['source_hint'] ?? null,
                    ];
                }, $facts));
            }
        }

        return [];
    }

    /**
     * Verify a fact using all available methods
     *
     * @param array $fact The fact to verify
     * @param string $verificationLevel 'strict', 'standard', or 'relaxed'
     * @return array Verification result with confidence score
     */
    public function verifyFact(array $fact, string $verificationLevel = 'strict', array $options = []): array
    {
        if (empty($options['skip_recursive'])) {
            // RLM: Try recursive fact verification
            $rlm = $this->tryRecursive('llm_knowledge_vetting', 'evidence_chase', ['fact' => $fact, 'level' => $verificationLevel, 'options' => $options], function ($ctx) {
                return $this->verifyFact($ctx['fact'] ?? $ctx['data'], $ctx['level'] ?? 'strict', $ctx['options'] ?? []);
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        $statement = $fact['statement'] ?? '';
        if (empty($statement)) {
            return ['verified' => false, 'error' => 'Empty fact statement'];
        }

        $factHash = hash('sha256', strtolower(trim($statement)));
        $verificationResult = [
            'fact_hash' => $factHash,
            'statement' => $statement,
            'verification_level' => $verificationLevel,
            'attempts' => [],
            'confidence_score' => 0.0,
            'verification_status' => 'unverified',
        ];

        // Check if this fact was already verified
        $existing = $this->getExistingFact($factHash);
        if ($existing && $existing['verification_status'] === 'verified') {
            return array_merge($verificationResult, [
                'verification_status' => 'already_verified',
                'confidence_score' => (float)$existing['confidence_score'],
                'existing_fact_id' => $existing['id'],
            ]);
        }

        // 1. RAG Cross-Reference
        $ragResult = $this->crossReferenceRAG($statement);
        $verificationResult['attempts'][] = [
            'method' => 'rag_lookup',
            'result' => $ragResult['match_found'] ? 'confirmed' : 'uncertain',
            'confidence' => $ragResult['score'],
            'evidence' => $ragResult['matching_content'] ?? null,
        ];

        // 2. External Source Verification
        $externalResult = $this->searchExternalSources($statement, $fact['verification_hint'] ?? null);
        $verificationResult['attempts'][] = [
            'method' => 'external_search',
            'result' => $externalResult['confirmed'] ? 'confirmed' : ($externalResult['denied'] ? 'denied' : 'uncertain'),
            'confidence' => $externalResult['confidence'],
            'sources_checked' => $externalResult['sources_checked'],
            'sources_confirmed' => $externalResult['sources_confirmed'],
            'evidence' => $externalResult['evidence'] ?? [],
        ];

        // 3. LLM Confidence (already have this from extraction)
        $llmConfidence = $fact['confidence'] ?? 0.5;
        $verificationResult['attempts'][] = [
            'method' => 'llm_confidence',
            'result' => $llmConfidence >= self::HIGH_LLM_CONFIDENCE_THRESHOLD ? 'confirmed' : 'uncertain',
            'confidence' => $llmConfidence,
        ];

        // Calculate combined confidence score
        $combinedScore = $this->calculateCombinedConfidence(
            $ragResult['score'],
            $externalResult['confidence'],
            $llmConfidence,
            $externalResult['sources_confirmed']
        );
        $verificationResult['confidence_score'] = $combinedScore;

        // Determine verification status based on level
        $verificationResult['verification_status'] = $this->determineVerificationStatus(
            $verificationLevel,
            $combinedScore,
            $externalResult['sources_confirmed'],
            $ragResult['match_found'],
            $llmConfidence
        );

        return $verificationResult;
    }

    /**
     * Cross-reference a fact against existing RAG knowledge base
     */
    public function crossReferenceRAG(string $factStatement): array
    {
        try {
            // Generate embedding for the fact statement
            $embeddingResult = $this->aiService->generateEmbedding($factStatement);

            // Check if embedding generation succeeded
            if (!($embeddingResult['success'] ?? false) || empty($embeddingResult['embedding'])) {
                $error = $embeddingResult['error'] ?? 'Failed to generate embedding';
                Log::debug('RAG cross-reference skipped - embedding failed', ['error' => $error]);
                return ['match_found' => false, 'score' => 0.0, 'error' => $error];
            }

            $embedding = $embeddingResult['embedding'];

            // Validate embedding is a numeric array
            if (!is_array($embedding) || empty($embedding) || !is_numeric($embedding[0])) {
                return ['match_found' => false, 'score' => 0.0, 'error' => 'Invalid embedding format'];
            }

            // Search RAG documents for similar content
            $embeddingStr = PgVector::literal($embedding);

            $results = DB::connection($this->connection)->select("
                SELECT
                    id,
                    title,
                    content,
                    1 - (embedding <=> ?::vector) as similarity
                FROM rag_documents
                WHERE embedding IS NOT NULL
                ORDER BY embedding <=> ?::vector
                LIMIT 5
            ", [$embeddingStr, $embeddingStr]);

            if (empty($results)) {
                return ['match_found' => false, 'score' => 0.0, 'matching_documents' => []];
            }

            $bestMatch = $results[0];
            $similarity = (float)$bestMatch->similarity;

            // Consider it a match if similarity is above threshold
            $matchFound = $similarity >= self::RAG_MATCH_THRESHOLD;

            return [
                'match_found' => $matchFound,
                'score' => $similarity,
                'matching_content' => $matchFound ? substr($bestMatch->content, 0, 500) : null,
                'matching_documents' => array_map(function ($r) {
                    return [
                        'id' => $r->id,
                        'title' => $r->title,
                        'similarity' => round((float)$r->similarity, 4),
                    ];
                }, $results),
            ];

        } catch (Exception $e) {
            Log::warning('RAG cross-reference failed', ['error' => $e->getMessage()]);
            return ['match_found' => false, 'score' => 0.0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Compare an AI-discovered fact against human-entered facts
     *
     * Uses semantic similarity to find related human facts, then uses AI
     * to determine if they confirm, contradict, or are unrelated.
     */
    public function compareToHumanKnowledge(string $factStatement): array
    {
        try {
            // Generate embedding for the fact
            $embeddingResult = $this->aiService->generateEmbedding($factStatement);

            if (!($embeddingResult['success'] ?? false) || empty($embeddingResult['embedding'])) {
                return ['has_comparison' => false, 'error' => 'Embedding generation failed'];
            }

            $embedding = $embeddingResult['embedding'];
            if (!is_array($embedding) || empty($embedding) || !is_numeric($embedding[0])) {
                return ['has_comparison' => false, 'error' => 'Invalid embedding format'];
            }

            $embeddingStr = PgVector::literal($embedding);

            // Search human-entered facts that are similar
            // First, search in research_facts where human_entered = true
            $humanFacts = DB::connection($this->connection)->select("
                SELECT
                    rf.id,
                    rf.fact_statement,
                    rf.reviewed_by,
                    rf.domain_category,
                    rf.source_citations,
                    rf.created_at
                FROM research_facts rf
                WHERE rf.llm_stated = false
                LIMIT 100
            ");

            if (empty($humanFacts)) {
                return [
                    'has_comparison' => false,
                    'human_facts_count' => 0,
                    'message' => 'No human-entered facts available for comparison',
                ];
            }

            // Generate embeddings for human facts (or retrieve cached)
            $similarities = [];
            foreach ($humanFacts as $hf) {
                $hfEmbedding = Cache::remember(
                    "human_fact_embedding:{$hf->id}",
                    3600,
                    function () use ($hf) {
                        $result = $this->aiService->generateEmbedding($hf->fact_statement);
                        return $result['embedding'] ?? null;
                    }
                );

                if ($hfEmbedding) {
                    // Calculate cosine similarity
                    $similarity = $this->cosineSimilarity($embedding, $hfEmbedding);
                    if ($similarity >= 0.5) { // Only consider moderately similar facts
                        $similarities[] = [
                            'fact' => $hf,
                            'similarity' => $similarity,
                        ];
                    }
                }
            }

            if (empty($similarities)) {
                return [
                    'has_comparison' => true,
                    'human_facts_count' => count($humanFacts),
                    'related_facts' => [],
                    'verdict' => 'no_related',
                    'message' => 'No related human-entered facts found',
                ];
            }

            // Sort by similarity
            usort($similarities, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
            $topMatches = array_slice($similarities, 0, 3);

            // Use AI to compare the facts
            $comparisonPrompt = $this->buildComparisonPrompt($factStatement, $topMatches);
            $aiResult = $this->aiService->process($comparisonPrompt, [
                'max_tokens' => 500,
                'factual_mode' => true,
            ]);

            $verdict = 'unknown';
            $explanation = '';

            if ($aiResult['success'] ?? false) {
                $response = strtolower($aiResult['response'] ?? '');
                if (str_contains($response, 'confirms') || str_contains($response, 'corroborates')) {
                    $verdict = 'confirmed';
                } elseif (str_contains($response, 'contradicts') || str_contains($response, 'conflicts')) {
                    $verdict = 'contradicted';
                } elseif (str_contains($response, 'unrelated') || str_contains($response, 'different')) {
                    $verdict = 'unrelated';
                } else {
                    $verdict = 'inconclusive';
                }
                $explanation = $aiResult['response'];
            }

            return [
                'has_comparison' => true,
                'human_facts_count' => count($humanFacts),
                'related_facts' => array_map(function ($m) {
                    return [
                        'id' => $m['fact']->id,
                        'statement' => $m['fact']->fact_statement,
                        'entered_by' => $m['fact']->human_entered_by,
                        'similarity' => round($m['similarity'], 4),
                    ];
                }, $topMatches),
                'verdict' => $verdict,
                'explanation' => $explanation,
            ];

        } catch (Exception $e) {
            Log::warning('Human knowledge comparison failed', ['error' => $e->getMessage()]);
            return ['has_comparison' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build prompt for comparing AI fact to human facts
     */
    private function buildComparisonPrompt(string $aiFact, array $humanMatches): string
    {
        $humanFactList = '';
        foreach ($humanMatches as $i => $match) {
            $humanFactList .= ($i + 1) . ". \"{$match['fact']->fact_statement}\" (entered by: {$match['fact']->human_entered_by})\n";
        }

        return <<<PROMPT
Compare the following AI-discovered fact against human-entered knowledge.

AI-DISCOVERED FACT:
"{$aiFact}"

HUMAN-ENTERED FACTS:
{$humanFactList}

Determine the relationship between the AI fact and human facts:
- CONFIRMS: Human facts support/validate the AI fact
- CONTRADICTS: Human facts conflict with the AI fact
- UNRELATED: Facts are about different topics
- INCONCLUSIVE: Cannot determine relationship

Provide a brief explanation (1-2 sentences). Start your response with the relationship type.
PROMPT;
    }

    /**
     * Calculate cosine similarity between two embedding vectors
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return \App\Support\VectorMath::cosineSimilarity($a, $b);
    }

    /**
     * Search external sources to verify a fact
     */
    public function searchExternalSources(string $fact, ?string $verificationHint = null, int $minSources = 2): array
    {
        $result = [
            'confirmed' => false,
            'denied' => false,
            'confidence' => 0.0,
            'sources_checked' => 0,
            'sources_confirmed' => 0,
            'sources_denied' => 0,
            'evidence' => [],
        ];

        // Build search query
        $searchQuery = $this->buildVerificationQuery($fact, $verificationHint);

        try {
            // Use existing research sources to search
            $searchEngines = DB::connection($this->connection)->select("
                SELECT id, name, search_url_template
                FROM research_sources
                WHERE is_search_engine = true AND is_active = true
                ORDER BY trust_score DESC
                LIMIT 3
            ");

            foreach ($searchEngines as $engine) {
                $searchResult = $this->searchEngine($engine, $searchQuery, $fact);
                $result['sources_checked']++;

                if ($searchResult['confirms']) {
                    $result['sources_confirmed']++;
                    $result['evidence'][] = [
                        'source' => $engine->name,
                        'url' => $searchResult['url'] ?? null,
                        'snippet' => $searchResult['snippet'] ?? null,
                        'type' => 'confirms',
                    ];
                } elseif ($searchResult['denies']) {
                    $result['sources_denied']++;
                    $result['evidence'][] = [
                        'source' => $engine->name,
                        'url' => $searchResult['url'] ?? null,
                        'snippet' => $searchResult['snippet'] ?? null,
                        'type' => 'denies',
                    ];
                }

                // Rate limiting
                usleep(300000); // 300ms
            }

            // Calculate confidence based on confirmations
            if ($result['sources_confirmed'] >= $minSources) {
                $result['confirmed'] = true;
                $result['confidence'] = min(1.0, 0.6 + ($result['sources_confirmed'] * 0.15));
            } elseif ($result['sources_denied'] >= 2) {
                $result['denied'] = true;
                $result['confidence'] = 0.0;
            } elseif ($result['sources_confirmed'] === 1) {
                $result['confidence'] = 0.5;
            }

        } catch (Exception $e) {
            Log::warning('External source verification failed', ['error' => $e->getMessage()]);
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Build verification search query from fact
     */
    private function buildVerificationQuery(string $fact, ?string $hint): string
    {
        // Extract key terms from the fact
        $query = $fact;

        // Add hint if available
        if ($hint) {
            $query = "{$fact} {$hint}";
        }

        // Truncate if too long
        if (strlen($query) > 200) {
            $query = substr($query, 0, 200);
        }

        return $query;
    }

    /**
     * Search a single engine for verification
     */
    private function searchEngine(object $engine, string $query, string $fact): array
    {
        try {
            $searchUrl = str_replace('{query}', urlencode($query), $engine->search_url_template);

            $response = \Illuminate\Support\Facades\Http::connectTimeout(5)->timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($searchUrl);

            if (!$response->successful()) {
                return ['confirms' => false, 'denies' => false];
            }

            $html = $response->body();

            // Look for fact keywords in results
            $factWords = array_filter(explode(' ', strtolower($fact)), fn($w) => strlen($w) > 4);
            $matchCount = 0;

            foreach ($factWords as $word) {
                if (stripos($html, $word) !== false) {
                    $matchCount++;
                }
            }

            // Simple heuristic: if most keywords found, consider it supporting
            $matchRatio = count($factWords) > 0 ? $matchCount / count($factWords) : 0;

            // Extract a snippet if we found a match
            $snippet = null;
            if ($matchRatio > 0.5) {
                // Try to extract relevant snippet
                preg_match('/<p[^>]*>([^<]*(' . implode('|', array_slice($factWords, 0, 3)) . ')[^<]*)<\/p>/i', $html, $matches);
                if (!empty($matches[1])) {
                    $snippet = substr(strip_tags($matches[1]), 0, 300);
                }
            }

            return [
                'confirms' => $matchRatio > 0.6,
                'denies' => false, // Would need more sophisticated contradiction detection
                'match_ratio' => $matchRatio,
                'snippet' => $snippet,
            ];

        } catch (Exception $e) {
            Log::debug("Search engine {$engine->name} failed", ['error' => $e->getMessage()]);
            return ['confirms' => false, 'denies' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Calculate combined confidence score from all verification methods
     */
    private function calculateCombinedConfidence(
        float $ragScore,
        float $externalConfidence,
        float $llmConfidence,
        int $externalConfirmations
    ): float {
        // Base calculation with weights
        $weighted = (
            (self::WEIGHT_RAG_CROSS_REFERENCE * $ragScore) +
            (self::WEIGHT_EXTERNAL_VERIFICATION * $externalConfidence) +
            (self::WEIGHT_LLM_CONFIDENCE * $llmConfidence)
        );

        // Bonus for multiple external confirmations
        if ($externalConfirmations >= 2) {
            $weighted = min(1.0, $weighted + 0.15);
        } elseif ($externalConfirmations >= 1) {
            $weighted = min(1.0, $weighted + 0.08);
        }

        // Boost if both RAG and external agree
        if ($ragScore >= self::RAG_MATCH_THRESHOLD && $externalConfidence >= 0.5) {
            $weighted = min(1.0, $weighted + 0.10);
        }

        return round($weighted, 4);
    }

    /**
     * Determine verification status based on level and scores
     */
    private function determineVerificationStatus(
        string $level,
        float $combinedScore,
        int $externalConfirmations,
        bool $ragMatch,
        float $llmConfidence
    ): string {
        switch ($level) {
            case 'strict':
                // Requires 2+ external confirmations
                if ($externalConfirmations >= self::STRICT_MIN_EXTERNAL_CONFIRMATIONS) {
                    return 'verified';
                }
                return $combinedScore >= 0.8 ? 'pending' : 'unverified';

            case 'standard':
                // Requires 1 external + high LLM confidence OR RAG match
                if ($externalConfirmations >= 1 && ($llmConfidence >= self::HIGH_LLM_CONFIDENCE_THRESHOLD || $ragMatch)) {
                    return 'verified';
                }
                return $combinedScore >= 0.65 ? 'pending' : 'unverified';

            case 'relaxed':
                // RAG match alone is sufficient
                if ($ragMatch) {
                    return 'verified';
                }
                return $combinedScore >= 0.5 ? 'pending' : 'unverified';

            default:
                return 'unverified';
        }
    }

    /**
     * Store a verified fact in the database
     */
    public function storeFact(array $fact, array $verificationResult, ?string $missionId = null): ?string
    {
        try {
            $factHash = $verificationResult['fact_hash'] ?? hash('sha256', strtolower(trim($fact['statement'])));

            $result = DB::connection($this->connection)->select("
                INSERT INTO research_facts (
                    mission_id, fact_statement, fact_hash, fact_type, domain_category,
                    verification_status, confidence_score,
                    llm_stated, llm_confidence, llm_model,
                    external_sources_checked, external_sources_confirmed, external_sources_denied,
                    rag_cross_referenced, rag_match_score, rag_match_document_ids,
                    source_citations, related_entities, tags
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?::jsonb,
                    ?::jsonb, ?::jsonb, ?::jsonb
                )
                ON CONFLICT (fact_hash) DO UPDATE SET
                    verification_status = EXCLUDED.verification_status,
                    confidence_score = GREATEST(research_facts.confidence_score, EXCLUDED.confidence_score),
                    external_sources_checked = research_facts.external_sources_checked + EXCLUDED.external_sources_checked,
                    external_sources_confirmed = research_facts.external_sources_confirmed + EXCLUDED.external_sources_confirmed,
                    updated_at = CURRENT_TIMESTAMP,
                    verified_at = CASE WHEN EXCLUDED.verification_status = 'verified' THEN CURRENT_TIMESTAMP ELSE research_facts.verified_at END
                RETURNING id
            ", [
                $missionId,
                $fact['statement'],
                $factHash,
                $fact['fact_type'] ?? 'unknown',
                $fact['domain_category'] ?? 'general',
                $verificationResult['verification_status'],
                $verificationResult['confidence_score'],
                $fact['llm_stated'] ?? true,
                $fact['confidence'] ?? 0.5,
                $fact['llm_model'] ?? null,
                $verificationResult['attempts'][1]['sources_checked'] ?? 0,
                $verificationResult['attempts'][1]['sources_confirmed'] ?? 0,
                $verificationResult['attempts'][1]['sources_denied'] ?? 0,
                isset($verificationResult['attempts'][0]) && $verificationResult['attempts'][0]['method'] === 'rag_lookup',
                $verificationResult['attempts'][0]['confidence'] ?? 0,
                json_encode($verificationResult['attempts'][0]['matching_documents'] ?? []),
                json_encode($verificationResult['attempts'][1]['evidence'] ?? []),
                json_encode($fact['entities'] ?? []),
                json_encode([]),
            ]);

            // Log verification attempts
            if (!empty($result[0]->id)) {
                $factId = $result[0]->id;
                foreach ($verificationResult['attempts'] as $attempt) {
                    $this->logVerificationAttempt($factId, $attempt);
                }
                return $factId;
            }

            return null;

        } catch (Exception $e) {
            Log::error('Failed to store fact', [
                'statement' => $fact['statement'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Log a verification attempt
     */
    private function logVerificationAttempt(string $factId, array $attempt): void
    {
        try {
            DB::connection($this->connection)->insert("
                INSERT INTO verification_attempts (
                    fact_id, method, result, confidence, evidence_snippet, source_url
                ) VALUES (?, ?, ?, ?, ?, ?)
            ", [
                $factId,
                $attempt['method'],
                $attempt['result'],
                $attempt['confidence'] ?? 0,
                $attempt['evidence']['snippet'] ?? ($attempt['evidence'] ? json_encode(array_slice($attempt['evidence'], 0, 3)) : null),
                $attempt['evidence']['url'] ?? null,
            ]);
        } catch (Exception $e) {
            Log::debug('Failed to log verification attempt', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get an existing fact by hash
     */
    private function getExistingFact(string $factHash): ?array
    {
        $result = DB::connection($this->connection)->select("
            SELECT id, fact_statement, verification_status, confidence_score
            FROM research_facts
            WHERE fact_hash = ?
            LIMIT 1
        ", [$factHash]);

        return !empty($result) ? (array)$result[0] : null;
    }

    /**
     * Get verification statistics
     */
    public function getVerificationStats(): array
    {
        $stats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total_facts,
                COUNT(*) FILTER (WHERE verification_status = 'verified') as verified,
                COUNT(*) FILTER (WHERE verification_status = 'pending') as pending,
                COUNT(*) FILTER (WHERE verification_status = 'unverified') as unverified,
                COUNT(*) FILTER (WHERE verification_status = 'rejected') as rejected,
                AVG(confidence_score)::numeric(5,4) as avg_confidence,
                AVG(external_sources_confirmed)::numeric(4,2) as avg_external_confirmations
            FROM research_facts
        ");

        $recentVerifications = DB::connection($this->connection)->select("
            SELECT
                method,
                result,
                COUNT(*) as count,
                AVG(confidence)::numeric(5,4) as avg_confidence
            FROM verification_attempts
            WHERE executed_at > NOW() - INTERVAL '24 hours'
            GROUP BY method, result
            ORDER BY count DESC
        ");

        return [
            'summary' => (array)($stats[0] ?? []),
            'recent_attempts' => array_map(fn($r) => (array)$r, $recentVerifications),
        ];
    }
}
