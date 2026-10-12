<?php

namespace App\Nodes;

use App\Services\GraphlitResearchService;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Enhance research with the configured Graphlit search and knowledge-base tools.
 */
class ResearchPluginEnhancementNode extends BaseNode
{
    private ?GraphlitResearchService $graphlit = null;

    public function execute(array $input): array
    {
        $query = $input['data']['query'] ?? $input['query'] ?? null;
        $existingResults = $input['data']['existing_results'] ?? $input['existing_results'] ?? '';

        if (empty($query)) {
            return $this->standardOutput(null, [], 'Query is required');
        }

        $mode = $this->getConfigValue('mode', 'enhance');
        $useGraphlit = $this->getConfigValue('use_graphlit', true);
        $ingestResults = $this->getConfigValue('ingest_results', false);
        $searchPodcasts = $this->getConfigValue('search_podcasts', false);

        Log::info('ResearchPluginEnhancementNode: Starting', [
            'query' => substr($query, 0, 100),
            'mode' => $mode,
            'use_graphlit' => $useGraphlit,
        ]);

        try {
            if ($mode === 'comprehensive') {
                return $this->comprehensiveResearch($query, $useGraphlit, $searchPodcasts, $ingestResults);
            }

            $startTime = microtime(true);
            $results = [
                'query' => $query,
                'mode' => $mode,
                'graphlit_results' => null,
                'synthesis' => null,
            ];

            if ($useGraphlit) {
                $this->initGraphlit();
                if ($this->graphlit) {
                    $graphlitResults = $this->graphlit->webSearch($query, ['limit' => 10]);
                    $results['graphlit_results'] = $graphlitResults;
                    $results['knowledge_base'] = $this->graphlit->retrieveSources($query, ['inLast' => 'P30D']);

                    if ($searchPodcasts) {
                        $results['podcast_results'] = $this->graphlit->searchPodcasts($query, 5);
                    }

                    if ($ingestResults && ($graphlitResults['success'] ?? false)) {
                        $results['ingested_count'] = $this->ingestTopResults($graphlitResults['results'] ?? []);
                    }
                }
            }

            $results['synthesis'] = $this->synthesizeResults($query, $existingResults, $results);
            $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
            $results['success'] = true;

            return $this->standardOutput($results, [
                'mode' => $mode,
                'sources_used' => ['graphlit' => $useGraphlit && $this->graphlit !== null],
            ]);
        } catch (Exception $e) {
            Log::error('ResearchPluginEnhancementNode: Failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->standardOutput(null, [], $e->getMessage());
        }
    }

    private function comprehensiveResearch(
        string $query,
        bool $useGraphlit,
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
            'synthesis' => null,
        ];

        if ($useGraphlit) {
            $this->initGraphlit();
            if ($this->graphlit) {
                $results = array_merge($results, $this->graphlit->comprehensiveResearch($query, [
                    'ingestResults' => $ingestResults,
                    'searchPodcasts' => $searchPodcasts,
                ]));
            }
        }

        $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);
        $results['success'] = true;

        return $this->standardOutput($results, ['mode' => 'comprehensive']);
    }

    /** @param array<int, array<string, mixed>> $sources */
    private function ingestTopResults(array $sources): int
    {
        $ingestedCount = 0;
        foreach (array_slice($sources, 0, 3) as $source) {
            $url = $source['url'] ?? null;
            if ($url && ($this->graphlit?->ingestUrl($url)['success'] ?? false)) {
                $ingestedCount++;
            }
        }

        return $ingestedCount;
    }

    private function synthesizeResults(string $query, string $existingResults, array $results): string
    {
        $sections = ["## Research Enhancement Results\n\n**Query:** {$query}\n"];

        if ($existingResults !== '') {
            $sections[] = "### Original Research\n".substr($existingResults, 0, 500)
                .(strlen($existingResults) > 500 ? '...' : '');
        }

        if (! empty($results['graphlit_results']['results'])) {
            $sections[] = "### Web Search (Graphlit)\n";
            foreach (array_slice($results['graphlit_results']['results'], 0, 5) as $result) {
                $title = $result['title'] ?? 'Untitled';
                $url = $result['url'] ?? '';
                $text = $result['text'] ?? $result['snippet'] ?? '';
                $sections[] = "- **{$title}**\n  {$text}\n  Source: {$url}\n";
            }
        }

        if (! empty($results['knowledge_base']['sources'])) {
            $sections[] = "### Knowledge Base\n";
            foreach (array_slice($results['knowledge_base']['sources'], 0, 3) as $source) {
                $sections[] = '- '.($source['name'] ?? 'Document')."\n";
            }
        }

        if (! empty($results['podcast_results']['results'])) {
            $sections[] = "### Related Podcasts\n";
            foreach (array_slice($results['podcast_results']['results'], 0, 3) as $result) {
                $sections[] = '- '.($result['title'] ?? 'Episode')."\n";
            }
        }

        return implode("\n", $sections);
    }

    private function initGraphlit(): void
    {
        if ($this->graphlit !== null) {
            return;
        }

        try {
            $this->graphlit = app(GraphlitResearchService::class);
        } catch (Exception $e) {
            Log::warning('ResearchPluginEnhancementNode: Graphlit not available', ['error' => $e->getMessage()]);
        }
    }
}
