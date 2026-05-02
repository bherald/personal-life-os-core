<?php

namespace App\Services\Research;

use App\DTOs\TrustEnvelope;
use App\Services\AIService;
use App\Services\TrustBoundaryFormatterService;
use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;
use App\Traits\RecursionAware;

/**
 * UniversalResearchOrchestrator - Coordinates all research services
 *
 * Implements the complete research pipeline:
 * 1. DISCOVER: Find sources for the topic
 * 2. GATHER: Scrape/API each source safely
 * 3. EXTRACT: Pull facts from raw content
 * 4. VERIFY: Multi-method verification
 * 5. SYNTHESIZE: AI creates coherent report
 * 6. INDEX: Store verified facts in RAG
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class UniversalResearchOrchestrator
{
    use RecursionAware;

    private AIService $aiService;
    private DynamicSourceDiscoveryService $discoveryService;
    private LLMKnowledgeVettingService $vettingService;
    private SafeScrapingService $scrapingService;
    private ?TrustBoundaryFormatterService $trustBoundaryFormatter = null;
    private string $connection = 'pgsql_rag';

    // Mission phases
    private const PHASE_DISCOVER = 'discover';
    private const PHASE_GATHER = 'gather';
    private const PHASE_EXTRACT = 'extract';
    private const PHASE_VERIFY = 'verify';
    private const PHASE_SYNTHESIZE = 'synthesize';
    private const PHASE_INDEX = 'index';
    private const PHASE_COMPLETE = 'complete';

    public function __construct(
        AIService $aiService,
        DynamicSourceDiscoveryService $discoveryService,
        LLMKnowledgeVettingService $vettingService,
        SafeScrapingService $scrapingService
    ) {
        $this->aiService = $aiService;
        $this->discoveryService = $discoveryService;
        $this->vettingService = $vettingService;
        $this->scrapingService = $scrapingService;
    }

    private function trustBoundaryFormatter(): TrustBoundaryFormatterService
    {
        return $this->trustBoundaryFormatter ??= app(TrustBoundaryFormatterService::class);
    }

    /**
     * Create a new research mission
     */
    public function createMission(array $params): array
    {
        $missionId = $this->generateUuid();

        try {
            DB::connection($this->connection)->insert("
                INSERT INTO research_missions (
                    id, title, description, mission_type, domain_category,
                    query_template, constraints, depth_level, verification_level,
                    max_sources, time_limit_minutes, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?::jsonb, ?, ?,
                    ?, ?, ?
                )
            ", [
                $missionId,
                $params['title'] ?? 'Research Mission',
                $params['description'] ?? null,
                $params['mission_type'] ?? 'knowledge_capture',
                $params['domain_category'] ?? 'general',
                $params['query'] ?? $params['title'],
                json_encode($params['constraints'] ?? []),
                $params['depth_level'] ?? 3,
                $params['verification_level'] ?? 'strict',
                $params['max_sources'] ?? 20,
                $params['time_limit_minutes'] ?? 30,
                $params['created_by'] ?? 'user',
            ]);

            Log::info('Research mission created', ['mission_id' => $missionId, 'title' => $params['title'] ?? 'unknown']);

            return [
                'success' => true,
                'mission_id' => $missionId,
                'status' => 'pending',
            ];

        } catch (Exception $e) {
            Log::error('Failed to create mission', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute a research mission
     */
    public function executeMission(string $missionId, array $options = []): array
    {
        $skipRecursive = !empty($options['skip_recursive']);
        $traceTiming = !empty($options['trace_timing']);

        if (!$skipRecursive) {
            // RLM: Try recursive research orchestration
            $rlm = $this->tryRecursive('universal_research', 'partition_map', ['mission_id' => $missionId, 'options' => $options], function ($ctx) {
                return $this->executeMission($ctx['mission_id'] ?? $ctx['data'], $ctx['options'] ?? []);
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        $mission = $this->getMission($missionId);
        if (!$mission) {
            return ['success' => false, 'error' => 'Mission not found'];
        }

        $startTime = microtime(true);
        $timeLimit = ($mission['time_limit_minutes'] ?? 30) * 60;
        $phaseStartedAt = $startTime;

        $logPhase = function (string $phase, array $extra = []) use (&$phaseStartedAt, $startTime, $traceTiming, $missionId, $skipRecursive): void {
            if (!$traceTiming) {
                return;
            }

            $now = microtime(true);
            Log::info('Research mission timing', array_merge([
                'mission_id' => $missionId,
                'phase' => $phase,
                'phase_ms' => (int) (($now - $phaseStartedAt) * 1000),
                'total_ms' => (int) (($now - $startTime) * 1000),
                'skip_recursive' => $skipRecursive,
            ], $extra));
            $phaseStartedAt = $now;
        };

        try {
            // Update status to active
            $this->updateMissionStatus($missionId, 'active', [
                'started_at' => now()->toIso8601String(),
            ]);

            // Phase 1: DISCOVER sources
            $this->updateMissionPhase($missionId, self::PHASE_DISCOVER, 10);
            $discoveryResult = $this->discoverPhase($mission);
            $logPhase(self::PHASE_DISCOVER, [
                'sources_found' => count($discoveryResult['sources'] ?? []),
                'success' => !empty($discoveryResult['success']),
            ]);

            if (!$discoveryResult['success'] || empty($discoveryResult['sources'])) {
                $this->updateMissionStatus($missionId, 'failed', [
                    'last_error' => 'No sources discovered',
                ]);
                return ['success' => false, 'error' => 'No sources discovered'];
            }

            // Check time limit
            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'discover');
            }

            // Phase 2: GATHER content from sources
            $this->updateMissionPhase($missionId, self::PHASE_GATHER, 30);
            $gatherResult = $this->gatherPhase($mission, $discoveryResult['sources']);
            $logPhase(self::PHASE_GATHER, [
                'successful_sources' => count($gatherResult['successful_sources'] ?? []),
                'content_items' => count($gatherResult['content'] ?? []),
            ]);

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'gather');
            }

            // Phase 3: EXTRACT facts from content
            $this->updateMissionPhase($missionId, self::PHASE_EXTRACT, 50);
            $extractResult = $this->extractPhase($mission, $gatherResult['content']);

            // Assess content quality - if poor, rely more heavily on LLM knowledge
            $webFactCount = count($extractResult['facts'] ?? []);
            $contentQuality = $this->assessContentQuality($gatherResult['content'], $webFactCount);

            Log::warning('Content quality assessment', [
                'mission_id' => $missionId,
                'web_facts' => $webFactCount,
                'quality_score' => $contentQuality['score'],
                'quality_level' => $contentQuality['level'],
            ]);

            // Extract LLM knowledge - more aggressively if web content is poor
            $llmKnowledge = $this->extractEnhancedLLMKnowledge(
                $mission['query_template'],
                $mission['domain_category'],
                $contentQuality['level'],
                $extractResult['facts'] ?? []
            );

            // Merge extracted facts (LLM facts marked with lower initial confidence)
            $allFacts = array_merge(
                $extractResult['facts'] ?? [],
                $llmKnowledge['facts'] ?? []
            );
            $logPhase(self::PHASE_EXTRACT, [
                'web_facts' => $webFactCount,
                'llm_facts' => count($llmKnowledge['facts'] ?? []),
                'total_facts' => count($allFacts),
            ]);

            Log::warning('Facts merged', [
                'web_facts' => $webFactCount,
                'llm_facts' => count($llmKnowledge['facts'] ?? []),
                'total_facts' => count($allFacts),
            ]);

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'extract');
            }

            // Phase 4: VERIFY facts
            $this->updateMissionPhase($missionId, self::PHASE_VERIFY, 70);
            $verifyResult = $this->verifyPhase($mission, $allFacts, $options);
            $logPhase(self::PHASE_VERIFY, [
                'verified_facts' => count($verifyResult['verified_facts'] ?? []),
                'verification_attempted' => $verifyResult['attempted_facts'] ?? null,
                'verification_skipped' => $verifyResult['skipped_facts'] ?? null,
            ]);

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'verify');
            }

            // Phase 5: SYNTHESIZE report
            $this->updateMissionPhase($missionId, self::PHASE_SYNTHESIZE, 85);
            $synthesisResult = $this->synthesizePhase($mission, $verifyResult['verified_facts'], $gatherResult['content']);
            $logPhase(self::PHASE_SYNTHESIZE, [
                'report_chars' => strlen($synthesisResult['report'] ?? ''),
            ]);

            // Phase 6: INDEX verified facts to RAG
            $this->updateMissionPhase($missionId, self::PHASE_INDEX, 95);
            $indexResult = $this->indexPhase($missionId, $verifyResult['verified_facts'], $synthesisResult['report']);
            $logPhase(self::PHASE_INDEX, [
                'indexed_facts' => $indexResult['indexed_count'] ?? count($verifyResult['verified_facts'] ?? []),
            ]);

            // Store the report in the mission record
            $this->storeReport($missionId, $synthesisResult['report']);

            // Mark complete
            $this->updateMissionStatus($missionId, 'completed', [
                'completed_at' => now()->toIso8601String(),
                'facts_discovered' => count($allFacts),
                'facts_verified' => count($verifyResult['verified_facts']),
                'sources_discovered' => count($discoveryResult['sources']),
                'sources_used' => count($gatherResult['successful_sources'] ?? []),
            ]);

            $this->updateMissionPhase($missionId, self::PHASE_COMPLETE, 100);
            $logPhase(self::PHASE_COMPLETE);

            $duration = microtime(true) - $startTime;

            Log::info('Research mission completed', [
                'mission_id' => $missionId,
                'duration_seconds' => round($duration, 2),
                'facts_discovered' => count($allFacts),
                'facts_verified' => count($verifyResult['verified_facts']),
            ]);

            return [
                'success' => true,
                'mission_id' => $missionId,
                'duration_seconds' => round($duration, 2),
                'sources_discovered' => count($discoveryResult['sources']),
                'sources_used' => count($gatherResult['successful_sources'] ?? []),
                'facts_discovered' => count($allFacts),
                'facts_verified' => count($verifyResult['verified_facts']),
                'facts_indexed' => $indexResult['indexed_count'],
                'report' => $synthesisResult['report'],
            ];

        } catch (Exception $e) {
            Log::error('Mission execution failed', [
                'mission_id' => $missionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateMissionStatus($missionId, 'failed', [
                'last_error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Phase 1: Discover sources for the topic
     */
    public function discoverPhase(array $mission): array
    {
        $query = $mission['query_template'];
        $category = $mission['domain_category'];
        $maxSources = $mission['max_sources'] ?? 20;

        Log::warning('DiscoverPhase starting', [
            'category' => $category,
            'max_sources' => $maxSources,
        ]);

        // Get existing specialized/curated sources FIRST (most reliable)
        $existing = $this->discoveryService->findSpecializedSources($category, $maxSources);

        Log::warning('DiscoverPhase found curated sources', [
            'count' => count($existing),
            'sources' => array_map(fn($s) => $s['name'] ?? $s['domain'] ?? 'unknown', $existing),
        ]);

        // Only discover dynamic sources if we need more
        $discovered = [];
        if (count($existing) < $maxSources) {
            $discovered = $this->discoveryService->discoverSourcesForTopic($query, $category, $maxSources - count($existing));
            Log::warning('DiscoverPhase discovered new sources', [
                'count' => count($discovered['sources'] ?? []),
            ]);
        }

        // Merge and deduplicate (prefer curated sources)
        $allSources = [];
        $seenDomains = [];

        // Add curated first
        foreach ($existing as $source) {
            $domain = $source['domain'] ?? '';
            if ($domain && !isset($seenDomains[$domain])) {
                $seenDomains[$domain] = true;
                $allSources[] = $source;
            }
        }

        // Then add discovered
        foreach ($discovered['sources'] ?? [] as $source) {
            $domain = $source['domain'] ?? '';
            if ($domain && !isset($seenDomains[$domain])) {
                $seenDomains[$domain] = true;
                $allSources[] = $source;
            }
        }

        // Sort by trust score
        usort($allSources, fn($a, $b) => ($b['trust_score'] ?? 0) <=> ($a['trust_score'] ?? 0));

        $finalSources = array_slice($allSources, 0, $maxSources);

        Log::warning('DiscoverPhase complete', [
            'total_sources' => count($finalSources),
            'with_search_template' => count(array_filter($finalSources, fn($s) => !empty($s['search_url_template']))),
        ]);

        return [
            'success' => !empty($allSources),
            'sources' => $finalSources,
            'discovered_new' => count($discovered['sources'] ?? []),
            'existing_specialized' => count($existing),
        ];
    }

    /**
     * Phase 2: Gather content from discovered sources
     * Now includes follow-links capability to get actual article content
     */
    public function gatherPhase(array $mission, array $sources): array
    {
        $content = [];
        $successfulSources = [];
        $failedSources = [];
        $skippedSources = [];
        $query = $mission['query_template'];
        $maxArticlesPerSource = config('research.max_articles_per_source', 3);
        $followLinks = $mission['follow_links'] ?? true;

        Log::warning('GatherPhase starting', [
            'mission_id' => $mission['id'] ?? 'unknown',
            'source_count' => count($sources),
            'query' => substr($query, 0, 100),
            'follow_links' => $followLinks,
        ]);

        foreach ($sources as $source) {
            $domain = $source['domain'] ?? '';
            $sourceName = $source['name'] ?? $domain;

            // Build search URL - ONLY use sources with proper search capability
            if (!empty($source['search_url_template'])) {
                $url = str_replace('{query}', urlencode($query), $source['search_url_template']);
            } elseif (!empty($source['is_search_engine']) && !empty($source['full_url'])) {
                // Fallback: append query params for known search engines
                $url = $source['full_url'] . (strpos($source['full_url'], '?') === false ? '?' : '&');
                $url .= http_build_query(['q' => $query]);
            } else {
                // Skip sources without search capability - don't scrape random homepages
                $skippedSources[] = [
                    'domain' => $domain,
                    'reason' => 'No search_url_template defined',
                ];
                continue;
            }

            // Validate URL before scraping — malformed templates produce "Invalid URL" errors
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $skippedSources[] = [
                    'domain' => $domain,
                    'reason' => "Invalid URL after template substitution: {$url}",
                ];
                continue;
            }

            // Check if this is an API source (already returns structured data)
            $isApiSource = ($source['source_type'] ?? '') === 'api';

            $scrapeResult = $this->scrapingService->scrape($url, [
                'timeout' => 20,
                'is_api' => $isApiSource,
            ]);

            if ($scrapeResult['success'] && !empty($scrapeResult['content'])) {
                $contentLength = strlen($scrapeResult['content'] ?? '');

                // For API sources (like PubMed), content is already article data
                if (!empty($scrapeResult['api'])) {
                    $content[] = [
                        'source' => $source,
                        'url' => $url,
                        'content' => $scrapeResult['content'] ?? '',
                        'title' => $scrapeResult['title'] ?? '',
                        'links' => [],
                        'is_api' => true,
                    ];
                    $successfulSources[] = $sourceName;

                    Log::warning('GatherPhase API response', [
                        'source' => $sourceName,
                        'content_length' => $contentLength,
                        'articles' => $scrapeResult['article_count'] ?? 0,
                    ]);
                }
                // For HTML sources, try to follow links to actual articles
                elseif ($followLinks && !empty($scrapeResult['links'])) {
                    // First add the search results page content
                    $content[] = [
                        'source' => $source,
                        'url' => $url,
                        'content' => $scrapeResult['content'] ?? '',
                        'title' => $scrapeResult['title'] ?? '',
                        'links' => $scrapeResult['links'] ?? [],
                        'is_search_results' => true,
                    ];

                    // Then follow relevant links to get actual article content
                    $articlesFollowed = $this->followRelevantLinks(
                        $scrapeResult['links'],
                        $source,
                        $query,
                        $maxArticlesPerSource
                    );

                    foreach ($articlesFollowed as $article) {
                        $content[] = $article;
                    }

                    $successfulSources[] = $sourceName;

                    Log::warning('GatherPhase with follow-links', [
                        'source' => $sourceName,
                        'search_content_length' => $contentLength,
                        'articles_followed' => count($articlesFollowed),
                    ]);
                } else {
                    // Just use the scraped content as-is
                    $content[] = [
                        'source' => $source,
                        'url' => $url,
                        'content' => $scrapeResult['content'] ?? '',
                        'title' => $scrapeResult['title'] ?? '',
                        'links' => $scrapeResult['links'] ?? [],
                    ];
                    $successfulSources[] = $sourceName;

                    Log::warning('GatherPhase scraped', [
                        'source' => $sourceName,
                        'content_length' => $contentLength,
                    ]);
                }
            } else {
                $failedSources[] = [
                    'domain' => $domain,
                    'error' => $scrapeResult['error'] ?? 'Empty content',
                ];

                Log::warning('GatherPhase failed', [
                    'source' => $sourceName,
                    'error' => $scrapeResult['error'] ?? 'Empty content',
                ]);
            }

            // Rate limiting between requests
            usleep(500000); // 500ms
        }

        Log::warning('GatherPhase complete', [
            'successful' => count($successfulSources),
            'failed' => count($failedSources),
            'skipped' => count($skippedSources),
            'content_items' => count($content),
        ]);

        return [
            'success' => !empty($content),
            'content' => $content,
            'successful_sources' => $successfulSources,
            'failed_sources' => $failedSources,
            'skipped_sources' => $skippedSources,
        ];
    }

    /**
     * Follow relevant links from search results to get actual article content
     */
    private function followRelevantLinks(array $links, array $source, string $query, int $maxArticles = 3): array
    {
        $articles = [];
        $sourceDomain = $source['domain'] ?? '';
        $queryWords = array_filter(explode(' ', strtolower($query)));
        $followed = 0;

        // Filter and score links by relevance
        $scoredLinks = [];
        foreach ($links as $link) {
            $linkUrl = $link['url'] ?? '';
            $linkText = strtolower($link['text'] ?? '');

            // Skip external links, navigation, etc.
            if (empty($linkUrl)) continue;
            if (!str_contains($linkUrl, $sourceDomain) && !str_starts_with($linkUrl, '/')) continue;
            if (preg_match('/\/(login|signup|subscribe|about|contact|privacy|terms)/i', $linkUrl)) continue;
            if (strlen($linkText) < 10) continue;

            // Score by query word matches in link text
            $score = 0;
            foreach ($queryWords as $word) {
                if (strlen($word) > 2 && str_contains($linkText, $word)) {
                    $score++;
                }
            }

            // Boost for article-like URLs
            if (preg_match('/\/(article|news|research|study|report|paper|publication)/i', $linkUrl)) {
                $score += 2;
            }

            if ($score > 0) {
                $scoredLinks[] = ['link' => $link, 'score' => $score];
            }
        }

        // Sort by score and take top matches
        usort($scoredLinks, fn($a, $b) => $b['score'] <=> $a['score']);
        $topLinks = array_slice($scoredLinks, 0, $maxArticles);

        foreach ($topLinks as $item) {
            $link = $item['link'];
            $linkUrl = $link['url'];

            // Make absolute URL if relative
            if (str_starts_with($linkUrl, '/')) {
                $baseUrl = $source['base_url'] ?? "https://{$sourceDomain}";
                $linkUrl = rtrim($baseUrl, '/') . $linkUrl;
            }

            try {
                $articleResult = $this->scrapingService->scrape($linkUrl, [
                    'timeout' => 15,
                ]);

                if ($articleResult['success'] && !empty($articleResult['content'])) {
                    $contentLength = strlen($articleResult['content']);

                    // Only add if content is substantial
                    if ($contentLength > 500) {
                        $articles[] = [
                            'source' => $source,
                            'url' => $linkUrl,
                            'content' => $articleResult['content'],
                            'title' => $articleResult['title'] ?? $link['text'] ?? '',
                            'links' => [],
                            'is_followed_article' => true,
                        ];
                        $followed++;

                        Log::debug('Followed link to article', [
                            'url' => $linkUrl,
                            'content_length' => $contentLength,
                        ]);
                    }
                }

            } catch (Exception $e) {
                Log::debug('Failed to follow link', [
                    'url' => $linkUrl,
                    'error' => $e->getMessage(),
                ]);
            }

            // Rate limiting
            usleep(300000); // 300ms
        }

        return $articles;
    }

    /**
     * Phase 3: Extract facts from gathered content
     */
    public function extractPhase(array $mission, array $content): array
    {
        $allFacts = [];
        $query = $mission['query_template'];
        $category = $mission['domain_category'];

        Log::warning('ExtractPhase starting', [
            'mission_id' => $mission['id'] ?? 'unknown',
            'content_items' => count($content),
            'query' => substr($query, 0, 100),
        ]);

        foreach ($content as $idx => $item) {
            $sourceContent = $item['content'] ?? '';
            $contentLen = strlen($sourceContent);

            if ($contentLen < 100) {
                Log::warning('ExtractPhase skipping short content', [
                    'source' => $item['source']['domain'] ?? 'unknown',
                    'content_length' => $contentLen,
                ]);
                continue;
            }

            // Truncate content to manageable size for fast extraction
            // Reduced from 15K to 5K to avoid Ollama timeouts on single-GPU systems
            $wrappedContent = $this->trustBoundaryFormatter()->format(new TrustEnvelope(
                sourceType: 'scraped_web',
                contentType: 'text/plain',
                origin: (string) ($item['source']['domain'] ?? 'unknown'),
                payload: $sourceContent,
                maxChars: 5000,
            ));

            // Use AI to extract facts from content (handles both articles and search results)
            $prompt = <<<PROMPT
Extract facts about: {$query}

Source: {$item['source']['domain']}
{$wrappedContent}

Return JSON array of facts. Empty [] if none.
Format: [{"statement": "fact", "confidence": 0.0-1.0, "fact_type": "date|event|claim", "source_url": "{$item['url']}"}]

Rules: specific verifiable statements only, one sentence each, max 5 facts, skip boilerplate.
PROMPT;

            try {
                $result = $this->aiService->process($prompt, [
                    'max_tokens' => 1000,
                    'factual_mode' => true,
                    'suppressAlert' => true, // Don't alert on individual source failures
                ]);

                // Log AIService result for debugging fallback behavior
                if (!($result['success'] ?? true)) {
                    Log::warning('ExtractPhase AIService failed, continuing to next source', [
                        'source' => $item['source']['domain'] ?? 'unknown',
                        'error' => $result['error'] ?? 'unknown',
                        'provider' => $result['provider'] ?? 'unknown',
                        'attempts' => $result['attempts'] ?? [],
                    ]);
                    continue;
                }

                $factsExtracted = 0;
                // AIService returns 'response' not 'content'
                $aiResponse = $result['response'] ?? $result['content'] ?? '';

                // Log which provider was used (for tracking fallback effectiveness)
                Log::debug('ExtractPhase AI response received', [
                    'source' => $item['source']['domain'] ?? 'unknown',
                    'provider' => $result['provider'] ?? 'unknown',
                    'response_length' => strlen($aiResponse),
                ]);

                if (!empty($aiResponse)) {
                    if (preg_match('/\[[\s\S]*?\]/m', $aiResponse, $matches)) {
                        $facts = json_decode($matches[0], true);
                        if (is_array($facts) && !empty($facts)) {
                            foreach ($facts as $fact) {
                                if (!empty($fact['statement'])) {
                                    $fact['domain_category'] = $category;
                                    $fact['extracted_from'] = $item['source']['domain'];
                                    $allFacts[] = $fact;
                                    $factsExtracted++;
                                }
                            }
                        }
                    }
                }

                Log::warning('ExtractPhase processed source', [
                    'source' => $item['source']['domain'] ?? 'unknown',
                    'content_length' => $contentLen,
                    'facts_extracted' => $factsExtracted,
                ]);

            } catch (Exception $e) {
                Log::warning('ExtractPhase fact extraction failed', [
                    'source' => $item['source']['domain'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Deduplicate facts by statement similarity
        $uniqueFacts = $this->deduplicateFacts($allFacts);

        Log::warning('ExtractPhase complete', [
            'total_extracted' => count($allFacts),
            'unique_facts' => count($uniqueFacts),
        ]);

        return [
            'success' => true,
            'facts' => $uniqueFacts,
            'total_extracted' => count($allFacts),
            'unique_facts' => count($uniqueFacts),
        ];
    }

    /**
     * Phase 4: Verify extracted facts
     */
    public function verifyPhase(array $mission, array|string $facts, array $options = []): array
    {
        if (!is_array($facts)) {
            Log::warning('Research mission verifyPhase received non-array facts payload', [
                'mission_id' => $mission['id'] ?? null,
                'facts_type' => gettype($facts),
            ]);
            $facts = [];
        }

        $verifiedFacts = [];
        $rejectedFacts = [];
        $pendingFacts = [];
        $verificationLevel = $mission['verification_level'] ?? 'strict';
        $maxVerificationFacts = max(1, (int) ($options['max_verification_facts'] ?? count($facts)));
        $factsToVerify = array_slice($facts, 0, $maxVerificationFacts);
        $skippedFacts = max(0, count($facts) - count($factsToVerify));

        if ($skippedFacts > 0) {
            Log::info('Research mission verification capped', [
                'mission_id' => $mission['id'] ?? null,
                'attempting' => count($factsToVerify),
                'skipped' => $skippedFacts,
                'verification_level' => $verificationLevel,
            ]);
        }

        foreach ($factsToVerify as $fact) {
            $verificationResult = $this->vettingService->verifyFact($fact, $verificationLevel, [
                'skip_recursive' => !empty($options['skip_recursive']),
            ]);

            $fact['verification_result'] = $verificationResult;

            switch ($verificationResult['verification_status']) {
                case 'verified':
                case 'already_verified':
                    $verifiedFacts[] = $fact;
                    break;
                case 'rejected':
                case 'disputed':
                    $rejectedFacts[] = $fact;
                    break;
                default:
                    $pendingFacts[] = $fact;
            }

            // Store the fact
            $this->vettingService->storeFact($fact, $verificationResult, $mission['id']);
        }

        return [
            'success' => true,
            'verified_facts' => $verifiedFacts,
            'rejected_facts' => $rejectedFacts,
            'pending_facts' => $pendingFacts,
            'verification_level' => $verificationLevel,
            'attempted_facts' => count($factsToVerify),
            'skipped_facts' => $skippedFacts,
        ];
    }

    /**
     * Phase 5: Synthesize a coherent report
     */
    public function synthesizePhase(array $mission, array $verifiedFacts, array $rawContent): array
    {
        $query = $mission['query_template'];
        $category = $mission['domain_category'];

        // Build fact summary
        $factStatements = array_map(fn($f) => "- " . $f['statement'] . " (confidence: " . round(($f['verification_result']['confidence_score'] ?? 0) * 100) . "%)", $verifiedFacts);
        $factSummary = implode("\n", array_slice($factStatements, 0, 30));

        // Build source summary
        $sourceSummary = implode("\n", array_map(fn($c) => "- " . ($c['source']['domain'] ?? 'unknown'), array_slice($rawContent, 0, 10)));

        $prompt = <<<PROMPT
Synthesize research findings about: {$query}
Domain: {$category}

VERIFIED FACTS:
{$factSummary}

SOURCES:
{$sourceSummary}

Output requirements:
- Be CONCISE - bullet points preferred over paragraphs
- Lead with key findings (most important first)
- Only include verified facts - no speculation
- Note confidence only for uncertain items
- Skip obvious context the user already knows
- Keep total output under 500 words

Format: Brief markdown, sections only if needed.
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 3000,
                'factual_mode' => true,
            ]);

            return [
                'success' => true,
                'report' => $result['response'] ?? $result['content'] ?? 'No report generated',
            ];

        } catch (Exception $e) {
            Log::warning('Report synthesis failed', ['error' => $e->getMessage()]);

            // Fallback: simple fact list
            return [
                'success' => true,
                'report' => "# Research Report: {$query}\n\n## Verified Facts\n\n{$factSummary}\n\n*Report generated with fallback method due to synthesis error.*",
            ];
        }
    }

    /**
     * Phase 6: Index verified facts to RAG
     *
     * By default, facts are NOT automatically indexed to RAG - they require human approval.
     * Only missions with auto_index_to_rag = true will skip the review queue.
     */
    public function indexPhase(string $missionId, array $verifiedFacts, string $report): array
    {
        // Check if auto-indexing is enabled for this mission
        $mission = DB::connection($this->connection)->select("
            SELECT auto_index_to_rag FROM research_missions WHERE id = ?
        ", [$missionId]);

        $autoIndex = !empty($mission) && ($mission[0]->auto_index_to_rag ?? false);

        if (!$autoIndex) {
            Log::info('Research mission facts queued for human review (auto_index_to_rag = false)', [
                'mission_id' => $missionId,
                'facts_count' => count($verifiedFacts),
            ]);

            // Mark facts as pending review instead of indexing
            foreach ($verifiedFacts as $fact) {
                if (!empty($fact['verification_result']['fact_hash'])) {
                    try {
                        DB::connection($this->connection)->update("
                            UPDATE research_facts
                            SET review_status = 'pending', indexed_to_rag = false
                            WHERE fact_hash = ?
                        ", [$fact['verification_result']['fact_hash']]);
                    } catch (Exception $e) {
                        Log::debug('Failed to mark fact for review', ['error' => $e->getMessage()]);
                    }
                }
            }

            return [
                'success' => true,
                'indexed_count' => 0,
                'pending_review' => count($verifiedFacts),
                'message' => 'Facts queued for human review',
            ];
        }

        // Auto-indexing enabled - proceed with RAG indexing
        $indexedCount = 0;

        // Index the synthesized report
        try {
            $embeddingResult = $this->aiService->generateEmbedding($report);

            // Extract the actual embedding array from the result
            $embedding = $embeddingResult['embedding'] ?? null;

            if (!empty($embedding) && is_array($embedding)) {
                $embeddingStr = PgVector::literal($embedding);

                DB::connection($this->connection)->insert("
                    INSERT INTO rag_documents (title, content, embedding, metadata, created_at)
                    VALUES (?, ?, ?::vector, ?::jsonb, CURRENT_TIMESTAMP)
                ", [
                    "Research Report: Mission {$missionId}",
                    $report,
                    $embeddingStr,
                    json_encode([
                        'type' => 'research_report',
                        'mission_id' => $missionId,
                        'fact_count' => count($verifiedFacts),
                    ]),
                ]);

                $indexedCount++;
            }
        } catch (Exception $e) {
            Log::warning('Failed to index report to RAG', ['error' => $e->getMessage()]);
        }

        // Mark verified facts as indexed
        foreach ($verifiedFacts as $fact) {
            if (!empty($fact['verification_result']['fact_hash'])) {
                try {
                    DB::connection($this->connection)->update("
                        UPDATE research_facts
                        SET indexed_to_rag = true, indexed_at = CURRENT_TIMESTAMP, review_status = 'approved'
                        WHERE fact_hash = ?
                    ", [$fact['verification_result']['fact_hash']]);
                    $indexedCount++;
                } catch (Exception $e) {
                    Log::debug('Failed to mark fact as indexed', ['error' => $e->getMessage()]);
                }
            }
        }

        return [
            'success' => true,
            'indexed_count' => $indexedCount,
        ];
    }

    /**
     * Deduplicate facts by statement similarity
     */
    private function deduplicateFacts(array $facts): array
    {
        $unique = [];
        $seen = [];

        foreach ($facts as $fact) {
            $statement = strtolower(trim($fact['statement'] ?? ''));
            $hash = md5($statement);

            // Simple hash-based dedup
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $fact;
            }
        }

        return $unique;
    }

    /**
     * Assess the quality of gathered content
     * Returns a quality score and level to inform LLM knowledge reliance
     */
    private function assessContentQuality(array $content, int $webFactCount): array
    {
        $totalContent = 0;
        $substantiveContent = 0;
        $apiContent = 0;
        $articleContent = 0;

        foreach ($content as $item) {
            $contentLength = strlen($item['content'] ?? '');
            $totalContent += $contentLength;

            // API content (like PubMed) is high quality
            if (!empty($item['is_api'])) {
                $apiContent += $contentLength;
                $substantiveContent += $contentLength;
            }
            // Followed article links are usually good content
            elseif (!empty($item['is_followed_article'])) {
                $articleContent += $contentLength;
                $substantiveContent += $contentLength;
            }
            // Search results pages have lower value
            elseif (!empty($item['is_search_results'])) {
                // Only count 10% of search results page content
                $substantiveContent += $contentLength * 0.1;
            }
            // Regular scraped content - check if it's substantial
            elseif ($contentLength > 2000) {
                $substantiveContent += $contentLength;
            }
        }

        // Calculate quality score (0-100)
        $score = 0;

        // Factor 1: Raw fact extraction success (40 points max)
        $score += min(40, $webFactCount * 8);

        // Factor 2: Substantive content ratio (30 points max)
        if ($totalContent > 0) {
            $ratio = $substantiveContent / $totalContent;
            $score += min(30, $ratio * 30);
        }

        // Factor 3: API/Article content bonus (30 points max)
        $highQualityContent = $apiContent + $articleContent;
        if ($highQualityContent > 5000) {
            $score += 30;
        } elseif ($highQualityContent > 2000) {
            $score += 20;
        } elseif ($highQualityContent > 500) {
            $score += 10;
        }

        // Determine quality level
        $level = 'poor';
        if ($score >= 70) {
            $level = 'good';
        } elseif ($score >= 40) {
            $level = 'fair';
        }

        return [
            'score' => $score,
            'level' => $level,
            'total_content' => $totalContent,
            'substantive_content' => $substantiveContent,
            'api_content' => $apiContent,
            'article_content' => $articleContent,
            'web_fact_count' => $webFactCount,
        ];
    }

    /**
     * Extract enhanced LLM knowledge based on content quality
     * Poor content quality = more comprehensive LLM knowledge extraction
     */
    private function extractEnhancedLLMKnowledge(
        string $query,
        string $category,
        string $qualityLevel,
        array $existingFacts = []
    ): array {
        // If content quality is good, use standard extraction
        if ($qualityLevel === 'good') {
            return $this->vettingService->extractLLMKnowledge($query, $category);
        }

        // For fair/poor quality, do enhanced extraction
        $existingStatements = array_map(
            fn($f) => $f['statement'] ?? '',
            array_slice($existingFacts, 0, 10)
        );
        $existingContext = !empty($existingStatements)
            ? "\n\nAlready discovered (don't repeat): " . implode('; ', $existingStatements)
            : '';

        $depthInstruction = $qualityLevel === 'poor'
            ? "Provide comprehensive factual information as web sources yielded limited data."
            : "Supplement the limited web-sourced data with additional relevant facts.";

        $prompt = <<<PROMPT
Research query: {$query}
Domain: {$category}

{$depthInstruction}
{$existingContext}

Provide specific, verifiable facts about this topic. Focus on:
- Key statistics and numbers
- Important dates and timelines
- Named entities (people, organizations, places)
- Technical specifications or medical data
- Recent developments or changes

Return ONLY a JSON array of facts:
[{"statement": "specific fact", "confidence": 0.7-0.95, "fact_type": "statistic|date|entity|technical|development", "source": "llm_knowledge"}]

Rules:
- Only include facts you are confident about
- Be specific with numbers, dates, names
- Max 15 facts
- No speculation or generalizations
- Mark confidence 0.7-0.8 for common knowledge, 0.8-0.9 for well-established facts, 0.9-0.95 for widely verified facts
PROMPT;

        try {
            $result = $this->aiService->process($prompt, [
                'max_tokens' => 2500,
                'factual_mode' => true,
            ]);

            $facts = [];
            // AIService returns 'response' not 'content'
            $aiResponse = $result['response'] ?? $result['content'] ?? '';
            if (!empty($aiResponse)) {
                if (preg_match('/\[[\s\S]*?\]/m', $aiResponse, $matches)) {
                    $parsed = json_decode($matches[0], true);
                    if (is_array($parsed)) {
                        foreach ($parsed as $fact) {
                            if (!empty($fact['statement'])) {
                                $fact['source_type'] = 'llm_enhanced';
                                $fact['quality_level'] = $qualityLevel;
                                $facts[] = $fact;
                            }
                        }
                    }
                }
            }

            Log::warning('Enhanced LLM knowledge extraction', [
                'quality_level' => $qualityLevel,
                'facts_extracted' => count($facts),
            ]);

            return [
                'success' => true,
                'facts' => $facts,
                'enhanced' => true,
                'quality_level' => $qualityLevel,
            ];

        } catch (Exception $e) {
            Log::warning('Enhanced LLM knowledge extraction failed', [
                'error' => $e->getMessage(),
            ]);

            // Fall back to standard extraction
            return $this->vettingService->extractLLMKnowledge($query, $category);
        }
    }

    /**
     * Get mission by ID
     */
    public function getMission(string $missionId): ?array
    {
        $result = DB::connection($this->connection)->select("
            SELECT * FROM research_missions WHERE id = ?
        ", [$missionId]);

        if (empty($result)) {
            return null;
        }

        $mission = (array)$result[0];
        $mission['constraints'] = json_decode($mission['constraints'] ?? '{}', true);
        $mission['phase_details'] = json_decode($mission['phase_details'] ?? '{}', true);

        return $mission;
    }

    /**
     * Update mission status
     */
    private function updateMissionStatus(string $missionId, string $status, array $additionalUpdates = []): void
    {
        $sets = ['status = ?', 'updated_at = CURRENT_TIMESTAMP'];
        $params = [$status];

        foreach ($additionalUpdates as $field => $value) {
            if (in_array($field, ['last_error', 'started_at', 'completed_at'])) {
                $sets[] = "{$field} = ?";
                $params[] = $value;
            } elseif (in_array($field, ['facts_discovered', 'facts_verified', 'sources_discovered', 'sources_used', 'error_count'])) {
                $sets[] = "{$field} = ?";
                $params[] = (int)$value;
            }
        }

        $params[] = $missionId;

        DB::connection($this->connection)->update(
            "UPDATE research_missions SET " . implode(', ', $sets) . " WHERE id = ?",
            $params
        );
    }

    /**
     * Update mission phase and progress
     */
    private function updateMissionPhase(string $missionId, string $phase, int $progressPct): void
    {
        DB::connection($this->connection)->update("
            UPDATE research_missions
            SET current_phase = ?, progress_pct = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$phase, $progressPct, $missionId]);
    }

    /**
     * Store the synthesized report in the mission record
     */
    private function storeReport(string $missionId, string $report): void
    {
        DB::connection($this->connection)->update("
            UPDATE research_missions
            SET report = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$report, $missionId]);
    }

    /**
     * Handle mission timeout
     */
    private function timeoutMission(string $missionId, string $phase): array
    {
        $this->updateMissionStatus($missionId, 'timeout', [
            'last_error' => "Timeout during {$phase} phase",
        ]);

        return [
            'success' => false,
            'error' => "Mission timed out during {$phase} phase",
            'partial' => true,
        ];
    }

    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        $result = DB::connection($this->connection)->select("SELECT gen_random_uuid() as uuid");
        return $result[0]->uuid;
    }

    // =========================================================================
    // DEDUPLICATION METHODS
    // =========================================================================

    /**
     * Check if a fact is a duplicate (already in RAG, pending, approved, or rejected)
     *
     * Layer 1 deduplication: Exact hash match against:
     * - research_rejected_facts (previously rejected)
     * - research_facts with review_status in ('approved', 'rejected', 'pending')
     */
    public function isDuplicateFact(string $factHash): array
    {
        // Check rejected facts table first (fastest, most common case for recurring research)
        $rejected = DB::connection($this->connection)->select("
            SELECT fact_hash, rejection_reason FROM research_rejected_facts WHERE fact_hash = ?
        ", [$factHash]);

        if (!empty($rejected)) {
            return [
                'duplicate' => true,
                'reason' => 'Previously rejected: ' . ($rejected[0]->rejection_reason ?? 'No reason given'),
                'source' => 'rejection_tracking',
            ];
        }

        // Check existing facts (approved, rejected, or pending)
        $existing = DB::connection($this->connection)->select("
            SELECT fact_hash, review_status FROM research_facts WHERE fact_hash = ?
        ", [$factHash]);

        if (!empty($existing)) {
            return [
                'duplicate' => true,
                'reason' => 'Exists with status: ' . $existing[0]->review_status,
                'source' => 'existing_facts',
            ];
        }

        return ['duplicate' => false];
    }

    /**
     * Check if a fact is semantically similar to existing RAG content
     *
     * Layer 2 deduplication: High-similarity match against RAG documents
     * Threshold: 0.90 (90% similarity)
     */
    public function isSemanticDuplicate(string $factStatement): array
    {
        try {
            // Generate embedding for the fact statement
            $embeddingResult = $this->aiService->generateEmbedding($factStatement);
            $embedding = $embeddingResult['embedding'] ?? null;

            if (empty($embedding) || !is_array($embedding)) {
                return ['duplicate' => false, 'reason' => 'Could not generate embedding'];
            }

            $embeddingStr = PgVector::literal($embedding);

            // Search for high-similarity matches (>= 0.90)
            $matches = DB::connection($this->connection)->select("
                SELECT
                    id,
                    title,
                    1 - (embedding <=> ?::vector) as similarity
                FROM rag_documents
                WHERE 1 - (embedding <=> ?::vector) >= 0.90
                ORDER BY similarity DESC
                LIMIT 1
            ", [$embeddingStr, $embeddingStr]);

            if (!empty($matches)) {
                return [
                    'duplicate' => true,
                    'reason' => 'Semantic duplicate of RAG doc #' . $matches[0]->id . ' (' . $matches[0]->title . ')',
                    'match_score' => round((float) $matches[0]->similarity, 4),
                    'match_id' => $matches[0]->id,
                ];
            }

            return ['duplicate' => false];

        } catch (Exception $e) {
            Log::debug('Semantic duplicate check failed', ['error' => $e->getMessage()]);
            return ['duplicate' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Store a fact with deduplication status
     */
    public function storeFactWithDeduplication(
        string $missionId,
        array $fact,
        string $reviewStatus = 'pending',
        ?string $skipReason = null
    ): array {
        $factStatement = $fact['statement'] ?? '';
        $factHash = hash('sha256', strtolower(trim($factStatement)));

        try {
            // Build verification summary
            $verificationSummary = json_encode([
                'external_confirmed' => $fact['verification_result']['external_sources_confirmed'] ?? 0,
                'external_denied' => $fact['verification_result']['external_sources_denied'] ?? 0,
                'rag_match_score' => $fact['verification_result']['rag_match_score'] ?? 0,
                'llm_confidence' => $fact['verification_result']['llm_confidence'] ?? 0,
            ]);

            DB::connection($this->connection)->insert("
                INSERT INTO research_facts (
                    id, mission_id, fact_statement, fact_hash, fact_type, domain_category,
                    context_snippet, verification_status, confidence_score, review_status,
                    skip_reason, source_count, verification_summary, source_urls,
                    external_sources_checked, external_sources_confirmed, external_sources_denied,
                    rag_match_score, llm_confidence, needs_human_review
                )
                VALUES (
                    gen_random_uuid(), ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?::jsonb, ?::jsonb,
                    ?, ?, ?,
                    ?, ?, ?
                )
                ON CONFLICT (fact_hash) DO UPDATE
                SET mission_id = EXCLUDED.mission_id,
                    updated_at = CURRENT_TIMESTAMP
            ", [
                $missionId,
                $factStatement,
                $factHash,
                $fact['fact_type'] ?? null,
                $fact['domain_category'] ?? null,
                $fact['context_snippet'] ?? null,
                $fact['verification_result']['verification_status'] ?? 'unverified',
                $fact['verification_result']['confidence_score'] ?? 0,
                $reviewStatus,
                $skipReason,
                $fact['verification_result']['external_sources_checked'] ?? 0,
                $verificationSummary,
                json_encode($fact['source_urls'] ?? []),
                $fact['verification_result']['external_sources_checked'] ?? 0,
                $fact['verification_result']['external_sources_confirmed'] ?? 0,
                $fact['verification_result']['external_sources_denied'] ?? 0,
                $fact['verification_result']['rag_match_score'] ?? 0,
                $fact['verification_result']['llm_confidence'] ?? 0,
                $reviewStatus === 'pending',
            ]);

            return ['success' => true, 'fact_hash' => $factHash];

        } catch (Exception $e) {
            Log::debug('Failed to store fact', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // RECURRING MISSION SCHEDULING METHODS
    // =========================================================================

    /**
     * Get missions due for execution (recurring missions whose next_run_at has passed)
     */
    public function getDueMissions(int $limit = 10): array
    {
        $missions = DB::connection($this->connection)->select("
            SELECT * FROM research_missions
            WHERE is_active = true
            AND frequency != 'once'
            AND (next_run_at IS NULL OR next_run_at <= NOW())
            ORDER BY next_run_at ASC NULLS FIRST
            LIMIT ?
        ", [$limit]);

        return array_map(function ($m) {
            $mission = (array) $m;
            $mission['constraints'] = json_decode($mission['constraints'] ?? '{}', true);
            $mission['phase_details'] = json_decode($mission['phase_details'] ?? '{}', true);
            return $mission;
        }, $missions);
    }

    /**
     * Update the next run time for a recurring mission based on its frequency
     */
    public function updateNextRunTime(string $missionId): void
    {
        $mission = $this->getMission($missionId);
        if (!$mission) {
            return;
        }

        $frequency = $mission['frequency'] ?? 'once';
        if ($frequency === 'once') {
            return;
        }

        $interval = match ($frequency) {
            'daily' => '1 day',
            'weekly' => '7 days',
            'monthly' => '1 month',
            'quarterly' => '3 months',
            'biannually' => '6 months',
            default => '1 day',
        };

        DB::connection($this->connection)->update("
            UPDATE research_missions
            SET last_ran_at = CURRENT_TIMESTAMP,
                next_run_at = CURRENT_TIMESTAMP + INTERVAL '{$interval}',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$missionId]);

        Log::info('Updated recurring mission schedule', [
            'mission_id' => $missionId,
            'frequency' => $frequency,
            'next_run' => now()->add($interval)->toIso8601String(),
        ]);
    }

    /**
     * Execute a mission with full deduplication support for recurring research
     *
     * This is an enhanced version of executeMission that:
     * 1. Performs 3-layer deduplication before storing facts
     * 2. Updates scheduling for recurring missions
     * 3. Tracks deduplication statistics
     */
    public function executeMissionWithDeduplication(string $missionId): array
    {
        $mission = $this->getMission($missionId);
        if (!$mission) {
            return ['success' => false, 'error' => 'Mission not found'];
        }

        $startTime = microtime(true);
        $timeLimit = ($mission['time_limit_minutes'] ?? 30) * 60;

        // Deduplication statistics
        $deduplicationStats = [
            'facts_extracted' => 0,
            'deduplicated_by_hash' => 0,
            'deduplicated_by_rejection' => 0,
            'deduplicated_by_semantic' => 0,
            'facts_for_review' => 0,
        ];

        try {
            // Update status to active
            $this->updateMissionStatus($missionId, 'active', [
                'started_at' => now()->toIso8601String(),
            ]);

            // Phase 1: DISCOVER sources
            $this->updateMissionPhase($missionId, self::PHASE_DISCOVER, 10);
            $discoveryResult = $this->discoverPhase($mission);

            if (!$discoveryResult['success'] || empty($discoveryResult['sources'])) {
                $this->updateMissionStatus($missionId, 'failed', [
                    'last_error' => 'No sources discovered',
                ]);
                return ['success' => false, 'error' => 'No sources discovered'];
            }

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'discover');
            }

            // Phase 2: GATHER content from sources
            $this->updateMissionPhase($missionId, self::PHASE_GATHER, 30);
            $gatherResult = $this->gatherPhase($mission, $discoveryResult['sources']);

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'gather');
            }

            // Phase 3: EXTRACT facts from content
            $this->updateMissionPhase($missionId, self::PHASE_EXTRACT, 50);
            $extractResult = $this->extractPhase($mission, $gatherResult['content']);

            // Assess content quality and extract enhanced LLM knowledge if needed
            $webFactCount = count($extractResult['facts'] ?? []);
            $contentQuality = $this->assessContentQuality($gatherResult['content'], $webFactCount);
            $llmKnowledge = $this->extractEnhancedLLMKnowledge(
                $mission['query_template'],
                $mission['domain_category'],
                $contentQuality['level'],
                $extractResult['facts'] ?? []
            );

            $allFacts = array_merge(
                $extractResult['facts'] ?? [],
                $llmKnowledge['facts'] ?? []
            );
            $deduplicationStats['facts_extracted'] = count($allFacts);

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'extract');
            }

            // Phase 4: VERIFY facts with deduplication
            $this->updateMissionPhase($missionId, self::PHASE_VERIFY, 70);
            $verifiedFacts = [];
            $rejectedFacts = [];

            foreach ($allFacts as $fact) {
                $factStatement = $fact['statement'] ?? '';
                $factHash = hash('sha256', strtolower(trim($factStatement)));

                // Layer 1: Check hash-based deduplication
                $hashCheck = $this->isDuplicateFact($factHash);
                if ($hashCheck['duplicate']) {
                    if (str_contains($hashCheck['reason'] ?? '', 'rejected')) {
                        $deduplicationStats['deduplicated_by_rejection']++;
                    } else {
                        $deduplicationStats['deduplicated_by_hash']++;
                    }
                    continue;
                }

                // Layer 2: Check semantic deduplication (only for high-confidence facts)
                if (($fact['confidence'] ?? 0) >= 0.7) {
                    $semanticCheck = $this->isSemanticDuplicate($factStatement);
                    if ($semanticCheck['duplicate']) {
                        $deduplicationStats['deduplicated_by_semantic']++;
                        // Store as auto-skipped
                        $this->storeFactWithDeduplication(
                            $missionId,
                            $fact,
                            'auto_skipped',
                            $semanticCheck['reason']
                        );
                        continue;
                    }
                }

                // Not a duplicate - proceed with verification
                $verificationResult = $this->vettingService->verifyFact($fact, $mission['verification_level'] ?? 'strict');
                $fact['verification_result'] = $verificationResult;

                // Layer 3: Check if RAG match during verification is too high
                $ragMatchScore = $verificationResult['rag_match_score'] ?? 0;
                if ($ragMatchScore >= 0.90) {
                    $deduplicationStats['deduplicated_by_semantic']++;
                    $this->storeFactWithDeduplication(
                        $missionId,
                        $fact,
                        'auto_skipped',
                        "Semantic duplicate: RAG match " . round($ragMatchScore * 100) . "%"
                    );
                    continue;
                }

                // Store fact for review
                $this->vettingService->storeFact($fact, $verificationResult, $missionId);
                $deduplicationStats['facts_for_review']++;

                if (in_array($verificationResult['verification_status'], ['verified', 'already_verified'])) {
                    $verifiedFacts[] = $fact;
                } else {
                    $rejectedFacts[] = $fact;
                }
            }

            if ((microtime(true) - $startTime) > $timeLimit) {
                return $this->timeoutMission($missionId, 'verify');
            }

            // Phase 5: SYNTHESIZE report
            $this->updateMissionPhase($missionId, self::PHASE_SYNTHESIZE, 85);
            $synthesisResult = $this->synthesizePhase($mission, $verifiedFacts, $gatherResult['content']);

            // Phase 6: INDEX (facts are already stored with pending review status)
            $this->updateMissionPhase($missionId, self::PHASE_INDEX, 95);
            $indexResult = $this->indexPhase($missionId, $verifiedFacts, $synthesisResult['report']);

            // Store the report
            $this->storeReport($missionId, $synthesisResult['report']);

            // Update scheduling for recurring missions
            $this->updateNextRunTime($missionId);

            // Mark complete
            $this->updateMissionStatus($missionId, 'completed', [
                'completed_at' => now()->toIso8601String(),
                'facts_discovered' => $deduplicationStats['facts_extracted'],
                'facts_verified' => count($verifiedFacts),
                'sources_discovered' => count($discoveryResult['sources']),
                'sources_used' => count($gatherResult['successful_sources'] ?? []),
            ]);

            $this->updateMissionPhase($missionId, self::PHASE_COMPLETE, 100);

            $duration = microtime(true) - $startTime;

            Log::info('Research mission completed with deduplication', [
                'mission_id' => $missionId,
                'duration_seconds' => round($duration, 2),
                'deduplication_stats' => $deduplicationStats,
            ]);

            return [
                'success' => true,
                'mission_id' => $missionId,
                'duration_seconds' => round($duration, 2),
                'sources_discovered' => count($discoveryResult['sources']),
                'sources_used' => count($gatherResult['successful_sources'] ?? []),
                'facts_discovered' => $deduplicationStats['facts_extracted'],
                'facts_verified' => count($verifiedFacts),
                'facts_indexed' => $indexResult['indexed_count'] ?? 0,
                'facts_pending_review' => $indexResult['pending_review'] ?? $deduplicationStats['facts_for_review'],
                'deduplication' => $deduplicationStats,
                'report' => $synthesisResult['report'],
            ];

        } catch (Exception $e) {
            Log::error('Mission execution failed', [
                'mission_id' => $missionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateMissionStatus($missionId, 'failed', [
                'last_error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get orchestrator statistics
     */
    public function getStats(): array
    {
        $missionStats = DB::connection($this->connection)->select("
            SELECT
                COUNT(*) as total_missions,
                COUNT(*) FILTER (WHERE status = 'completed') as completed,
                COUNT(*) FILTER (WHERE status = 'active') as active,
                COUNT(*) FILTER (WHERE status = 'failed') as failed,
                COUNT(*) FILTER (WHERE status = 'timeout') as timeout,
                SUM(facts_discovered) as total_facts_discovered,
                SUM(facts_verified) as total_facts_verified,
                SUM(sources_discovered) as total_sources_discovered,
                AVG(EXTRACT(EPOCH FROM (completed_at - started_at)))::integer as avg_duration_seconds
            FROM research_missions
        ");

        $recentMissions = DB::connection($this->connection)->select("
            SELECT id, title, status, progress_pct, current_phase, created_at
            FROM research_missions
            ORDER BY created_at DESC
            LIMIT 10
        ");

        return [
            'summary' => (array)($missionStats[0] ?? []),
            'recent_missions' => array_map(fn($r) => (array)$r, $recentMissions),
        ];
    }
}
