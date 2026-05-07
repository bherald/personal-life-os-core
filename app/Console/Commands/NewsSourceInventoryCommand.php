<?php

namespace App\Console\Commands;

use App\Services\BiasRatingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NewsSourceInventoryCommand extends Command
{
    protected $signature = 'news:source-inventory
                            {--workflow=news_brief : Workflow name to inspect, or "all"}
                            {--days=7 : Recent news_articles window}
                            {--limit=100 : Maximum feed rows to show}
                            {--strict : Return failure when warnings are present}
                            {--json : Output machine-readable JSON}
                            {--compact : Output an aggregate-only operator summary}';

    protected $description = 'Read-only inventory of table-backed RSS feeds, health, recent articles, and bias-rating coverage';

    public function handle(): int
    {
        $missingTables = $this->missingTables([
            'workflows',
            'workflow_nodes',
            'workflow_node_configs',
        ]);

        if ($missingTables !== []) {
            return $this->finish([
                'generated_at' => now()->toIso8601String(),
                'status' => 'fail',
                'warnings' => [],
                'errors' => ['Missing required table(s): '.implode(', ', $missingTables)],
                'storage' => $this->storageSummary(),
                'summary' => $this->emptySummary(),
                'coverage_gaps' => $this->emptyCoverageGaps(),
                'feeds' => [],
            ], self::FAILURE);
        }

        $feeds = $this->collectFeeds();
        $feeds = array_slice($feeds, 0, $this->limit());

        $recentStats = $this->recentArticleStats($this->days());
        $healthStats = $this->feedHealthStats();
        $biasService = Schema::hasTable('bias_ratings') ? app(BiasRatingService::class) : null;

        $feeds = array_map(function (array $feed) use ($recentStats, $healthStats, $biasService): array {
            $feedUrl = $feed['feed_url'];
            $recent = $recentStats[$feedUrl] ?? null;
            $health = $healthStats[$feedUrl] ?? null;
            $lookupSource = $feed['feed_label'] ?: $this->hostFromUrl($feedUrl);
            $rating = $biasService ? $biasService->getRating($lookupSource, $feedUrl) : null;

            $feed['recent_articles'] = [
                'window_days' => $this->days(),
                'count' => (int) ($recent['count'] ?? 0),
                'last_article_at' => $recent['last_article_at'] ?? null,
            ];

            $feed['health'] = [
                'status' => $health['status'] ?? null,
                'consecutive_failures' => $health['consecutive_failures'] ?? null,
                'last_check_at' => $health['last_check_at'] ?? null,
                'last_success_at' => $health['last_success_at'] ?? null,
                'last_error_type' => $health['last_error_type'] ?? null,
            ];

            $feed['bias'] = [
                'lookup_source' => $lookupSource,
                'rating_found' => $rating !== null,
                'rating' => $rating['rating'] ?? null,
                'canonical_source' => $rating['source'] ?? null,
                'data_source' => $rating['data_source'] ?? null,
            ];

            return $feed;
        }, $feeds);

        $coverageGaps = $this->coverageGaps($feeds);
        $warnings = $this->warnings($feeds, $coverageGaps);
        $status = $warnings === [] ? 'pass' : 'warn';
        $exitCode = $status === 'warn' && $this->option('strict') ? self::FAILURE : self::SUCCESS;

        return $this->finish([
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'warnings' => $warnings,
            'errors' => [],
            'storage' => $this->storageSummary(),
            'summary' => $this->summary($feeds),
            'coverage_gaps' => $coverageGaps,
            'feeds' => $feeds,
        ], $exitCode);
    }

    private function collectFeeds(): array
    {
        $workflow = trim((string) $this->option('workflow'));
        $workflow = $workflow === '' ? 'news_brief' : $workflow;

        $sql = 'SELECT
                    w.id AS workflow_id,
                    w.name AS workflow_name,
                    w.active AS workflow_active,
                    wn.id AS node_id,
                    wn.node_type,
                    wn.node_order,
                    wnc.config_key,
                    wnc.config_value
                FROM workflows w
                INNER JOIN workflow_nodes wn ON wn.workflow_id = w.id
                LEFT JOIN workflow_node_configs wnc ON wnc.workflow_node_id = wn.id
                WHERE wn.node_type IN (?, ?)';
        $params = ['RSSFeedReader', 'ParallelRSSFeedReader'];

        if (strtolower($workflow) !== 'all') {
            $sql .= ' AND w.name = ?';
            $params[] = $workflow;
        }

        $sql .= ' ORDER BY w.name ASC, wn.node_order ASC, wn.id ASC, wnc.config_key ASC';

        $nodes = [];
        foreach (DB::select($sql, $params) as $row) {
            $nodeId = (int) $row->node_id;
            if (! isset($nodes[$nodeId])) {
                $nodes[$nodeId] = [
                    'workflow_id' => (int) $row->workflow_id,
                    'workflow_name' => (string) $row->workflow_name,
                    'workflow_active' => (bool) $row->workflow_active,
                    'node_id' => $nodeId,
                    'node_type' => (string) $row->node_type,
                    'node_order' => (int) $row->node_order,
                    'configs' => [],
                ];
            }

            if ($row->config_key !== null) {
                $nodes[$nodeId]['configs'][(string) $row->config_key] = $row->config_value;
            }
        }

        $feeds = [];
        foreach ($nodes as $node) {
            if ($node['node_type'] === 'RSSFeedReader') {
                $feedUrl = $this->stringOrNull($node['configs']['feed_url'] ?? null);
                if ($feedUrl === null) {
                    continue;
                }

                $feeds[] = $this->feedRow(
                    $node,
                    $feedUrl,
                    $this->stringOrNull($node['configs']['feed_name'] ?? null),
                    $this->intOrNull($node['configs']['limit'] ?? null),
                    $this->intOrNull($node['configs']['timeout'] ?? null),
                    null
                );
            }

            if ($node['node_type'] === 'ParallelRSSFeedReader') {
                foreach ($this->parallelFeedConfigs($node['configs']['feeds'] ?? null) as $index => $feedConfig) {
                    $feedUrl = $this->stringOrNull($feedConfig['url'] ?? null);
                    if ($feedUrl === null) {
                        continue;
                    }

                    $feeds[] = $this->feedRow(
                        $node,
                        $feedUrl,
                        $this->feedLabel($feedConfig),
                        $this->intOrNull($feedConfig['limit'] ?? null),
                        $this->intOrNull($feedConfig['timeout'] ?? null),
                        $index
                    );
                }
            }
        }

        return $feeds;
    }

    private function feedRow(array $node, string $feedUrl, ?string $feedLabel, ?int $limit, ?int $timeout, ?int $feedIndex): array
    {
        return [
            'workflow_id' => $node['workflow_id'],
            'workflow_name' => $node['workflow_name'],
            'workflow_active' => $node['workflow_active'],
            'node_id' => $node['node_id'],
            'node_type' => $node['node_type'],
            'node_order' => $node['node_order'],
            'feed_index' => $feedIndex,
            'feed_url' => $feedUrl,
            'feed_label' => $feedLabel ?? $this->hostFromUrl($feedUrl),
            'limit' => $limit,
            'timeout' => $timeout,
        ];
    }

    private function parallelFeedConfigs(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private function recentArticleStats(int $days): array
    {
        if (! Schema::hasTable('news_articles')) {
            return [];
        }

        $since = now()->subDays($days)->toDateTimeString();
        $rows = DB::select(
            'SELECT
                feed_url,
                COUNT(*) AS count,
                MAX(COALESCE(published_at, fetched_at)) AS last_article_at
             FROM news_articles
             WHERE fetched_at >= ?
             GROUP BY feed_url',
            [$since]
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->feed_url] = [
                'count' => (int) $row->count,
                'last_article_at' => $this->stringOrNull($row->last_article_at),
            ];
        }

        return $stats;
    }

    private function feedHealthStats(): array
    {
        if (! Schema::hasTable('rss_feed_health')) {
            return [];
        }

        $rows = DB::select(
            'SELECT
                feed_url,
                status,
                consecutive_failures,
                last_check_at,
                last_success_at,
                last_error_type
             FROM rss_feed_health'
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row->feed_url] = [
                'status' => $this->stringOrNull($row->status),
                'consecutive_failures' => $row->consecutive_failures === null ? null : (int) $row->consecutive_failures,
                'last_check_at' => $this->stringOrNull($row->last_check_at),
                'last_success_at' => $this->stringOrNull($row->last_success_at),
                'last_error_type' => $this->stringOrNull($row->last_error_type),
            ];
        }

        return $stats;
    }

    private function storageSummary(): array
    {
        return [
            'rss_feed_config_table' => 'workflow_node_configs',
            'rss_feed_health_table_present' => Schema::hasTable('rss_feed_health'),
            'news_articles_table_present' => Schema::hasTable('news_articles'),
            'bias_ratings_table_present' => Schema::hasTable('bias_ratings'),
            'bias_rating_aliases_table_present' => Schema::hasTable('bias_rating_aliases'),
            'polarizing_topics_table_present' => Schema::hasTable('polarizing_topics'),
            'emotional_language_words_table_present' => Schema::hasTable('emotional_language_words'),
        ];
    }

    private function summary(array $feeds): array
    {
        $health = [];
        $recentArticleCount = 0;
        $biasCovered = 0;

        foreach ($feeds as $feed) {
            $status = $feed['health']['status'] ?? 'untracked';
            $status = $status ?: 'untracked';
            $health[$status] = ($health[$status] ?? 0) + 1;
            $recentArticleCount += (int) ($feed['recent_articles']['count'] ?? 0);

            if (($feed['bias']['rating_found'] ?? false) === true) {
                $biasCovered++;
            }
        }

        ksort($health);

        return [
            'feeds' => count($feeds),
            'active_workflows' => count(array_unique(array_map(
                fn (array $feed): int => $feed['workflow_active'] ? $feed['workflow_id'] : 0,
                array_filter($feeds, fn (array $feed): bool => $feed['workflow_active'])
            ))),
            'recent_articles' => $recentArticleCount,
            'bias_covered' => $biasCovered,
            'bias_missing' => count($feeds) - $biasCovered,
            'health' => $health,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'feeds' => 0,
            'active_workflows' => 0,
            'recent_articles' => 0,
            'bias_covered' => 0,
            'bias_missing' => 0,
            'health' => [],
        ];
    }

    private function emptyCoverageGaps(): array
    {
        return [
            'missing_bias_feeds' => [],
            'failed_health_feeds' => [],
        ];
    }

    private function coverageGaps(array $feeds): array
    {
        $missingBias = [];
        $failedHealth = [];

        foreach ($feeds as $feed) {
            $row = [
                'workflow_name' => $feed['workflow_name'],
                'feed_label' => $feed['feed_label'],
                'feed_url' => $feed['feed_url'],
                'lookup_source' => $feed['bias']['lookup_source'] ?? null,
                'recent_articles' => (int) ($feed['recent_articles']['count'] ?? 0),
                'health_status' => $feed['health']['status'] ?? null,
            ];

            if (! ($feed['bias']['rating_found'] ?? false)) {
                $missingBias[] = $row;
            }

            if (($feed['health']['status'] ?? null) === 'failed') {
                $failedHealth[] = $row + [
                    'consecutive_failures' => $feed['health']['consecutive_failures'] ?? null,
                    'last_error_type' => $feed['health']['last_error_type'] ?? null,
                ];
            }
        }

        return [
            'missing_bias_feeds' => $missingBias,
            'failed_health_feeds' => $failedHealth,
        ];
    }

    private function warnings(array $feeds, array $coverageGaps): array
    {
        $warnings = [];

        if ($feeds === []) {
            $warnings[] = 'No RSS feeds were found for the selected workflow filter.';
        }

        if (! Schema::hasTable('bias_ratings')) {
            $warnings[] = 'bias_ratings table is missing; political-bias coverage cannot be evaluated.';
        }

        if (! Schema::hasTable('bias_rating_aliases')) {
            $warnings[] = 'bias_rating_aliases table is missing; operator-maintained source aliases are unavailable.';
        }

        $missingBias = count($coverageGaps['missing_bias_feeds'] ?? []);
        if ($missingBias > 0) {
            $warnings[] = "{$missingBias} configured feed(s) do not resolve to a bias rating: ".$this->gapLabels($coverageGaps['missing_bias_feeds']);
        }

        $failedFeeds = count($coverageGaps['failed_health_feeds'] ?? []);
        if ($failedFeeds > 0) {
            $warnings[] = "{$failedFeeds} configured feed(s) currently have failed RSS health: ".$this->gapLabels($coverageGaps['failed_health_feeds']);
        }

        return $warnings;
    }

    private function gapLabels(array $feeds): string
    {
        $labels = array_map(
            fn (array $feed): string => (string) ($feed['feed_label'] ?: $feed['feed_url']),
            array_slice($feeds, 0, 5)
        );

        $remaining = count($feeds) - count($labels);
        $text = implode(', ', $labels);

        return $remaining > 0 ? "{$text}, +{$remaining} more" : $text;
    }

    private function finish(array $payload, int $exitCode): int
    {
        if ($this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->line('News source inventory: '.strtoupper((string) $payload['status']));

        foreach ($payload['errors'] as $error) {
            $this->error($error);
        }

        foreach ($payload['warnings'] as $warning) {
            $this->warn($warning);
        }

        $summary = $payload['summary'];
        $this->table(['Metric', 'Value'], [
            ['Feeds', $summary['feeds']],
            ['Active workflows', $summary['active_workflows']],
            ['Recent articles', $summary['recent_articles']],
            ['Bias covered', $summary['bias_covered']],
            ['Bias missing', $summary['bias_missing']],
            ['Health', json_encode($summary['health'], JSON_UNESCAPED_SLASHES)],
        ]);

        if ($this->option('compact')) {
            return $exitCode;
        }

        $this->table(
            ['Workflow', 'Feed', 'Bias', 'Health', 'Recent', 'URL'],
            array_map(fn (array $feed): array => [
                $feed['workflow_name'],
                $this->shorten($feed['feed_label'], 32),
                $feed['bias']['rating'] ?? 'missing',
                $feed['health']['status'] ?? 'untracked',
                $feed['recent_articles']['count'] ?? 0,
                $this->shorten($feed['feed_url'], 72),
            ], $payload['feeds'])
        );

        return $exitCode;
    }

    private function compactPayload(array $payload): array
    {
        $gaps = $payload['coverage_gaps'] ?? [];
        $missingBias = $gaps['missing_bias_feeds'] ?? [];
        $failedHealth = $gaps['failed_health_feeds'] ?? [];

        return [
            'generated_at' => $payload['generated_at'] ?? now()->toIso8601String(),
            'status' => $payload['status'] ?? 'fail',
            'warnings' => $payload['warnings'] ?? [],
            'errors' => $payload['errors'] ?? [],
            'summary' => $payload['summary'] ?? $this->emptySummary(),
            'coverage_gaps' => [
                'missing_bias_feeds' => count($missingBias),
                'failed_health_feeds' => count($failedHealth),
                'missing_bias_labels' => $this->gapLabelList($missingBias),
                'failed_health_labels' => $this->gapLabelList($failedHealth),
            ],
            'storage' => [
                'rss_feed_config_table' => $payload['storage']['rss_feed_config_table'] ?? 'workflow_node_configs',
                'rss_feed_health_table_present' => (bool) ($payload['storage']['rss_feed_health_table_present'] ?? false),
                'news_articles_table_present' => (bool) ($payload['storage']['news_articles_table_present'] ?? false),
                'bias_ratings_table_present' => (bool) ($payload['storage']['bias_ratings_table_present'] ?? false),
                'bias_rating_aliases_table_present' => (bool) ($payload['storage']['bias_rating_aliases_table_present'] ?? false),
            ],
        ];
    }

    private function gapLabelList(array $feeds): array
    {
        return array_values(array_map(
            fn (array $feed): string => $this->shorten((string) ($feed['feed_label'] ?? $feed['lookup_source'] ?? 'unlabeled feed'), 80),
            array_slice($feeds, 0, 5)
        ));
    }

    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    private function feedLabel(array $feedConfig): ?string
    {
        foreach (['name', 'feed_name', 'source', 'title', 'description'] as $key) {
            $value = $this->stringOrNull($feedConfig[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? strtolower($host) : strtolower($url);

        foreach (['www.', 'feeds.', 'rss.', 'api.', 'news.'] as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return substr($host, strlen($prefix));
            }
        }

        return $host;
    }

    private function days(): int
    {
        return max(1, min(365, (int) $this->option('days')));
    }

    private function limit(): int
    {
        return max(1, min(500, (int) $this->option('limit')));
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function shorten(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 3).'...';
    }
}
