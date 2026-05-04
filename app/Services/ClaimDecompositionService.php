<?php

namespace App\Services;

use App\Traits\RecursionAware;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Claim Decomposition Service
 *
 * Four-stage pipeline for extracting atomic, verifiable claims from text:
 * 1. splitWithContext - Sentence splitting preserving surrounding context
 * 2. isVerifiable - AI check if claim is verifiable (not opinion/speculation)
 * 3. disambiguate - Resolve pronouns and ambiguous references
 * 4. extractAtomicClaims - Break compound claims into atomic facts
 *
 * Based on: Microsoft Claimify + Loki patterns
 * Reference: research-synthesis-feb2026.md §5
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 */
class ClaimDecompositionService
{
    use RecursionAware;

    private AIService $aiService;

    /** @var float Minimum score for a claim to be considered checkworthy */
    private const CHECKWORTHINESS_THRESHOLD = 0.5;

    /** @var int Maximum words for an atomic claim */
    private const MAX_CLAIM_WORDS = 25;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Decompose text into atomic, verifiable claims
     *
     * @param  string  $text  Source text to decompose
     * @param  array  $options  Options:
     *                          - source_document_id: int - Link claims to source document
     *                          - persist: bool - Save claims to database (default: true)
     *                          - checkworthiness_threshold: float - Override default threshold
     * @return array Decomposed claims with metadata
     */
    public function decompose(string $text, array $options = []): array
    {
        // RLM: Try recursive claim decomposition. Recursive sub-calls must use
        // the normal path so an enabled runtime config cannot self-reenter.
        if (! ($options['disable_recursion'] ?? false)) {
            $rlm = $this->tryRecursive('claim_decomposition', 'partition_map', ['text' => $text, 'options' => $options], function ($ctx) {
                $subOptions = $ctx['options'] ?? [];
                $subOptions['disable_recursion'] = true;

                return $this->decompose($ctx['text'] ?? $ctx['data'], $subOptions);
            });
            if ($rlm !== null) {
                return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
            }
        }

        $startTime = microtime(true);
        $persist = $options['persist'] ?? true;
        $sourceDocId = $options['source_document_id'] ?? null;
        $threshold = $options['checkworthiness_threshold'] ?? self::CHECKWORTHINESS_THRESHOLD;

        $claims = [];
        $stats = [
            'total_sentences' => 0,
            'verifiable_sentences' => 0,
            'disambiguated' => 0,
            'atomic_claims' => 0,
            'checkworthy_claims' => 0,
            'stage_timings' => [],
        ];

        try {
            // Stage 1: Split text into sentences with context
            $stageStart = microtime(true);
            $sentences = $this->splitWithContext($text);
            $stats['stage_timings']['split'] = round((microtime(true) - $stageStart) * 1000);
            $stats['total_sentences'] = count($sentences);

            Log::info('ClaimDecomposition: Stage 1 complete', [
                'sentences' => count($sentences),
                'duration_ms' => $stats['stage_timings']['split'],
            ]);

            foreach ($sentences as $sentence) {
                // Stage 2: Check if sentence contains verifiable claims
                $stageStart = microtime(true);
                $verifiability = $this->isVerifiable($sentence);

                if (! $verifiability['verifiable']) {
                    continue;
                }
                $stats['verifiable_sentences']++;

                // Stage 3: Disambiguate pronouns and references
                $stageStart = microtime(true);
                $disambiguated = $this->disambiguate($sentence);

                if ($disambiguated === null) {
                    Log::debug('ClaimDecomposition: Could not disambiguate', [
                        'sentence' => substr($sentence['text'], 0, 100),
                    ]);

                    continue;
                }
                $stats['disambiguated']++;

                // Stage 4: Extract atomic claims
                $stageStart = microtime(true);
                $atomicClaims = $this->extractAtomicClaims($disambiguated);

                foreach ($atomicClaims as $atomic) {
                    // Calculate checkworthiness for each atomic claim
                    $checkworthiness = $this->assessCheckworthiness($atomic);
                    $stats['atomic_claims']++;

                    $claimData = [
                        'source_text' => $sentence['text'],
                        'normalized_claim' => $atomic['claim'],
                        'checkworthiness_score' => $checkworthiness['score'],
                        'entities' => json_encode($atomic['entities'] ?? []),
                        'source_document_id' => $sourceDocId,
                        'decomposition_context' => json_encode([
                            'original_context' => $sentence['context'] ?? '',
                            'verifiability_reason' => $verifiability['reason'] ?? '',
                            'disambiguation_applied' => $sentence['text'] !== $disambiguated,
                        ]),
                    ];

                    // Only include claims above checkworthiness threshold
                    if ($checkworthiness['score'] >= $threshold) {
                        $stats['checkworthy_claims']++;

                        if ($persist) {
                            $claimData['id'] = $this->persistClaim($claimData);
                        }

                        $claims[] = $claimData;
                    }
                }
            }

            $stats['stage_timings']['total'] = round((microtime(true) - $startTime) * 1000);

            Log::info('ClaimDecomposition: Pipeline complete', [
                'claims_extracted' => count($claims),
                'checkworthy' => $stats['checkworthy_claims'],
                'duration_ms' => $stats['stage_timings']['total'],
            ]);

            return [
                'success' => true,
                'claims' => $claims,
                'stats' => $stats,
            ];

        } catch (Exception $e) {
            Log::error('ClaimDecomposition: Pipeline failed', [
                'error' => $e->getMessage(),
                'text_length' => strlen($text),
            ]);

            return [
                'success' => false,
                'claims' => [],
                'error' => $e->getMessage(),
                'stats' => $stats,
            ];
        }
    }

