<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * RAG-10: Grounding Verifier — FACTUM (arXiv:2601.05866)
 *
 * Post-generation check: given a RAG response and its source documents,
 * verify that each factual claim in the response is actually entailed by
 * (or at least consistent with) the retrieved sources.
 *
 * Pipeline:
 *   1. Extract atomic claims from the response (1 fast LLM call)
 *   2. For each claim, check entailment against top source docs (1 LLM call/claim)
 *   3. Compute grounding_score = fraction of claims that are entailed
 *   4. Return verdict: grounded / partially_grounded / ungrounded
 *
 * Why this matters: a RAG response may be fluent and plausible but still
 * contain hallucinated facts that are absent from the retrieved sources.
 * Grounding verification catches this without external web search.
 *
 * Reference: FACTUM (arXiv:2601.05866); also RAGAs grounding metric
 */
class GroundingVerifierService
{
    /** Max atomic claims to extract per response */
    public const MAX_CLAIMS = 6;

    /** Max source documents used per NLI check */
    public const MAX_DOCS_FOR_NLI = 3;

    /** Max chars per document excerpt in the NLI prompt */
    public const MAX_DOC_CHARS = 700;

    /** Grounding score at or above which verdict = 'grounded' */
    public const THRESHOLD_GROUNDED = 0.75;

    /** Grounding score at or above which verdict = 'partially_grounded' */
    public const THRESHOLD_PARTIAL = 0.40;

    private AIService $ai;

    public function __construct(AIService $ai)
    {
        $this->ai = $ai;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Verify that the response claims are grounded in the retrieved documents.
     *
     * @param  string $response   The LLM-generated answer to verify
     * @param  array  $documents  RAGService full_documents array (stdClass objects with ->content)
     * @return array{
     *   claims: array,
     *   grounding_score: float,
     *   ungrounded_claims: string[],
     *   contradicted_claims: string[],
     *   verdict: string,
     *   claim_count: int,
     *   doc_count: int
     * }
     */
    public function verify(string $response, array $documents): array
    {
        $empty = [
            'claims'              => [],
            'grounding_score'     => 0.0,
            'ungrounded_claims'   => [],
            'contradicted_claims' => [],
            'verdict'             => 'ungrounded',
            'claim_count'         => 0,
            'doc_count'           => count($documents),
        ];

        if (empty(trim($response)) || empty($documents)) {
            return $empty;
        }

        // Step 1: extract atomic claims
        $claims = $this->extractClaims($response);
        if (empty($claims)) {
            return array_merge($empty, ['verdict' => 'no_claims']);
        }

        // Step 2: NLI each claim against top source docs
        $topDocs       = array_slice($documents, 0, self::MAX_DOCS_FOR_NLI);
        $results       = [];
        $entailedCount = 0;
        $ungrounded    = [];
        $contradicted  = [];

        foreach ($claims as $claim) {
            $nli = $this->checkGrounding($claim, $topDocs);

            if ($nli['label'] === 'entailed') {
                $entailedCount++;
            } elseif ($nli['label'] === 'contradicted') {
                $contradicted[] = $claim;
            } else {
                $ungrounded[] = $claim;
            }

            $results[] = [
                'claim'           => $claim,
                'label'           => $nli['label'],
                'score'           => $nli['score'],
                'evidence_doc_id' => $nli['evidence_doc_id'] ?? null,
            ];
        }

        $groundingScore = count($claims) > 0
            ? round($entailedCount / count($claims), 3)
            : 0.0;

        $verdict = $this->determineVerdict($groundingScore);

        Log::info('GroundingVerifierService: verification complete', [
            'claim_count'     => count($claims),
            'entailed'        => $entailedCount,
            'ungrounded'      => count($ungrounded),
            'contradicted'    => count($contradicted),
            'grounding_score' => $groundingScore,
            'verdict'         => $verdict,
        ]);

        return [
            'claims'              => $results,
            'grounding_score'     => $groundingScore,
            'ungrounded_claims'   => $ungrounded,
            'contradicted_claims' => $contradicted,
            'verdict'             => $verdict,
            'claim_count'         => count($claims),
            'doc_count'           => count($documents),
        ];
    }

    // =========================================================================
    // Claim extraction
    // =========================================================================

    /**
     * Extract up to MAX_CLAIMS atomic factual claims from the response.
     * Returns a flat array of claim strings.
     */
    public function extractClaims(string $response): array
    {
        $truncated = mb_substr($response, 0, 2000);

        $prompt = "Extract up to " . self::MAX_CLAIMS . " specific, verifiable factual claims "
            . "from the following text. Ignore opinions, hedges, and general statements.\n\n"
            . "Output ONLY a JSON array of claim strings — no explanation, no numbering.\n"
            . "Example: [\"The census was conducted in 1880.\", \"John Smith lived in Ohio.\"]\n\n"
            . "TEXT:\n{$truncated}";

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 400,
            'temperature'    => 0.1,
            'expect_json'    => true,
            'task_type'      => 'grounding_claim_extraction',
            'model_role'     => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            Log::warning('GroundingVerifierService: claim extraction failed', [
                'error' => $result['error'] ?? 'unknown',
            ]);
            return [];
        }

        $raw = trim($result['response'] ?? '');

        // Strip markdown fences
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $claims = json_decode($raw, true);
        if (!is_array($claims)) {
            Log::warning('GroundingVerifierService: claim JSON parse failed', [
                'raw' => substr($raw, 0, 200),
            ]);
            return [];
        }

        return array_values(array_filter(
            array_slice($claims, 0, self::MAX_CLAIMS),
            fn($c) => is_string($c) && mb_strlen(trim($c)) >= 10
        ));
    }

