<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * NewsArticleService - Persist RSS/news articles to MySQL
 *
 * Part of Data Scanning Sprint: converts transient RSS workflow
 * data into persistent storage for historical search and RAG indexing.
 */
class NewsArticleService
{
    /**
     * Persist articles from RSS feeds to MySQL
     *
     * @param array $articles Articles from RSSFeedReader
     * @param string|null $feedUrl Source feed URL
     * @param string|null $feedName Human-readable feed name
     * @param int|null $workflowId Workflow that produced these articles
     * @return array Stats about persisted articles
     */
    public function persistArticles(
        array $articles,
        ?string $feedUrl = null,
        ?string $feedName = null,
        ?int $workflowId = null
    ): array {
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($articles as $article) {
            try {
                // Generate hash for dedup
                $articleUrl = $article['link'] ?? $article['url'] ?? '';
                $title = $article['title'] ?? '';

                if (empty($articleUrl) && empty($title)) {
                    $errors++;
                    continue;
                }

                // Hash based on URL primarily, fallback to title+date
                $hashSource = $articleUrl ?: ($title . ($article['published'] ?? ''));
                $articleHash = hash('sha256', $hashSource);

                // Check if already exists
                $existing = DB::select(
                    "SELECT id FROM news_articles WHERE article_hash = ? LIMIT 1",
                    [$articleHash]
                );

                if ($existing) {
                    $skipped++;
                    continue;
                }

                // Parse published date
                $publishedAt = null;
                if (!empty($article['published'])) {
                    try {
                        $publishedAt = date('Y-m-d H:i:s', strtotime($article['published']));
                    } catch (Exception $e) {
                        // Ignore date parse errors
                    }
                }

                // Extract content
                $description = $article['description'] ?? $article['summary'] ?? null;
                $content = $article['content'] ?? $article['fullContent'] ?? null;

                // Truncate if too long
                if ($description && strlen($description) > 65000) {
                    $description = substr($description, 0, 65000);
                }
                if ($content && strlen($content) > 16000000) {
                    $content = substr($content, 0, 16000000);
                }

                // Insert
                DB::insert(
                    "INSERT INTO news_articles
                        (feed_url, feed_name, article_url, article_hash, title, description, content, author, published_at, fetched_at, workflow_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW())",
                    [
                        $feedUrl ?? ($article['feed_url'] ?? null),
                        $feedName ?? ($article['source'] ?? null),
                        $articleUrl,
                        $articleHash,
                        $title,
                        $description,
                        $content,
                        $article['author'] ?? null,
                        $publishedAt,
                        $workflowId,
                    ]
                );
                $inserted++;

            } catch (Exception $e) {
                Log::warning('Failed to persist news article', [
                    'title' => $article['title'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        if ($inserted > 0) {
            Log::info('News articles persisted to MySQL', [
                'inserted' => $inserted,
                'skipped' => $skipped,
                'errors' => $errors,
                'feed' => $feedName ?? $feedUrl,
            ]);
        }

        return [
            'inserted' => $inserted,
            'skipped' => $skipped,
            'errors' => $errors,
            'total' => count($articles),
        ];
    }

    /**
     * Get articles from database
     *
     * @param array $options Query options (limit, offset, feed_url, days_back, search)
     * @return array Articles from database
     */
    public function getArticles(array $options = []): array
    {
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $feedUrl = $options['feed_url'] ?? null;
        $daysBack = $options['days_back'] ?? null;
        $search = $options['search'] ?? null;

        $query = "SELECT * FROM news_articles WHERE 1=1";
        $params = [];

        if ($feedUrl) {
            $query .= " AND feed_url = ?";
            $params[] = $feedUrl;
        }

        if ($daysBack) {
            $query .= " AND fetched_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
            $params[] = $daysBack;
        }

        if ($search) {
            $query .= " AND (title LIKE ? OR description LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $query .= " ORDER BY published_at DESC, fetched_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return DB::select($query, $params);
    }

    /**
     * Get article counts by feed
     *
     * @param int $daysBack Days to look back (default 7)
     * @return array Feed statistics
     */
    public function getFeedStats(int $daysBack = 7): array
    {
        return DB::select(
            "SELECT
                feed_name,
                feed_url,
                COUNT(*) as article_count,
                MIN(published_at) as oldest_article,
                MAX(published_at) as newest_article,
                SUM(CASE WHEN rag_indexed_at IS NOT NULL THEN 1 ELSE 0 END) as rag_indexed_count
            FROM news_articles
            WHERE fetched_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY feed_name, feed_url
            ORDER BY article_count DESC",
            [$daysBack]
        );
    }

    /**
     * Get articles pending RAG indexing
     *
     * @param int $limit Max articles to return
     * @return array Articles needing RAG indexing
     */
    public function getArticlesPendingRagIndex(int $limit = 100): array
    {
        return DB::select(
            "SELECT * FROM news_articles
            WHERE rag_indexed_at IS NULL
            ORDER BY published_at DESC
            LIMIT ?",
            [$limit]
        );
    }

    /**
     * Mark article as RAG indexed
     *
     * @param int $articleId Article ID
     * @return bool Success
     */
    public function markRagIndexed(int $articleId): bool
    {
        return DB::update(
            "UPDATE news_articles SET rag_indexed_at = NOW() WHERE id = ?",
            [$articleId]
        ) > 0;
    }

    /**
     * Clean up old articles
     *
     * @param int $daysToKeep Days to retain articles
     * @return int Number of deleted articles
     */
    public function cleanupOldArticles(int $daysToKeep = 90): int
    {
        $deleted = DB::delete(
            "DELETE FROM news_articles
            WHERE fetched_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            AND rag_indexed_at IS NULL",
            [$daysToKeep]
        );

        if ($deleted > 0) {
            Log::info('Cleaned up old news articles', ['deleted' => $deleted]);
        }

        return $deleted;
    }
}
