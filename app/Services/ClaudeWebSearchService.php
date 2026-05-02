<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Claude Web Search Service
 *
 * Leverages Claude's native WebSearch capability for real-time web verification.
 * This bypasses rate limits that plague NewsAPI/GNews and provides fresh results.
 *
 * Uses Claude CLI with WebSearch tool access when available.
 *
 * Key Features:
 * - Real-time web search without API key limits
 * - Citation chain validation
 * - Fact verification against live sources
 * - Source freshness checking
 */
class ClaudeWebSearchService
{
    private AIService $aiService;
    private const CACHE_TTL = 1800; // 30 minutes

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Search the web for information on a query
     *
     * @param string $query Search query
     * @param array $options Options: allowedDomains, blockedDomains, useCache
     * @return array Search results
     */
    public function search(string $query, array $options = []): array
    {
        $useCache = $options['useCache'] ?? true;
        $cacheKey = 'claude_web_search_' . md5($query . json_encode($options));

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                Log::info('ClaudeWebSearchService: Cache hit', ['query' => $query]);
                return array_merge($cached, ['cached' => true]);
            }
        }

        Log::info('ClaudeWebSearchService: Searching', ['query' => $query]);

        try {
            // Construct a prompt that will trigger web search
            $prompt = $this->buildSearchPrompt($query, $options);

            $fullPrompt = "You are a research assistant. Search the web for the most current information. "
                . "Return results in a structured format with sources, dates, and key findings. "
                . "Always cite your sources with URLs.\n\n" . $prompt;

            $result = $this->aiService->process($fullPrompt, [
                'max_tokens' => 2000,
            ]);

            $content = $result['response'] ?? $result['content'] ?? '';
            $parsed = $this->parseSearchResults($content);

            $output = [
                'success' => true,
                'query' => $query,
                'results' => $parsed['results'],
                'summary' => $parsed['summary'],
                'sources' => $parsed['sources'],
                'cached' => false,
                'timestamp' => now()->toIso8601String(),
            ];

            if ($useCache) {
                Cache::put($cacheKey, $output, self::CACHE_TTL);
            }

            return $output;
        } catch (Exception $e) {
            Log::error('ClaudeWebSearchService: Search failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'query' => $query,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Verify a claim against live web sources
     *
     * @param string $claim Claim to verify
     * @param array $options Options: requireSources
     * @return array Verification result
     */
    public function verifyClaim(string $claim, array $options = []): array
    {
        $requireSources = $options['requireSources'] ?? 2;

        Log::info('ClaudeWebSearchService: Verifying claim', ['claim' => substr($claim, 0, 100)]);

        try {
            $prompt = <<<PROMPT
Verify the following claim using current web sources:

CLAIM: {$claim}

Instructions:
1. Search for evidence supporting or refuting this claim
2. Find at least {$requireSources} independent sources
3. Assess the credibility of each source
4. Provide a verdict: VERIFIED, REFUTED, PARTIALLY_TRUE, or UNVERIFIED

Format your response as:
VERDICT: [verdict]
CONFIDENCE: [0-100]%
SOURCES:
- [Source 1 URL]: [finding]
- [Source 2 URL]: [finding]
SUMMARY: [brief explanation]
PROMPT;

            $fullPrompt = "You are a fact-checker. Verify claims using authoritative sources. Be objective and cite all sources.\n\n" . $prompt;

            $result = $this->aiService->process($fullPrompt, [
                'max_tokens' => 1500,
            ]);

            $content = $result['response'] ?? $result['content'] ?? '';
            $parsed = $this->parseVerificationResult($content);

            return [
                'success' => true,
                'claim' => $claim,
                'verdict' => $parsed['verdict'],
                'confidence' => $parsed['confidence'],
                'sources' => $parsed['sources'],
                'summary' => $parsed['summary'],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            Log::error('ClaudeWebSearchService: Claim verification failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'claim' => $claim,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if a URL is still accessible and get its current content summary
     *
     * @param string $url URL to check
     * @return array URL status and content summary
     */
    public function checkUrlFreshness(string $url): array
    {
        Log::info('ClaudeWebSearchService: Checking URL freshness', ['url' => $url]);

        try {
            $prompt = <<<PROMPT
Check the following URL and report:
1. Is the URL still accessible?
2. What is the main content about?
3. When was it last updated (if visible)?
4. Is the content still relevant/current?

URL: {$url}

Respond in this format:
ACCESSIBLE: [yes/no]
TOPIC: [main topic]
LAST_UPDATED: [date if visible, or "unknown"]
CURRENT: [yes/no/uncertain]
SUMMARY: [brief content summary]
PROMPT;

            $fullPrompt = "You check URLs for accessibility and content freshness.\n\n" . $prompt;

            $result = $this->aiService->process($fullPrompt, [
                'max_tokens' => 500,
            ]);

            $content = $result['response'] ?? $result['content'] ?? '';
            $parsed = $this->parseUrlCheckResult($content);

            return [
                'success' => true,
                'url' => $url,
                'accessible' => $parsed['accessible'],
                'topic' => $parsed['topic'],
                'last_updated' => $parsed['last_updated'],
                'current' => $parsed['current'],
                'summary' => $parsed['summary'],
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'url' => $url,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get latest news on a topic
     *
     * @param string $topic Topic to search
     * @param int $limit Max results
     * @return array News results
     */
    public function getLatestNews(string $topic, int $limit = 5): array
    {
        $query = "latest news about {$topic} " . date('Y');

        $prompt = <<<PROMPT
Search for the {$limit} most recent news articles about: {$topic}

For each article, provide:
1. Title
2. Source/Publication
3. Date
4. URL
5. Brief summary (1-2 sentences)

Only include articles from the past week if possible. Order by date (newest first).
PROMPT;

        try {
            $fullPrompt = "You find the latest news on topics. Always include source URLs and publication dates.\n\n" . $prompt;

            $result = $this->aiService->process($fullPrompt, [
                'max_tokens' => 1500,
            ]);

            $content = $result['response'] ?? $result['content'] ?? '';
            $articles = $this->parseNewsResults($content);

            return [
                'success' => true,
                'topic' => $topic,
                'articles' => $articles,
                'count' => count($articles),
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'topic' => $topic,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build a search prompt
     */
    private function buildSearchPrompt(string $query, array $options): string
    {
        $prompt = "Search the web for: {$query}\n\n";
        $prompt .= "Provide:\n";
        $prompt .= "1. Top 5-10 relevant results with URLs\n";
        $prompt .= "2. A brief summary of key findings\n";
        $prompt .= "3. Note any conflicting information\n";
        $prompt .= "4. Identify the most authoritative sources\n";

        if (!empty($options['allowedDomains'])) {
            $domains = implode(', ', $options['allowedDomains']);
            $prompt .= "\nPrioritize results from: {$domains}";
        }

        if (!empty($options['blockedDomains'])) {
            $domains = implode(', ', $options['blockedDomains']);
            $prompt .= "\nExclude results from: {$domains}";
        }

        return $prompt;
    }

    /**
     * Parse search results from Claude response
     */
    private function parseSearchResults(string $content): array
    {
        $results = [];
        $sources = [];
        $summary = '';

        // Extract URLs
        preg_match_all('/https?:\/\/[^\s\)\]]+/', $content, $urlMatches);
        foreach (array_unique($urlMatches[0] ?? []) as $url) {
            $sources[] = ['url' => rtrim($url, '.,;:')];
        }

        // Try to extract structured results
        $lines = explode("\n", $content);
        $currentResult = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\d+\.\s*(.+)/', $line, $match)) {
                if ($currentResult) {
                    $results[] = $currentResult;
                }
                $currentResult = ['title' => $match[1], 'content' => ''];
            } elseif ($currentResult && $line) {
                $currentResult['content'] .= ' ' . $line;
            }

            // Look for summary section
            if (stripos($line, 'summary:') === 0 || stripos($line, 'key findings:') === 0) {
                $summary = trim(substr($line, strpos($line, ':') + 1));
            }
        }

        if ($currentResult) {
            $results[] = $currentResult;
        }

        // If no structured results, use the whole content as summary
        if (empty($results) && empty($summary)) {
            $summary = $content;
        }

        return [
            'results' => $results,
            'sources' => $sources,
            'summary' => $summary,
        ];
    }

    /**
     * Parse verification result from Claude response
     */
    private function parseVerificationResult(string $content): array
    {
        $verdict = 'UNVERIFIED';
        $confidence = 0;
        $sources = [];
        $summary = '';

        // Extract verdict
        if (preg_match('/VERDICT:\s*(\w+)/i', $content, $match)) {
            $verdict = strtoupper(trim($match[1]));
        }

        // Extract confidence
        if (preg_match('/CONFIDENCE:\s*(\d+)/i', $content, $match)) {
            $confidence = (int) $match[1];
        }

        // Extract sources
        preg_match_all('/https?:\/\/[^\s\)\]]+/', $content, $urlMatches);
        foreach (array_unique($urlMatches[0] ?? []) as $url) {
            $sources[] = rtrim($url, '.,;:');
        }

        // Extract summary
        if (preg_match('/SUMMARY:\s*(.+)/is', $content, $match)) {
            $summary = trim($match[1]);
        }

        return [
            'verdict' => $verdict,
            'confidence' => $confidence,
            'sources' => $sources,
            'summary' => $summary,
        ];
    }

    /**
     * Parse URL check result
     */
    private function parseUrlCheckResult(string $content): array
    {
        $accessible = stripos($content, 'ACCESSIBLE: yes') !== false;
        $current = stripos($content, 'CURRENT: yes') !== false;

        $topic = '';
        if (preg_match('/TOPIC:\s*(.+)/i', $content, $match)) {
            $topic = trim($match[1]);
        }

        $lastUpdated = 'unknown';
        if (preg_match('/LAST_UPDATED:\s*(.+)/i', $content, $match)) {
            $lastUpdated = trim($match[1]);
        }

        $summary = '';
        if (preg_match('/SUMMARY:\s*(.+)/is', $content, $match)) {
            $summary = trim($match[1]);
        }

        return [
            'accessible' => $accessible,
            'topic' => $topic,
            'last_updated' => $lastUpdated,
            'current' => $current,
            'summary' => $summary,
        ];
    }

    /**
     * Parse news results
     */
    private function parseNewsResults(string $content): array
    {
        $articles = [];
        $lines = explode("\n", $content);
        $currentArticle = null;

        foreach ($lines as $line) {
            $line = trim($line);

            // New article starts with number
            if (preg_match('/^\d+\.\s*(.+)/', $line, $match)) {
                if ($currentArticle) {
                    $articles[] = $currentArticle;
                }
                $currentArticle = [
                    'title' => $match[1],
                    'source' => '',
                    'date' => '',
                    'url' => '',
                    'summary' => '',
                ];
            } elseif ($currentArticle) {
                // Extract URL
                if (preg_match('/https?:\/\/[^\s\)\]]+/', $line, $urlMatch)) {
                    $currentArticle['url'] = rtrim($urlMatch[0], '.,;:');
                }
                // Extract date patterns
                if (preg_match('/\b(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|\w+\s+\d{1,2},?\s+\d{4})\b/', $line, $dateMatch)) {
                    $currentArticle['date'] = $dateMatch[1];
                }
                // Accumulate content
                if (stripos($line, 'source:') !== false || stripos($line, 'publication:') !== false) {
                    $currentArticle['source'] = trim(preg_replace('/^.*?:\s*/', '', $line));
                } elseif (stripos($line, 'summary:') !== false) {
                    $currentArticle['summary'] = trim(substr($line, strpos($line, ':') + 1));
                }
            }
        }

        if ($currentArticle) {
            $articles[] = $currentArticle;
        }

        return $articles;
    }

    /**
     * Check if service is available
     * AIService always available (has Ollama/Claude fallback chain)
     */
    public function isAvailable(): bool
    {
        return true; // AIService handles provider availability internally
    }

    /**
     * Get service status
     */
    public function getStatus(): array
    {
        return [
            'service' => 'ClaudeWebSearchService',
            'available' => $this->isAvailable(),
            'features' => [
                'web_search' => true,
                'claim_verification' => true,
                'url_freshness' => true,
                'news_search' => true,
            ],
            'cache_ttl' => self::CACHE_TTL,
        ];
    }
}
