<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ResearchMCPService;

/**
 * Research Command
 *
 * CLI interface for multi-source news research
 */
class ResearchCommand extends Command
{
    protected $signature = 'research
                            {action : Action to perform (query, trending, topics, status)}
                            {query? : Search query (for query action)}
                            {--index=0 : Trending story index (for trending action)}
                            {--limit=10 : Number of results}
                            {--sources=* : Specific sources to use}
                            {--no-ai : Disable AI analysis}';

    protected $description = 'Multi-source news research with ground.news bias detection';

    private ResearchMCPService $researchService;

    public function __construct()
    {
        parent::__construct();
        $this->researchService = app(ResearchMCPService::class);
    }

    public function handle()
    {
        $action = $this->argument('action');

        try {
            switch ($action) {
                case 'query':
                    $this->handleQuery();
                    break;

                case 'trending':
                    $this->handleTrending();
                    break;

                case 'topics':
                    $this->handleTopics();
                    break;

                case 'status':
                    $this->handleStatus();
                    break;

                default:
                    $this->error("Unknown action: {$action}");
                    $this->line('');
                    $this->line('Available actions:');
                    $this->line('  query <query>        - Research a topic');
                    $this->line('  trending [--index=N] - Research trending story');
                    $this->line('  topics               - Show trending topics');
                    $this->line('  status               - Show service status');
                    return 1;
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Research failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function handleQuery()
    {
        $query = $this->argument('query');

        if (!$query) {
            $this->error('Query is required for query action');
            $this->line('Usage: php artisan research query "your search query"');
            return;
        }

        $this->info("Researching: {$query}");
        $this->line('');

        $sources = $this->option('sources');
        // Handle comma-separated sources (e.g., --sources=brave,newsapi)
        if (is_array($sources) && count($sources) === 1 && str_contains($sources[0], ',')) {
            $sources = explode(',', $sources[0]);
        }
        $limit = (int) $this->option('limit');
        $useAi = !$this->option('no-ai');

        $result = $this->researchService->research_query(
            $query,
            $sources,
            $limit,
            true, // parallel
            $useAi
        );

        $this->displayResults($result);
    }

    private function handleTrending()
    {
        $index = (int) $this->option('index');
        $limit = (int) $this->option('limit');
        $useAi = !$this->option('no-ai');

        $this->info("Researching trending story #{$index}...");
        $this->line('');

        $result = $this->researchService->research_trending(
            $index,
            [],
            $limit,
            $useAi
        );

        // Show ground.news context first
        if (isset($result['ground_news_context'])) {
            $ctx = $result['ground_news_context'];
            $this->line('<fg=cyan>Ground News Context:</>');
            $this->line('  Headline: ' . $ctx['original_headline']);
            $this->line(sprintf('  Bias: L:%d%% C:%d%% R:%d%%',
                $ctx['bias']['left'],
                $ctx['bias']['center'],
                $ctx['bias']['right']
            ));
            if ($ctx['blindspot']) {
                $this->line('  Blindspot: ' . $ctx['blindspot']);
            }
            $this->line('  Sources: ' . $ctx['sources']);
            $this->line('');
        }

        $this->displayResults($result);
    }

    private function handleTopics()
    {
        $limit = (int) $this->option('limit');

        $this->info("Fetching trending topics from ground.news...");
        $this->line('');

        $result = $this->researchService->get_trending_topics($limit);

        $this->line('<fg=cyan>Trending Stories from Ground News:</>');
        $this->line('');

        foreach ($result['stories'] as $i => $story) {
            $this->line("<fg=yellow>[{$i}] {$story['headline']}</>");

            if ($story['sources']) {
                $this->line("    Sources: {$story['sources']}");
            }

            if ($story['bias']) {
                $this->line(sprintf("    Bias: L:%d%% C:%d%% R:%d%%",
                    $story['bias']['left'] ?? 0,
                    $story['bias']['center'] ?? 0,
                    $story['bias']['right'] ?? 0
                ));
            }

            if ($story['blindspot']) {
                $this->line("    <fg=red>Blindspot for the {$story['blindspot']}</>");
            }

            if ($story['balance_score']) {
                $this->line("    Balance Score: {$story['balance_score']} (L-R difference)");
            }

            $this->line('');
        }

        $this->info("Total: {$result['count']} trending stories");
    }

    private function handleStatus()
    {
        $this->info('Research Service Status');
        $this->line('');

        $status = $this->researchService->research_status();

        $this->line("<fg=cyan>Status:</> {$status['status']}");
        $this->line("<fg=cyan>Enabled Sources:</> {$status['enabled_sources']}/{$status['total_sources']}");
        $this->line('');

        $this->line('<fg=cyan>Available Sources:</>');
        foreach ($status['sources'] as $key => $source) {
            $icon = $source['enabled'] ? '✓' : '✗';
            $color = $source['enabled'] ? 'green' : 'red';

            $this->line(sprintf(
                "  <fg={$color}>{$icon}</> %s (%s)",
                $source['name'],
                $source['free_tier']
            ));

            if ($source['requires_api_key']) {
                $configured = $source['configured'] ? 'configured' : 'NOT configured';
                $configColor = $source['configured'] ? 'green' : 'yellow';
                $this->line("      API Key: <fg={$configColor}>{$configured}</>");

                if (!$source['configured'] && isset($source['signup_url'])) {
                    $this->line("      Signup: {$source['signup_url']}");
                }
            }
        }

        $this->line('');
        $this->line('<fg=cyan>Features:</>');
        foreach ($status['features'] as $feature => $enabled) {
            $icon = $enabled ? '✓' : '✗';
            $color = $enabled ? 'green' : 'red';
            $featureName = str_replace('_', ' ', ucfirst($feature));
            $this->line("  <fg={$color}>{$icon}</> {$featureName}");
        }
    }

    private function displayResults(array $result)
    {
        $this->line('<fg=cyan>Query:</> ' . $result['query']);
        $this->line('<fg=cyan>Duration:</> ' . $result['duration_ms'] . 'ms');
        $this->line('<fg=cyan>Sources:</> ' . implode(', ', $result['sources_queried']));
        $this->line('<fg=cyan>Total Results:</> ' . $result['total_results']);
        $this->line('');

        // Show source stats
        if (isset($result['results']['source_stats'])) {
            $this->line('<fg=cyan>Results by Source:</>');
            foreach ($result['results']['source_stats'] as $source => $stats) {
                $status = $stats['success'] ? '✓' : '✗';
                $this->line("  {$status} {$source}: {$stats['count']} results");
            }
            $this->line('');
        }

        // Show AI analysis if available
        if (isset($result['ai_analysis']) && $result['ai_analysis']) {
            $this->line('<fg=cyan>AI Analysis:</>');
            $this->line('');
            $this->line($this->wrapText($result['ai_analysis'], 80));
            $this->line('');
        }

        // Show top results
        $articles = $result['results']['articles'] ?? [];
        if (!empty($articles)) {
            $this->line('<fg=cyan>Top Results:</>');
            $this->line('');

            foreach (array_slice($articles, 0, 5) as $i => $article) {
                $this->line("<fg=yellow>[" . ($i + 1) . "] {$article['title']}</>");
                $this->line("    Source: {$article['source_name']} ({$article['source']})");
                if ($article['description']) {
                    $desc = substr($article['description'], 0, 150);
                    if (strlen($article['description']) > 150) $desc .= '...';
                    $this->line("    " . $this->wrapText($desc, 76, '    '));
                }
                $this->line("    URL: {$article['url']}");
                $this->line('');
            }

            if (count($articles) > 5) {
                $remaining = count($articles) - 5;
                $this->line("<fg=gray>... and {$remaining} more results</>");
            }
        } else {
            $this->warn('No results found');
        }
    }

    private function wrapText(string $text, int $width = 80, string $prefix = ''): string
    {
        $wrapped = wordwrap($text, $width, "\n{$prefix}");
        return $prefix . $wrapped;
    }
}
