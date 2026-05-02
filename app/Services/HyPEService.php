<?php

namespace App\Services;

use App\Support\PgVector;
use App\Traits\RecursionAware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RAG-4: HyPE (Hypothetical questions Per chunk at Index time)
 *
 * At index time: for each eligible chunk, generate QUESTIONS_PER_CHUNK hypothetical
 * questions the chunk answers, embed those questions, and store them in
 * rag_chunk_hypotheticals. At search time, embed the user's query and find chunks
 * whose hypothetical questions are semantically close.
 *
 * Why it works: query-to-question similarity is higher than query-to-chunk-text
 * similarity because both are short, interrogative, and in the same embedding space.
 *
 * Reference: HyPE (Hypothetical Prompt Embeddings), Lindqvist & Johansson 2025
 *            arXiv:2502.09672 — 42pp precision improvement on BEIR benchmarks
 */
class HyPEService
{
    use RecursionAware;

    /** Minimum content length (chars) to be worth generating questions for */
    public const MIN_CONTENT_LENGTH = 300;

    /** Number of hypothetical questions generated per chunk */
    public const QUESTIONS_PER_CHUNK = 3;

    /** Scheduled builds favor throughput; richer HyPE can catch up later. */
    private const SCHEDULED_QUESTIONS_PER_CHUNK = 1;

    /** Scheduled builds should not send large documents through question generation. */
    private const SCHEDULED_CONTENT_CHAR_LIMIT = 800;

    /** Interactive/manual builds keep the original richer prompt window. */
    private const DEFAULT_CONTENT_CHAR_LIMIT = 1500;

    /** Structured-character ratio above which a document is skipped */
    private const MAX_STRUCTURED_RATIO = 0.15;

    /** Minimum word count — very low = likely IDs/paths, not prose */
    private const MIN_WORD_COUNT = 30;

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Eligibility screening (heuristic-only, no LLM)
    // =========================================================================

    /**
     * Return true if the document is worth generating hypothetical questions for.
     * All heuristics — no LLM calls.
     */
    public function screenDocument(object|array $doc): bool
    {
        $content = is_array($doc) ? ($doc['content'] ?? '') : ($doc->content ?? '');
        $content = trim($content);
        $len     = mb_strlen($content);

        // Too short
        if ($len < self::MIN_CONTENT_LENGTH) {
            return false;
        }

        // Structured data — JSON, YAML front-matter, arrays
        if (str_starts_with($content, '{') || str_starts_with($content, '[')) {
            return false;
        }

        // High ratio of structured chars → code, CSV, TSV
        $structuredCount = preg_match_all('/[{}\[\]|,;=<>()\t]/', $content);
        if ($structuredCount / max(1, $len) > self::MAX_STRUCTURED_RATIO) {
            return false;
        }

        // Too few words — likely a list of paths, IDs, or hashes
        if (str_word_count($content) < self::MIN_WORD_COUNT) {
            return false;
        }

        return true;
    }

    // =========================================================================
    // Question generation
    // =========================================================================

