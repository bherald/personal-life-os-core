<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Contextual Retrieval Service for RAG
 *
 * Implements Anthropic's Contextual Retrieval pattern that achieves 67% failure reduction
 * by prepending context to each chunk before embedding.
 *
 * The core insight: chunks lose context when separated from their source document.
 * By adding a brief context prefix explaining the chunk's relevance to the whole
 * document, embeddings become more semantically accurate.
 *
 * Process:
 * 1. For each chunk, use LLM to generate 50-100 token context
 * 2. Prepend context to chunk content
 * 3. Generate embedding from contextualized content
 * 4. Store both original chunk and context prefix
 *
 * @see https://www.anthropic.com/news/contextual-retrieval
 */
class ContextualRetrievalService
{
    private AIService $aiService;

    /**
     * Cache TTL for contextualized chunks (7 days)
     */
    private const CONTEXT_CACHE_TTL = 604800;

    /**
     * Cache key prefix
     */
    private const CACHE_PREFIX = 'contextual_chunk:';

    /**
     * System prompt for context generation
     */
    private const CONTEXT_SYSTEM_PROMPT = <<<'PROMPT'
You are a document context generator. Given a document and a chunk from that document, write a SHORT context (50-100 tokens) explaining:
- What this chunk is about
- How it relates to the overall document
- Key entities or concepts mentioned

Rules:
- Be concise and factual
- Start with "This chunk" or similar
- Focus on semantic meaning, not formatting
- Do NOT summarize the entire document
- Do NOT repeat the chunk content verbatim
PROMPT;

