<?php

namespace App\Services;

use App\Support\PgVector;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RAPTOR Hierarchical Summarization Service
 *
 * Implements RAPTOR (Recursive Abstractive Processing for Tree-Organized Retrieval)
 * to build hierarchical summaries: sentences -> paragraphs -> sections -> document.
 *
 * This enables retrieval at multiple granularity levels - users can find:
 * - Specific sentence-level details
 * - Paragraph-level context
 * - Section-level themes
 * - Document-level overviews
 *
 * @see https://arxiv.org/abs/2401.18059 RAPTOR Paper
 */
class RaptorSummarizationService
{
    private AIService $aiService;

    /**
     * Summary level constants
     */
    public const LEVEL_SENTENCE = 0;

    public const LEVEL_PARAGRAPH = 1;

    public const LEVEL_SECTION = 2;

    public const LEVEL_DOCUMENT = 3;

    public const LEVEL_NAMES = [
        self::LEVEL_SENTENCE => 'sentence',
        self::LEVEL_PARAGRAPH => 'paragraph',
        self::LEVEL_SECTION => 'section',
        self::LEVEL_DOCUMENT => 'document',
    ];

    /**
     * GR-7: Minimum document length thresholds for RAPTOR eligibility.
     *
     * MIN_CHARS_ELIGIBLE — docs shorter than this are never summarized; too little
     *   content for a meaningful 2-level hierarchy. Raised from 1000 → 2000 chars
     *   to focus RAPTOR on documents with substantive prose content.
     *
     * MIN_CHARS_AUTO_ELIGIBLE — docs this long skip AI vetting (always eligible).
     *   Long prose is almost certainly summarizable; the AI call would be wasted.
     *
     * Future (GR-7b): replace sequential sentence windowing with Leiden graph
     *   clustering on sentence embeddings for semantically coherent paragraphs.
     */
    public const MIN_CHARS_ELIGIBLE = 2000;

    public const MIN_CHARS_AUTO_ELIGIBLE = 5000;