    /**
     * Stage 1: Split text into sentences preserving context
     *
     * @param  string  $text  Source text
     * @return array Array of ['text' => sentence, 'context' => surrounding text]
     */
    public function splitWithContext(string $text): array
    {
        // Clean and normalize text
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Split on sentence boundaries (period, question mark, exclamation)
        // Preserves abbreviations like "Dr.", "Mr.", "U.S."
        $pattern = '/(?<=[.!?])\s+(?=[A-Z])/';
        $rawSentences = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        $sentences = [];
        $total = count($rawSentences);

        foreach ($rawSentences as $i => $sentence) {
            $sentence = trim($sentence);

            // Skip very short sentences (likely fragments)
            if (strlen($sentence) < 20) {
                continue;
            }

            // Build context from surrounding sentences
            $contextBefore = $i > 0 ? $rawSentences[$i - 1] : '';
            $contextAfter = $i < $total - 1 ? $rawSentences[$i + 1] : '';

            $sentences[] = [
                'text' => $sentence,
                'context' => trim($contextBefore.' [CURRENT] '.$contextAfter),
                'position' => $i,
                'total_sentences' => $total,
            ];
        }

        return $sentences;
    }

    /**
     * Stage 2: Check if a sentence contains verifiable factual claims
     *
     * @param  array  $sentence  Sentence with context
     * @return array ['verifiable' => bool, 'reason' => string]
     */
    public function isVerifiable(array $sentence): array
    {
        $prompt = <<<PROMPT
Analyze if this sentence contains verifiable factual claims:

Sentence: "{$sentence['text']}"

Context: {$sentence['context']}

VERIFIABLE claims describe:
- Specific events, dates, or statistics
- Measurable facts that can be checked against records
- Attributable statements (who said what, when)
- Scientific or historical facts

NOT VERIFIABLE:
- Opinions or subjective judgments
- Predictions or speculation about the future
- Rhetorical questions
- Personal feelings or preferences
- Fiction or hypotheticals

Output ONLY valid JSON (no markdown, no explanation):
{"verifiable": true/false, "reason": "brief explanation"}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 150,
        ]);

        if (! $result['success']) {
            // On AI failure, assume not verifiable to avoid noise
            return ['verifiable' => false, 'reason' => 'AI analysis failed'];
        }

        $parsed = $this->parseJsonResponse($result['response']);

        return [
            'verifiable' => $parsed['verifiable'] ?? false,
            'reason' => $parsed['reason'] ?? 'Unknown',
        ];
    }

    /**
     * Stage 3: Disambiguate pronouns and ambiguous references
     *
     * @param  array  $sentence  Sentence with context
     * @return string|null Disambiguated sentence, or null if impossible
     */
    public function disambiguate(array $sentence): ?string
    {
        $text = $sentence['text'];

        // Quick check: if no pronouns or ambiguous references, return as-is
        if (! preg_match('/\b(he|she|they|it|this|that|these|those|the company|the organization|the government)\b/i', $text)) {
            return $text;
        }

        $prompt = <<<PROMPT
Rewrite this sentence to replace ALL pronouns and ambiguous references with their specific referents.

Original: "{$text}"

Context: {$sentence['context']}

Rules:
1. Replace pronouns (he, she, they, it) with specific names/entities
2. Replace "this", "that", "these" with what they refer to
3. Replace vague terms like "the company" with the actual company name
4. If you CANNOT determine what a reference means, output: {"success": false}
5. Keep the meaning EXACTLY the same - only resolve references

Output ONLY valid JSON (no markdown):
{"success": true, "disambiguated": "the rewritten sentence"}
or
{"success": false}
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 300,
        ]);

        if (! $result['success']) {
            return $text; // Return original on AI failure
        }

        $parsed = $this->parseJsonResponse($result['response']);

        if (! ($parsed['success'] ?? false)) {
            return null; // Could not disambiguate
        }

        return $parsed['disambiguated'] ?? $text;
    }

    /**
     * Stage 4: Extract atomic claims from a sentence
     *
     * @param  string  $text  Disambiguated sentence
     * @return array Array of ['claim' => string, 'entities' => array]
     */
    public function extractAtomicClaims(string $text): array
    {
        $prompt = <<<PROMPT
Extract atomic, independently verifiable claims from this sentence:

"{$text}"

Rules:
1. Each claim must be SELF-CONTAINED (understandable without additional context)
2. Each claim must describe a SINGLE fact (not compound statements)
3. Claims should be under 25 words
4. Preserve critical context: dates, names, quantities, locations
5. Extract named entities for each claim

Output ONLY valid JSON array (no markdown):
[
  {"claim": "specific atomic claim", "entities": [{"text": "Name", "type": "PERSON|ORG|DATE|LOC|NUMBER"}]},
  ...
]

If the sentence contains only ONE atomic fact, return a single-element array.
PROMPT;

        $result = $this->aiService->process($prompt, [
            'factual_mode' => true,
            'max_tokens' => 500,
        ]);

        if (! $result['success']) {
            // Fallback: return original as single claim
            return [['claim' => $text, 'entities' => []]];
        }

        $parsed = $this->parseJsonResponse($result['response'], true);

        if (empty($parsed) || ! is_array($parsed)) {
            return [['claim' => $text, 'entities' => []]];
        }

        // Validate and clean extracted claims
        $claims = [];
        foreach ($parsed as $item) {
            if (empty($item['claim'])) {
                continue;
            }

            $claim = trim($item['claim']);
            $wordCount = str_word_count($claim);

            // Skip claims that are too short or too long
            if ($wordCount < 3 || $wordCount > self::MAX_CLAIM_WORDS) {
                continue;
            }

            $claims[] = [
                'claim' => $claim,
                'entities' => $item['entities'] ?? [],
            ];
        }

        return ! empty($claims) ? $claims : [['claim' => $text, 'entities' => []]];
    }

    /**
     * Assess how checkworthy a claim is (worth fact-checking)
     *
     * @param  array  $atomic  Atomic claim with entities
     * @return array ['score' => float 0-1, 'factors' => array]
     */
    /**
     * FC-5: Enhanced check-worthiness scoring.
     * Scores claims for verification priority, filtering opinions, tautologies,
     * and unfalsifiable statements. Based on CheckThat! 2025 patterns.
     */
    public function assessCheckworthiness(array $atomic): array
    {
        $claim = $atomic['claim'];
        $entities = $atomic['entities'] ?? [];
        $claimLower = strtolower($claim);

        $score = 0.5; // Base score
        $factors = [];

        // === POSITIVE FACTORS (increase check-worthiness) ===

        // Contains numbers/statistics (+0.15)
        if (preg_match('/\d+/', $claim)) {
            $score += 0.15;
            $factors[] = 'contains_numbers';
        }

        // Contains dates (+0.1)
        if (preg_match('/\b(19|20)\d{2}\b|\b(january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $claim)) {
            $score += 0.1;
            $factors[] = 'contains_dates';
        }

        // Contains named entities (+0.1 per entity, max +0.2)
        $entityBonus = min(0.2, count($entities) * 0.1);
        if ($entityBonus > 0) {
            $score += $entityBonus;
            $factors[] = 'has_entities';
        }

        // Contains strong assertion words (+0.1)
        if (preg_match('/\b(always|never|all|none|every|no one|everyone|only|first|largest|smallest|most|least)\b/i', $claim)) {
            $score += 0.1;
            $factors[] = 'strong_assertions';
        }

        // Longer claims tend to be more specific (+0.05)
        if (str_word_count($claim) >= 10) {
            $score += 0.05;
            $factors[] = 'detailed_claim';
        }

        // Causal or comparative claims are high-value (+0.1)
        if (preg_match('/\b(caused|because|due to|leads to|results in|more than|less than|compared to|increased|decreased|doubled|tripled)\b/i', $claim)) {
            $score += 0.1;
            $factors[] = 'causal_comparative';
        }

        // === NEGATIVE FACTORS (reduce check-worthiness) ===

        // Hedging language (-0.2)
        if (preg_match('/\b(might|maybe|possibly|could be|some say|allegedly|reportedly|appears to|seems to|tend to|generally)\b/i', $claim)) {
            $score -= 0.2;
            $factors[] = 'hedging_language';
        }

        // Opinion indicators (-0.3)
        if (preg_match('/\b(i think|i believe|in my opinion|in my view|personally|should|must|ought to|we need to|it.s (important|crucial|vital))\b/i', $claim)) {
            $score -= 0.3;
            $factors[] = 'opinion_language';
        }

        // Tautology detection (-0.4) — circular/unfalsifiable claims
        if ($this->isTautology($claimLower)) {
            $score -= 0.4;
            $factors[] = 'tautology';
        }

        // Vague/unfalsifiable claims (-0.25) — no concrete referent
        if (preg_match('/\b(many people|some experts|studies show|research suggests|it is (known|said|believed)|they say|sources say)\b/i', $claim)
            && count($entities) === 0) {
            $score -= 0.25;
            $factors[] = 'vague_attribution';
        }

        // Questions are not claims (-0.5)
        if (str_ends_with(trim($claim), '?')) {
            $score -= 0.5;
            $factors[] = 'question_not_claim';
        }

        // Imperative/command sentences (-0.3)
        if (preg_match('/^(do|don.t|stop|start|try|make sure|remember|note that|consider)\b/i', trim($claim))) {
            $score -= 0.3;
            $factors[] = 'imperative';
        }

        // Very short claims lack substance (-0.15)
        if (str_word_count($claim) <= 3) {
            $score -= 0.15;
            $factors[] = 'too_brief';
        }

        return [
            'score' => max(0, min(1, round($score, 3))),
            'factors' => $factors,
        ];
    }

    /**
     * Detect tautological claims — true by definition, unfalsifiable.
     */
    private function isTautology(string $claimLower): bool
    {
        // "X is X" pattern
        if (preg_match('/^(.+?)\s+is\s+\1$/i', trim($claimLower))) {
            return true;
        }

        // "All X are X" pattern
        if (preg_match('/^all\s+(.+?)\s+are\s+\1$/i', trim($claimLower))) {
            return true;
        }

        // Common tautological phrases
        $tautologies = [
            'it is what it is',
            'by definition',
            'goes without saying',
            'needless to say',
            'obviously true',
            'self-evident',
            'common knowledge that',
            'everyone knows that',
        ];

        foreach ($tautologies as $phrase) {
            if (str_contains($claimLower, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Persist a claim to the database
     *
     * @param  array  $claimData  Claim data to persist
     * @return int Inserted claim ID
     */
    private function persistClaim(array $claimData): int
    {
        $result = DB::connection('pgsql_rag')->select('
            INSERT INTO claims (
                source_text, normalized_claim, checkworthiness_score,
                entities, source_document_id, decomposition_context, created_at
            ) VALUES (?, ?, ?, ?::jsonb, ?, ?::jsonb, CURRENT_TIMESTAMP)
            RETURNING id
        ', [
            $claimData['source_text'],
            $claimData['normalized_claim'],
            $claimData['checkworthiness_score'],
            $claimData['entities'],
            $claimData['source_document_id'],
            $claimData['decomposition_context'],
        ]);

        return $result[0]->id ?? 0;
    }

    /**
     * Get claims by source document
     *
     * @param  int  $documentId  Source document ID
     * @param  float|null  $minCheckworthiness  Minimum checkworthiness score
     * @return array Claims
     */
    public function getClaimsByDocument(int $documentId, ?float $minCheckworthiness = null): array
    {
        $threshold = $minCheckworthiness ?? self::CHECKWORTHINESS_THRESHOLD;

        return DB::connection('pgsql_rag')->select('
            SELECT id, source_text, normalized_claim, checkworthiness_score,
                   entities, decomposition_context, created_at
            FROM claims
            WHERE source_document_id = ?
              AND checkworthiness_score >= ?
            ORDER BY checkworthiness_score DESC
        ', [$documentId, $threshold]);
    }

    /**
     * Get unverified claims (claims without verdicts)
     *
     * @param  int  $limit  Maximum claims to return
     * @param  float|null  $minCheckworthiness  Minimum checkworthiness score
     * @return array Claims awaiting verification
     */
    public function getUnverifiedClaims(int $limit = 50, ?float $minCheckworthiness = null): array
    {
        $threshold = $minCheckworthiness ?? self::CHECKWORTHINESS_THRESHOLD;

        return DB::connection('pgsql_rag')->select('
            SELECT c.id, c.source_text, c.normalized_claim, c.checkworthiness_score,
                   c.entities, c.created_at
            FROM claims c
            LEFT JOIN verdicts v ON v.claim_id = c.id
            WHERE v.id IS NULL
              AND c.checkworthiness_score >= ?
            ORDER BY c.checkworthiness_score DESC, c.created_at ASC
            LIMIT ?
        ', [$threshold, $limit]);
    }

    /**
     * Parse JSON response from AI, handling common formatting issues
     *
     * @param  string  $response  AI response text
     * @param  bool  $expectArray  Whether to expect an array result
     * @return array Parsed JSON or empty array on failure
     */
    private function parseJsonResponse(string $response, bool $expectArray = false): array
    {
        // Remove markdown code blocks if present
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('ClaimDecomposition: JSON parse failed', [
                'error' => json_last_error_msg(),
                'response' => substr($response, 0, 200),
            ]);

            return $expectArray ? [] : ['verifiable' => false];
        }

        return $decoded ?? ($expectArray ? [] : []);
    }
}
