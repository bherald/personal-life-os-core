<?php

namespace App\Console\Commands;

use App\Services\BiasRatingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BiasAliasCommand extends Command
{
    protected $signature = 'bias:aliases
                            {--list : List configured source aliases}
                            {--add= : Add or update an alias}
                            {--canonical= : Canonical bias_ratings.news_source for --add}
                            {--notes= : Optional notes for --add}
                            {--activate= : Reactivate an alias}
                            {--deactivate= : Deactivate an alias}
                            {--unmatched : Show recent unmatched BiasRatingEnrich sources}
                            {--workflow=news_brief : Workflow used by --unmatched, or "all" for recent BiasRatingEnrich outputs}
                            {--limit=25 : Maximum rows to show}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Manage table-backed news source aliases for bias rating enrichment';

    public function handle(): int
    {
        if (! Schema::hasTable('bias_rating_aliases')) {
            return $this->finish([
                'status' => 'fail',
                'message' => 'bias_rating_aliases table is missing. Run migrations first.',
            ], self::FAILURE);
        }

        if ($this->filledOption('add')) {
            return $this->addAlias();
        }

        if ($this->filledOption('activate')) {
            return $this->setAliasActive((string) $this->option('activate'), true);
        }

        if ($this->filledOption('deactivate')) {
            return $this->setAliasActive((string) $this->option('deactivate'), false);
        }

        if ($this->option('unmatched')) {
            return $this->showUnmatched();
        }

        return $this->listAliases();
    }

    private function addAlias(): int
    {
        $alias = $this->normalizeAlias((string) $this->option('add'));
        $canonical = trim((string) $this->option('canonical'));

        if ($alias === '' || $canonical === '') {
            return $this->finish([
                'status' => 'fail',
                'message' => '--add and --canonical are required.',
            ], self::FAILURE);
        }

        $rating = DB::selectOne(
            'SELECT news_source FROM bias_ratings WHERE news_source = ? LIMIT 1',
            [$canonical]
        );

        if (! $rating) {
            return $this->finish([
                'status' => 'fail',
                'message' => "Canonical source '{$canonical}' was not found in bias_ratings.",
            ], self::FAILURE);
        }

        $values = [
            'canonical_source' => $canonical,
            'active' => true,
            'notes' => $this->stringOrNull($this->option('notes')),
            'updated_at' => now(),
        ];

        $existing = DB::selectOne(
            'SELECT id FROM bias_rating_aliases WHERE alias = ? LIMIT 1',
            [$alias]
        );

        if ($existing) {
            DB::table('bias_rating_aliases')
                ->where('alias', $alias)
                ->update($values);
        } else {
            DB::table('bias_rating_aliases')->insert(array_merge($values, [
                'alias' => $alias,
                'created_at' => now(),
            ]));
        }

        return $this->finish([
            'status' => 'ok',
            'action' => 'upserted',
            'alias' => $alias,
            'canonical_source' => $canonical,
        ]);
    }

    private function setAliasActive(string $alias, bool $active): int
    {
        $alias = $this->normalizeAlias($alias);

        if ($alias === '') {
            return $this->finish([
                'status' => 'fail',
                'message' => 'Alias is required.',
            ], self::FAILURE);
        }

        $existing = DB::selectOne(
            'SELECT id FROM bias_rating_aliases WHERE alias = ? LIMIT 1',
            [$alias]
        );

        if (! $existing) {
            return $this->finish([
                'status' => 'fail',
                'message' => "Alias '{$alias}' was not found.",
            ], self::FAILURE);
        }

        DB::update(
            'UPDATE bias_rating_aliases SET active = ?, updated_at = ? WHERE alias = ?',
            [$active ? 1 : 0, now(), $alias]
        );

        return $this->finish([
            'status' => 'ok',
            'action' => $active ? 'activated' : 'deactivated',
            'alias' => $alias,
        ]);
    }

    private function listAliases(): int
    {
        $rows = DB::select(
            'SELECT
                bra.alias,
                bra.canonical_source,
                bra.active,
                CASE WHEN br.id IS NULL THEN 0 ELSE 1 END AS canonical_found,
                bra.notes
             FROM bias_rating_aliases bra
             LEFT JOIN bias_ratings br ON br.news_source = bra.canonical_source
             ORDER BY bra.active DESC, bra.alias ASC
             LIMIT ?',
            [$this->limit()]
        );

        $aliases = array_map(fn ($row) => [
            'alias' => (string) $row->alias,
            'canonical_source' => (string) $row->canonical_source,
            'active' => (int) $row->active === 1,
            'canonical_found' => (int) $row->canonical_found === 1,
            'notes' => $this->stringOrNull($row->notes),
        ], $rows);

        return $this->finish([
            'status' => 'ok',
            'aliases' => $aliases,
        ]);
    }

    private function showUnmatched(): int
    {
        $limit = $this->limit();
        $executionIds = $this->recentBiasExecutionIds($limit);
        $unmatched = [];
        $handledExecutionIds = $this->collectUnmatchedFromMeta($unmatched, $limit, $executionIds);
        $this->collectUnmatchedFromData($unmatched, $limit, $handledExecutionIds, $executionIds);

        usort($unmatched, fn (array $a, array $b): int => [$b['count'], $a['source']] <=> [$a['count'], $b['source']]);

        return $this->finish([
            'status' => 'ok',
            'workflow' => $this->workflowOption(),
            'unmatched' => array_values($unmatched),
        ]);
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_title: ?string, sample_feed_url: ?string}>  $unmatched
     * @return array<int>
     */
    private function collectUnmatchedFromMeta(array &$unmatched, int $limit, array $includedExecutionIds = []): array
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
                    $this->stringOrNull($source['title'] ?? $source['sample_title'] ?? null),
                    $this->stringOrNull($source['feed_url'] ?? $source['url'] ?? null)
                );
            }
        }

        return $handledExecutionIds;
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_title: ?string, sample_feed_url: ?string}>  $unmatched
     * @param  array<int>  $excludedExecutionIds
     * @param  array<int>  $includedExecutionIds
     */
    private function collectUnmatchedFromData(array &$unmatched, int $limit, array $excludedExecutionIds = [], array $includedExecutionIds = []): void
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

        $service = app(BiasRatingService::class);

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
                if ($service->getRating($source, $feedUrl) !== null) {
                    continue;
                }

                $this->addUnmatchedSource(
                    $unmatched,
                    $source,
                    $this->stringOrNull($article['title'] ?? null),
                    $feedUrl
                );
            }
        }
    }

    /**
     * @param  array<string, array{source: string, count: int, sample_title: ?string, sample_feed_url: ?string}>  $unmatched
     */
    private function addUnmatchedSource(array &$unmatched, string $source, ?string $title, ?string $feedUrl): void
    {
        $key = strtolower($source);
        if (! isset($unmatched[$key])) {
            $unmatched[$key] = [
                'source' => $source,
                'count' => 0,
                'sample_title' => $title,
                'sample_feed_url' => $feedUrl,
            ];
        }

        $unmatched[$key]['count']++;
    }

    private function finish(array $payload, int $exitCode = self::SUCCESS): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        if (($payload['status'] ?? null) === 'fail') {
            $this->error((string) ($payload['message'] ?? 'Command failed.'));

            return $exitCode;
        }

        if (isset($payload['aliases'])) {
            $this->table(
                ['Alias', 'Canonical Source', 'Active', 'Canonical Found', 'Notes'],
                array_map(fn ($row) => [
                    $row['alias'],
                    $row['canonical_source'],
                    $row['active'] ? 'yes' : 'no',
                    $row['canonical_found'] ? 'yes' : 'no',
                    $row['notes'] ?? '',
                ], $payload['aliases'])
            );

            return $exitCode;
        }

        if (isset($payload['unmatched'])) {
            $this->table(
                ['Source', 'Count', 'Sample Title', 'Feed URL'],
                array_map(fn ($row) => [
                    $row['source'],
                    $row['count'],
                    $row['sample_title'] ?? '',
                    $row['sample_feed_url'] ?? '',
                ], $payload['unmatched'])
            );

            return $exitCode;
        }

        $this->info((string) ($payload['action'] ?? 'ok').': '.(string) ($payload['alias'] ?? ''));

        return $exitCode;
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

        if (array_is_list($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function extractUnmatchedSources(string $outputValue): ?array
    {
        $decoded = json_decode($outputValue, true);
        if (! is_array($decoded) || ! array_key_exists('unmatched_sources', $decoded)) {
            return null;
        }

        return is_array($decoded['unmatched_sources']) ? $decoded['unmatched_sources'] : [];
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
            $value = $this->normalizeAlias((string) ($article[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeAlias(string $value): string
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

    private function limit(): int
    {
        $limit = (int) $this->option('limit');

        return max(1, min(500, $limit));
    }

    /**
     * @return array<int>
     */
    private function recentBiasExecutionIds(int $limit): array
    {
        $workflow = $this->workflowOption();
        if ($workflow === 'all') {
            return [];
        }

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

    private function workflowOption(): string
    {
        $workflow = strtolower(trim((string) $this->option('workflow')));

        return $workflow === '' ? 'news_brief' : $workflow;
    }

    private function missingTables(array $tables): array
    {
        return array_values(array_filter($tables, fn (string $table): bool => ! Schema::hasTable($table)));
    }

    private function filledOption(string $name): bool
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
