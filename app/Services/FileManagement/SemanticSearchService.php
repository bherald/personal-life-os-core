<?php

namespace App\Services\FileManagement;

use App\Support\PgVector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\AIService;
use Exception;

/**
 * File Semantic Search Integration Service
 *
 * Enables natural language search across files using
 * vector embeddings and RAG integration.
 *
 * Embeddings stored in pgsql_rag (file_semantic_embeddings).
 * File metadata looked up from MySQL (file_registry) — two-step query.
 */
class SemanticSearchService
{
    // =========================================================================
    // SEARCH
    // =========================================================================

    public function search(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $threshold = $options['threshold'] ?? 0.5;
        $fileTypes = $options['file_types'] ?? [];
        $dateRange = $options['date_range'] ?? null;

        try {
            // Get embedding for query
            $queryEmbedding = $this->getEmbedding($query);

            if (!$queryEmbedding) {
                return ['success' => false, 'error' => 'Failed to generate query embedding'];
            }

            // Build the vector search query
            $embeddingString = PgVector::literal($queryEmbedding);

            // Step 1: Vector similarity search in pgsql_rag
            $embeddingResults = DB::connection('pgsql_rag')->select(
                "SELECT
                    fse.file_id,
                    fse.chunk_index,
                    fse.chunk_text,
                    1 - (fse.embedding <=> ?::vector) as similarity
                 FROM file_semantic_embeddings fse
                 WHERE 1 - (fse.embedding <=> ?::vector) >= ?
                 ORDER BY similarity DESC
                 LIMIT ?",
                [$embeddingString, $embeddingString, $threshold, $limit]
            );

            if (empty($embeddingResults)) {
                return ['success' => true, 'query' => $query, 'total_matches' => 0, 'files_matched' => 0, 'results' => []];
            }

            // Step 2: Look up file metadata from MySQL
            $fileIds = array_unique(array_column($embeddingResults, 'file_id'));
            $placeholders = implode(',', array_fill(0, count($fileIds), '?'));

            $whereClause = "fr.id IN ({$placeholders})";
            $fileParams = array_values($fileIds);

            if (!empty($fileTypes)) {
                $typePlaceholders = implode(',', array_fill(0, count($fileTypes), '?'));
                $whereClause .= " AND fr.mime_type IN ({$typePlaceholders})";
                $fileParams = array_merge($fileParams, $fileTypes);
            }
            if ($dateRange) {
                if (isset($dateRange['start'])) {
                    $whereClause .= " AND fr.created_at >= ?";
                    $fileParams[] = $dateRange['start'];
                }
                if (isset($dateRange['end'])) {
                    $whereClause .= " AND fr.created_at <= ?";
                    $fileParams[] = $dateRange['end'];
                }
            }

            $fileRows = DB::select(
                "SELECT id, current_path, original_filename, mime_type, file_size FROM file_registry fr WHERE {$whereClause}",
                $fileParams
            );
            $fileMap = [];
            foreach ($fileRows as $fr) {
                $fileMap[$fr->id] = $fr;
            }

            // Enrich embedding results with file metadata
            $results = [];
            foreach ($embeddingResults as $er) {
                if (!isset($fileMap[$er->file_id])) continue;
                $fr = $fileMap[$er->file_id];
                $results[] = (object) [
                    'file_id' => $er->file_id,
                    'chunk_index' => $er->chunk_index,
                    'chunk_text' => $er->chunk_text,
                    'similarity' => $er->similarity,
                    'file_path' => $fr->current_path,
                    'file_name' => $fr->original_filename,
                    'file_type' => $fr->mime_type,
                    'file_size' => $fr->file_size,
                ];
            }

            // Group results by file
            $groupedResults = $this->groupByFile($results);

            return [
                'success' => true,
                'query' => $query,
                'total_matches' => count($results),
                'files_matched' => count($groupedResults),
                'results' => $groupedResults,
            ];
        } catch (Exception $e) {
            Log::error('SemanticSearch: Search failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function groupByFile(array $results): array
    {
        $grouped = [];

        foreach ($results as $result) {
            $fileId = $result->file_id;

            if (!isset($grouped[$fileId])) {
                $grouped[$fileId] = [
                    'file_id' => $fileId,
                    'file_path' => $result->file_path,
                    'file_name' => $result->file_name,
                    'file_type' => $result->file_type,
                    'file_size' => $result->file_size,
                    'max_similarity' => $result->similarity,
                    'matching_chunks' => [],
                ];
            }

            $grouped[$fileId]['matching_chunks'][] = [
                'chunk_index' => $result->chunk_index,
                'text' => $result->chunk_text,
                'similarity' => round($result->similarity, 4),
            ];

            if ($result->similarity > $grouped[$fileId]['max_similarity']) {
                $grouped[$fileId]['max_similarity'] = $result->similarity;
            }
        }

        // Sort by max similarity
        usort($grouped, fn($a, $b) => $b['max_similarity'] <=> $a['max_similarity']);

        return array_values($grouped);
    }

    // =========================================================================
    // INDEXING
    // =========================================================================

    public function indexFile(int $fileId, ?string $content = null): array
    {
        $file = DB::selectOne("SELECT * FROM file_registry WHERE id = ?", [$fileId]);

        if (!$file) {
            return ['success' => false, 'error' => 'File not found'];
        }

        // Get or extract content
        if (!$content) {
            $content = $this->extractFileContent($file);
        }

        if (!$content || strlen(trim($content)) < 10) {
            return ['success' => false, 'error' => 'No content to index'];
        }

        try {
            // Clear existing embeddings
            DB::connection('pgsql_rag')->delete(
                "DELETE FROM file_semantic_embeddings WHERE file_id = ?",
                [$fileId]
            );

            // Chunk the content
            $chunks = $this->chunkContent($content);

            $indexed = 0;
            foreach ($chunks as $i => $chunk) {
                $embedding = $this->getEmbedding($chunk);

                if ($embedding) {
                    $embeddingString = PgVector::literal($embedding);

                    DB::connection('pgsql_rag')->insert(
                        "INSERT INTO file_semantic_embeddings
                            (file_id, chunk_index, chunk_text, embedding, created_at)
                         VALUES (?, ?, ?, ?::vector, NOW())",
                        [$fileId, $i, $chunk, $embeddingString]
                    );

                    $indexed++;
                }
            }

            // Update file registry
            DB::update(
                "UPDATE file_registry SET semantic_indexed_at = NOW(), semantic_chunk_count = ? WHERE id = ?",
                [$indexed, $fileId]
            );

            Log::info('SemanticSearch: File indexed', [
                'file_id' => $fileId,
                'chunks' => $indexed,
            ]);

            return [
                'success' => true,
                'file_id' => $fileId,
                'chunks_indexed' => $indexed,
            ];
        } catch (Exception $e) {
            Log::error('SemanticSearch: Indexing failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function batchIndex(array $fileIds): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($fileIds as $fileId) {
            $result = $this->indexFile($fileId);

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['file_id' => $fileId, 'error' => $result['error']];
            }
        }

        return $results;
    }

    public function indexUnindexedFiles(int $limit = 50): array
    {
        try {
            $unindexed = DB::select(
                "SELECT id FROM file_registry
                 WHERE semantic_indexed_at IS NULL
                 AND mime_type IN ('application/pdf', 'text/plain', 'text/markdown', 'text/html', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
                 ORDER BY created_at DESC
                 LIMIT ?",
                [$limit]
            );
        } catch (\Throwable $e) {
            // semantic_indexed_at column may not exist yet
            return ['success' => 0, 'failed' => 0, 'errors' => []];
        }

        $fileIds = array_column($unindexed, 'id');
        return $this->batchIndex($fileIds);
    }

    // =========================================================================
    // CONTENT EXTRACTION
    // =========================================================================

    private function extractFileContent(object $file): ?string
    {
        $filePath = $file->file_path;

        if (!file_exists($filePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // Text-based files
        if (in_array($extension, ['txt', 'md', 'markdown', 'json', 'xml', 'csv', 'log'])) {
            return file_get_contents($filePath);
        }

        // Code files
        if (in_array($extension, ['php', 'js', 'ts', 'py', 'java', 'go', 'rb', 'rs', 'c', 'cpp', 'h', 'css', 'html', 'vue', 'jsx', 'tsx'])) {
            return file_get_contents($filePath);
        }

        // Use Tika for complex documents
        if (in_array($extension, ['pdf', 'doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'xls', 'xlsx'])) {
            return $this->extractWithTika($filePath);
        }

        return null;
    }

    private function extractWithTika(string $filePath): ?string
    {
        $handle = null;

        try {
            $tikaUrl = config('services.tika.url', 'http://127.0.0.1:9998');
            $handle = fopen($filePath, 'r');

            if ($handle === false) {
                return null;
            }

            $ch = curl_init("{$tikaUrl}/tika");
            curl_setopt_array($ch, [
                CURLOPT_PUT => true,
                CURLOPT_INFILE => $handle,
                CURLOPT_INFILESIZE => filesize($filePath),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Accept: text/plain'],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 60,
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $content) {
                return $content;
            }

            if ($error) {
                Log::warning('SemanticSearch: Tika transport failed', ['file' => $filePath, 'error' => $error]);
            } elseif ($httpCode > 0) {
                Log::warning('SemanticSearch: Tika returned unexpected status', [
                    'file' => $filePath,
                    'http_code' => $httpCode,
                ]);
            }
        } catch (Exception $e) {
            Log::warning('SemanticSearch: Tika extraction failed', ['error' => $e->getMessage()]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return null;
    }

    // =========================================================================
    // CHUNKING
    // =========================================================================

    private function chunkContent(string $content, int $chunkSize = 500, int $overlap = 50): array
    {
        $content = trim($content);
        $chunks = [];

        // Split into sentences first
        $sentences = preg_split('/(?<=[.!?])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

        $currentChunk = '';
        $currentLength = 0;

        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);

            if ($currentLength + $sentenceLength > $chunkSize && $currentLength > 0) {
                $chunks[] = trim($currentChunk);

                // Keep overlap from end of current chunk
                $words = explode(' ', $currentChunk);
                $overlapWords = array_slice($words, -($overlap / 5));
                $currentChunk = implode(' ', $overlapWords) . ' ';
                $currentLength = strlen($currentChunk);
            }

            $currentChunk .= $sentence . ' ';
            $currentLength += $sentenceLength + 1;
        }

        if (trim($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    // =========================================================================
    // EMBEDDING
    // =========================================================================

    private function getEmbedding(string $text): ?array
    {
        try {
            $result = app(AIService::class)->generateEmbedding($text);
            return $result['success'] ? $result['embedding'] : null;
        } catch (Exception $e) {
            Log::warning('SemanticSearch: Embedding failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================================
    // SIMILAR FILES
    // =========================================================================

    public function findSimilarFiles(int $fileId, int $limit = 10): array
    {
        try {
            // Get representative embedding for file
            $fileEmbedding = DB::connection('pgsql_rag')->selectOne(
                "SELECT embedding FROM file_semantic_embeddings
                 WHERE file_id = ?
                 ORDER BY chunk_index
                 LIMIT 1",
                [$fileId]
            );

            if (!$fileEmbedding) {
                return ['success' => false, 'error' => 'File not indexed'];
            }

            // Step 1: Find similar embeddings in pgsql_rag
            $embeddingResults = DB::connection('pgsql_rag')->select(
                "SELECT DISTINCT ON (fse.file_id)
                    fse.file_id,
                    1 - (fse.embedding <=> ?::vector) as similarity
                 FROM file_semantic_embeddings fse
                 WHERE fse.file_id != ?
                 ORDER BY fse.file_id, similarity DESC
                 LIMIT ?",
                [$fileEmbedding->embedding, $fileId, $limit]
            );

            // Step 2: Enrich with file metadata from MySQL
            $similarFiles = [];
            if (!empty($embeddingResults)) {
                $ids = array_column($embeddingResults, 'file_id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $fileRows = DB::select("SELECT id, original_filename, mime_type FROM file_registry WHERE id IN ({$placeholders})", $ids);
                $fileMap = [];
                foreach ($fileRows as $fr) { $fileMap[$fr->id] = $fr; }

                foreach ($embeddingResults as $er) {
                    $fr = $fileMap[$er->file_id] ?? null;
                    $similarFiles[] = [
                        'file_id' => $er->file_id,
                        'similarity' => round($er->similarity, 4),
                        'file_name' => $fr->original_filename ?? null,
                        'file_type' => $fr->mime_type ?? null,
                    ];
                }
            }

            return [
                'success' => true,
                'source_file_id' => $fileId,
                'similar_files' => $similarFiles,
            ];
        } catch (Exception $e) {
            Log::error('SemanticSearch: findSimilarFiles failed', ['file_id' => $fileId, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================

    public function removeDeletedFiles(): int
    {
        try {
            // file_registry is MySQL, file_semantic_embeddings is PostgreSQL — two-step pattern
            $validIds = DB::select("SELECT id FROM file_registry");
            if (empty($validIds)) {
                return 0;
            }

            $idList = array_map(fn($row) => $row->id, $validIds);
            $placeholders = implode(',', array_fill(0, count($idList), '?'));

            return DB::connection('pgsql_rag')->delete(
                "DELETE FROM file_semantic_embeddings WHERE file_id NOT IN ({$placeholders})",
                $idList
            );
        } catch (Exception $e) {
            Log::warning('SemanticSearch: removeDeletedFiles failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function reindexFile(int $fileId): array
    {
        try {
            // Clear and re-index
            DB::connection('pgsql_rag')->delete(
                "DELETE FROM file_semantic_embeddings WHERE file_id = ?",
                [$fileId]
            );
        } catch (\Throwable $e) {
            // file_semantic_embeddings table may not exist yet
            return ['success' => false, 'error' => 'file_semantic_embeddings table not available'];
        }

        return $this->indexFile($fileId);
    }

    // =========================================================================
    // STATS
    // =========================================================================

    public function getStats(): array
    {
        try {
            $pgStats = DB::connection('pgsql_rag')->selectOne(
                "SELECT
                    COUNT(DISTINCT file_id) as indexed_files,
                    COUNT(*) as total_chunks,
                    AVG(LENGTH(chunk_text)) as avg_chunk_length
                 FROM file_semantic_embeddings"
            );
        } catch (Exception $e) {
            Log::warning('SemanticSearch: file_semantic_embeddings table not available', ['error' => $e->getMessage()]);
            $pgStats = (object) ['indexed_files' => 0, 'total_chunks' => 0, 'avg_chunk_length' => 0];
        }

        try {
            $mysqlStats = DB::selectOne(
                "SELECT
                    COUNT(*) as total_files,
                    SUM(CASE WHEN semantic_indexed_at IS NOT NULL THEN 1 ELSE 0 END) as indexed_count
                 FROM file_registry"
            );
        } catch (\Throwable $e) {
            // semantic_indexed_at column may not exist yet
            $mysqlStats = (object) ['total_files' => 0, 'indexed_count' => 0];
        }

        return [
            'indexed_files' => $pgStats->indexed_files ?? 0,
            'total_chunks' => $pgStats->total_chunks ?? 0,
            'avg_chunk_length' => round($pgStats->avg_chunk_length ?? 0),
            'total_files' => $mysqlStats->total_files ?? 0,
            'index_coverage' => ($mysqlStats->total_files ?? 0) > 0
                ? round((($pgStats->indexed_files ?? 0) / $mysqlStats->total_files) * 100, 1)
                : 0,
        ];
    }
}
