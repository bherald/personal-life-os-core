<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Evidence Retriever Service
 *
 * Multi-source evidence retrieval for claim verification:
 * 1. Multi-query search (generates 3 diverse queries per claim)
 * 2. RAG search (existing indexed documents)
 * 3. Web search (SearXNG meta-search)
 * 4. Cached research results
 * 5. Contradiction-driven evidence retrieval (FC-3) — dedicated negated/opposing queries
 * 6. Source credibility scoring
 * 7. Evidence ranking and deduplication
 *
 * Based on: Loki/VeriScore evidence retrieval patterns
 * FC-3: Contradiction-Driven Evidence Retrieval — explicitly searches for
 * disproving evidence using negated query terms and alternative claims.
 * Presents both supporting + contradicting evidence to the verifier.
 * Reference: research-synthesis-feb2026.md
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class EvidenceRetrieverService
{
    private AIService $aiService;
    private RAGService $ragService;
    private SearXNGService $searxngService;
    private ?DomainCredibilityService $domainCredibilityService = null;

    /** @var int Default queries to generate per claim */
    private const DEFAULT_QUERY_COUNT = 3;

    /** @var int Maximum evidence items per source type */
    private const MAX_EVIDENCE_PER_SOURCE = 10;

    /** @var int Cache TTL for query generation (1 hour) */
    private const QUERY_CACHE_TTL = 3600;

    /** @var float Minimum similarity for RAG results */
    private const RAG_SIMILARITY_THRESHOLD = 0.5;

    /** @var float Threshold for semantic deduplication */
    private const DEDUP_SIMILARITY_THRESHOLD = 0.85;

    /** @var int Number of contradiction queries to generate (FC-3) */
    private const CONTRADICTION_QUERY_COUNT = 2;

    /** @var int Cache TTL for contradiction queries (1 hour) */
    private const CONTRADICTION_CACHE_TTL = 3600;

    /** @var array Recency score decay by age in days */
    private const RECENCY_DECAY = [
        7 => 1.0,      // Last week: full score
        30 => 0.9,     // Last month: 90%
        90 => 0.8,     // Last 3 months: 80%
        365 => 0.6,    // Last year: 60%
        730 => 0.4,    // Last 2 years: 40%
        'older' => 0.3, // Older: 30%
    ];

    public function __construct(
        AIService $aiService,
        RAGService $ragService,
        SearXNGService $searxngService
    ) {
        $this->aiService = $aiService;
        $this->ragService = $ragService;
        $this->searxngService = $searxngService;
    }

    private function getDomainCredibilityService(): DomainCredibilityService
    {
        if ($this->domainCredibilityService === null) {
            $this->domainCredibilityService = app(DomainCredibilityService::class);
        }
        return $this->domainCredibilityService;
    }

    /**
     * Retrieve evidence for a claim from all available sources
     *
     * @param string $claim The claim to find evidence for
     * @param array $options Options:
     *   - query_count: int - Number of search queries to generate (default: 3)
     *   - max_results: int - Max total evidence items (default: 20)
     *   - sources: array - Which sources to search ['rag', 'web', 'research']
     *   - time_range: string - Web search time range (day, week, month, year)
     *   - skip_dedup: bool - Skip deduplication (default: false)
     *   - min_credibility: float - Minimum credibility score (default: 0.3)
     *   - contradiction_search: bool - Run dedicated contradiction queries (FC-3, default: true)
     * @return array Evidence items ranked by relevance
     */
    public function retrieve(string $claim, array $options = []): array
    {
        $startTime = microtime(true);
        $queryCount = $options['query_count'] ?? self::DEFAULT_QUERY_COUNT;
        $maxResults = $options['max_results'] ?? 20;
        $sources = $options['sources'] ?? ['rag', 'web', 'research'];
        $timeRange = $options['time_range'] ?? '';
        $skipDedup = $options['skip_dedup'] ?? false;
        $minCredibility = $options['min_credibility'] ?? 0.3;
        $contradictionSearch = $options['contradiction_search'] ?? true;

        $allEvidence = [];
        $stats = [
            'queries_generated' => 0,
            'contradiction_queries_generated' => 0,
            'rag_results' => 0,
            'web_results' => 0,
            'research_results' => 0,
            'contradiction_results' => 0,
            'pre_dedup_count' => 0,
            'post_dedup_count' => 0,
            'source_timings' => [],
        ];

        try {
            // Step 1: Generate diverse search queries
            $queries = $this->generateQueries($claim, $queryCount);
            $stats['queries_generated'] = count($queries);

            Log::info('EvidenceRetriever: Generated queries', [
                'claim' => substr($claim, 0, 100),
                'queries' => $queries,
            ]);

            // Step 2: Search each source
            if (in_array('rag', $sources)) {
                $sourceStart = microtime(true);
                $ragEvidence = $this->searchRAG($claim, $queries);
                $allEvidence = array_merge($allEvidence, $ragEvidence);
                $stats['rag_results'] = count($ragEvidence);
                $stats['source_timings']['rag'] = round((microtime(true) - $sourceStart) * 1000);
            }

            if (in_array('web', $sources)) {
                $sourceStart = microtime(true);
                $webEvidence = $this->searchWeb($queries, $timeRange);
                $allEvidence = array_merge($allEvidence, $webEvidence);
                $stats['web_results'] = count($webEvidence);
                $stats['source_timings']['web'] = round((microtime(true) - $sourceStart) * 1000);
            }

            if (in_array('research', $sources)) {
                $sourceStart = microtime(true);
                $researchEvidence = $this->searchCachedResearch($claim, $queries);
                $allEvidence = array_merge($allEvidence, $researchEvidence);
                $stats['research_results'] = count($researchEvidence);
                $stats['source_timings']['research'] = round((microtime(true) - $sourceStart) * 1000);
            }

            // Tag all evidence collected so far as 'general' intent
            foreach ($allEvidence as &$item) {
                $item['retrieval_intent'] = 'general';
            }
            unset($item);

            // FC-3: Contradiction-Driven Evidence Retrieval
            // Generate dedicated negated/opposing queries and search for disproving evidence
            if ($contradictionSearch) {
                $sourceStart = microtime(true);
                $contradictionEvidence = $this->searchContradictionEvidence($claim, $sources, $timeRange);
                $stats['contradiction_queries_generated'] = $contradictionEvidence['queries_generated'];
                $stats['contradiction_results'] = $contradictionEvidence['result_count'];
                $allEvidence = array_merge($allEvidence, $contradictionEvidence['evidence']);
                $stats['source_timings']['contradiction'] = round((microtime(true) - $sourceStart) * 1000);

                Log::info('EvidenceRetriever: Contradiction search complete', [
                    'claim' => substr($claim, 0, 80),
                    'queries' => $contradictionEvidence['queries_generated'],
                    'results' => $contradictionEvidence['result_count'],
                ]);
            }

            $stats['pre_dedup_count'] = count($allEvidence);

            // Step 3: Score and filter evidence
            $scoredEvidence = $this->scoreEvidence($allEvidence, $claim);

            // Step 4: Filter by minimum credibility
            $filteredEvidence = array_filter(
                $scoredEvidence,
                fn($e) => ($e['credibility_score'] ?? 0.5) >= $minCredibility
            );

            // Step 5: Deduplicate
            if (!$skipDedup) {
                $filteredEvidence = $this->deduplicateEvidence(array_values($filteredEvidence));
            }

            $stats['post_dedup_count'] = count($filteredEvidence);

            // Step 6: Rank by combined score and limit
            $rankedEvidence = $this->rankEvidence($filteredEvidence, $maxResults);

            $duration = round((microtime(true) - $startTime) * 1000);

            Log::info('EvidenceRetriever: Retrieval complete', [
                'claim' => substr($claim, 0, 80),
                'results' => count($rankedEvidence),
                'duration_ms' => $duration,
                'stats' => $stats,
            ]);

            return [
                'success' => true,
                'claim' => $claim,
                'evidence' => $rankedEvidence,
                'queries_used' => $queries,
                'contradiction_queries_used' => $contradictionSearch ? ($contradictionEvidence['queries'] ?? []) : [],
                'stats' => $stats,
                'duration_ms' => $duration,
            ];

        } catch (Exception $e) {
            Log::error('EvidenceRetriever: Retrieval failed', [
                'claim' => substr($claim, 0, 100),
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'claim' => $claim,
                'evidence' => [],
                'error' => $e->getMessage(),
                'stats' => $stats,
            ];
        }
    }

    /**
     * Generate diverse search queries for a claim (Loki pattern)
     *
     * @param string $claim The claim to generate queries for
     * @param int $count Number of queries to generate
     * @return array Array of search query strings
     */
    public function generateQueries(string $claim, int $count = 3): array
    {
        // Check cache first
        $cacheKey = 'evidence_queries:' . md5($claim . $count);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $prompt = <<<PROMPT
Generate {$count} diverse search queries to find evidence about this claim:

CLAIM: "{$claim}"

Query generation rules:
1. Each query approaches the claim from a DIFFERENT angle
2. Query 1: Direct factual search (key terms from the claim)
3. Query 2: Source-seeking search (who reported this, official sources)
4. Query 3: Counter-evidence search (could find contradicting info)
5. Extract specific names, dates, numbers, organizations from the claim
6. Avoid leading or biased phrasing
7. Keep each query under 10 words for better search results

Output ONLY a JSON array of query strings (no markdown, no explanation):
["query 1", "query 2", "query 3"]
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 200,
        ]);

        if (!$result['success']) {
            Log::warning('EvidenceRetriever: Query generation failed, using claim as query', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return [$claim];
        }

        $queries = $this->parseJsonArray($result['response']);

        if (empty($queries)) {
            return [$claim];
        }

        // Validate queries are strings and limit
        $queries = array_filter($queries, 'is_string');
        $queries = array_slice($queries, 0, $count);

        // Cache successful query generation
        Cache::put($cacheKey, $queries, self::QUERY_CACHE_TTL);

        return $queries;
    }

    /**
     * Generate queries specifically designed to find contradicting evidence (FC-3)
     *
     * Unlike generateQueries() which produces general/balanced queries,
     * this generates queries that explicitly seek disproving evidence:
     * negated claims, alternative explanations, debunking sources.
     *
     * @param string $claim The claim to find contradictions for
     * @return array Array of contradiction-seeking query strings
     */
    public function generateContradictionQueries(string $claim): array
    {
        $cacheKey = 'contradiction_queries:' . md5($claim);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $count = self::CONTRADICTION_QUERY_COUNT;

        $prompt = <<<PROMPT
Generate {$count} search queries specifically designed to find evidence that CONTRADICTS or DISPROVES this claim:

CLAIM: "{$claim}"

Query generation rules:
1. Each query should actively seek OPPOSING evidence
2. Use negation, alternative facts, or debunking angles
3. Examples of good contradiction queries:
   - For "X happened in 1889": search "X NOT 1889" or "X actual date" or "X date controversy"
   - For "Y causes Z": search "Y does not cause Z" or "Z alternative causes" or "Y Z debunked"
   - For "Person did X": search "Person never did X" or "Person X disputed" or "Person X myth"
4. Include queries that search for fact-check articles about the claim
5. Keep each query under 10 words
6. Do NOT just rephrase the original claim — actively seek the OPPOSITE

Output ONLY a JSON array of query strings (no markdown, no explanation):
["contradiction query 1", "contradiction query 2"]
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 200,
            'use_cache' => false,
        ]);

        if (!$result['success']) {
            Log::warning('EvidenceRetriever: Contradiction query generation failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            // Fallback: simple negation
            return [$this->buildNegatedQuery($claim)];
        }

        $queries = $this->parseJsonArray($result['response']);

        if (empty($queries)) {
            return [$this->buildNegatedQuery($claim)];
        }

        $queries = array_filter($queries, 'is_string');
        $queries = array_slice($queries, 0, $count);

        Cache::put($cacheKey, $queries, self::CONTRADICTION_CACHE_TTL);

        return $queries;
    }

    /**
     * Build a simple negated query as fallback when LLM is unavailable
     *
     * @param string $claim The claim to negate
     * @return string Negated query
     */
    private function buildNegatedQuery(string $claim): string
    {
        $terms = $this->extractKeyTerms($claim);
        if (empty($terms)) {
            return $claim . ' false debunked';
        }
        return implode(' ', array_slice($terms, 0, 3)) . ' false debunked';
    }

    /**
     * Run the contradiction evidence search across enabled sources (FC-3)
     *
     * @param string $claim The claim to find contradictions for
     * @param array $sources Enabled source types
     * @param string $timeRange Web search time range
     * @return array ['evidence' => [...], 'queries' => [...], 'queries_generated' => int, 'result_count' => int]
     */
    private function searchContradictionEvidence(string $claim, array $sources, string $timeRange): array
    {
        $queries = $this->generateContradictionQueries($claim);
        $evidence = [];

        // Search RAG with contradiction queries
        if (in_array('rag', $sources)) {
            $ragEvidence = $this->searchRAG($claim, $queries);
            foreach ($ragEvidence as &$item) {
                $item['retrieval_intent'] = 'contradiction';
            }
            unset($item);
            $evidence = array_merge($evidence, $ragEvidence);
        }

        // Search web with contradiction queries
        if (in_array('web', $sources)) {
            $webEvidence = $this->searchWeb($queries, $timeRange);
            foreach ($webEvidence as &$item) {
                $item['retrieval_intent'] = 'contradiction';
            }
            unset($item);
            $evidence = array_merge($evidence, $webEvidence);
        }

        return [
            'evidence' => $evidence,
            'queries' => $queries,
            'queries_generated' => count($queries),
            'result_count' => count($evidence),
        ];
    }

    /**
     * Search RAG index for relevant evidence
     *
     * @param string $claim Original claim for context
     * @param array $queries Search queries
     * @return array Evidence items from RAG
     */
    private function searchRAG(string $claim, array $queries): array
    {
        $evidence = [];
        $seenIds = [];

        foreach ($queries as $query) {
            try {
                $results = $this->ragService->search(
                    $query,
                    self::MAX_EVIDENCE_PER_SOURCE,
                    null, // all document types
                    'auto' // use HyDE when appropriate
                );

                foreach ($results as $result) {
                    $doc = $result['document'];
                    $docId = $doc->id ?? null;

                    // Skip duplicates within RAG results
                    if ($docId && isset($seenIds[$docId])) {
                        continue;
                    }
                    $seenIds[$docId] = true;

                    // Skip low similarity results
                    if (($result['similarity'] ?? 0) < self::RAG_SIMILARITY_THRESHOLD) {
                        continue;
                    }

                    $evidence[] = [
                        'source_type' => 'rag',
                        'snippet' => $this->truncateSnippet($doc->content ?? '', 500),
                        'title' => $doc->title ?? 'Untitled',
                        'source_url' => $doc->media_url ?? '',
                        'source_domain' => $this->extractDomain($doc->media_url ?? ''),
                        'retrieval_query' => $query,
                        'retrieval_rank' => array_search($result, $results) + 1,
                        'similarity_score' => $result['similarity'] ?? 0,
                        'document_type' => $doc->document_type ?? 'unknown',
                        'rag_document_id' => $docId,
                        'created_at' => $doc->created_at ?? null,
                    ];

                    if (count($evidence) >= self::MAX_EVIDENCE_PER_SOURCE) {
                        break 2;
                    }
                }
            } catch (Exception $e) {
                Log::warning('EvidenceRetriever: RAG search failed for query', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $evidence;
    }

    /**
     * Search web via SearXNG for evidence
     *
     * @param array $queries Search queries
     * @param string $timeRange Time range filter
     * @return array Evidence items from web search
     */
    private function searchWeb(array $queries, string $timeRange = ''): array
    {
        if (!$this->searxngService->isAvailable()) {
            Log::warning('EvidenceRetriever: SearXNG not available, skipping web search');
            return [];
        }

        $evidence = [];
        $seenUrls = [];

        foreach ($queries as $query) {
            try {
                $results = $this->searxngService->search(
                    $query,
                    self::MAX_EVIDENCE_PER_SOURCE,
                    'en',
                    $timeRange
                );

                if (!$results['success'] || empty($results['results'])) {
                    continue;
                }

                foreach ($results['results'] as $rank => $result) {
                    $url = $result['url'] ?? '';

                    // Skip duplicates within web results
                    if (!empty($url) && isset($seenUrls[$url])) {
                        continue;
                    }
                    $seenUrls[$url] = true;

                    $domain = $this->extractDomain($url);

                    $evidence[] = [
                        'source_type' => 'web',
                        'snippet' => $result['snippet'] ?? $result['content'] ?? '',
                        'title' => $result['title'] ?? 'Untitled',
                        'source_url' => $url,
                        'source_domain' => $domain,
                        'retrieval_query' => $query,
                        'retrieval_rank' => $rank + 1,
                        'search_engines' => $result['engines'] ?? [],
                        'search_score' => $result['score'] ?? null,
                        'published_at' => $result['published_at'] ?? $result['publishedDate'] ?? null,
                    ];

                    if (count($evidence) >= self::MAX_EVIDENCE_PER_SOURCE * 2) {
                        break 2;
                    }
                }
            } catch (Exception $e) {
                Log::warning('EvidenceRetriever: Web search failed for query', [
                    'query' => $query,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $evidence;
    }

    /**
     * Search cached research results for relevant evidence
     *
     * @param string $claim Original claim
     * @param array $queries Search queries
     * @return array Evidence from research cache
     */
    private function searchCachedResearch(string $claim, array $queries): array
    {
        $evidence = [];

        try {
            // Search research_results table for relevant cached findings
            // Using ILIKE for case-insensitive matching on key terms
            $terms = $this->extractKeyTerms($claim);

            if (empty($terms)) {
                return [];
            }

            $placeholders = [];
            $params = [];

            foreach ($terms as $index => $term) {
                $placeholders[] = "content ILIKE ?";
                $params[] = '%' . $term . '%';
            }

            $whereClause = implode(' OR ', $placeholders);
            $params[] = self::MAX_EVIDENCE_PER_SOURCE;

            $results = DB::connection('pgsql_rag')->select("
                SELECT id, content, source_url, source_name, relevance_score,
                       quality_score, created_at, extracted_facts
                FROM research_results
                WHERE ({$whereClause})
                  AND dedup_status IS DISTINCT FROM 'duplicate'
                ORDER BY relevance_score DESC, quality_score DESC, created_at DESC
                LIMIT ?
            ", $params);

            foreach ($results as $result) {
                $evidence[] = [
                    'source_type' => 'research',
                    'snippet' => $this->truncateSnippet($result->content ?? '', 500),
                    'title' => $result->source_name ?? 'Research Finding',
                    'source_url' => $result->source_url ?? '',
                    'source_domain' => $this->extractDomain($result->source_url ?? ''),
                    'retrieval_query' => implode(' ', $terms),
                    'retrieval_rank' => 0,
                    'relevance_score' => $result->relevance_score ?? 0.5,
                    'quality_score' => $result->quality_score ?? 0.5,
                    'research_result_id' => $result->id,
                    'extracted_facts' => $result->extracted_facts,
                    'created_at' => $result->created_at,
                ];
            }
        } catch (Exception $e) {
            Log::warning('EvidenceRetriever: Research cache search failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $evidence;
    }

    /**
     * Score evidence items for credibility and relevance
     *
     * @param array $evidence Raw evidence items
     * @param string $claim The claim being verified
     * @return array Evidence with scores added
     */
    private function scoreEvidence(array $evidence, string $claim): array
    {
        foreach ($evidence as &$item) {
            // Domain credibility score
            $item['credibility_score'] = $this->getCredibilityScore($item['source_domain'] ?? '');

            // Recency score
            $item['recency_score'] = $this->getRecencyScore($item);

            // Calculate combined relevance score
            $baseRelevance = $this->calculateRelevanceScore($item, $claim);

            // Weight: 40% relevance, 35% credibility, 25% recency
            $item['combined_score'] = (
                ($baseRelevance * 0.40) +
                ($item['credibility_score'] * 0.35) +
                ($item['recency_score'] * 0.25)
            );
        }

        return $evidence;
    }

    /**
     * Calculate base relevance score for an evidence item
     *
     * @param array $item Evidence item
     * @param string $claim Original claim
     * @return float Relevance score 0-1
     */
    private function calculateRelevanceScore(array $item, string $claim): float
    {
        $sourceType = $item['source_type'] ?? 'unknown';

        switch ($sourceType) {
            case 'rag':
                // RAG similarity is already a relevance measure
                return $item['similarity_score'] ?? 0.5;

            case 'web':
                // Use search rank and search score
                $rankScore = 1 - (($item['retrieval_rank'] ?? 10) / 20);
                $searchScore = $item['search_score'] ?? 0.5;
                return ($rankScore + $searchScore) / 2;

            case 'research':
                // Use stored relevance and quality scores
                $relevance = $item['relevance_score'] ?? 0.5;
                $quality = $item['quality_score'] ?? 0.5;
                return ($relevance * 0.6 + $quality * 0.4);

            default:
                return 0.5;
        }
    }

    /**
     * Get credibility score for a domain (from shared domain_credibility table)
     *
     * @param string $domain Domain name
     * @return float Credibility score 0-1
     */
    public function getCredibilityScore(string $domain): float
    {
        return $this->getDomainCredibilityService()->getScore($domain);
    }

    /**
     * Get recency score based on content age
     *
     * @param array $item Evidence item
     * @return float Recency score 0-1
     */
    private function getRecencyScore(array $item): float
    {
        $dateStr = $item['published_at'] ?? $item['created_at'] ?? null;

        if (empty($dateStr)) {
            return 0.5; // Unknown date, neutral score
        }

        try {
            $date = new \DateTime($dateStr);
            $now = new \DateTime();
            $diff = $now->diff($date);
            $days = $diff->days;

            foreach (self::RECENCY_DECAY as $threshold => $score) {
                if ($threshold === 'older') {
                    return $score;
                }
                if ($days <= $threshold) {
                    return $score;
                }
            }
        } catch (Exception $e) {
            return 0.5;
        }

        return self::RECENCY_DECAY['older'];
    }

    /**
     * Deduplicate evidence items using URL and semantic similarity
     *
     * @param array $evidence Evidence items to deduplicate
     * @return array Deduplicated evidence
     */
    private function deduplicateEvidence(array $evidence): array
    {
        if (count($evidence) <= 1) {
            return $evidence;
        }

        $unique = [];
        $seenUrls = [];
        $seenSnippetHashes = [];

        foreach ($evidence as $item) {
            $url = $item['source_url'] ?? '';
            $snippet = $item['snippet'] ?? '';

            // Layer 1: Exact URL deduplication
            if (!empty($url)) {
                $normalizedUrl = $this->normalizeUrl($url);
                if (isset($seenUrls[$normalizedUrl])) {
                    continue;
                }
                $seenUrls[$normalizedUrl] = true;
            }

            // Layer 2: Content hash deduplication
            $snippetHash = md5($this->normalizeSnippet($snippet));
            if (isset($seenSnippetHashes[$snippetHash])) {
                continue;
            }
            $seenSnippetHashes[$snippetHash] = true;

            // Layer 3: Fuzzy deduplication using Jaccard similarity
            $dominated = false;
            foreach ($unique as $existingItem) {
                $similarity = $this->jaccardSimilarity(
                    $snippet,
                    $existingItem['snippet'] ?? ''
                );

                if ($similarity >= self::DEDUP_SIMILARITY_THRESHOLD) {
                    // Keep the one with higher combined score
                    $existingScore = $existingItem['combined_score'] ?? 0;
                    $newScore = $item['combined_score'] ?? 0;

                    if ($existingScore >= $newScore) {
                        $dominated = true;
                        break;
                    }
                }
            }

            if (!$dominated) {
                $unique[] = $item;
            }
        }

        return $unique;
    }

    /**
     * Rank evidence by combined score
     *
     * @param array $evidence Scored evidence items
     * @param int $limit Maximum items to return
     * @return array Ranked and limited evidence
     */
    private function rankEvidence(array $evidence, int $limit): array
    {
        // Sort by combined score descending
        usort($evidence, function ($a, $b) {
            return ($b['combined_score'] ?? 0) <=> ($a['combined_score'] ?? 0);
        });

        // Apply limit and add rank
        $ranked = [];
        foreach (array_slice($evidence, 0, $limit) as $index => $item) {
            $item['rank'] = $index + 1;
            $ranked[] = $item;
        }

        return $ranked;
    }

    /**
     * Persist evidence to database
     *
     * @param int $claimId Associated claim ID
     * @param array $evidence Evidence items to persist
     * @return int Number of items persisted
     */
    public function persistEvidence(int $claimId, array $evidence): int
    {
        $persisted = 0;

        foreach ($evidence as $item) {
            try {
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO evidence (
                        claim_id, snippet, source_url, source_title, source_domain,
                        credibility_score, retrieval_query, retrieval_rank, retrieval_intent, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ", [
                    $claimId,
                    $item['snippet'] ?? '',
                    $item['source_url'] ?? '',
                    $item['title'] ?? '',
                    $item['source_domain'] ?? '',
                    $item['credibility_score'] ?? 0.5,
                    $item['retrieval_query'] ?? '',
                    $item['rank'] ?? $item['retrieval_rank'] ?? 0,
                    $item['retrieval_intent'] ?? 'general',
                ]);
                $persisted++;
            } catch (Exception $e) {
                Log::warning('EvidenceRetriever: Failed to persist evidence', [
                    'claim_id' => $claimId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $persisted;
    }

    /**
     * Get existing evidence for a claim
     *
     * @param int $claimId Claim ID
     * @param float $minCredibility Minimum credibility score
     * @return array Evidence records
     */
    public function getEvidenceForClaim(int $claimId, float $minCredibility = 0.0): array
    {
        return DB::connection('pgsql_rag')->select("
            SELECT *
            FROM evidence
            WHERE claim_id = ?
              AND credibility_score >= ?
            ORDER BY credibility_score DESC, nli_score DESC
        ", [$claimId, $minCredibility]);
    }

    /**
     * Get evidence statistics
     *
     * @return array Statistics about stored evidence
     */
    public function getStats(): array
    {
        $totalCount = DB::connection('pgsql_rag')->select("
            SELECT COUNT(*) as count FROM evidence
        ")[0]->count ?? 0;

        $byDomain = DB::connection('pgsql_rag')->select("
            SELECT source_domain, COUNT(*) as count, AVG(credibility_score) as avg_credibility
            FROM evidence
            WHERE source_domain IS NOT NULL AND source_domain != ''
            GROUP BY source_domain
            ORDER BY count DESC
            LIMIT 20
        ");

        $byNliLabel = DB::connection('pgsql_rag')->select("
            SELECT nli_label, COUNT(*) as count
            FROM evidence
            GROUP BY nli_label
        ");

        $byRetrievalIntent = DB::connection('pgsql_rag')->select("
            SELECT COALESCE(retrieval_intent, 'general') as intent, COUNT(*) as count
            FROM evidence
            GROUP BY COALESCE(retrieval_intent, 'general')
        ");

        return [
            'total_evidence' => $totalCount,
            'by_domain' => $byDomain,
            'by_nli_label' => $byNliLabel,
            'by_retrieval_intent' => $byRetrievalIntent,
        ];
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Parse JSON array from AI response
     */
    private function parseJsonArray(string $response): array
    {
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): string
    {
        if (empty($url)) {
            return '';
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return '';
        }

        // Remove www. prefix
        return preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * Truncate snippet to max length
     */
    private function truncateSnippet(string $text, int $maxLength = 500): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Normalize URL for comparison
     */
    private function normalizeUrl(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://(www\.)?#', '', $url);
        $url = rtrim($url, '/');
        // Remove common tracking parameters
        $url = preg_replace('/[?&](utm_|fbclid|gclid)[^&]*/', '', $url);
        return $url;
    }

    /**
     * Normalize snippet for comparison
     */
    private function normalizeSnippet(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Extract key terms from claim for search
     */
    private function extractKeyTerms(string $claim): array
    {
        // Remove common stopwords and extract significant terms
        $stopwords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would',
            'could', 'should', 'may', 'might', 'must', 'shall', 'can', 'of', 'in',
            'to', 'for', 'on', 'with', 'at', 'by', 'from', 'as', 'and', 'or', 'but',
            'if', 'then', 'than', 'that', 'this', 'it', 'its'];

        $words = preg_split('/\s+/', strtolower($claim));
        $words = array_filter($words, function ($word) use ($stopwords) {
            $word = preg_replace('/[^a-z0-9]/', '', $word);
            return strlen($word) > 2 && !in_array($word, $stopwords);
        });

        // Return up to 5 significant terms
        return array_slice(array_values(array_unique($words)), 0, 5);
    }

    /**
     * Calculate Jaccard similarity between two texts
     */
    private function jaccardSimilarity(string $text1, string $text2): float
    {
        $words1 = array_unique(preg_split('/\s+/', strtolower($text1)));
        $words2 = array_unique(preg_split('/\s+/', strtolower($text2)));

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        if (empty($union)) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }
}
