<?php

namespace App\Services;

use App\Support\PromptIntegrityPolicy;
use Illuminate\Support\Facades\DB;

/** Compatibility results for existing callers; compression execution has been retired. */
class ContextualCompressionService
{
    public function compress(string $chunk, string $query): array
    {
        return $this->unchangedResult($chunk);
    }

    public function batchCompress(array $chunks, string $query, ?int $maxTokens = null): array
    {
        return [
            'chunks' => array_map(function (mixed $chunk): array {
                if (! is_array($chunk)) {
                    return ['original' => (string) $chunk, 'compressed' => (string) $chunk, 'ratio' => 1.0, 'compression_disabled' => true];
                }
                $content = $chunk['document']->content ?? $chunk['content'] ?? '';
                $chunk['compressed_content'] = (string) $content;
                $chunk['compression_ratio'] = 1.0;
                $chunk['compression_disabled'] = true;

                return $chunk;
            }, $chunks),
            'total_chunks' => count($chunks),
            'total_tokens_saved' => 0,
            'compression_disabled' => true,
        ];
    }

    public function getCompressionStats(): array
    {
        $stats = DB::connection('pgsql_rag')->selectOne('
            SELECT
                COUNT(*) as total_compressed,
                AVG(compression_ratio) as avg_ratio,
                MIN(compression_ratio) as min_ratio,
                MAX(compression_ratio) as max_ratio
            FROM rag_documents
            WHERE compression_ratio IS NOT NULL
        ');

        return [
            'total_compressed_documents' => $stats->total_compressed ?? 0,
            'avg_compression_ratio' => round($stats->avg_ratio ?? 0, 3),
            'min_ratio' => round($stats->min_ratio ?? 0, 3),
            'max_ratio' => round($stats->max_ratio ?? 0, 3),
        ];
    }

    public function cacheCompression(int $documentId, string $query, string $compressed): void
    {
        // Historical compressed fields remain readable; never replace stored source content.
    }

    /**
     * @return array<string, mixed>
     */
    private function unchangedResult(string $chunk): array
    {
        return [
            'compressed' => $chunk,
            'original_length' => strlen($chunk),
            'compressed_length' => strlen($chunk),
            'compression_ratio' => 1.0,
            'tokens_saved_estimate' => 0,
            'compression_disabled' => true,
            'disabled_reason' => PromptIntegrityPolicy::DISABLED_REASON,
        ];
    }
}
