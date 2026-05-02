<?php

namespace App\Services;

use App\Support\PgVector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * SemanticCache - Intelligent Prompt Caching with Similarity Matching
 *
 * E01 Phase 3.5: Implements semantic caching for LLM prompts to reduce
 * redundant AI calls by 40-60%. Uses embedding similarity to match
 * semantically similar prompts.
 *
 * Features:
 * - Exact match caching (fast, hash-based)
 * - Semantic similarity matching (embedding-based)
 * - Configurable similarity thresholds
 * - TTL-based expiration
 * - Cache hit/miss statistics
 *
 * @see https://gptcache.readthedocs.io/en/latest/
 * @see https://www.pinecone.io/learn/semantic-cache/
 */
class SemanticCache
{
    private ?AIService $aiService = null;

    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'semantic_cache_';

    /** @var string Stats cache key */
    private const STATS_KEY = 'semantic_cache_stats';

    /** @var float Default similarity threshold (0.85 = 85% similar) */
    private float $similarityThreshold;

    /** @var int Default TTL in seconds (24 hours) */
    private int $ttl;

    /** @var int Max cache entries to search for semantic match */
    private int $maxSearchEntries;

    /** @var bool Whether semantic matching is enabled */
    private bool $semanticEnabled;

    public function __construct(array $options = [])
    {
        $this->similarityThreshold = $options['similarity_threshold'] ?? 0.85;
        $this->ttl = $options['ttl'] ?? 86400; // 24 hours
        $this->maxSearchEntries = $options['max_search'] ?? 100;
        $this->semanticEnabled = $options['semantic_enabled'] ?? true;
    }

    /**
     * Set AIService for embedding generation
     */
    public function setAIService(AIService $aiService): self
    {
        $this->aiService = $aiService;
        return $this;
    }

    /**
     * Get cached response or null if not found
     *
     * @param string $prompt The prompt to look up
     * @param array $context Optional context that affects caching
     * @return array|null Cached response or null
     */
    public function get(string $prompt, array $context = []): ?array
    {
        // Step 1: Try exact match (fast path)
        $exactKey = $this->getExactKey($prompt, $context);
        $exactMatch = Cache::get($exactKey);

        if ($exactMatch !== null) {
            $this->recordHit('exact');
            Log::debug('SemanticCache: Exact hit', [
                'prompt' => substr($prompt, 0, 50),
            ]);
            return $exactMatch;
        }

        // Step 2: Try semantic match (slower, embedding-based)
        if ($this->semanticEnabled && $this->aiService) {
            $semanticMatch = $this->findSemanticMatch($prompt, $context);

            if ($semanticMatch !== null) {
                $this->recordHit('semantic');
                Log::debug('SemanticCache: Semantic hit', [
                    'prompt' => substr($prompt, 0, 50),
                    'similarity' => $semanticMatch['similarity'],
                ]);
                return $semanticMatch['response'];
            }
        }

        $this->recordMiss();
        return null;
    }

    /**
     * Store response in cache
     *
     * @param string $prompt The prompt
     * @param array $response The AI response
     * @param array $context Optional context
     */
    public function put(string $prompt, array $response, array $context = []): void
    {
        // Store exact match
        $exactKey = $this->getExactKey($prompt, $context);
        Cache::put($exactKey, $response, $this->ttl);

        // Store for semantic matching (with embedding)
        if ($this->semanticEnabled && $this->aiService) {
            $this->storeSemanticEntry($prompt, $response, $context);
        }

        Log::debug('SemanticCache: Stored', [
            'prompt' => substr($prompt, 0, 50),
            'semantic' => $this->semanticEnabled,
        ]);
    }

    /**
     * Invalidate cache entries matching a pattern
     *
     * @param string $pattern Pattern to match (supports *)
     */
    public function invalidate(string $pattern = '*'): int
    {
        $count = 0;

        // For exact match entries
        if ($pattern === '*') {
            // Clear all semantic cache entries
            $this->clearSemanticIndex();
            Cache::forget(self::STATS_KEY);
            Log::info('SemanticCache: Cleared all entries');
        }

        return $count;
    }

    /**
     * Get cache key for exact matching
     */
    private function getExactKey(string $prompt, array $context): string
    {
        // Include relevant context in key
        $contextHash = md5(json_encode($context));
        $promptHash = hash('xxh3', $prompt); // Fast hash

        return self::CACHE_PREFIX . 'exact_' . $promptHash . '_' . $contextHash;
    }

