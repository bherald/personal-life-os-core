<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Parallel RSS Feed Processor
 *
 * Fetches multiple RSS feeds in parallel to dramatically reduce total fetch time.
 * Instead of sequential fetching (Feed1 → Feed2 → Feed3), fetches concurrently.
 *
 * Performance Impact:
 * - Sequential: 3 feeds × 2s each = 6s total
 * - Parallel: max(2s, 2s, 2s) = 2s total
 * - Speedup: 3x (or 5x+ with more feeds)
 *
 * Uses Symfony Process component for true parallelism via separate processes.
 * Each feed runs in its own PHP process to avoid blocking.
 */
class ParallelRSSProcessor
{
    private const DEFAULT_TIMEOUT = 30; // seconds
    // N82: MAX_CONCURRENT reads from config/research.php at runtime

    private RetryService $retryService;

    private CircuitBreaker $circuitBreaker;

    private TimeoutManager $timeoutManager;

    public function __construct()
    {
        $this->retryService = app(RetryService::class);
        $this->circuitBreaker = app(CircuitBreaker::class);
        $this->timeoutManager = app(TimeoutManager::class);
    }

    /**
     * Fetch multiple RSS feeds in parallel
     *
     * @param  array  $feedConfigs  Array of feed configurations:
     *                              [
     *                              ['url' => 'https://...', 'limit' => 10, 'timeout' => 20],
     *                              ['url' => 'https://...', 'limit' => 5],
     *                              ...
     *                              ]
     * @param  int|null  $maxConcurrent  Maximum concurrent fetches (default: 10)
     * @return array Results array with successful and failed feeds
     */
    public function fetchFeeds(array $feedConfigs, ?int $maxConcurrent = null): array
    {
        $maxConcurrent = $maxConcurrent ?? config('research.rss_max_parallel', 10);
        $startTime = microtime(true);

        Log::info('ParallelRSSProcessor: Starting parallel fetch', [
            'feed_count' => count($feedConfigs),
            'max_concurrent' => $maxConcurrent,
        ]);

        // Process feeds in chunks based on max concurrency
        $chunks = array_chunk($feedConfigs, $maxConcurrent, true);
        $allResults = ['successful' => [], 'failed' => []];

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkNum = $chunkIndex + 1;
            $totalChunks = count($chunks);

            Log::info("ParallelRSSProcessor: Processing chunk {$chunkNum}/{$totalChunks}", [
                'feeds_in_chunk' => count($chunk),
            ]);

            $chunkResults = $this->fetchChunk($chunk);

            // Merge results
            $allResults['successful'] = array_merge($allResults['successful'], $chunkResults['successful']);
            $allResults['failed'] = array_merge($allResults['failed'], $chunkResults['failed']);
        }

        $duration = round((microtime(true) - $startTime) * 1000);
        $successCount = count($allResults['successful']);
        $failCount = count($allResults['failed']);

        Log::info('ParallelRSSProcessor: Completed parallel fetch', [
            'duration_ms' => $duration,
            'successful' => $successCount,
            'failed' => $failCount,
            'success_rate' => count($feedConfigs) > 0
                ? round(($successCount / count($feedConfigs)) * 100, 1).'%'
                : '0%',
        ]);

