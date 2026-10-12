<?php

namespace App\Services;

use App\Support\SensitiveTextTelemetry;
use Illuminate\Support\Facades\Log;

/**
 * RAG-6: Self-RAG Relevance Gating (Asai et al., ICLR 2024 — arXiv:2310.11511)
 *
 * After retrieval, scores each candidate document for relevance to the query
 * using a fast LLM call. Documents below the relevance threshold are filtered
 * out before they are injected into the generation context.
 *
 * Why this matters: vector similarity ≠ relevance. A document can be
 * embedding-close to the query without actually containing an answer. Filtering
 * irrelevant documents reduces context noise and improves answer precision,
 * especially for topical or factual queries where off-topic context confuses
 * the generator.
 *
 * Pipeline:
 *   1. For the table-owned maximum document count, ask a routed LLM to score
 *      relevance 0.0–1.0 (one LLM call per document)
 *   2. Drop documents whose score < threshold
 *   3. Safety fallback: if all documents are filtered, return the single
 *      highest-scoring one to prevent empty context
 *   4. Results beyond the table-owned cap are passed through with no gating
 *      (lower-similarity docs rarely need extra filtering)
 *
 * Reference: Self-RAG (Asai et al., ICLR 2024, arXiv:2310.11511)
 */
class RelevanceGatingService
{
    private const QUERY_TELEMETRY_SCOPE = 'rag-relevance-gating';

    public function __construct(
        private readonly AIService $ai,
        private readonly RelevanceGatingRuntimePolicyService $runtimePolicy,
    ) {}

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Filter the retrieval result set to documents relevant to the query.
     *
     * @param  string  $query  The user query
     * @param  array  $results  RAGService search result array (each has 'document' stdClass + 'similarity')
     * @param  float|null  $threshold  Override table threshold (0.0 = keep all)
     * @return array{
     *   results: array,
     *   filtered_count: int,
     *   total_count: int,
     *   policy_source: string,
     *   effective_policy: array<string,int|float|string>
     * }
     */
    public function gateResults(string $query, array $results, ?float $threshold = null): array
    {
        $policy = $this->runtimePolicy->required();
        $threshold ??= $policy['relevance_threshold'];
        if ($threshold < 0.0 || $threshold > 1.0) {
            throw new \InvalidArgumentException('Relevance threshold must be between 0.0 and 1.0.');
        }

        $total = count($results);
        $metadata = [
            'policy_source' => 'system_configs.'.RelevanceGatingRuntimePolicyService::CONFIG_KEY,
            'effective_policy' => [
                'relevance_threshold' => $threshold,
                'max_documents_to_gate' => $policy['max_documents_to_gate'],
                'max_document_chars' => $policy['max_document_chars'],
                'neutral_score' => $policy['neutral_score'],
                'scorer_max_tokens' => $policy['scorer']['max_tokens'],
                'scorer_temperature' => $policy['scorer']['temperature'],
                'scorer_model_role' => $policy['scorer']['model_role'],
            ],
        ];

        if (empty($results) || $threshold <= 0.0) {
            return [
                'results' => $results,
                'filtered_count' => 0,
                'total_count' => $total,
                ...$metadata,
            ];
        }

        // Split: gate the top slice, pass the rest through unchanged
        $toGate = array_slice($results, 0, $policy['max_documents_to_gate']);
        $passThrough = array_slice($results, $policy['max_documents_to_gate']);

        $scored = [];
        $filteredCount = 0;
        $scoringUnavailableCount = 0;
        $bestScore = -1.0;
        $bestResult = null;

        foreach ($toGate as $result) {
            $doc = $result['document'];
            $content = $doc->content ?? '';
            $title = $doc->title ?? '';

            $scored_result = $result;

            // Web pseudo-docs (id=0) always pass — they were web-fetched for this query
            if (($doc->id ?? 1) === 0) {
                $scored_result['relevance_score'] = 1.0;
                $scored[] = $scored_result;
                if ($bestScore < 1.0) {
                    $bestScore = 1.0;
                    $bestResult = $scored_result;
                }

                continue;
            }

            $scoreResult = $this->scoreDocumentWithPolicy($query, $content, $title, $policy);
            $score = $scoreResult['score'];
            $scored_result['relevance_score'] = $score;

            if (! $scoreResult['scoring_available']) {
                $scoringUnavailableCount++;
                $scored[] = $scored_result;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestResult = $scored_result;
                }

                continue;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestResult = $scored_result;
            }

            if ($score < $threshold) {
                $filteredCount++;
                Log::debug('RelevanceGatingService: filtered document', [
                    'score' => $score,
                    'document_identity_logged' => false,
                    'model_reason_logged' => false,
                ]);

                continue;
            }

            $scored[] = $scored_result;
        }

