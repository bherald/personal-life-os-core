<?php

namespace App\Console\Commands;

use App\Services\NewsArticleService;
use App\Services\RAGService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DI-5: Index news articles into RAG for cross-domain search.
 *
 * Reads from news_articles table (populated by RSS workflows),
 * builds document content, indexes into RAG via RAGService,
 * marks articles as indexed.
 *
 * Usage:
 *   php artisan news:rag-index --limit=100
 *   php artisan news:rag-index --stats
 *   php artisan news:rag-index --dry-run
 *   php artisan news:rag-index --reindex
 */
class NewsRagIndexCommand extends Command
{
    protected $signature = 'news:rag-index
                            {--limit=100 : Max articles to index per run}
                            {--reindex : Re-index already indexed articles}
                            {--stats : Show indexing statistics}
                            {--dry-run : Preview without indexing}';

    protected $description = 'DI-5: Index news articles into RAG for cross-domain search';

    public function handle(): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        $limit = (int) $this->option('limit');
        $reindex = $this->option('reindex');
        $dryRun = $this->option('dry-run');

        $this->info("News RAG indexing (limit: {$limit}, reindex: " . ($reindex ? 'yes' : 'no') . ")");

        $articles = $this->getArticlesToIndex($limit, $reindex);

        if (empty($articles)) {
            $this->info('No articles to index. [ITEMS_PROCESSED:0]');
            return 0;
        }

        $this->info("Found " . count($articles) . " articles to index.");

        $ragService = app(RAGService::class);
        $newsService = app(NewsArticleService::class);
        $indexed = 0;
        $failed = 0;
        $startTime = microtime(true);

        foreach ($articles as $article) {
            try {
                $content = $this->buildArticleContent($article);

                if (strlen(trim($content)) < 50) {
                    $this->line("  Skip (too little content): {$article->title}");
                    continue;
                }

                if ($dryRun) {
                    $this->line("  Would index: {$article->title} (" . strlen($content) . " chars)");
                    $indexed++;
                    continue;
                }

                $ragService->indexDocument([
                    'title' => $article->title ?? 'Untitled Article',
                    'content' => $content,
                    'source' => 'news_article',
                    'source_id' => (string) $article->id,
                    'source_url' => $article->url ?? '',
                    'metadata' => json_encode([
                        'feed_name' => $article->feed_name,
                        'author' => $article->author,
                        'published_at' => $article->published_at,
                        'categories' => $article->categories,
                    ]),
                ]);

                $newsService->markRagIndexed($article->id);
                $indexed++;

                if ($indexed % 25 === 0) {
                    $elapsed = round(microtime(true) - $startTime, 1);
                    $this->line("  Progress: {$indexed} indexed ({$elapsed}s)");
                }

            } catch (\Exception $e) {
                $failed++;
                Log::warning('NewsRagIndex: Failed to index article', [
                    'article_id' => $article->id,
                    'title' => $article->title ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Done: {$indexed} indexed, {$failed} failed ({$elapsed}s). [ITEMS_PROCESSED:{$indexed}]");

        return $failed > 0 ? 1 : 0;
    }

    private function getArticlesToIndex(int $limit, bool $reindex): array
    {
        if ($reindex) {
            return DB::select(
                "SELECT * FROM news_articles ORDER BY published_at DESC LIMIT ?",
                [$limit]
            );
        }

        return DB::select(
            "SELECT * FROM news_articles WHERE rag_indexed_at IS NULL ORDER BY published_at DESC LIMIT ?",
            [$limit]
        );
    }

    private function buildArticleContent(object $article): string
    {
        $parts = [];

        if ($article->title) {
            $parts[] = "# {$article->title}";
        }

        if ($article->author) {
            $parts[] = "By: {$article->author}";
        }

        if ($article->published_at) {
            $parts[] = "Published: {$article->published_at}";
        }

        if ($article->feed_name) {
            $parts[] = "Source: {$article->feed_name}";
        }

        if ($article->description) {
            $parts[] = "\n{$article->description}";
        }

        if ($article->content && $article->content !== $article->description) {
            $parts[] = "\n{$article->content}";
        }

        if ($article->categories) {
            $cats = is_string($article->categories) ? $article->categories : json_encode($article->categories);
            $parts[] = "\nCategories: {$cats}";
        }

        return implode("\n", $parts);
    }

    private function showStats(): int
    {
        $stats = DB::selectOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) as indexed,
                SUM(CASE WHEN rag_indexed_at IS NULL THEN 1 ELSE 0 END) as pending,
                MIN(fetched_at) as oldest,
                MAX(fetched_at) as newest,
                COUNT(DISTINCT feed_name) as feeds
             FROM news_articles"
        );

        $this->info("News Article RAG Index Stats:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total articles', $stats->total],
                ['RAG indexed', $stats->indexed],
                ['Pending', $stats->pending],
                ['Completion', $stats->total > 0 ? round(($stats->indexed / $stats->total) * 100, 1) . '%' : '0%'],
                ['Feeds', $stats->feeds],
                ['Oldest', $stats->oldest],
                ['Newest', $stats->newest],
            ]
        );

        return 0;
    }
}