        return $allResults;
    }

    /**
     * Fetch a chunk of feeds in parallel (within max concurrency limit)
     * Uses CircuitBreaker to skip feeds from hosts that are consistently failing
     */
    private function fetchChunk(array $chunk): array
    {
        $processes = [];
        $results = ['successful' => [], 'failed' => []];

        // Start all processes in parallel
        foreach ($chunk as $index => $config) {
            $feedUrl = $config['url'];
            $limit = $config['limit'] ?? 10;
            $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;

            // Extract host for circuit breaker key
            $host = parse_url($feedUrl, PHP_URL_HOST) ?? 'unknown';
            $circuitKey = "rss_feed:{$host}";

            try {
                // Check if circuit breaker allows this request
                if (! $this->circuitBreaker->isAvailable($circuitKey)) {
                    Log::info('ParallelRSSProcessor: Skipping feed due to circuit breaker', [
                        'feed_url' => $feedUrl,
                        'host' => $host,
                    ]);

                    $results['failed'][] = [
                        'config' => $config,
                        'error' => "Circuit breaker open for {$host}",
                    ];

                    continue;
                }

                // Create separate PHP process to fetch feed
                $process = $this->createFetchProcess($feedUrl, $limit, $timeout);
                $process->start();

                $processes[$index] = [
                    'process' => $process,
                    'config' => $config,
                    'start_time' => microtime(true),
                    'circuit_key' => $circuitKey,
                ];

            } catch (Exception $e) {
                Log::error('ParallelRSSProcessor: Failed to start process', [
                    'feed_url' => $feedUrl,
                    'error' => $e->getMessage(),
                ]);

                // Record failure with circuit breaker
                $this->circuitBreaker->recordFailure($circuitKey);

                $results['failed'][] = [
                    'config' => $config,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Wait for all processes to complete and collect results
        foreach ($processes as $index => $processInfo) {
            $process = $processInfo['process'];
            $config = $processInfo['config'];
            $startTime = $processInfo['start_time'];
            $circuitKey = $processInfo['circuit_key'];

            try {
                // Wait for process to finish (with timeout)
                $timeout = $config['timeout'] ?? self::DEFAULT_TIMEOUT;
                $process->wait(function ($type, $buffer) {
                    // Optional: Stream output in real-time (disabled for now)
                });

                $duration = round((microtime(true) - $startTime) * 1000);

                if ($process->isSuccessful()) {
                    $output = $process->getOutput();
                    $data = json_decode($output, true);

                    if (json_last_error() === JSON_ERROR_NONE && isset($data['articles'])) {
                        Log::info('ParallelRSSProcessor: Feed fetched successfully', [
                            'feed_url' => $config['url'],
                            'articles' => count($data['articles']),
                            'duration_ms' => $duration,
                        ]);

                        // Record success with circuit breaker
                        $this->circuitBreaker->recordSuccess($circuitKey);

                        $results['successful'][] = [
                            'config' => $config,
                            'data' => $data,
                            'duration_ms' => $duration,
                        ];
                    } else {
                        throw new Exception('Invalid JSON response from feed process');
                    }
                } else {
                    throw new Exception($process->getErrorOutput() ?: 'Process failed with exit code: '.$process->getExitCode());
                }

            } catch (Exception $e) {
                Log::error('ParallelRSSProcessor: Feed fetch failed', [
                    'feed_url' => $config['url'],
                    'error' => $e->getMessage(),
                ]);

                // Record failure with circuit breaker
                $this->circuitBreaker->recordFailure($circuitKey);

                $results['failed'][] = [
                    'config' => $config,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Create a Symfony Process to fetch RSS feed in separate process
     */
    private function createFetchProcess(string $feedUrl, int $limit, int $timeout): SymfonyProcess
    {
        // Create a standalone PHP script that doesn't need autoloading
        $script = <<<'PHPSCRIPT'
<?php
// Standalone RSS feed fetcher (no dependencies)

$feedUrl = $argv[1] ?? '';
$limit = (int)($argv[2] ?? 10);
$timeout = (int)($argv[3] ?? 30);

function rss_source_host(string $url): ?string
{
    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return null;
    }

    $host = strtolower($host);
    foreach (['www.', 'feeds.', 'rss.', 'api.', 'news.'] as $prefix) {
        if (str_starts_with($host, $prefix)) {
            return substr($host, strlen($prefix));
        }
    }

    return $host;
}

if (empty($feedUrl)) {
    echo json_encode(['error' => 'No feed URL provided']);
    exit(1);
}

try {
    // Fetch feed with timeout
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'user_agent' => 'PLOS-RSS-Reader/2.0 (Parallel)',
            'follow_location' => 1,
            'max_redirects' => 3
        ]
    ]);

    $content = @file_get_contents($feedUrl, false, $context);

    if ($content === false) {
        throw new \Exception("Failed to fetch feed");
    }

    // Parse feed
    libxml_use_internal_errors(true);
    $xml = new \SimpleXMLElement($content);
    libxml_clear_errors();

    $articles = [];

    // RSS 2.0
    if (isset($xml->channel->item)) {
        $items = $xml->channel->item;
        $count = 0;

        foreach ($items as $item) {
            if ($count >= $limit) break;

            $article = [
                'title' => (string)$item->title,
                'description' => strip_tags((string)$item->description),
                'url' => (string)$item->link,
                'pubDate' => (string)$item->pubDate,
                'author' => (string)($item->author ?? $item->creator ?? ''),
                'source' => rss_source_host($feedUrl)
            ];

            if (!empty($article['title']) && !empty($article['url'])) {
                $articles[] = $article;
                $count++;
            }
        }
    }
    // Atom feed
    elseif (isset($xml->entry)) {
        $count = 0;

        foreach ($xml->entry as $entry) {
            if ($count >= $limit) break;

            $article = [
                'title' => (string)$entry->title,
                'description' => strip_tags((string)$entry->summary),
                'url' => (string)($entry->link['href'] ?? $entry->link),
                'pubDate' => (string)($entry->published ?? $entry->updated),
                'author' => (string)($entry->author->name ?? ''),
                'source' => rss_source_host($feedUrl)
            ];

            if (!empty($article['title']) && !empty($article['url'])) {
                $articles[] = $article;
                $count++;
            }
        }
    }

    // Output JSON result
    echo json_encode([
        'articles' => $articles,
        'feed_url' => $feedUrl,
        'fetched_at' => date('c')
    ]);

    exit(0);

} catch (\Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
PHPSCRIPT;

        // Write temporary script file
        $scriptFile = tempnam(sys_get_temp_dir(), 'rss_fetch_');
        file_put_contents($scriptFile, $script);

        // Create process to execute the script
        $process = new SymfonyProcess([
            'php',
            $scriptFile,
            $feedUrl,
            (string) $limit,
            (string) $timeout,
        ]);

        $process->setTimeout($timeout + 5); // Add 5s buffer

        // Register cleanup callback
        register_shutdown_function(function () use ($scriptFile) {
            if (file_exists($scriptFile)) {
                @unlink($scriptFile);
            }
        });

        return $process;
    }

    /**
     * Combine articles from multiple feed results
     */
    public function combineResults(array $results): array
    {
        $allArticles = [];
        $metadata = [
            'total_feeds' => count($results['successful']) + count($results['failed']),
            'successful_feeds' => count($results['successful']),
            'failed_feeds' => count($results['failed']),
            'feeds' => [],
        ];

        foreach ($results['successful'] as $result) {
            $feedUrl = $result['config']['url'];
            $articles = $result['data']['articles'] ?? [];

            // Add feed metadata
            $metadata['feeds'][] = [
                'url' => $feedUrl,
                'status' => 'success',
                'article_count' => count($articles),
                'duration_ms' => $result['duration_ms'],
            ];

            // Inject feed_url into each article so downstream persistence has it
            foreach ($articles as &$article) {
                if (empty($article['feed_url'])) {
                    $article['feed_url'] = $feedUrl;
                }
            }
            unset($article);

            // Merge articles
            $allArticles = array_merge($allArticles, $articles);
        }

        foreach ($results['failed'] as $result) {
            $metadata['feeds'][] = [
                'url' => $result['config']['url'],
                'status' => 'failed',
                'error' => $result['error'],
            ];
        }

        $metadata['total_articles'] = count($allArticles);

        return [
            'articles' => $allArticles,
            'metadata' => $metadata,
        ];
    }

    /**
     * Get performance statistics
     */
    public function getStatistics(): array
    {
        // Future: Track parallel fetch performance over time
        return [
            'avg_speedup' => 'Not yet implemented',
            'total_parallel_fetches' => 0,
            'avg_concurrent_feeds' => 0,
        ];
    }
}