    /**
     * Generate hypothetical questions a chunk could answer via a fast LLM.
     *
     * @param  string $content Chunk text
     * @param  int    $count   Number of questions to generate
     * @param  array  $options Runtime controls:
     *                         - skip_recursive: bool
     *                         - scheduled_batch: bool
     * @return string[]  Question strings; empty array on failure
     */
    public function generateQuestions(string $content, int $count = self::QUESTIONS_PER_CHUNK, array $options = []): array
    {
        if (!empty($options['scheduled_batch'])) {
            $count = min($count, self::SCHEDULED_QUESTIONS_PER_CHUNK);
            return $this->generateScheduledQuestions($content, $count, $options['title'] ?? null);
        }

        // RLM: Try recursive question generation unless scheduled runtime opts out.
        if (empty($options['skip_recursive'])) {
            $rlm = $this->tryRecursive('hype', 'quality_gate_retry', ['content' => $content, 'count' => $count, 'options' => $options], function ($ctx) {
                return $this->generateQuestions(
                    $ctx['content'] ?? $ctx['data'],
                    $ctx['count'] ?? self::QUESTIONS_PER_CHUNK,
                    $ctx['options'] ?? []
                );
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        $contentLimit = !empty($options['scheduled_batch'])
            ? self::SCHEDULED_CONTENT_CHAR_LIMIT
            : self::DEFAULT_CONTENT_CHAR_LIMIT;

        $prompt = "Generate exactly {$count} specific, factual questions that the following text directly answers. "
            . "Output ONLY a JSON array of question strings — no explanations, no numbering, no markdown. "
            . "Example: [\"What year was X founded?\", \"Who invented Y?\", \"How does Z work?\"]\n\n"
            . "TEXT:\n" . mb_substr($content, 0, $contentLimit);

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 300,
            'temperature'    => 0.3,
            'expect_json'    => true,
            'task_type'      => 'hype_question_generation',
            'model_role'     => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            Log::warning('HyPEService: question generation failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return [];
        }

        $raw = trim($result['response'] ?? '');

        // Strip markdown code fences
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $questions = json_decode($raw, true);
        if (!is_array($questions)) {
            Log::warning('HyPEService: JSON parse failed', ['raw' => substr($raw, 0, 200)]);
            return [];
        }

        // Filter to well-formed question strings only
        return array_values(array_filter(
            $questions,
            fn($q) => is_string($q) && mb_strlen(trim($q)) >= 10
        ));
    }

    private function generateScheduledQuestions(string $content, int $count, ?string $title = null): array
    {
        $subject = trim((string) $title);
        if ($subject === '') {
            $subject = trim(preg_replace('/\s+/', ' ', mb_substr(strip_tags($content), 0, 90)));
        }

        if ($subject === '') {
            return [];
        }

        $subject = mb_substr($subject, 0, 140);
        $questions = [
            "What information is contained in {$subject}?",
        ];

        return array_slice($questions, 0, max(1, $count));
    }

    // =========================================================================
    // Index-time processing
    // =========================================================================

    /**
     * Generate, embed, and store hypothetical questions for one document.
     * Deletes any prior rows first (idempotent / supports --rebuild).
     *
     * @return array{questions_generated: int, questions_embedded: int, duration_ms: int}
     */
    public function indexDocument(int $documentId, string $content, array $options = []): array
    {
        $start = microtime(true);

        $questionCount = $options['question_count']
            ?? (!empty($options['scheduled_batch']) ? self::SCHEDULED_QUESTIONS_PER_CHUNK : self::QUESTIONS_PER_CHUNK);
        $questions = $this->generateQuestions($content, $questionCount, $options);

        if (empty($questions)) {
            $this->markIndexed($documentId);
            return ['questions_generated' => 0, 'questions_embedded' => 0, 'duration_ms' => 0];
        }

        // Remove prior rows (rebuild support)
        DB::connection('pgsql_rag')->delete(
            "DELETE FROM rag_chunk_hypotheticals WHERE document_id = ?",
            [$documentId]
        );

        $embedded = 0;
        foreach ($questions as $idx => $question) {
            $emb = $this->ai->generateEmbedding($question);
            if (!($emb['success'] ?? false) || empty($emb['embedding'])) {
                Log::warning('HyPEService: embedding failed', [
                    'document_id'    => $documentId,
                    'question_index' => $idx,
                ]);
                continue;
            }

            $vector = PgVector::literal($emb['embedding']);
            DB::connection('pgsql_rag')->insert(
                "INSERT INTO rag_chunk_hypotheticals
                    (document_id, question_text, embedding, question_index, created_at)
                 VALUES (?, ?, ?::vector, ?, NOW())",
                [$documentId, $question, $vector, $idx]
            );
            $embedded++;
        }

        $this->markIndexed($documentId);

        $ms = (int) round((microtime(true) - $start) * 1000);
        Log::debug('HyPEService: indexed', [
            'document_id'         => $documentId,
            'questions_generated' => count($questions),
            'questions_embedded'  => $embedded,
            'duration_ms'         => $ms,
        ]);

        return [
            'questions_generated' => count($questions),
            'questions_embedded'  => $embedded,
            'duration_ms'         => $ms,
        ];
    }

    /**
     * Increment the error counter for a document.
     * After 3 errors the document is excluded from future runs.
     */
    public function recordError(int $documentId): void
    {
        DB::connection('pgsql_rag')->update(
            "UPDATE rag_documents
             SET hype_error_count = COALESCE(hype_error_count, 0) + 1
             WHERE id = ?",
            [$documentId]
        );
    }

    // =========================================================================
    // Search-time retrieval
    // =========================================================================

    /**
     * Embed query, find the nearest hypothetical questions, return parent chunks.
     *
     * Results are deduplicated by document_id (highest-similarity question wins)
     * and returned in the same {document, similarity} format as RAGService::search().
     *
     * @param  string      $query
     * @param  int         $limit
     * @param  string|null $documentType  Optional document_type filter
     * @return array<int, array{document: object, similarity: float, hype_question: string}>
     */
    public function search(string $query, int $limit = 10, ?string $documentType = null): array
    {
        $emb = $this->ai->generateEmbedding($query);
        if (!($emb['success'] ?? false) || empty($emb['embedding'])) {
            Log::warning('HyPEService: query embedding failed');
            return [];
        }

        $vector = PgVector::literal($emb['embedding']);

        // Fetch candidate question matches (extra headroom for dedup)
        $rows = DB::connection('pgsql_rag')->select("
            SELECT document_id,
                   question_text,
                   1 - (embedding <=> ?::vector) AS similarity
            FROM rag_chunk_hypotheticals
            ORDER BY embedding <=> ?::vector
            LIMIT ?
        ", [$vector, $vector, $limit * 4]);

        if (empty($rows)) {
            return [];
        }

        // Deduplicate by document_id — keep the highest-similarity question per doc
        $byDocId = [];
        foreach ($rows as $row) {
            $docId = $row->document_id;
            if (!isset($byDocId[$docId]) || $row->similarity > $byDocId[$docId]->similarity) {
                $byDocId[$docId] = $row;
            }
        }

        // Sort and trim
        uasort($byDocId, fn($a, $b) => $b->similarity <=> $a->similarity);
        $topRows = array_slice(array_values($byDocId), 0, $limit);

        // Fetch full document rows
        $docIds       = array_column($topRows, 'document_id');
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $typeWhere    = $documentType !== null ? ' AND document_type = ?' : '';
        $docParams    = $documentType !== null
            ? array_merge($docIds, [$documentType])
            : $docIds;

        $docs = DB::connection('pgsql_rag')->select(
            "SELECT * FROM rag_documents WHERE id IN ({$placeholders}){$typeWhere}",
            $docParams
        );

        $docMap = [];
        foreach ($docs as $doc) {
            $docMap[$doc->id] = $doc;
        }

        $results = [];
        foreach ($topRows as $row) {
            if (!isset($docMap[$row->document_id])) {
                continue;
            }
            $results[] = [
                'document'      => $docMap[$row->document_id],
                'similarity'    => (float) $row->similarity,
                'hype_question' => $row->question_text,
            ];
        }

        return $results;
    }

    // =========================================================================
    // Statistics
    // =========================================================================

    public function getStats(): array
    {
        $docStats = DB::connection('pgsql_rag')->selectOne("
            SELECT
                COUNT(*)                                              AS total_docs,
                COUNT(*) FILTER (WHERE hype_eligible = 1)            AS eligible,
                COUNT(*) FILTER (WHERE hype_eligible = 0)            AS ineligible,
                COUNT(*) FILTER (WHERE hype_eligible IS NULL)        AS unscreened,
                COUNT(*) FILTER (WHERE hype_indexed_at IS NOT NULL)  AS indexed,
                COUNT(*) FILTER (WHERE hype_eligible = 1
                                   AND hype_indexed_at IS NULL
                                   AND COALESCE(hype_error_count, 0) < 3) AS pending
            FROM rag_documents
            WHERE parent_id IS NULL
        ");

        $qCount = DB::connection('pgsql_rag')->selectOne(
            "SELECT COUNT(*) AS total FROM rag_chunk_hypotheticals"
        );

        return [
            'total_docs'       => (int) ($docStats->total_docs    ?? 0),
            'eligible'         => (int) ($docStats->eligible       ?? 0),
            'ineligible'       => (int) ($docStats->ineligible     ?? 0),
            'unscreened'       => (int) ($docStats->unscreened     ?? 0),
            'indexed'          => (int) ($docStats->indexed        ?? 0),
            'pending'          => (int) ($docStats->pending        ?? 0),
            'total_questions'  => (int) ($qCount->total            ?? 0),
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function markIndexed(int $documentId): void
    {
        DB::connection('pgsql_rag')->update(
            "UPDATE rag_documents
             SET hype_indexed_at = NOW(),
                 hype_error_count = COALESCE(hype_error_count, 0)
             WHERE id = ?",
            [$documentId]
        );
    }
}