        // Safety: never return an empty set — keep the best-scored doc
        if (empty($scored) && $bestResult !== null) {
            $scored = [$bestResult];
            $filteredCount = $total - 1;

            Log::warning('RelevanceGatingService: all docs filtered — keeping best-scored doc', [
                'best_score' => $bestScore,
                ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
            ]);
        }

        $final = array_merge($scored, $passThrough);

        Log::info('RelevanceGatingService: gating complete', [
            'total' => $total,
            'gated' => count($toGate),
            'filtered' => $filteredCount,
            'kept' => count($scored),
            'pass_through' => count($passThrough),
            'scoring_unavailable' => $scoringUnavailableCount,
            'threshold' => $threshold,
            'policy_source' => $metadata['policy_source'],
            ...SensitiveTextTelemetry::query($query, self::QUERY_TELEMETRY_SCOPE),
        ]);

        return [
            'results' => $final,
            'filtered_count' => $filteredCount,
            'total_count' => $total,
            ...$metadata,
        ];
    }

    // =========================================================================
    // Per-document relevance scoring
    // =========================================================================

    /**
     * Ask a fast LLM whether the document is relevant to the query.
     *
     * On AI failure, returns the table-owned neutral pass-through score.
     *
     * @return array{relevant: bool, score: float, reason: string, scoring_available: bool}
     */
    public function scoreDocument(string $query, string $docContent, string $docTitle): array
    {
        return $this->scoreDocumentWithPolicy($query, $docContent, $docTitle, $this->runtimePolicy->required());
    }

    /**
     * @param array{
     *   relevance_threshold:float,
     *   max_documents_to_gate:int,
     *   max_document_chars:int,
     *   neutral_score:float,
     *   scorer:array{max_tokens:int,temperature:float,model_role:string}
     * } $policy
     * @return array{relevant: bool, score: float, reason: string, scoring_available: bool}
     */
    private function scoreDocumentWithPolicy(string $query, string $docContent, string $docTitle, array $policy): array
    {
        $neutral = [
            'relevant' => true,
            'score' => $policy['neutral_score'],
            'reason' => 'Scoring unavailable; neutral pass-through.',
            'scoring_available' => false,
        ];

        $snippet = mb_substr($docContent, 0, $policy['max_document_chars']);

        $prompt = "You are assessing document relevance for a retrieval-augmented generation system.\n\n"
            ."QUERY: \"{$query}\"\n\n"
            ."DOCUMENT TITLE: {$docTitle}\n"
            ."DOCUMENT EXCERPT:\n{$snippet}\n\n"
            .'Is this document relevant to the query? A document is relevant if it contains '
            ."information that would directly help answer the query.\n\n"
            ."Output ONLY valid JSON (no markdown):\n"
            .'{"relevant": true, "score": 0.0-1.0, "reason": "brief explanation"}';

        $result = $this->ai->process($prompt, [
            'max_tokens' => $policy['scorer']['max_tokens'],
            'temperature' => $policy['scorer']['temperature'],
            'expect_json' => true,
            'task_type' => 'relevance_gating',
            'model_role' => $policy['scorer']['model_role'],
            'suppress_alert' => true,
        ]);

        if (! ($result['success'] ?? false)) {
            return $neutral;
        }

        $raw = trim($result['response'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed) || ! is_numeric($parsed['score'] ?? null)) {
            return $neutral;
        }

        return [
            'relevant' => (bool) ($parsed['relevant'] ?? true),
            'score' => max(0.0, min(1.0, (float) $parsed['score'])),
            'reason' => (string) ($parsed['reason'] ?? ''),
            'scoring_available' => true,
        ];
    }
}
