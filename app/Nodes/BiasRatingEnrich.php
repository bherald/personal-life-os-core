<?php

namespace App\Nodes;

use App\Services\BiasRatingService;
use Exception;

/**
 * Bias Rating Enrich Node
 *
 * Enriches news articles with AllSides bias ratings.
 * Adds political bias information (left/center/right) to help identify balanced sources.
 *
 * Input: Articles array with 'source' or 'source_name' fields
 * Output: Articles enriched with bias_rating data
 *
 * Configuration:
 * - source_field: (optional) Field name containing source (default: auto-detect 'source' or 'source_name')
 * - include_summary: (optional) Include bias distribution summary (default: true)
 */
class BiasRatingEnrich extends BaseNode
{
    public function execute(array $input): array
    {
        try {
            $biasService = app(BiasRatingService::class);
            $sourceField = $this->getConfigValue('source_field', null);
            $includeSummary = $this->getConfigValue('include_summary', true);

            // Extract articles and formatted text from input
            $articles = $this->extractArticles($input);
            $formattedText = $this->extractFormattedText($input);

            if (empty($articles)) {
                // Pass through formatted text even if no articles to enrich
                return $this->standardOutput(
                    $formattedText ?: $input,
                    ['enriched_count' => 0, 'message' => 'No articles found to enrich'],
                    null
                );
            }

            // Enrich articles with bias ratings
            $enrichedArticles = [];
            $enrichedCount = 0;
            $unmatchedSources = [];

            foreach ($articles as $article) {
                if (! is_array($article)) {
                    $enrichedArticles[] = $article;

                    continue;
                }

                // Get source name and feed URL from article
                $sourceName = $this->extractSourceName($article, $sourceField);
                $feedUrl = $article['feed_url'] ?? null;

                if ($sourceName) {
                    // Use BiasRatingService to enrich article with all scoring
                    $article = $biasService->enrichArticle($article);

                    // Check if bias rating was found
                    if (isset($article['bias_rating'])) {
                        $enrichedCount++;
                    } else {
                        $unmatchedSources[] = [
                            'source' => $sourceName,
                            'feed_url' => $feedUrl ?? $article['url'] ?? null,
                        ];
                    }
                }

                $enrichedArticles[] = $article;
            }

            // Build meta info
            $meta = [
                'total_articles' => count($articles),
                'enriched_count' => $enrichedCount,
                'enrichment_rate' => count($articles) > 0
                    ? round(($enrichedCount / count($articles)) * 100, 1).'%'
                    : '0%',
                'unmatched_sources' => array_slice($unmatchedSources, 0, 20),
            ];

            // Add bias distribution summary if requested
            if ($includeSummary && $enrichedCount > 0) {
                $meta['bias_distribution'] = $biasService->getBiasDistribution($enrichedArticles);
                $meta['bias_summary'] = $biasService->getBiasSummary($enrichedArticles);
            }

            // Return both enriched articles AND formatted text for next nodes
            $outputData = $formattedText ?: ['articles' => $enrichedArticles];
            if ($formattedText && ! empty($enrichedArticles)) {
                // If we have formatted text, also include enriched articles
                $outputData = [
                    'formatted_text' => $formattedText,
                    'articles' => $enrichedArticles,
                ];
            }

            return $this->standardOutput($outputData, $meta);

        } catch (Exception $e) {
            return $this->standardOutput(
                $input,
                ['error_message' => $e->getMessage()],
                'Failed to enrich articles with bias ratings: '.$e->getMessage()
            );
        }
    }

    /**
     * Extract articles array from input data
     */
    private function extractArticles(array $input): array
    {
        // Try common article array locations
        if (isset($input['data']['articles'])) {
            return $input['data']['articles'];
        }

        if (isset($input['data']) && is_array($input['data'])) {
            // If data is an array of articles
            if ($this->isArticleArray($input['data'])) {
                return $input['data'];
            }
        }

        if (isset($input['articles'])) {
            return $input['articles'];
        }

        // If input itself is an array of articles
        if ($this->isArticleArray($input)) {
            return $input;
        }

        return [];
    }

    /**
     * Extract formatted text from input data
     */
    private function extractFormattedText(array $input)
    {
        // Check for formatted_text in data
        if (isset($input['data']['formatted_text'])) {
            return $input['data']['formatted_text'];
        }

        // Check if data itself is formatted text (string)
        if (isset($input['data']) && is_string($input['data'])) {
            return $input['data'];
        }

        return null;
    }

    /**
     * Check if array looks like an article array
     */
    private function isArticleArray(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check first element for article-like structure
        $firstItem = reset($data);

        if (! is_array($firstItem)) {
            return false;
        }

        // Article should have at least one of these fields
        return isset($firstItem['title']) ||
               isset($firstItem['source']) ||
               isset($firstItem['source_name']) ||
               isset($firstItem['url']);
    }

    /**
     * Extract source name from article
     */
    private function extractSourceName(array $article, ?string $sourceField): ?string
    {
        // Use specified field if provided
        if ($sourceField && isset($article[$sourceField])) {
            return $article[$sourceField];
        }

        // Auto-detect common source field names
        return $article['source']
            ?? $article['source_name']
            ?? $article['sourceName']
            ?? null;
    }
}
