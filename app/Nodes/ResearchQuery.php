<?php

namespace App\Nodes;

use App\Services\ResearchService;
use Exception;

/**
 * ResearchQuery Node
 *
 * Performs multi-source research using NewsAPI and GNews
 * Provides balanced news coverage from multiple sources with optional AI analysis
 */
class ResearchQuery extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $query = $this->getConfigValue('query');
            $limit = (int) $this->getConfigValue('limit', 20); // Default 20 for fair & balanced
            $maxTotalArticles = $this->resolvePositiveIntConfig('max_total_articles', 50);
            $maxFormattedChars = $this->resolvePositiveIntConfig('max_formatted_chars', 50000);
            // TEMPORARILY DISABLED: All API sources disabled to avoid rate limiting
            // Previous: 'newsapi' - Re-enable when needed
            $rawSources = $this->getConfigValue('sources', null);
            $sources = $rawSources ?? ''; // Empty = no API calls
            $useAi = $this->resolveBooleanConfig('use_ai', false); // Disable AI by default for news briefs
            $parallel = $this->resolveBooleanConfig('parallel', true);

            if (!$query) {
                throw new Exception('Research query configuration is required');
            }

            // Parse sources (comma-separated or array)
            if (is_string($sources)) {
                $sources = array_map('trim', explode(',', $sources));
            }
            if (is_array($sources)) {
                $sources = array_values(array_filter($sources, fn($source) => is_string($source) ? trim($source) !== '' : !empty($source)));
            }

            // Get Research Service
            $researchService = app(ResearchService::class);

            // CRITICAL: Prepend RSS feed data from previous nodes (if any)
            // This allows RSS feeds to execute before ResearchQuery and accumulate data
            $rssData = $this->extractPreviousData($input);
            $rssArticles = $this->extractPreviousArticles($input);

            // Lightweight news-brief path: if previous RSS nodes already produced articles
            // and this node has no explicit external sources configured, avoid sending the
            // workflow through the full ResearchService recursive/search stack.
            if ($rawSources !== null && empty($sources) && !$useAi && !empty($rssArticles)) {
                $formattedResults = $rssData ?: "Research Query: {$query}\nSources: rss_only\nTotal Results: " . count($rssArticles);
                $formattedResults = $this->trimFormattedText($formattedResults, $maxFormattedChars);

                return $this->standardOutput([
                    'formatted_text' => $formattedResults,
                    'articles' => array_slice($rssArticles, 0, $maxTotalArticles),
                ], [
                    'query' => $query,
                    'sources' => 'rss_only',
                    'rss_articles' => count($rssArticles),
                    'api_articles' => 0,
                    'total_results' => min(count($rssArticles), $maxTotalArticles),
                    'max_total_articles' => $maxTotalArticles,
                    'formatted_text_chars' => $formattedResults ? mb_strlen($formattedResults) : 0,
                    'duration_ms' => 0,
                    'ai_analysis' => null,
                    'lightweight_rss_only' => true,
                ]);
            }

            // Perform research
            $result = $researchService->research($query, [
                'parallel' => $parallel,
                'limit' => $limit,
                'sources' => $sources,
                'use_ai' => $useAi,
                // Daily brief workflows using non-AI collection mode do not benefit
                // from the recursive research partitioner or RAG indexing overhead.
                'disable_recursion' => !$useAi,
                'check_rag_first' => $useAi,
                'index_to_rag' => $useAi,
            ]);

            // Format results for workflow output
            $formattedResults = $this->formatResults($result);

            if (!empty($rssData)) {
                $formattedResults = $rssData . "\n\n" . "---" . "\n\n" . $formattedResults;
            }
            $formattedResults = $this->trimFormattedText($formattedResults, $maxFormattedChars);

            // CRITICAL: Merge RSS articles with API articles
            $apiArticles = $result['results']['articles'] ?? [];
            $allArticles = $this->mergeArticleCollections($rssArticles, $apiArticles, $maxTotalArticles);

            // NEW: Output both structured articles AND formatted text
            // This allows BiasRatingEnrich to work with structured data
            return $this->standardOutput([
                'formatted_text' => $formattedResults,
                'articles' => $allArticles  // Combined RSS + API articles
            ], [
                'query' => $query,
                'sources' => implode(', ', $sources),
                'rss_articles' => count($rssArticles),
                'api_articles' => count($apiArticles),
                'total_results' => count($allArticles),
                'max_total_articles' => $maxTotalArticles,
                'formatted_text_chars' => $formattedResults ? mb_strlen($formattedResults) : 0,
                'duration_ms' => $result['duration_ms'],
                'ai_analysis' => $result['ai_analysis']
            ]);

        } catch (Exception $e) {
            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    /**
     * Extract previous node data (e.g., RSS feeds) from input
     * This handles the workflow chaining where RSS feeds execute before ResearchQuery
     */
    private function extractPreviousData(array $input): string
    {
        // Check for formatted_text from RSS feeds
        if (isset($input['data']['formatted_text']) && is_string($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        // Check if input contains data from previous nodes
        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        return '';
    }

    /**
     * Extract previous articles (structured) from input
     * This allows RSS articles to flow through to BatchProcessor
     */
    private function extractPreviousArticles(array $input): array
    {
        // Check for articles array from previous RSS feeds
        if (isset($input['data']['articles']) && is_array($input['data']['articles'])) {
            return $input['data']['articles'];
        }

        return [];
    }

    /**
     * Format research results into a readable text format for AI processing
     */
    private function formatResults(array $result): string
    {
        $output = [];

        // Add query info
        $output[] = "Research Query: {$result['query']}";
        $output[] = "Sources: " . implode(', ', $result['sources_queried']);
        $output[] = "Total Results: {$result['total_results']}";
        $output[] = "Duration: {$result['duration_ms']}ms";
        $output[] = "";

        // Add AI analysis if available
        if (!empty($result['ai_analysis'])) {
            $output[] = "AI ANALYSIS:";
            $output[] = $result['ai_analysis'];
            $output[] = "";
            $output[] = "---";
            $output[] = "";
        }

        // Add article details
        $output[] = "SEARCH RESULTS:";
        $output[] = "";

        foreach ($result['results']['articles'] as $index => $article) {
            $num = $index + 1;
            $output[] = "[{$num}] {$article['title']}";

            if (!empty($article['description'])) {
                $output[] = "    {$article['description']}";
            }

            if (!empty($article['source'])) {
                // Include bias rating if available
                $biasInfo = '';
                if (isset($article['bias_rating'])) {
                    $biasEmoji = $article['bias_rating']['emoji'] ?? '';
                    $biasRating = $article['bias_rating']['rating'] ?? '';
                    if ($biasEmoji && $biasRating) {
                        $biasInfo = " {$biasEmoji} (bias: {$biasRating})";
                    }
                }
                $output[] = "    Source: {$article['source']}{$biasInfo}";
            }

            if (!empty($article['url'])) {
                $output[] = "    URL: {$article['url']}";
            }

            $output[] = "";
        }

        return implode("\n", $output);
    }
}