    // =========================================================================
    // NLI grounding check
    // =========================================================================

    /**
     * Check whether a single claim is entailed, neutral, or contradicted
     * by the provided source documents.
     *
     * @param  string   $claim
     * @param  object[] $documents  stdClass objects with ->id and ->content
     * @return array{label: string, score: float, evidence_doc_id: int|null}
     */
    public function checkGrounding(string $claim, array $documents): array
    {
        if (empty($documents)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        // Build evidence block from top docs
        $evidenceParts = [];
        foreach (array_slice($documents, 0, self::MAX_DOCS_FOR_NLI) as $idx => $doc) {
            $snippet        = mb_substr($doc->content ?? '', 0, self::MAX_DOC_CHARS);
            $title          = $doc->title ?? "Document " . ($idx + 1);
            $evidenceParts[] = "[Doc {$idx}] {$title}\n{$snippet}";
        }
        $evidence = implode("\n\n", $evidenceParts);

        $prompt = "Classify the relationship between the CLAIM and the SOURCES.\n\n"
            . "CLAIM: \"{$claim}\"\n\n"
            . "SOURCES:\n{$evidence}\n\n"
            . "Classification rules:\n"
            . "- entailed: at least one source directly confirms or strongly supports the claim\n"
            . "- contradicted: at least one source directly denies the claim\n"
            . "- neutral: no source confirms or denies the claim\n\n"
            . "Output ONLY valid JSON (no markdown):\n"
            . "{\"label\": \"entailed|contradicted|neutral\", \"score\": 0.0-1.0, \"doc_index\": 0-2_or_null}";

        $result = $this->ai->process($prompt, [
            'max_tokens'     => 120,
            'temperature'    => 0.0,
            'expect_json'    => true,
            'task_type'      => 'grounding_nli_check',
            'model_role'     => 'fast',
            'suppress_alert' => true,
        ]);

        if (!($result['success'] ?? false)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        $raw = trim($result['response'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (!is_array($parsed)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        $label = $parsed['label'] ?? 'neutral';
        if (!in_array($label, ['entailed', 'contradicted', 'neutral'], true)) {
            $label = 'neutral';
        }

        // Map doc_index back to real document ID
        $docIndex     = isset($parsed['doc_index']) && is_int($parsed['doc_index']) ? $parsed['doc_index'] : null;
        $evidenceDocId = ($docIndex !== null && isset($documents[$docIndex]))
            ? ($documents[$docIndex]->id ?? null)
            : null;

        return [
            'label'           => $label,
            'score'           => (float) ($parsed['score'] ?? 0.5),
            'evidence_doc_id' => $evidenceDocId,
        ];
    }

    // =========================================================================
    // Verdict
    // =========================================================================

    /**
     * Classify the overall grounding score into a human-readable verdict.
     */
    public function determineVerdict(float $groundingScore): string
    {
        if ($groundingScore >= self::THRESHOLD_GROUNDED) {
            return 'grounded';
        }

        if ($groundingScore >= self::THRESHOLD_PARTIAL) {
            return 'partially_grounded';
        }

        return 'ungrounded';
    }
}
