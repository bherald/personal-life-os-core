<?php

namespace App\Services;

use App\Support\SensitiveTextTelemetry;
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
    private const MODEL_RESPONSE_TELEMETRY_SCOPE = 'rag-grounding-verifier-model-response';

    public function __construct(
        private readonly AIService $ai,
        private readonly GroundingVerifierRuntimePolicyService $runtimePolicy,
    ) {}

    // =========================================================================
    // Main entry point
    // =========================================================================

    /**
     * Verify that the response claims are grounded in the retrieved documents.
     *
     * @param  string  $response  The LLM-generated answer to verify
     * @param  array  $documents  RAGService full_documents array (stdClass objects with ->content)
     * @return array{
     *   claims: array,
     *   grounding_score: float,
     *   ungrounded_claims: string[],
     *   contradicted_claims: string[],
     *   verdict: string,
     *   claim_count: int,
     *   doc_count: int,
     *   policy_source: string,
     *   effective_policy: array<string,mixed>
     * }
     */
    public function verify(string $response, array $documents): array
    {
        $policy = $this->runtimePolicy->required();
        $metadata = $this->policyMetadata($policy);
        $empty = [
            'claims' => [],
            'grounding_score' => 0.0,
            'ungrounded_claims' => [],
            'contradicted_claims' => [],
            'verdict' => 'ungrounded',
            'claim_count' => 0,
            'doc_count' => count($documents),
            ...$metadata,
        ];

        if (empty(trim($response)) || empty($documents)) {
            return $empty;
        }

        // Step 1: extract atomic claims
        $claims = $this->extractClaimsWithPolicy($response, $policy);
        if (empty($claims)) {
            return array_merge($empty, ['verdict' => 'no_claims']);
        }

        // Step 2: NLI each claim against top source docs
        $topDocs = array_slice($documents, 0, $policy['max_source_documents']);
        $results = [];
        $entailedCount = 0;
        $ungrounded = [];
        $contradicted = [];

        foreach ($claims as $claim) {
            $nli = $this->checkGroundingWithPolicy($claim, $topDocs, $policy);

            if ($nli['label'] === 'entailed') {
                $entailedCount++;
            } elseif ($nli['label'] === 'contradicted') {
                $contradicted[] = $claim;
            } else {
                $ungrounded[] = $claim;
            }

            $results[] = [
                'claim' => $claim,
                'label' => $nli['label'],
                'score' => $nli['score'],
                'evidence_doc_id' => $nli['evidence_doc_id'] ?? null,
            ];
        }

        $groundingScore = count($claims) > 0
            ? round($entailedCount / count($claims), 3)
            : 0.0;

        $verdict = $this->determineVerdictWithPolicy($groundingScore, $policy);

        Log::info('GroundingVerifierService: verification complete', [
            'claim_count' => count($claims),
            'entailed' => $entailedCount,
            'ungrounded' => count($ungrounded),
            'contradicted' => count($contradicted),
            'grounding_score' => $groundingScore,
            'verdict' => $verdict,
        ]);

        return [
            'claims' => $results,
            'grounding_score' => $groundingScore,
            'ungrounded_claims' => $ungrounded,
            'contradicted_claims' => $contradicted,
            'verdict' => $verdict,
            'claim_count' => count($claims),
            'doc_count' => count($documents),
            ...$metadata,
        ];
    }

    // =========================================================================
    // Claim extraction
    // =========================================================================

    /**
     * Extract up to the table-owned maximum atomic factual claims from the response.
     * Returns a flat array of claim strings.
     */
    public function extractClaims(string $response): array
    {
        return $this->extractClaimsWithPolicy($response, $this->runtimePolicy->required());
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return string[]
     */
    private function extractClaimsWithPolicy(string $response, array $policy): array
    {
        $truncated = mb_substr($response, 0, $policy['response_max_chars']);

        $prompt = "Extract up to {$policy['max_claims']} specific, verifiable factual claims "
            ."from the following text. Ignore opinions, hedges, and general statements.\n\n"
            ."Output ONLY a JSON array of claim strings — no explanation, no numbering.\n"
            ."Example: [\"The census was conducted in 1880.\", \"John Smith lived in Ohio.\"]\n\n"
            ."TEXT:\n{$truncated}";

        $result = $this->ai->process($prompt, [
            'max_tokens' => $policy['claim_extractor']['max_tokens'],
            'temperature' => $policy['claim_extractor']['temperature'],
            'expect_json' => true,
            'task_type' => 'grounding_claim_extraction',
            'model_role' => $policy['claim_extractor']['model_role'],
            'suppress_alert' => true,
        ]);

        if (! ($result['success'] ?? false)) {
            Log::warning('GroundingVerifierService: claim extraction failed', [
                'model_error_logged' => false,
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
        if (! is_array($claims)) {
            Log::warning('GroundingVerifierService: claim JSON parse failed', [
                ...SensitiveTextTelemetry::content($raw, self::MODEL_RESPONSE_TELEMETRY_SCOPE, 'model_response'),
            ]);

            return [];
        }

        return array_values(array_filter(
            array_slice($claims, 0, $policy['max_claims']),
            fn ($c) => is_string($c) && mb_strlen(trim($c)) >= $policy['minimum_claim_chars']
        ));
    }

    // =========================================================================
    // NLI grounding check
    // =========================================================================

    /**
     * Check whether a single claim is entailed, neutral, or contradicted
     * by the provided source documents.
     *
     * @param  object[]  $documents  stdClass objects with ->id and ->content
     * @return array{label: string, score: float, evidence_doc_id: int|null}
     */
    public function checkGrounding(string $claim, array $documents): array
    {
        return $this->checkGroundingWithPolicy($claim, $documents, $this->runtimePolicy->required());
    }

    /**
     * @param  object[]  $documents
     * @param  array<string,mixed>  $policy
     * @return array{label: string, score: float, evidence_doc_id: int|null}
     */
    private function checkGroundingWithPolicy(string $claim, array $documents, array $policy): array
    {
        if (empty($documents)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        // Build evidence block from top docs
        $evidenceParts = [];
        $documents = array_slice($documents, 0, $policy['max_source_documents']);
        foreach ($documents as $idx => $doc) {
            $snippet = mb_substr($doc->content ?? '', 0, $policy['document_max_chars']);
            $title = $doc->title ?? 'Document '.($idx + 1);
            $evidenceParts[] = "[Doc {$idx}] {$title}\n{$snippet}";
        }
        $evidence = implode("\n\n", $evidenceParts);

        $prompt = "Classify the relationship between the CLAIM and the SOURCES.\n\n"
            ."CLAIM: \"{$claim}\"\n\n"
            ."SOURCES:\n{$evidence}\n\n"
            ."Classification rules:\n"
            ."- entailed: at least one source directly confirms or strongly supports the claim\n"
            ."- contradicted: at least one source directly denies the claim\n"
            ."- neutral: no source confirms or denies the claim\n\n"
            ."Output ONLY valid JSON (no markdown):\n"
            .'{"label": "entailed|contradicted|neutral", "score": 0.0-1.0, "doc_index": 0-'
            .($policy['max_source_documents'] - 1).'_or_null}';

        $result = $this->ai->process($prompt, [
            'max_tokens' => $policy['nli']['max_tokens'],
            'temperature' => $policy['nli']['temperature'],
            'expect_json' => true,
            'task_type' => 'grounding_nli_check',
            'model_role' => $policy['nli']['model_role'],
            'suppress_alert' => true,
        ]);

        if (! ($result['success'] ?? false)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        $raw = trim($result['response'] ?? '');
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-z]*\n?/i', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            return ['label' => 'neutral', 'score' => 0.0, 'evidence_doc_id' => null];
        }

        $label = $parsed['label'] ?? 'neutral';
        if (! in_array($label, ['entailed', 'contradicted', 'neutral'], true)) {
            $label = 'neutral';
        }

        // Map doc_index back to real document ID
        $docIndex = isset($parsed['doc_index']) && is_int($parsed['doc_index']) ? $parsed['doc_index'] : null;
        $evidenceDocId = ($docIndex !== null && isset($documents[$docIndex]))
            ? ($documents[$docIndex]->id ?? null)
            : null;

        return [
            'label' => $label,
            'score' => is_numeric($parsed['score'] ?? null)
                ? max(0.0, min(1.0, (float) $parsed['score']))
                : $policy['neutral_score'],
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
        return $this->determineVerdictWithPolicy($groundingScore, $this->runtimePolicy->required());
    }

    /** @param array<string,mixed> $policy */
    private function determineVerdictWithPolicy(float $groundingScore, array $policy): string
    {
        if ($groundingScore >= $policy['grounded_threshold']) {
            return 'grounded';
        }

        if ($groundingScore >= $policy['partial_threshold']) {
            return 'partially_grounded';
        }

        return 'ungrounded';
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array{policy_source:string,effective_policy:array<string,mixed>}
     */
    private function policyMetadata(array $policy): array
    {
        return [
            'policy_source' => 'system_configs.'.GroundingVerifierRuntimePolicyService::CONFIG_KEY,
            'effective_policy' => $policy,
        ];
    }
}
