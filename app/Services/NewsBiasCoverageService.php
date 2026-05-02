<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NewsBiasCoverageService
{
    public function __construct(
        private readonly BiasRatingService $biasRatings = new BiasRatingService,
    ) {}

    public function collect(int $days = 7, int $limit = 25): array
    {
        $days = max(1, min(365, $days));
        $limit = max(1, min(100, $limit));
        $missing = $this->missingTables([
            'bias_ratings',
            'bias_rating_aliases',
            'news_articles',
            'node_executions',
            'node_execution_outputs',
        ]);

        if ($missing !== []) {
            return [
                'status' => 'blocked',
                'window_days' => $days,
                'missing_tables' => $missing,
                'summary' => $this->emptySummary(),
                'top_unmatched_sources' => [],
            ];
        }

        $ratings = $this->ratingSummary();
        $aliases = $this->aliasSummary();
        $recent = $this->recentArticleSummary($days);
        $unmatched = $this->recentUnmatchedSources($limit);

        $status = 'healthy';
        if ($recent['recent_articles'] === 0 || $recent['recent_bias_missing'] > 0 || count($unmatched) > 0 || $aliases['orphaned_aliases'] > 0) {
            $status = 'watch';
        }

        return [
            'status' => $status,
            'window_days' => $days,
            'missing_tables' => [],
            'summary' => array_merge($ratings, $aliases, $recent, [
                'unmatched_sources' => count($unmatched),
            ]),
            'top_unmatched_sources' => array_slice($unmatched, 0, 5),
        ];
    }

    private function ratingSummary(): array
    {
        $row = DB::selectOne(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN rating = 'left' THEN 1 ELSE 0 END) AS left_count,
                SUM(CASE WHEN rating = 'left-center' THEN 1 ELSE 0 END) AS left_center_count,
                SUM(CASE WHEN rating = 'center' THEN 1 ELSE 0 END) AS center_count,
                SUM(CASE WHEN rating = 'right-center' THEN 1 ELSE 0 END) AS right_center_count,
                SUM(CASE WHEN rating = 'right' THEN 1 ELSE 0 END) AS right_count
             FROM bias_ratings"
        );

        return [
            'bias_ratings' => (int) ($row->total ?? 0),
            'left_sources' => (int) ($row->left_count ?? 0),
            'left_center_sources' => (int) ($row->left_center_count ?? 0),
            'center_sources' => (int) ($row->center_count ?? 0),
            'right_center_sources' => (int) ($row->right_center_count ?? 0),
            'right_sources' => (int) ($row->right_count ?? 0),
        ];
    }

    private function aliasSummary(): array
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN bra.active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN bra.active = 0 THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN br.id IS NULL THEN 1 ELSE 0 END) AS orphaned_count
             FROM bias_rating_aliases bra
             LEFT JOIN bias_ratings br ON br.news_source = bra.canonical_source'
        );

        return [
            'aliases' => (int) ($row->total ?? 0),
            'active_aliases' => (int) ($row->active_count ?? 0),
            'inactive_aliases' => (int) ($row->inactive_count ?? 0),
            'orphaned_aliases' => (int) ($row->orphaned_count ?? 0),
        ];
    }

    private function recentArticleSummary(int $days): array
    {
        $feedSources = $this->configuredFeedSources('news_brief');
        $feedNameSelect = Schema::hasColumn('news_articles', 'feed_name') ? 'feed_name' : 'NULL AS feed_name';
        $feedFilter = '';
        $params = [$days];

        if ($feedSources !== []) {
            $placeholders = implode(', ', array_fill(0, count($feedSources), '?'));
            $feedFilter = " AND feed_url IN ({$placeholders})";
            $params = array_merge($params, array_keys($feedSources));
        }

        $rows = DB::select(
            "SELECT
                feed_url,
                {$feedNameSelect},
                COUNT(*) AS articles,
                SUM(CASE WHEN bias_score IS NOT NULL OR bias_data IS NOT NULL THEN 1 ELSE 0 END) AS persisted_covered,
                MAX(COALESCE(published_at, fetched_at)) AS latest_article_at
             FROM news_articles
             WHERE fetched_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               {$feedFilter}
             GROUP BY feed_url, feed_name",
            $params
        );

        $articles = 0;
        $covered = 0;
        $feeds = 0;
        $latestArticleAt = null;

        foreach ($rows as $row) {
            $feedUrl = (string) ($row->feed_url ?? '');
            $count = (int) ($row->articles ?? 0);
            $persistedCovered = (int) ($row->persisted_covered ?? 0);
            $lookupSource = $feedSources[$feedUrl] ?? $this->articleFeedSource($row->feed_name ?? null, $feedUrl);

            $articles += $count;
            $feeds++;
            $covered += $this->biasRatings->getRating($lookupSource, $feedUrl) !== null
                ? $count
                : $persistedCovered;

            $latest = $this->nullableString($row->latest_article_at ?? null);
            if ($latest !== null && ($latestArticleAt === null || strcmp($latest, $latestArticleAt) > 0)) {
                $latestArticleAt = $latest;
            }
        }

        return [
            'recent_articles' => $articles,
            'recent_feeds' => $feeds,
            'recent_bias_covered' => $covered,
            'recent_bias_missing' => max(0, $articles - $covered),
            'recent_bias_coverage_rate' => $articles > 0 ? round($covered / $articles, 4) : null,
            'latest_article_at' => $latestArticleAt,
        ];
    }

    private function recentUnmatchedSources(int $limit): array
    {
        $unmatched = [];
        $executionIds = $this->recentBiasExecutionIdsForWorkflow('news_brief', $limit);
        $handledExecutionIds = $this->collectRecentUnmatchedSourcesFromMeta($unmatched, $limit, $executionIds);
        $this->collectRecentUnmatchedSourcesFromData($unmatched, $limit, $handledExecutionIds, $executionIds);

        usort($unmatched, fn (array $a, array $b): int => [$b['count'], $a['source']] <=> [$a['count'], $b['source']]);

        return array_values($unmatched);
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_feed_url: ?string}>  $unmatched
     * @return array<int>
     */
    private function collectRecentUnmatchedSourcesFromMeta(array &$unmatched, int $limit, array $includedExecutionIds = []): array
    {
        $includedSql = '';
        $params = ['BiasRatingEnrich', 'success', 'meta'];
        if ($includedExecutionIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($includedExecutionIds), '?'));
            $includedSql = " AND ne.id IN ({$placeholders})";
            $params = array_merge($params, $includedExecutionIds);
        }
        $params[] = $limit;

        $rows = DB::select(
            'SELECT ne.id, neo.output_value
             FROM node_executions ne
             INNER JOIN node_execution_outputs neo ON neo.node_execution_id = ne.id
             WHERE ne.node_type = ?
               AND ne.state = ?
               AND neo.output_key = ?
               '.$includedSql.'
             ORDER BY ne.executed_at DESC, ne.id DESC
             LIMIT ?',
            $params
        );

        $handledExecutionIds = [];
        foreach ($rows as $row) {
            $sources = $this->extractUnmatchedSources((string) $row->output_value);
            if ($sources === null) {
                continue;
            }

            $handledExecutionIds[] = (int) $row->id;
            foreach ($sources as $source) {
                $sourceName = $this->stringOrNull($source['source'] ?? null);
                if ($sourceName === null) {
                    continue;
                }

                $this->addUnmatchedSource(
                    $unmatched,
                    $sourceName,
                    $this->stringOrNull($source['feed_url'] ?? $source['url'] ?? null)
                );
            }
        }

        return $handledExecutionIds;
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_feed_url: ?string}>  $unmatched
     * @param  array<int>  $excludedExecutionIds
     * @param  array<int>  $includedExecutionIds
     */
    private function collectRecentUnmatchedSourcesFromData(array &$unmatched, int $limit, array $excludedExecutionIds = [], array $includedExecutionIds = []): void
    {
        $includedSql = '';
        $excludedSql = '';
        $params = ['BiasRatingEnrich', 'success', 'data'];
        if ($includedExecutionIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($includedExecutionIds), '?'));
            $includedSql = " AND ne.id IN ({$placeholders})";
            $params = array_merge($params, $includedExecutionIds);
        }
        if ($excludedExecutionIds !== []) {
            $placeholders = implode(', ', array_fill(0, count($excludedExecutionIds), '?'));
            $excludedSql = " AND ne.id NOT IN ({$placeholders})";
            $params = array_merge($params, $excludedExecutionIds);
        }
        $params[] = $limit;

        $rows = DB::select(
            'SELECT neo.output_value
             FROM node_executions ne
             INNER JOIN node_execution_outputs neo ON neo.node_execution_id = ne.id
             WHERE ne.node_type = ?
               AND ne.state = ?
               AND neo.output_key = ?
               '.$includedSql.'
               '.$excludedSql.'
             ORDER BY ne.executed_at DESC, ne.id DESC
             LIMIT ?',
            $params
        );

        foreach ($rows as $row) {
            foreach ($this->extractArticles((string) $row->output_value) as $article) {
                if (! is_array($article)) {
                    continue;
                }

                $source = $this->articleSource($article);
                if ($source === '') {
                    continue;
                }

                $feedUrl = $this->stringOrNull($article['feed_url'] ?? null);
                if ($this->biasRatings->getRating($source, $feedUrl) !== null) {
                    continue;
                }

                $this->addUnmatchedSource($unmatched, $source, $feedUrl);
            }
        }
    }

    private function extractArticles(string $outputValue): array
    {
        $decoded = json_decode($outputValue, true);
        if (! is_array($decoded)) {
            return [];
        }

        $articles = $decoded['articles'] ?? $decoded['data']['articles'] ?? null;
        if (is_array($articles)) {
            return $articles;
        }

        return array_is_list($decoded) ? $decoded : [];
    }

    private function extractUnmatchedSources(string $outputValue): ?array
    {
        $decoded = json_decode($outputValue, true);
        if (! is_array($decoded) || ! array_key_exists('unmatched_sources', $decoded)) {
            return null;
        }

        return is_array($decoded['unmatched_sources']) ? $decoded['unmatched_sources'] : [];
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_feed_url: ?string}>  $unmatched
     */
    private function addUnmatchedSource(array &$unmatched, string $source, ?string $feedUrl): void
    {
        $key = strtolower($source);
        $unmatched[$key] ??= [
            'source' => $source,
            'count' => 0,
            'sample_feed_url' => $feedUrl,
        ];
        $unmatched[$key]['count']++;
    }

    private function articleSource(array $article): string
    {
        foreach (['source', 'source_name'] as $key) {
            $value = $this->stringOrNull($article[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        foreach (['feed_url', 'url'] as $key) {
            $value = $this->normalizeHost((string) ($article[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeHost(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $value = strtolower($host);
        }

        foreach (['www.', 'feeds.', 'rss.', 'api.', 'news.'] as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return substr($value, strlen($prefix));
            }
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function configuredFeedSources(string $workflow): array
    {
        if ($this->missingTables(['workflows', 'workflow_nodes', 'workflow_node_configs']) !== []) {
            return [];
        }

        $rows = DB::select(
            'SELECT
                wn.id AS node_id,
                wn.node_type,
                wnc.config_key,
                wnc.config_value
             FROM workflows w
             INNER JOIN workflow_nodes wn ON wn.workflow_id = w.id
             LEFT JOIN workflow_node_configs wnc ON wnc.workflow_node_id = wn.id
             WHERE w.name = ?
               AND wn.node_type IN (?, ?)
             ORDER BY wn.node_order ASC, wn.id ASC, wnc.config_key ASC',
            [$workflow, 'RSSFeedReader', 'ParallelRSSFeedReader']
        );

        $nodes = [];
        foreach ($rows as $row) {
            $nodeId = (int) $row->node_id;
            $nodes[$nodeId] ??= [
                'node_type' => (string) $row->node_type,
                'configs' => [],
            ];

            if ($row->config_key !== null) {
                $nodes[$nodeId]['configs'][(string) $row->config_key] = $row->config_value;
            }
        }

        $feeds = [];
        foreach ($nodes as $node) {
            if ($node['node_type'] === 'RSSFeedReader') {
                $feedUrl = $this->stringOrNull($node['configs']['feed_url'] ?? null);
                if ($feedUrl !== null) {
                    $feeds[$feedUrl] = $this->stringOrNull($node['configs']['feed_name'] ?? null)
                        ?? $this->normalizeHost($feedUrl);
                }
            }

            if ($node['node_type'] === 'ParallelRSSFeedReader') {
                foreach ($this->parallelFeedConfigs($node['configs']['feeds'] ?? null) as $feedConfig) {
                    $feedUrl = $this->stringOrNull($feedConfig['url'] ?? null);
                    if ($feedUrl !== null) {
                        $feeds[$feedUrl] = $this->feedLabel($feedConfig) ?? $this->normalizeHost($feedUrl);
                    }
                }
            }
        }

        return $feeds;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parallelFeedConfigs(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
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

    private function articleFeedSource(mixed $feedName, string $feedUrl): string
    {
        $name = $this->stringOrNull($feedName);
        if ($name !== null && ! in_array(strtolower($name), ['parallel rss', 'rss'], true)) {
            return $name;
        }

        return $this->normalizeHost($feedUrl);
    }

    /**
     * @return array<int>
     */
    private function recentBiasExecutionIdsForWorkflow(string $workflow, int $limit): array
    {
        if (
            $this->missingTables(['workflows', 'workflow_runs', 'node_executions']) !== []
            || ! Schema::hasColumn('node_executions', 'run_id')
        ) {
            return [];
        }

        $rows = DB::select(
            'SELECT ne.id
             FROM node_executions ne
             INNER JOIN (
                SELECT wr.id
                FROM workflow_runs wr
                INNER JOIN workflows w ON w.id = wr.workflow_id
                WHERE w.name = ?
                  AND wr.status = ?
                ORDER BY COALESCE(wr.completed_at, wr.started_at) DESC, wr.id DESC
                LIMIT 1
             ) latest_run ON latest_run.id = ne.run_id
             WHERE 1 = 1
               AND ne.node_type = ?
               AND ne.state = ?
             ORDER BY ne.executed_at DESC, ne.id DESC
             LIMIT ?',
            [$workflow, 'completed', 'BiasRatingEnrich', 'success', $limit]
        );

        return array_map(fn (object $row): int => (int) $row->id, $rows);
    }

    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    private function emptySummary(): array
    {
        return [
            'bias_ratings' => 0,
            'aliases' => 0,
            'active_aliases' => 0,
            'orphaned_aliases' => 0,
            'recent_articles' => 0,
            'recent_feeds' => 0,
            'recent_bias_covered' => 0,
            'recent_bias_missing' => 0,
            'recent_bias_coverage_rate' => null,
            'unmatched_sources' => 0,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
