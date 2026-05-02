<?php

namespace App\Nodes;

use App\Services\GraphlitResearchService;
use App\Services\ClaudeWebSearchService;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Research Plugin Enhancement Node
 *
 * Enhances research results using Claude's plugins/MCP tools:
 * - Graphlit: Knowledge graph search, web search (Exa/Tavily), podcast search
 * - Claude WebSearch: Real-time verification, claim verification, URL freshness
 *
 * Configuration:
 * - mode: 'enhance' (add to existing), 'verify' (fact-check existing), 'comprehensive' (full research)
 * - use_graphlit: boolean (default: true)
 * - use_claude_search: boolean (default: true)
 * - ingest_results: boolean - save results to Graphlit KB (default: false)
 * - search_podcasts: boolean - include podcast search (default: false)
 * - verify_claims: boolean - verify key claims (default: false)
 *
 * Input:
 * - query: Research query (required)
 * - existing_results: Previous research results to enhance (optional)
 * - claims: Array of claims to verify (optional, for verify mode)
 *
 * Output:
 * - enhanced_results: Combined research findings
 * - graphlit_results: Raw Graphlit search results
 * - claude_results: Raw Claude search results
 * - verifications: Claim verification results (if enabled)
 * - synthesis: AI-synthesized summary
 */
class ResearchPluginEnhancementNode extends BaseNode
{
    private ?GraphlitResearchService $graphlit = null;
    private ?ClaudeWebSearchService $claudeSearch = null;

