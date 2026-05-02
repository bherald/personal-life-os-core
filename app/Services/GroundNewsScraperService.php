<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Engine\MCPRouter;

/**
 * Ground News Scraper Service
 *
 * Scrapes trending stories from ground.news home page to identify
 * topics with bias indicators for balanced news research.
 */
class GroundNewsScraperService
{
    private $mcpRouter;

    public function __construct()
    {
        $this->mcpRouter = app(MCPRouter::class);
    }

    /**
     * Scrape trending stories from ground.news home page
     *
     * @param int $limit Maximum number of stories to return
     * @return array Array of stories with headlines, bias info, and metadata
     */
    public function getTrendingStories(int $limit = 10): array
    {
        try {
            Log::info('GroundNewsScraperService: Fetching trending stories', ['limit' => $limit]);

            // Navigate to ground.news
            Log::info('GroundNewsScraperService: Step 1 - Navigating...');
            $this->mcpRouter->callTool('puppeteer', 'puppeteer_navigate', [
                'url' => 'https://ground.news'
            ]);
            Log::info('GroundNewsScraperService: Step 1 - Navigation complete');

            // Click through to actual home page (bypass splash screen)
            Log::info('GroundNewsScraperService: Step 2 - Clicking splash screen...');
            $this->mcpRouter->callTool('puppeteer', 'puppeteer_click', [
                'selector' => 'a[href*="ground.news"]'
            ]);
            Log::info('GroundNewsScraperService: Step 2 - Click complete');

            // Wait a moment for page to load
            Log::info('GroundNewsScraperService: Step 3 - Waiting for page load...');
            sleep(3);
            Log::info('GroundNewsScraperService: Step 3 - Wait complete');

            // Extract story data using JavaScript
            Log::info('GroundNewsScraperService: Step 4 - Evaluating JavaScript...');
            $result = $this->mcpRouter->callTool('puppeteer', 'puppeteer_evaluate', [
                'script' => $this->getExtractionScript($limit)
            ]);
            Log::info('GroundNewsScraperService: Step 4 - Evaluation complete', [
                'result_type' => gettype($result),
                'has_result_key' => isset($result['result'])
            ]);

            $stories = json_decode($result['result'] ?? '[]', true);

            Log::info('GroundNewsScraperService: Extracted stories', [
                'count' => count($stories),
                'first_story' => $stories[0] ?? null
            ]);

            return $stories;

        } catch (\Exception $e) {
            Log::error('GroundNewsScraperService: Error scraping', [
                'error' => $e->getMessage()
            ]);

            // Return empty array on error, don't fail entire research
            return [];
        }
    }

    /**
     * Get JavaScript code for extracting story data from ground.news
     */
    private function getExtractionScript(int $limit): string
    {
        $script = <<<'JAVASCRIPT'
(function() {
    const stories = [];
    const seen = new Set();

    // Look for all text elements that might contain headlines
    const allElements = document.querySelectorAll('h1, h2, h3, h4, p, div, span');

    allElements.forEach((el) => {
        try {
            // Get the parent container to search for related info
            let container = el;
            for (let i = 0; i < 5; i++) {
                container = container.parentElement;
                if (!container) break;
            }
            if (!container) return;

            const containerText = container.textContent;
            const headline = el.textContent.trim();

            // Filter for likely headlines (30-200 chars, not generic text)
            if (headline.length < 30 || headline.length > 200) return;
            if (headline.includes('See every side')) return;
            if (headline.includes('Start your free')) return;
            if (headline.match(/^\d+\s*stories/i)) return;
            if (seen.has(headline)) return;

            // Look for bias indicators in container
            const lMatch = containerText.match(/L[:\s]*(\d+)%/i);
            const cMatch = containerText.match(/C[:\s]*(\d+)%/i);
            const rMatch = containerText.match(/R[:\s]*(\d+)%/i);

            // Look for blindspot indicator
            const blindspotMatch = containerText.match(/Blindspot for the (Left|Right)/i);

            // Look for source count
            const sourceMatch = containerText.match(/(\d+)\s*sources?/i);

            // Look for timestamp
            const timeMatch = containerText.match(/(\d+)\s*(hour|day|min|week)s?\s*(ago|left)?/i);

            // Only include if we found some ground.news specific data
            if (lMatch || cMatch || rMatch || blindspotMatch || sourceMatch) {
                seen.add(headline);
                stories.push({
                    headline: headline,
                    sources: sourceMatch ? parseInt(sourceMatch[1]) : null,
                    bias: {
                        left: lMatch ? parseInt(lMatch[1]) : 0,
                        center: cMatch ? parseInt(cMatch[1]) : 0,
                        right: rMatch ? parseInt(rMatch[1]) : 0
                    },
                    blindspot: blindspotMatch ? blindspotMatch[1] : null,
                    timestamp: timeMatch ? timeMatch[0] : null,
                    balance_score: lMatch && cMatch && rMatch ?
                        Math.abs(parseInt(lMatch[1]) - parseInt(rMatch[1])) : null
                });
            }
        } catch (e) {
            // Skip errors
        }
    });

    // Sort by source count (more sources = more significant story)
    stories.sort((a, b) => (b.sources || 0) - (a.sources || 0));

    return JSON.stringify(stories.slice(0, __LIMIT__), null, 2);
})();
JAVASCRIPT;
        return str_replace('__LIMIT__', (string)$limit, $script);
    }

    /**
     * Get topics from trending stories (just the headlines)
     * Useful for feeding into search APIs
     */
    public function getTrendingTopics(int $limit = 10): array
    {
        $stories = $this->getTrendingStories($limit);
        return array_map(fn($story) => $story['headline'], $stories);
    }

    /**
     * Get stories with significant bias (for focused balanced research)
     *
     * @param int $minBalanceScore Minimum difference between left and right coverage
     * @return array Stories with significant bias differences
     */
    public function getBiasedStories(int $minBalanceScore = 30, int $limit = 10): array
    {
        $stories = $this->getTrendingStories($limit * 2); // Get more, then filter

        $biased = array_filter($stories, function($story) use ($minBalanceScore) {
            return ($story['balance_score'] ?? 0) >= $minBalanceScore
                || $story['blindspot'] !== null;
        });

        return array_slice($biased, 0, $limit);
    }
}