    /**
     * Configuration for clustering at each level
     */
    private array $config = [
        'sentences_per_paragraph' => 5,
        'paragraphs_per_section' => 4,
        'sections_per_document' => 6,
        'max_summary_tokens' => 500,
        'min_chunks_to_summarize' => 2,
    ];

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Build complete RAPTOR hierarchy for a document
     *
     * @param  int  $documentId  RAG document ID (parent document or first chunk)
     * @return array Summary statistics
     */
    public function buildHierarchy(int $documentId): array
    {
        $startTime = microtime(true);
        $stats = [
            'document_id' => $documentId,
            'levels' => [],
            'total_summaries' => 0,
        ];

        try {
            // Get all chunks for this document
            $chunks = $this->getDocumentChunks($documentId);
            if (empty($chunks)) {
                throw new Exception("No chunks found for document {$documentId}");
            }

            Log::info('RaptorSummarizationService: Starting hierarchy build', [
                'document_id' => $documentId,
                'chunk_count' => count($chunks),
            ]);

            // Level 0: Sentences (base chunks already exist in rag_documents)
            // We don't create summaries at this level - chunks ARE the base
            $currentLevelItems = $chunks;
            $stats['levels'][self::LEVEL_SENTENCE] = count($chunks);

            // Level 1: Paragraph summaries
            $paragraphSummaries = $this->buildLevel(
                $documentId,
                $currentLevelItems,
                self::LEVEL_PARAGRAPH,
                $this->config['sentences_per_paragraph']
            );
            $stats['levels'][self::LEVEL_PARAGRAPH] = count($paragraphSummaries);
            $stats['total_summaries'] += count($paragraphSummaries);

            if (count($paragraphSummaries) >= $this->config['min_chunks_to_summarize']) {
                // Level 2: Section summaries
                $sectionSummaries = $this->buildLevel(
                    $documentId,
                    $paragraphSummaries,
                    self::LEVEL_SECTION,
                    $this->config['paragraphs_per_section']
                );
                $stats['levels'][self::LEVEL_SECTION] = count($sectionSummaries);
                $stats['total_summaries'] += count($sectionSummaries);

                if (count($sectionSummaries) >= $this->config['min_chunks_to_summarize']) {
                    // Level 3: Document summary
                    $documentSummaries = $this->buildLevel(
                        $documentId,
                        $sectionSummaries,
                        self::LEVEL_DOCUMENT,
                        $this->config['sections_per_document']
                    );
                    $stats['levels'][self::LEVEL_DOCUMENT] = count($documentSummaries);
                    $stats['total_summaries'] += count($documentSummaries);
                }
            }

            // Mark indexed whether or not summaries were created.
            // 0 summaries with no exception = structural skip (too few chunks, no sentence-window children).
            // Marking indexed prevents infinite recycling of single-chunk docs.
            // AI failures throw exceptions (caught below) and increment error_count instead.
            $this->markDocumentIndexed($documentId);

            $stats['duration_ms'] = round((microtime(true) - $startTime) * 1000);

            Log::info('RaptorSummarizationService: Hierarchy complete', $stats);

            return $stats;

        } catch (Exception $e) {
            Log::error('RaptorSummarizationService: Hierarchy build failed', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build summaries for a single level
     *
     * @param  int  $documentId  Parent document ID
     * @param  array  $items  Items to summarize (chunks or lower-level summaries)
     * @param  int  $level  Target level
     * @param  int  $groupSize  Number of items to group together
     * @return array Created summary records
     */
    private function buildLevel(int $documentId, array $items, int $level, int $groupSize): array
    {
        $summaries = [];
        $groups = array_chunk($items, $groupSize);

        foreach ($groups as $groupIndex => $group) {
            if (count($group) < $this->config['min_chunks_to_summarize']) {
                continue;
            }

            // Combine texts for summarization
            $combinedText = $this->combineTexts($group, $level);

            // Generate summary using AI
            $summaryText = $this->generateSummary($combinedText, $level);
            if (empty($summaryText)) {
                continue;
            }

            // Generate embedding for the summary
            $embedding = $this->generateEmbedding($summaryText);

            // Get source IDs (either chunk IDs or summary IDs)
            $sourceIds = array_map(function ($item) {
                return is_object($item) ? $item->id : $item['id'];
            }, $group);

            // Get parent summary ID if we're building on previous level summaries
            $parentSummaryId = null;
            if ($level > self::LEVEL_PARAGRAPH && ! empty($group)) {
                $firstItem = $group[0];
                $parentSummaryId = is_object($firstItem)
                    ? ($firstItem->parent_summary_id ?? null)
                    : ($firstItem['parent_summary_id'] ?? null);
            }

            // Store the summary
            $summaryId = $this->storeSummary(
                $documentId,
                $parentSummaryId,
                $level,
                $summaryText,
                $sourceIds,
                $embedding
            );

            $summaries[] = (object) [
                'id' => $summaryId,
                'level' => $level,
                'content' => $summaryText,
                'parent_summary_id' => $parentSummaryId,
            ];
        }

        return $summaries;
    }

    /**
     * Combine texts from items for summarization
     */
    private function combineTexts(array $items, int $level): string
    {
        $texts = [];
        foreach ($items as $item) {
            if (is_object($item)) {
                $text = $item->content ?? $item->summary_text ?? $item->text ?? '';
            } else {
                $text = $item['content'] ?? $item['summary_text'] ?? $item['text'] ?? '';
            }
            if (! empty($text)) {
                $texts[] = trim($text);
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * Generate summary using AI
     */
    private function generateSummary(string $text, int $level): ?string
    {
        $levelName = self::LEVEL_NAMES[$level];
        $maxTokens = $this->config['max_summary_tokens'];

        $prompt = match ($level) {
            self::LEVEL_PARAGRAPH => "Summarize the following text into a coherent paragraph. Keep key details and maintain context. Maximum {$maxTokens} words.\n\nText:\n{$text}",
            self::LEVEL_SECTION => "Create a section summary that captures the main themes and key points from these paragraphs. Focus on the overarching narrative. Maximum {$maxTokens} words.\n\nParagraphs:\n{$text}",
            self::LEVEL_DOCUMENT => "Write a comprehensive document summary that captures the essence, main topics, and key conclusions. This is a high-level overview. Maximum {$maxTokens} words.\n\nSections:\n{$text}",
            default => "Summarize: {$text}",
        };

        try {
            $result = $this->aiService->process($prompt, [
                'task_type' => 'raptor_summarization',
                'model_role' => 'quality',
                'temperature' => 0.3,
                'max_tokens' => $maxTokens * 2,
            ]);

            if (! empty($result['success']) && ! empty($result['response'])) {
                return trim($result['response']);
            }

            Log::warning('RaptorSummarizationService: AI summary returned empty, using extractive fallback', [
                'level' => $levelName,
            ]);
        } catch (Exception $e) {
            Log::warning('RaptorSummarizationService: Summary generation failed, using extractive fallback', [
                'level' => $levelName,
                'error' => $e->getMessage(),
            ]);
        }

        // Extractive fallback: truncate to max_tokens words so the hierarchy still builds
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return null;
        }

        return implode(' ', array_slice($words, 0, $maxTokens)).(count($words) > $maxTokens ? '…' : '');
    }

    /**
     * Generate embedding for a summary
     */
    private function generateEmbedding(string $text): ?array
    {
        try {
            $result = $this->aiService->generateEmbedding($text);
            if ($result['success']) {
                return $result['embedding'];
            }

            return null;
        } catch (Exception $e) {
            Log::warning('RaptorSummarizationService: Embedding generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Store summary in the database
     */
    private function storeSummary(
        int $documentId,
        ?int $parentSummaryId,
        int $level,
        string $summaryText,
        array $sourceChunkIds,
        ?array $embedding
    ): int {
        $embeddingStr = $embedding ? PgVector::literal($embedding) : null;
        $levelName = self::LEVEL_NAMES[$level];

        $sql = '
            INSERT INTO raptor_summaries
            (document_id, parent_summary_id, level, level_name, summary_text, source_chunk_ids, token_count, embedding, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, '.($embeddingStr ? "'{$embeddingStr}'::vector" : 'NULL').', NOW(), NOW())
            RETURNING id
        ';

        $result = DB::connection('pgsql_rag')->selectOne($sql, [
            $documentId,
            $parentSummaryId,
            $level,
            $levelName,
            $summaryText,
            json_encode($sourceChunkIds),
            str_word_count($summaryText),
        ]);

        return $result->id;
    }

    /**
     * Get all chunks for a document
     */
    private function getDocumentChunks(int $documentId): array
    {
        // Get the parent document
        $parent = DB::connection('pgsql_rag')->selectOne(
            'SELECT id, content FROM rag_documents WHERE id = ?',
            [$documentId]
        );

        if (! $parent) {
            return [];
        }

        // Get all chunks (parent + children)
        $chunks = DB::connection('pgsql_rag')->select('
            SELECT id, content, title, metadata
            FROM rag_documents
            WHERE id = ? OR parent_id = ?
            ORDER BY id
        ', [$documentId, $documentId]);

        return $chunks;
    }

    /**
     * Mark document as RAPTOR-indexed and reset error count
     */
    private function markDocumentIndexed(int $documentId): void
    {
        DB::connection('pgsql_rag')->update(
            'UPDATE rag_documents SET raptor_indexed_at = NOW(), raptor_error_count = 0 WHERE id = ?',
            [$documentId]
        );
    }

    /**
     * Record a RAPTOR build failure for a document.
     * After 3 failures the batch job permanently skips it (use --force to retry).
     */
    public function recordDocumentError(int $documentId): void
    {
        DB::connection('pgsql_rag')->update(
            'UPDATE rag_documents SET raptor_error_count = COALESCE(raptor_error_count, 0) + 1 WHERE id = ?',
            [$documentId]
        );
    }

    /**
     * Screen a document for RAPTOR eligibility and persist the result.
     *
     * Phase 1 — instant heuristics (no LLM):
     *   - Too short (< 1000 chars) → ineligible
     *   - Starts with JSON/array literal → ineligible
     *   - High structured-character ratio (> 15%) → ineligible (CSV, code, logs)
     *   - Fewer than 10 sentences → ineligible
     *   - 4000+ chars and passes heuristics → eligible (skip AI)
     *
     * Phase 2 — AI vetting for borderline (1000–3999 chars):
     *   Fast model, binary YES/NO, ~1–2s. Saves 15–65s of hierarchy work
     *   if rejected. On AI failure, defaults to eligible.
     *
     * @param  object  $doc  Must have ->id and ->content
     * @return bool True if eligible
     */
    public function screenDocument(object $doc): bool
    {
        $content = $doc->content ?? '';
        $len = strlen($content);

        // Too short to form a 2-paragraph RAPTOR hierarchy (GR-7: raised 1000 → 2000)
        if ($len < self::MIN_CHARS_ELIGIBLE) {
            $this->markIneligible($doc->id, 'too_short');

            return false;
        }

        // JSON / array literal — structured data, not prose
        $trimmed = ltrim($content);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $this->markIneligible($doc->id, 'json_content');

            return false;
        }

        // --- Code file detection (runs before the >=4000 shortcut so large code files are caught) ---
        $newlineCount = max(1, substr_count($content, "\n"));

        // Definitive single-occurrence markers: one of these = file IS code
        foreach (['<?php', '<?xml', '#!/'] as $marker) {
            if (str_contains($content, $marker)) {
                $this->markIneligible($doc->id, 'code_marker');

                return false;
            }
        }

        // Semicolon density: statement terminators in C/PHP/JS/Java/CSS
        // Prose rarely hits 0.25 semicolons-per-line; code always does
        if (substr_count($content, ';') / $newlineCount >= 0.25) {
            $this->markIneligible($doc->id, 'code_semicolons');

            return false;
        }

        // Multi-occurrence markers: 2+ appearances = file IS code (not prose quoting a snippet)
        foreach ([
            'import ', 'def ', 'function ', '#include',
            'SELECT ', 'INSERT INTO', 'CREATE TABLE',
            // FoxPro/xBase (.prg, .scx, .vcx, etc.)
            'PROCEDURE ', 'ENDPROC', 'REPLACE ', 'ENDIF', 'ENDDO',
        ] as $marker) {
            if (substr_count($content, $marker) >= 2) {
                $this->markIneligible($doc->id, 'code_markers');

                return false;
            }
        }
        // --- End code file detection ---

        // High ratio of structured characters → CSV / log / table
        $structuredCount = preg_match_all('/[|,\t=><{}\[\]\/\\\\]/', $content);
        if ($structuredCount / $len > 0.15) {
            $this->markIneligible($doc->id, 'structured_data');

            return false;
        }

        // Too few sentences to form 2 paragraphs (sentences_per_paragraph = 5, min_chunks = 2)
        $sentenceCount = preg_match_all('/[.!?]+(?:\s|$)/', $content);
        if ($sentenceCount < 10) {
            $this->markIneligible($doc->id, 'too_few_sentences');

            return false;
        }

        // Long prose — skip AI vetting, always eligible (GR-7: raised 4000 → 5000)
        if ($len >= self::MIN_CHARS_AUTO_ELIGIBLE) {
            $this->markEligible($doc->id);

            return true;
        }

        // Borderline (2000–4999 chars): ask AI for a binary verdict
        try {
            $result = $this->aiService->process(
                "Does the following text contain coherent narrative prose that would benefit from hierarchical summarization into paragraphs, sections, and a document overview? Answer YES or NO only.\n\nText:\n".substr($content, 0, 1500),
                ['model_role' => 'fast', 'max_tokens' => 5, 'temperature' => 0]
            );

            $eligible = str_contains(strtoupper($result['response'] ?? ''), 'YES');

            if ($eligible) {
                $this->markEligible($doc->id);
            } else {
                $this->markIneligible($doc->id, 'ai_rejected');
            }

            return $eligible;
        } catch (Exception $e) {
            // AI unavailable — give benefit of the doubt
            Log::warning('RaptorSummarizationService: AI screening failed, defaulting to eligible', [
                'doc' => $doc->id, 'error' => $e->getMessage(),
            ]);
            $this->markEligible($doc->id);

            return true;
        }
    }

    private function markEligible(int $documentId): void
    {
        DB::connection('pgsql_rag')->update(
            'UPDATE rag_documents SET raptor_eligible = 1 WHERE id = ?',
            [$documentId]
        );
    }

    private function markIneligible(int $documentId, string $reason = ''): void
    {
        DB::connection('pgsql_rag')->update(
            'UPDATE rag_documents SET raptor_eligible = 0 WHERE id = ?',
            [$documentId]
        );
        if ($reason) {
            Log::debug('RaptorSummarizationService: marked ineligible', [
                'doc' => $documentId, 'reason' => $reason,
            ]);
        }
    }

    /**
     * Search across all RAPTOR levels with automatic level selection
     *
     * @param  string  $query  Search query
     * @param  int  $limit  Results per level
     * @param  int|null  $preferredLevel  Preferred granularity level (null = all levels)
     * @return array Results organized by level
     */
    public function search(string $query, int $limit = 5, ?int $preferredLevel = null): array
    {
        // Generate query embedding
        $result = $this->aiService->generateEmbedding($query);
        if (! $result['success']) {
            throw new Exception('Query embedding failed: '.($result['error'] ?? 'unknown'));
        }

        $embeddingStr = PgVector::literal($result['embedding']);

        $results = [
            'query' => $query,
            'levels' => [],
        ];

        $levels = $preferredLevel !== null
            ? [$preferredLevel]
            : [self::LEVEL_DOCUMENT, self::LEVEL_SECTION, self::LEVEL_PARAGRAPH];

        foreach ($levels as $level) {
            $levelResults = DB::connection('pgsql_rag')->select("
                SELECT
                    rs.id,
                    rs.document_id,
                    rs.level,
                    rs.level_name,
                    rs.summary_text,
                    rs.source_chunk_ids,
                    1 - (rs.embedding <=> '{$embeddingStr}'::vector) as similarity,
                    rd.title as document_title,
                    rd.source_type
                FROM raptor_summaries rs
                JOIN rag_documents rd ON rd.id = rs.document_id
                WHERE rs.level = ?
                  AND rs.embedding IS NOT NULL
                ORDER BY rs.embedding <=> '{$embeddingStr}'::vector
                LIMIT ?
            ", [$level, $limit]);

            $results['levels'][self::LEVEL_NAMES[$level]] = array_map(function ($row) {
                $row->source_chunk_ids = json_decode($row->source_chunk_ids, true);

                return $row;
            }, $levelResults);
        }

        return $results;
    }

    /**
     * Get the complete hierarchy for a document
     *
     * @param  int  $documentId  RAG document ID
     * @return array Hierarchical structure
     */
    public function getHierarchy(int $documentId): array
    {
        $summaries = DB::connection('pgsql_rag')->select('
            SELECT id, parent_summary_id, level, level_name, summary_text, source_chunk_ids, token_count
            FROM raptor_summaries
            WHERE document_id = ?
            ORDER BY level DESC, id
        ', [$documentId]);

        // Build tree structure
        $tree = [
            'document_id' => $documentId,
            'levels' => [],
        ];

        foreach ($summaries as $summary) {
            $levelName = $summary->level_name;
            if (! isset($tree['levels'][$levelName])) {
                $tree['levels'][$levelName] = [];
            }
            $summary->source_chunk_ids = json_decode($summary->source_chunk_ids, true);
            $tree['levels'][$levelName][] = $summary;
        }

        return $tree;
    }

    /**
     * Get documents pending RAPTOR indexing
     *
     * @param  int  $limit  Maximum documents to return
     * @return array Document IDs
     */
    public function getPendingDocuments(int $limit = 100): array
    {
        return DB::connection('pgsql_rag')->select('
            SELECT id, title, document_type, source_type
            FROM rag_documents
            WHERE raptor_indexed_at IS NULL
              AND parent_id IS NULL
              AND COALESCE(raptor_error_count, 0) < 3
              AND raptor_eligible = 1
            ORDER BY created_at DESC
            LIMIT ?
        ', [$limit]);
    }

    /**
     * Delete RAPTOR hierarchy for a document
     *
     * @param  int  $documentId  RAG document ID
     * @return int Number of summaries deleted
     */
    public function deleteHierarchy(int $documentId): int
    {
        $deleted = DB::connection('pgsql_rag')->delete(
            'DELETE FROM raptor_summaries WHERE document_id = ?',
            [$documentId]
        );

        // Reset indexed flag and error count so the document can be retried
        DB::connection('pgsql_rag')->update(
            'UPDATE rag_documents SET raptor_indexed_at = NULL, raptor_error_count = 0 WHERE id = ?',
            [$documentId]
        );

        return $deleted;
    }
}
