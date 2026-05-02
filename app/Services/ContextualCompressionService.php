<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

/**
 * Contextual Compression Service
 *
 * Compresses RAG retrieval results by removing irrelevant sentences
 * from each chunk relative to the query, reducing token usage
 * while preserving relevant information.
 */
class ContextualCompressionService
{
    private ?AIService $aiService = null;

    private function getAIService(): AIService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIService::class);
        }
        return $this->aiService;
    }

    public function compress(string $chunk, string $query): array
    {
        // Check cache first
        $cacheKey = 'ctx_compress_' . md5($chunk . $query);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            $prompt = "Given the following search query and text chunk, remove sentences that are NOT relevant to answering the query. "
                . "Keep only the sentences that directly help answer the query. Return the compressed text only, no explanations.\n\n"
                . "Query: {$query}\n\n"
                . "Text:\n{$chunk}";

            $result = $this->getAIService()->process($prompt, [
                'max_tokens' => max(100, (int)(strlen($chunk) / 2)),
                'system' => 'You compress text by removing irrelevant sentences. Return only the compressed text.',
            ]);

            $compressed = $result['response'] ?? $result['content'] ?? $chunk;
            $originalLen = strlen($chunk);
            $compressedLen = strlen($compressed);
            $ratio = $originalLen > 0 ? round($compressedLen / $originalLen, 3) : 1.0;

            $output = [
                'compressed' => $compressed,
                'original_length' => $originalLen,
                'compressed_length' => $compressedLen,
                'compression_ratio' => $ratio,
                'tokens_saved_estimate' => (int)(($originalLen - $compressedLen) / 4),
            ];

            Cache::put($cacheKey, $output, 3600);
            return $output;
        } catch (Exception $e) {
            Log::warning('ContextualCompression: Failed, returning original', ['error' => $e->getMessage()]);
            return [
                'compressed' => $chunk,
                'original_length' => strlen($chunk),
                'compressed_length' => strlen($chunk),
                'compression_ratio' => 1.0,
                'tokens_saved_estimate' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function batchCompress(array $chunks, string $query, ?int $maxTokens = null): array
    {
        $results = [];
        $totalSaved = 0;

        foreach ($chunks as $i => $chunk) {
            $content = is_string($chunk) ? $chunk : ($chunk['document']->content ?? $chunk['content'] ?? '');
            if (empty($content)) {
                $results[] = $chunk;
                continue;
            }

            $compressed = $this->compress($content, $query);
            $totalSaved += $compressed['tokens_saved_estimate'];

            if (is_array($chunk)) {
                $chunk['compressed_content'] = $compressed['compressed'];
                $chunk['compression_ratio'] = $compressed['compression_ratio'];
                $results[] = $chunk;
            } else {
                $results[] = [
                    'original' => $content,
                    'compressed' => $compressed['compressed'],
                    'ratio' => $compressed['compression_ratio'],
                ];
            }

            // Check token budget
            if ($maxTokens) {
                $currentTokens = array_sum(array_map(function ($r) {
                    $text = $r['compressed_content'] ?? $r['compressed'] ?? '';
                    return (int)(strlen($text) / 4);
                }, $results));

                if ($currentTokens >= $maxTokens) {
                    break;
                }
            }
        }

        return [
            'chunks' => $results,
            'total_chunks' => count($results),
            'total_tokens_saved' => $totalSaved,
        ];
    }

    public function getCompressionStats(): array
    {
        $stats = DB::connection('pgsql_rag')->selectOne("
            SELECT
                COUNT(*) as total_compressed,
                AVG(compression_ratio) as avg_ratio,
                MIN(compression_ratio) as min_ratio,
                MAX(compression_ratio) as max_ratio
            FROM rag_documents
            WHERE compression_ratio IS NOT NULL
        ");

        return [
            'total_compressed_documents' => $stats->total_compressed ?? 0,
            'avg_compression_ratio' => round($stats->avg_ratio ?? 0, 3),
            'min_ratio' => round($stats->min_ratio ?? 0, 3),
            'max_ratio' => round($stats->max_ratio ?? 0, 3),
        ];
    }

    public function cacheCompression(int $documentId, string $query, string $compressed): void
    {
        $ratio = null;
        $original = DB::connection('pgsql_rag')->selectOne(
            "SELECT content FROM rag_documents WHERE id = ?",
            [$documentId]
        );

        if ($original && $original->content) {
            $ratio = round(strlen($compressed) / max(strlen($original->content), 1), 3);
        }

        DB::connection('pgsql_rag')->update(
            "UPDATE rag_documents SET compressed_content = ?, compression_ratio = ? WHERE id = ?",
            [$compressed, $ratio, $documentId]
        );
    }
}