    /**
     * User prompt template for context generation
     */
    private const CONTEXT_USER_TEMPLATE = <<<'PROMPT'
<document>
%s
</document>

<chunk>
%s
</chunk>

Generate a brief context (50-100 tokens) for this chunk:
PROMPT;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Generate context prefix for a chunk within its source document
     *
     * Uses fast/cheap LLM call to generate semantic context.
     * Results are cached to avoid re-processing identical chunks.
     *
     * @param string $fullDocument The complete source document
     * @param string $chunk The chunk to contextualize
     * @param array $options Optional settings:
     *   - use_cache: bool (default true) - Whether to use caching
     *   - max_tokens: int (default 150) - Max tokens for context generation
     *   - truncate_document: int (default 4000) - Truncate document to this many chars for context
     * @return string Context prefix to prepend to chunk
     */
    public function contextualizeChunk(string $fullDocument, string $chunk, array $options = []): string
    {
        $useCache = $options['use_cache'] ?? true;
        $maxTokens = $options['max_tokens'] ?? 150;
        $truncateDocument = $options['truncate_document'] ?? 4000;

        // Generate cache key from document + chunk hash
        $cacheKey = $this->getCacheKey($fullDocument, $chunk);

        // Check cache first
        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                Log::debug('ContextualRetrieval: Cache hit', [
                    'cache_key' => substr($cacheKey, 0, 50),
                ]);
                return $cached;
            }
        }

        try {
            // Truncate document if too long (context generation doesn't need full doc)
            $truncatedDoc = $fullDocument;
            if (strlen($fullDocument) > $truncateDocument) {
                // Keep beginning and end, which often have the most context
                $halfSize = (int)($truncateDocument / 2);
                $truncatedDoc = substr($fullDocument, 0, $halfSize) .
                    "\n\n[... document truncated ...]\n\n" .
                    substr($fullDocument, -$halfSize);
            }

            // Build prompt
            $userPrompt = sprintf(self::CONTEXT_USER_TEMPLATE, $truncatedDoc, $chunk);

            // Call AI service with fast profile for efficiency
            $result = $this->aiService->process(
                $userPrompt,
                [
                    'system' => self::CONTEXT_SYSTEM_PROMPT,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.3,
                    'model_role' => 'fast',
                ]
            );

            if (!$result['success']) {
                Log::warning('ContextualRetrieval: AI call failed, returning empty context', [
                    'error' => $result['error'] ?? 'unknown',
                ]);
                return '';
            }

            $context = trim($result['response'] ?? '');

            // Validate context isn't too long or empty
            if (empty($context)) {
                Log::warning('ContextualRetrieval: Generated empty context');
                return '';
            }

            // Cache the result
            if ($useCache && !empty($context)) {
                Cache::put($cacheKey, $context, self::CONTEXT_CACHE_TTL);
            }

            Log::debug('ContextualRetrieval: Generated context', [
                'context_length' => strlen($context),
                'chunk_preview' => substr($chunk, 0, 50),
            ]);

            return $context;

        } catch (Exception $e) {
            Log::error('ContextualRetrieval: Exception during context generation', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Prepend context to chunk for embedding
     *
     * Format: "[Context] <context>\n\n<chunk>"
     *
     * @param string $context The context prefix
     * @param string $chunk The original chunk content
     * @return string Contextualized chunk ready for embedding
     */
    public function prependContext(string $context, string $chunk): string
    {
        if (empty($context)) {
            return $chunk;
        }

        return "[Context] {$context}\n\n{$chunk}";
    }

    /**
     * Contextualize a chunk and return both parts
     *
     * Convenience method that generates context and returns structured result.
     *
     * @param string $fullDocument The complete source document
     * @param string $chunk The chunk to contextualize
     * @param array $options Optional settings (see contextualizeChunk)
     * @return array ['context' => string, 'original_chunk' => string, 'contextualized' => string]
     */
    public function contextualizeWithParts(string $fullDocument, string $chunk, array $options = []): array
    {
        $context = $this->contextualizeChunk($fullDocument, $chunk, $options);

        return [
            'context' => $context,
            'original_chunk' => $chunk,
            'contextualized' => $this->prependContext($context, $chunk),
        ];
    }

    /**
     * Batch contextualize multiple chunks from the same document
     *
     * More efficient than calling contextualizeChunk repeatedly as it
     * can potentially batch API calls in the future.
     *
     * @param string $fullDocument The complete source document
     * @param array $chunks Array of chunk strings
     * @param array $options Optional settings (see contextualizeChunk)
     * @return array Array of contextualized results, same order as input
     */
    public function batchContextualize(string $fullDocument, array $chunks, array $options = []): array
    {
        $results = [];

        foreach ($chunks as $index => $chunk) {
            $results[] = $this->contextualizeWithParts($fullDocument, $chunk, $options);
        }

        Log::info('ContextualRetrieval: Batch contextualization completed', [
            'chunks_processed' => count($chunks),
            'document_length' => strlen($fullDocument),
        ]);

        return $results;
    }

    /**
     * Check if a chunk has cached context
     *
     * @param string $fullDocument The source document
     * @param string $chunk The chunk to check
     * @return bool True if context is cached
     */
    public function hasCachedContext(string $fullDocument, string $chunk): bool
    {
        return Cache::has($this->getCacheKey($fullDocument, $chunk));
    }

    /**
     * Clear cached context for a specific chunk
     *
     * @param string $fullDocument The source document
     * @param string $chunk The chunk whose cache to clear
     * @return bool True if cache was cleared
     */
    public function clearCachedContext(string $fullDocument, string $chunk): bool
    {
        return Cache::forget($this->getCacheKey($fullDocument, $chunk));
    }

    /**
     * Generate cache key for document+chunk pair
     *
     * Uses hash of document title/beginning + full chunk content
     * to create unique but collision-resistant key.
     *
     * @param string $fullDocument The source document
     * @param string $chunk The chunk
     * @return string Cache key
     */
    private function getCacheKey(string $fullDocument, string $chunk): string
    {
        // Use first 500 chars of document + chunk content for hash
        $docSignature = substr($fullDocument, 0, 500);
        $hash = hash('sha256', $docSignature . '||' . $chunk);

        return self::CACHE_PREFIX . $hash;
    }

    /**
     * Get statistics about context cache usage
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array
    {
        // Note: Full cache stats would require Redis KEYS command
        // which can be expensive. This provides basic info.
        return [
            'cache_prefix' => self::CACHE_PREFIX,
            'cache_ttl_seconds' => self::CONTEXT_CACHE_TTL,
            'cache_ttl_days' => self::CONTEXT_CACHE_TTL / 86400,
        ];
    }
}