    /**
     * Find semantically similar cached response via pgvector HNSW index.
     * Falls back to Redis in-memory search if pgvector unavailable.
     */
    private function findSemanticMatch(string $prompt, array $context): ?array
    {
        try {
            $result = $this->aiService->generateEmbedding($prompt);

            if (!$result['success'] || empty($result['embedding'])) {
                return null;
            }

            $queryEmbedding = $result['embedding'];
            $contextHash = md5(json_encode($context));

            // Try pgvector HNSW search first (O(log N), persistent, scalable)
            $pgResult = $this->findViaPgvector($queryEmbedding, $contextHash);
            if ($pgResult) {
                return $pgResult;
            }

            // Fallback: Redis in-memory search (for entries not yet migrated)
            return $this->findViaRedis($queryEmbedding, $context);
        } catch (\Exception $e) {
            Log::warning('SemanticCache: Semantic match failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * pgvector HNSW similarity search — fast, persistent, scalable.
     */
    private function findViaPgvector(array $queryEmbedding, string $contextHash): ?array
    {
        try {
            $embeddingStr = PgVector::literal($queryEmbedding);

            $row = DB::connection('pgsql_rag')->selectOne("
                SELECT id, response, prompt_preview,
                       1 - (embedding <=> ?::vector) as similarity
                FROM ai_semantic_cache
                WHERE context_hash = ?
                  AND created_at > NOW() - INTERVAL '{$this->ttl} seconds'
                ORDER BY embedding <=> ?::vector
                LIMIT 1
            ", [$embeddingStr, $contextHash, $embeddingStr]);

            if (!$row || $row->similarity < $this->similarityThreshold) {
                return null;
            }

            // Update hit count
            DB::connection('pgsql_rag')->update(
                "UPDATE ai_semantic_cache SET hit_count = hit_count + 1, last_accessed_at = NOW() WHERE id = ?",
                [$row->id]
            );

            return [
                'response' => json_decode($row->response, true),
                'similarity' => round($row->similarity, 4),
                'original_prompt' => $row->prompt_preview,
                'source' => 'pgvector',
            ];
        } catch (\Exception $e) {
            // pgvector table may not exist yet — fall through to Redis
            Log::debug('SemanticCache: pgvector lookup failed, using Redis fallback', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Redis in-memory fallback — linear search over cached entries.
     */
    private function findViaRedis(array $queryEmbedding, array $context): ?array
    {
        $entries = $this->getSemanticIndex();
        if (empty($entries)) {
            return null;
        }

        $bestMatch = null;
        $bestSimilarity = 0;
        $contextHash = md5(json_encode($context));

        foreach ($entries as $entry) {
            if (!empty($context) && ($entry['context_hash'] ?? '') !== $contextHash) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryEmbedding, $entry['embedding']);

            if ($similarity >= $this->similarityThreshold && $similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = [
                    'response' => $entry['response'],
                    'similarity' => round($similarity, 4),
                    'original_prompt' => $entry['prompt'],
                    'source' => 'redis',
                ];
            }
        }

        return $bestMatch;
    }

    /**
     * Store entry in semantic index (pgvector primary, Redis fallback).
     */
    private function storeSemanticEntry(string $prompt, array $response, array $context): void
    {
        try {
            $result = $this->aiService->generateEmbedding($prompt);

            if (!$result['success'] || empty($result['embedding'])) {
                return;
            }

            $embedding = $result['embedding'];
            $contextHash = md5(json_encode($context));
            $promptPreview = substr($prompt, 0, 500);
            $promptHash = hash('xxh3', $prompt);

            // Store in pgvector (persistent, HNSW-indexed)
            try {
                $embeddingStr = PgVector::literal($embedding);
                DB::connection('pgsql_rag')->insert("
                    INSERT INTO ai_semantic_cache (prompt_hash, context_hash, embedding, response, prompt_preview, created_at)
                    VALUES (?, ?, ?::vector, ?::jsonb, ?, NOW())
                ", [$promptHash, $contextHash, $embeddingStr, json_encode($response), $promptPreview]);
            } catch (\Exception $e) {
                Log::debug('SemanticCache: pgvector store failed, using Redis only', ['error' => $e->getMessage()]);
            }

            // Also store in Redis (fast path for immediate re-use)
            $entry = [
                'prompt' => $promptPreview,
                'embedding' => $embedding,
                'response' => $response,
                'context_hash' => $contextHash,
                'created_at' => time(),
            ];
            $this->addToSemanticIndex($entry);
        } catch (\Exception $e) {
            Log::warning('SemanticCache: Failed to store semantic entry', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get semantic index from cache/storage
     */
    private function getSemanticIndex(): array
    {
        $index = Cache::get(self::CACHE_PREFIX . 'semantic_index', []);

        // Remove expired entries
        $now = time();
        $filtered = array_filter($index, function($entry) use ($now) {
            return ($entry['created_at'] ?? 0) + $this->ttl > $now;
        });

        // Limit to most recent entries
        return array_slice($filtered, -$this->maxSearchEntries);
    }

    /**
     * Add entry to semantic index
     */
    private function addToSemanticIndex(array $entry): void
    {
        $index = Cache::get(self::CACHE_PREFIX . 'semantic_index', []);

        // Add new entry
        $index[] = $entry;

        // Trim to max size
        if (count($index) > $this->maxSearchEntries * 2) {
            $index = array_slice($index, -$this->maxSearchEntries);
        }

        Cache::put(self::CACHE_PREFIX . 'semantic_index', $index, $this->ttl * 2);
    }

    /**
     * Clear semantic index
     */
    private function clearSemanticIndex(): void
    {
        Cache::forget(self::CACHE_PREFIX . 'semantic_index');
    }

    /**
     * Calculate cosine similarity between two embeddings
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        return \App\Support\VectorMath::cosineSimilarity($a, $b);
    }

    /**
     * Record cache hit
     */
    private function recordHit(string $type): void
    {
        $stats = Cache::get(self::STATS_KEY, $this->getDefaultStats());
        $stats['hits']++;
        $stats["{$type}_hits"]++;
        $stats['last_hit'] = now()->toIso8601String();
        Cache::put(self::STATS_KEY, $stats, 86400 * 7); // 7 day TTL for stats
    }

    /**
     * Record cache miss
     */
    private function recordMiss(): void
    {
        $stats = Cache::get(self::STATS_KEY, $this->getDefaultStats());
        $stats['misses']++;
        $stats['last_miss'] = now()->toIso8601String();
        Cache::put(self::STATS_KEY, $stats, 86400 * 7);
    }

    /**
     * Get default stats structure
     */
    private function getDefaultStats(): array
    {
        return [
            'hits' => 0,
            'misses' => 0,
            'exact_hits' => 0,
            'semantic_hits' => 0,
            'last_hit' => null,
            'last_miss' => null,
            'created_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = Cache::get(self::STATS_KEY, $this->getDefaultStats());
        $total = $stats['hits'] + $stats['misses'];

        return array_merge($stats, [
            'hit_rate' => $total > 0 ? round($stats['hits'] / $total * 100, 2) . '%' : 'N/A',
            'semantic_hit_rate' => $stats['hits'] > 0 ? round($stats['semantic_hits'] / $stats['hits'] * 100, 2) . '%' : 'N/A',
            'total_requests' => $total,
            'index_size' => count($this->getSemanticIndex()),
            'config' => [
                'similarity_threshold' => $this->similarityThreshold,
                'ttl_hours' => $this->ttl / 3600,
                'semantic_enabled' => $this->semanticEnabled,
            ],
        ]);
    }

    /**
     * Estimate cost savings based on cache stats
     *
     * @param float $costPerRequest Estimated cost per AI request
     * @return array Cost savings estimate
     */
    public function estimateSavings(float $costPerRequest = 0.01): array
    {
        $stats = $this->getStats();
        $hits = $stats['hits'];
        $total = $stats['total_requests'];

        return [
            'requests_saved' => $hits,
            'estimated_savings_usd' => round($hits * $costPerRequest, 2),
            'hit_rate' => $stats['hit_rate'],
            'if_100_requests' => [
                'without_cache' => round(100 * $costPerRequest, 2),
                'with_cache' => round((100 - ($hits > 0 ? ($hits / $total) * 100 : 0)) * $costPerRequest, 2),
            ],
        ];
    }
}