    public function execute(array $input): array
    {
        $query = $input['data']['query'] ?? $input['query'] ?? null;
        $existingResults = $input['data']['existing_results'] ?? $input['existing_results'] ?? '';
        $claims = $input['data']['claims'] ?? $input['claims'] ?? [];

        if (empty($query)) {
            return $this->standardOutput(null, [], 'Query is required');
        }

        $mode = $this->getConfigValue('mode', 'enhance');
        $useGraphlit = $this->getConfigValue('use_graphlit', true);
        $useClaudeSearch = $this->getConfigValue('use_claude_search', true);
        $ingestResults = $this->getConfigValue('ingest_results', false);
        $searchPodcasts = $this->getConfigValue('search_podcasts', false);
        $verifyClaims = $this->getConfigValue('verify_claims', false);

        Log::info('ResearchPluginEnhancementNode: Starting', [
            'query' => substr($query, 0, 100),
            'mode' => $mode,
            'use_graphlit' => $useGraphlit,
            'use_claude_search' => $useClaudeSearch,
        ]);

        $startTime = microtime(true);
        $results = [
            'query' => $query,
            'mode' => $mode,
            'graphlit_results' => null,
            'claude_results' => null,
            'verifications' => [],
            'synthesis' => null,
        ];

        try {
            // Mode: comprehensive - full research from scratch
            if ($mode === 'comprehensive') {
                return $this->comprehensiveResearch($query, $useGraphlit, $useClaudeSearch, $searchPodcasts, $ingestResults);
            }

            // Mode: verify - fact-check existing content
            if ($mode === 'verify') {
                return $this->verifyMode($query, $existingResults, $claims);
            }

            // Mode: enhance (default) - add to existing research
            // Step 1: Graphlit web search
            if ($useGraphlit) {
                $this->initGraphlit();
                if ($this->graphlit) {
                    $graphlitResults = $this->graphlit->webSearch($query, ['limit' => 10]);
                    $results['graphlit_results'] = $graphlitResults;

                    // Optional podcast search
                    if ($searchPodcasts) {
                        $podcastResults = $this->graphlit->searchPodcasts($query, 5);
                        $results['podcast_results'] = $podcastResults;
                    }

                    // Optional: Retrieve from existing knowledge base
                    $kbResults = $this->graphlit->retrieveSources($query, ['inLast' => 'P30D']);
                    $results['knowledge_base'] = $kbResults;

                    // Optional: Ingest top results for future retrieval
                    if ($ingestResults && ($graphlitResults['success'] ?? false)) {
                        $ingestedCount = 0;
                        foreach (array_slice($graphlitResults['results'] ?? [], 0, 3) as $source) {
                            $url = $source['url'] ?? null;
                            if ($url) {
                                $ingested = $this->graphlit->ingestUrl($url);
                                if ($ingested['success'] ?? false) {
                                    $ingestedCount++;
                                }
                            }
                        }
                        $results['ingested_count'] = $ingestedCount;
                    }
                }
            }

            // Step 2: Claude web search for additional/latest info
            if ($useClaudeSearch) {
                $this->initClaudeSearch();
                if ($this->claudeSearch && $this->claudeSearch->isAvailable()) {
                    $claudeResults = $this->claudeSearch->search($query);
                    $results['claude_results'] = $claudeResults;

                    // Optional: Get latest news
                    $newsResults = $this->claudeSearch->getLatestNews($query, 3);
                    $results['news_results'] = $newsResults;
                }
            }

            // Step 3: Optional claim verification
            if ($verifyClaims && !empty($claims)) {
                $results['verifications'] = $this->verifyClaims($claims);
            }

            // Step 4: Synthesize all results
            $results['synthesis'] = $this->synthesizeResults($query, $existingResults, $results);

            $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
            $results['success'] = true;

            Log::info('ResearchPluginEnhancementNode: Completed', [
                'query' => substr($query, 0, 50),
                'duration_ms' => $results['duration_ms'],
                'graphlit_count' => count($results['graphlit_results']['results'] ?? []),
                'claude_count' => count($results['claude_results']['results'] ?? []),
            ]);

            return $this->standardOutput($results, [
                'mode' => $mode,
                'sources_used' => [
                    'graphlit' => $useGraphlit && $this->graphlit !== null,
                    'claude_search' => $useClaudeSearch && $this->claudeSearch !== null,
                ],
            ]);

        } catch (Exception $e) {
            Log::error('ResearchPluginEnhancementNode: Failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Comprehensive research using all available tools
     */
    private function comprehensiveResearch(
        string $query,
        bool $useGraphlit,
        bool $useClaudeSearch,
        bool $searchPodcasts,
        bool $ingestResults
    ): array {
        $startTime = microtime(true);
        $results = [
            'query' => $query,
            'mode' => 'comprehensive',
            'web_search' => null,
            'podcast_search' => null,
            'knowledge_base' => null,
            'claude_search' => null,
            'synthesis' => null,
        ];

        // Graphlit comprehensive research
        if ($useGraphlit) {
            $this->initGraphlit();
            if ($this->graphlit) {
                $comprehensive = $this->graphlit->comprehensiveResearch($query, [
                    'ingestResults' => $ingestResults,
                    'searchPodcasts' => $searchPodcasts,
                ]);
                $results = array_merge($results, $comprehensive);
            }
        }

        // Claude supplementary search
        if ($useClaudeSearch) {
            $this->initClaudeSearch();
            if ($this->claudeSearch && $this->claudeSearch->isAvailable()) {
                $claudeResults = $this->claudeSearch->search($query);
                $results['claude_search'] = $claudeResults;
            }
        }

        $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $results['success'] = true;

        return $this->standardOutput($results, ['mode' => 'comprehensive']);
    }

    /**
     * Verification mode - fact-check existing content
     */
    private function verifyMode(string $query, string $existingResults, array $claims): array
    {
        $startTime = microtime(true);
        $results = [
            'query' => $query,
            'mode' => 'verify',
            'verifications' => [],
            'url_checks' => [],
        ];

        $this->initClaudeSearch();
        if (!$this->claudeSearch || !$this->claudeSearch->isAvailable()) {
            return $this->standardOutput(null, [], 'Claude search not available for verification');
        }

        // Extract claims from existing results if none provided
        if (empty($claims)) {
            $claims = $this->extractClaimsFromContent($existingResults);
        }

        // Verify each claim
        foreach (array_slice($claims, 0, 5) as $claim) {
            $verification = $this->claudeSearch->verifyClaim($claim);
            $results['verifications'][] = $verification;
        }

        // Extract and check URLs from existing results
        preg_match_all('/https?:\/\/[^\s\)\]]+/', $existingResults, $urlMatches);
        $urls = array_unique(array_slice($urlMatches[0] ?? [], 0, 5));

        foreach ($urls as $url) {
            $urlCheck = $this->claudeSearch->checkUrlFreshness($url);
            $results['url_checks'][] = $urlCheck;
        }

        $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $results['success'] = true;

        return $this->standardOutput($results, ['mode' => 'verify']);
    }

    /**
     * Verify a list of claims
     */
    private function verifyClaims(array $claims): array
    {
        $verifications = [];

        $this->initClaudeSearch();
        if (!$this->claudeSearch || !$this->claudeSearch->isAvailable()) {
            return $verifications;
        }

        foreach (array_slice($claims, 0, 5) as $claim) {
            $verification = $this->claudeSearch->verifyClaim($claim);
            $verifications[] = $verification;
        }

        return $verifications;
    }

    /**
     * Extract claims from content for verification
     */
    private function extractClaimsFromContent(string $content): array
    {
        $claims = [];

        // Look for sentences that make factual assertions
        $sentences = preg_split('/(?<=[.!?])\s+/', $content);

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            // Skip short sentences, questions, and common non-factual phrases
            if (strlen($sentence) < 30) continue;
            if (str_ends_with($sentence, '?')) continue;
            if (preg_match('/^(Note:|Suggestion:|Consider:|Please |However,|Therefore,)/i', $sentence)) continue;

            // Look for factual indicators
            if (preg_match('/(was |is |are |were |has |have |in \d{4}|on \d|born |died |married |according to)/i', $sentence)) {
                $claims[] = $sentence;
            }
        }

        return array_slice($claims, 0, 10);
    }

    /**
     * Synthesize all research results into a coherent summary
     */
    private function synthesizeResults(string $query, string $existingResults, array $results): string
    {
        $sections = [];
        $sections[] = "## Research Enhancement Results\n\n**Query:** {$query}\n";

        // Existing results
        if (!empty($existingResults)) {
            $sections[] = "### Original Research\n" . substr($existingResults, 0, 500) .
                (strlen($existingResults) > 500 ? '...' : '');
        }

        // Graphlit web search
        if (!empty($results['graphlit_results']['results'])) {
            $sections[] = "### Web Search (Graphlit)\n";
            foreach (array_slice($results['graphlit_results']['results'], 0, 5) as $result) {
                $title = $result['title'] ?? 'Untitled';
                $url = $result['url'] ?? '';
                $text = $result['text'] ?? $result['snippet'] ?? '';
                $sections[] = "- **{$title}**\n  {$text}\n  Source: {$url}\n";
            }
        }

        // Knowledge base
        if (!empty($results['knowledge_base']['sources'])) {
            $sections[] = "### Knowledge Base\n";
            foreach (array_slice($results['knowledge_base']['sources'], 0, 3) as $source) {
                $name = $source['name'] ?? 'Document';
                $sections[] = "- {$name}\n";
            }
        }

        // Claude search results
        if (!empty($results['claude_results']['results'])) {
            $sections[] = "### Additional Sources (Claude)\n";
            foreach (array_slice($results['claude_results']['results'], 0, 3) as $result) {
                $title = $result['title'] ?? 'Source';
                $sections[] = "- {$title}\n";
            }
        }

        // Podcast results
        if (!empty($results['podcast_results']['results'])) {
            $sections[] = "### Related Podcasts\n";
            foreach (array_slice($results['podcast_results']['results'], 0, 3) as $result) {
                $title = $result['title'] ?? 'Episode';
                $sections[] = "- {$title}\n";
            }
        }

        // Verifications
        if (!empty($results['verifications'])) {
            $sections[] = "### Claim Verifications\n";
            foreach ($results['verifications'] as $v) {
                if ($v['success'] ?? false) {
                    $verdict = $v['verdict'] ?? 'UNKNOWN';
                    $confidence = $v['confidence'] ?? 0;
                    $claim = substr($v['claim'] ?? '', 0, 100);
                    $sections[] = "- **{$verdict}** ({$confidence}%): {$claim}...\n";
                }
            }
        }

        return implode("\n", $sections);
    }

    /**
     * Initialize Graphlit service
     */
    private function initGraphlit(): void
    {
        if ($this->graphlit === null) {
            try {
                $this->graphlit = app(GraphlitResearchService::class);
            } catch (Exception $e) {
                Log::warning('ResearchPluginEnhancementNode: Graphlit not available', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Initialize Claude search service
     */
    private function initClaudeSearch(): void
    {
        if ($this->claudeSearch === null) {
            try {
                $this->claudeSearch = app(ClaudeWebSearchService::class);
            } catch (Exception $e) {
                Log::warning('ResearchPluginEnhancementNode: Claude search not available', ['error' => $e->getMessage()]);
            }
        }
    }
}
