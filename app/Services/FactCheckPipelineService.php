<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;
use App\Traits\RecursionAware;

/**
 * Fact-Check Pipeline Service
 *
 * Orchestrates the complete fact-checking pipeline based on Loki/OpenFactVerification architecture:
 * 1. ClaimDecomposition - Extract atomic, verifiable claims from text
 * 2. CheckWorthiness - Score claims for verification priority
 * 3. EvidenceRetrieval - Search for supporting/contradicting evidence
 * 4. NLI Ranking - Classify evidence using Natural Language Inference
 * 5. Verdict - Aggregate evidence into final verdicts with confidence scores
 *
 * Features:
 * - Configurable pipeline stages (enable/disable individual stages)
 * - Threshold-based filtering at each stage
 * - Result aggregation with confidence scoring
 * - Batch processing support
 * - Comprehensive statistics and timing data
 *
 * Uses raw SQL per project standards - NO Eloquent/Query Builder
 * PostgreSQL connection 'pgsql_rag' for fact-check tables
 */
class FactCheckPipelineService
{
    use RecursionAware;

    private ClaimDecompositionService $decompositionService;
    private ClaimVerificationService $verificationService;

    /** @var array Default pipeline configuration */
    private const DEFAULT_CONFIG = [
        'stages' => [
            'decomposition' => true,
            'checkworthiness' => true,
            'evidence_retrieval' => true,
            'nli_ranking' => true,
            'verdict' => true,
        ],
        'thresholds' => [
            'checkworthiness_min' => 0.5,      // Minimum checkworthiness to proceed
            'evidence_confidence_min' => 0.3,   // Minimum evidence confidence
            'verdict_confidence_min' => 0.4,    // Minimum verdict confidence for inclusion
        ],
        'limits' => [
            'max_claims_per_document' => 50,    // N82: overridden at runtime from config/factcheck.php
            'max_evidence_per_claim' => 10,
            'query_count' => 3,
        ],
        'persist' => true,                      // Save results to database
        'parallel_verification' => false,       // Process claims sequentially by default
        'consensus_verification' => false,      // FC-1: Multi-LLM consensus (2-3 providers + devil's advocate)
    ];

    public function __construct(
        ClaimDecompositionService $decompositionService,
        ClaimVerificationService $verificationService
    ) {
        $this->decompositionService = $decompositionService;
        $this->verificationService = $verificationService;
    }

    /**
     * Run the complete fact-check pipeline on text content
     *
     * @param string $text Source text to fact-check
     * @param array $options Pipeline configuration overrides
     * @return array Pipeline results with claims, verdicts, and statistics
     */
    public function run(string $text, array $options = []): array
    {
        // RLM: Try recursive fact-check decomposition
        $rlm = $this->tryRecursive('factcheck_pipeline', 'partition_map', ['text' => $text, 'options' => $options], function ($ctx) {
            return $this->run($ctx['text'] ?? $ctx['data'], $ctx['options'] ?? []);
        });
        if ($rlm !== null) {
            return is_array($rlm) && isset($rlm[0]) ? end($rlm) : $rlm;
        }

        $startTime = microtime(true);
        $config = $this->mergeConfig($options);
        $pipelineId = $this->generatePipelineId();

        $result = [
            'pipeline_id' => $pipelineId,
            'success' => false,
            'stages_completed' => [],
            'claims' => [],
            'verdicts' => [],
            'aggregated_result' => null,
            'statistics' => [
                'total_duration_ms' => 0,
                'stage_timings' => [],
                'claim_count' => 0,
                'verified_count' => 0,
                'supported_count' => 0,
                'refuted_count' => 0,
                'inconclusive_count' => 0,
            ],
            'config' => $config,
        ];

        try {
            // Persist pipeline run start
            if ($config['persist']) {
                $this->persistPipelineStart($pipelineId, $text, $config);
            }

            // Stage 1: Claim Decomposition
            if ($config['stages']['decomposition']) {
                $stageStart = microtime(true);

                $decompositionResult = $this->decompositionService->decompose($text, [
                    'persist' => $config['persist'],
                    'checkworthiness_threshold' => $config['stages']['checkworthiness']
                        ? $config['thresholds']['checkworthiness_min']
                        : 0.0, // If checkworthiness stage disabled, accept all
                ]);

                $result['statistics']['stage_timings']['decomposition'] = round((microtime(true) - $stageStart) * 1000);
                $result['stages_completed'][] = 'decomposition';

                if (!$decompositionResult['success']) {
                    throw new Exception('Claim decomposition failed: ' . ($decompositionResult['error'] ?? 'Unknown error'));
                }

                $result['claims'] = $decompositionResult['claims'];
                $result['statistics']['claim_count'] = count($result['claims']);
                $result['statistics']['decomposition_stats'] = $decompositionResult['stats'] ?? [];

                Log::info('FactCheckPipeline: Decomposition complete', [
                    'pipeline_id' => $pipelineId,
                    'claims' => count($result['claims']),
                    'duration_ms' => $result['statistics']['stage_timings']['decomposition'],
                ]);
            }

            // Early exit if no claims to verify
            if (empty($result['claims'])) {
                $result['success'] = true;
                $result['aggregated_result'] = $this->createEmptyAggregation();
                $result['statistics']['total_duration_ms'] = round((microtime(true) - $startTime) * 1000);

                if ($config['persist']) {
                    $this->persistPipelineComplete($pipelineId, $result);
                }

                return $result;
            }

            // Apply claim limit
            if (count($result['claims']) > $config['limits']['max_claims_per_document']) {
                // Sort by checkworthiness and take top N
                usort($result['claims'], function ($a, $b) {
                    return ($b['checkworthiness_score'] ?? 0) <=> ($a['checkworthiness_score'] ?? 0);
                });
                $result['claims'] = array_slice($result['claims'], 0, $config['limits']['max_claims_per_document']);
            }

            // Stage 2: Checkworthiness filtering (already done in decomposition if threshold > 0)
            if ($config['stages']['checkworthiness']) {
                $stageStart = microtime(true);

                $result['claims'] = array_filter($result['claims'], function ($claim) use ($config) {
                    return ($claim['checkworthiness_score'] ?? 0) >= $config['thresholds']['checkworthiness_min'];
                });
                $result['claims'] = array_values($result['claims']); // Re-index

                $result['statistics']['stage_timings']['checkworthiness'] = round((microtime(true) - $stageStart) * 1000);
                $result['stages_completed'][] = 'checkworthiness';
                $result['statistics']['checkworthy_claims'] = count($result['claims']);

                Log::info('FactCheckPipeline: Checkworthiness filter complete', [
                    'pipeline_id' => $pipelineId,
                    'claims_after_filter' => count($result['claims']),
                ]);
            }

            // Stages 3-5: Evidence Retrieval, NLI Ranking, and Verdict
            if (!empty($result['claims']) && ($config['stages']['evidence_retrieval'] || $config['stages']['nli_ranking'] || $config['stages']['verdict'])) {
                $stageStart = microtime(true);

                // Prepare claims for verification service
                $claimsToVerify = array_map(function ($claim) {
                    return [
                        'id' => $claim['id'] ?? null,
                        'normalized_claim' => $claim['normalized_claim'] ?? $claim['claim'] ?? '',
                    ];
                }, $result['claims']);

                $verificationResult = $this->verificationService->verify($claimsToVerify, [
                    'persist' => $config['persist'],
                    'query_count' => $config['limits']['query_count'],
                    'max_evidence' => $config['limits']['max_evidence_per_claim'],
                    'consensus_verification' => $config['consensus_verification'],
                ]);

                // Record stage timings
                $totalVerificationTime = round((microtime(true) - $stageStart) * 1000);
                if ($config['stages']['evidence_retrieval']) {
                    $result['statistics']['stage_timings']['evidence_retrieval'] = intval($totalVerificationTime * 0.4);
                    $result['stages_completed'][] = 'evidence_retrieval';
                }
                if ($config['stages']['nli_ranking']) {
                    $result['statistics']['stage_timings']['nli_ranking'] = intval($totalVerificationTime * 0.4);
                    $result['stages_completed'][] = 'nli_ranking';
                }
                if ($config['stages']['verdict']) {
                    $result['statistics']['stage_timings']['verdict'] = intval($totalVerificationTime * 0.2);
                    $result['stages_completed'][] = 'verdict';
                }

                $result['verdicts'] = $verificationResult['results'] ?? [];
                $result['statistics']['verified_count'] = count($result['verdicts']);
                $result['statistics']['evidence_collected'] = $verificationResult['stats']['evidence_collected'] ?? 0;

                // Count verdict types
                foreach ($result['verdicts'] as $verdict) {
                    $verdictType = $verdict['verdict'] ?? 'inconclusive';
                    switch ($verdictType) {
                        case 'supported':
                            $result['statistics']['supported_count']++;
                            break;
                        case 'refuted':
                            $result['statistics']['refuted_count']++;
                            break;
                        default:
                            $result['statistics']['inconclusive_count']++;
                    }
                }

                Log::info('FactCheckPipeline: Verification complete', [
                    'pipeline_id' => $pipelineId,
                    'verified' => count($result['verdicts']),
                    'supported' => $result['statistics']['supported_count'],
                    'refuted' => $result['statistics']['refuted_count'],
                    'duration_ms' => $totalVerificationTime,
                ]);
            }

            // Filter verdicts by confidence threshold
            if (!empty($result['verdicts'])) {
                $result['verdicts'] = array_filter($result['verdicts'], function ($v) use ($config) {
                    return ($v['confidence'] ?? 0) >= $config['thresholds']['verdict_confidence_min'];
                });
                $result['verdicts'] = array_values($result['verdicts']);
            }

            // Aggregate results
            $result['aggregated_result'] = $this->aggregateResults($result['claims'], $result['verdicts']);

            $result['success'] = true;
            $result['statistics']['total_duration_ms'] = round((microtime(true) - $startTime) * 1000);

            // Persist completion
            if ($config['persist']) {
                $this->persistPipelineComplete($pipelineId, $result);
            }

            Log::info('FactCheckPipeline: Pipeline complete', [
                'pipeline_id' => $pipelineId,
                'success' => true,
                'overall_factuality' => $result['aggregated_result']['overall_factuality_score'] ?? null,
                'duration_ms' => $result['statistics']['total_duration_ms'],
            ]);

        } catch (Exception $e) {
            $result['success'] = false;
            $result['error'] = $e->getMessage();
            $result['statistics']['total_duration_ms'] = round((microtime(true) - $startTime) * 1000);

            Log::error('FactCheckPipeline: Pipeline failed', [
                'pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
                'stages_completed' => $result['stages_completed'],
            ]);

            if ($config['persist']) {
                $this->persistPipelineError($pipelineId, $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Run pipeline on a specific claim (bypass decomposition)
     *
     * @param string $claim The claim to verify
     * @param array $options Pipeline configuration
     * @return array Verification result
     */
    public function verifyClaim(string $claim, array $options = []): array
    {
        $config = $this->mergeConfig($options);
        $config['stages']['decomposition'] = false; // Bypass decomposition

        $startTime = microtime(true);
        $pipelineId = $this->generatePipelineId();

        // Assess checkworthiness
        $checkworthiness = $this->decompositionService->assessCheckworthiness([
            'claim' => $claim,
            'entities' => [],
        ]);

        $claimData = [
            'normalized_claim' => $claim,
            'checkworthiness_score' => $checkworthiness['score'],
            'checkworthiness_factors' => $checkworthiness['factors'],
        ];

        // Verify
        $verificationResult = $this->verificationService->verify([$claimData], [
            'persist' => $config['persist'],
            'query_count' => $config['limits']['query_count'],
            'max_evidence' => $config['limits']['max_evidence_per_claim'],
            'consensus_verification' => $config['consensus_verification'],
        ]);

        $verdict = $verificationResult['results'][0] ?? null;

        return [
            'pipeline_id' => $pipelineId,
            'success' => $verificationResult['success'] ?? false,
            'claim' => $claim,
            'checkworthiness' => $checkworthiness,
            'verdict' => $verdict['verdict'] ?? 'inconclusive',
            'confidence' => $verdict['confidence'] ?? 0,
            'factuality_score' => $verdict['factuality_score'] ?? null,
            'evidence_count' => $verdict['evidence_count'] ?? 0,
            'supporting_count' => $verdict['supporting_count'] ?? 0,
            'contradicting_count' => $verdict['contradicting_count'] ?? 0,
            'evidence_summary' => $verdict['evidence_summary'] ?? '',
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Batch verify multiple claims
     *
     * @param array $claims Array of claim strings
     * @param array $options Pipeline configuration
     * @return array Batch verification results
     */
    public function verifyBatch(array $claims, array $options = []): array
    {
        $config = $this->mergeConfig($options);
        $startTime = microtime(true);
        $results = [];

        foreach ($claims as $claim) {
            if (is_string($claim) && !empty(trim($claim))) {
                $results[] = $this->verifyClaim($claim, $config);
            }
        }

        return [
            'success' => true,
            'results' => $results,
            'total_claims' => count($claims),
            'verified_count' => count($results),
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
        ];
    }

    /**
     * Get pipeline run history
     *
     * @param int $limit Maximum records to return
     * @param string|null $status Filter by status
     * @return array Pipeline runs
     */
    public function getHistory(int $limit = 50, ?string $status = null): array
    {
        try {
            $sql = "
                SELECT pipeline_id, status, source_title,
                       claim_count, supported_count, refuted_count, inconclusive_count,
                       overall_factuality_score, duration_ms, created_at, completed_at
                FROM fact_check_pipeline_runs
            ";

            $params = [];
            if ($status !== null) {
                $sql .= " WHERE status = ?";
                $params[] = $status;
            }

            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;

            return DB::connection('pgsql_rag')->select($sql, $params);
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: fact_check_pipeline_runs table not available', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get detailed pipeline run by ID
     *
     * @param string $pipelineId Pipeline run ID
     * @return array|null Pipeline details with claims and verdicts
     */
    public function getRunDetails(string $pipelineId): ?array
    {
        try {
            $run = DB::connection('pgsql_rag')->select("
                SELECT * FROM fact_check_pipeline_runs WHERE pipeline_id = ?
            ", [$pipelineId]);

            if (empty($run)) {
                return null;
            }

            $run = (array) $run[0];

            // Get associated claims — claims table uses source_document_id, not pipeline_id
            $run['claims'] = DB::connection('pgsql_rag')->select("
                SELECT c.*, v.verdict, v.confidence, v.factuality_score, v.evidence_summary
                FROM claims c
                LEFT JOIN verdicts v ON v.claim_id = c.id
                WHERE c.source_document_id = ?
                ORDER BY c.checkworthiness_score DESC
            ", [$pipelineId]);

            return $run;
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: fact_check_pipeline_runs table not available', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Aggregate results from claims and verdicts
     *
     * @param array $claims Decomposed claims
     * @param array $verdicts Verification verdicts
     * @return array Aggregated result
     */
    private function aggregateResults(array $claims, array $verdicts): array
    {
        if (empty($verdicts)) {
            return $this->createEmptyAggregation();
        }

        $supported = 0;
        $refuted = 0;
        $inconclusive = 0;
        $totalConfidence = 0;
        $totalFactuality = 0;
        $factualityCount = 0;

        foreach ($verdicts as $verdict) {
            $totalConfidence += $verdict['confidence'] ?? 0;

            if (isset($verdict['factuality_score']) && $verdict['factuality_score'] !== null) {
                $totalFactuality += $verdict['factuality_score'];
                $factualityCount++;
            }

            // FC-8: Map 5-class verdicts to support/refute/inconclusive counts
            // for aggregation. Legacy 'supported'/'refuted' also handled.
            switch ($verdict['verdict'] ?? 'inconclusive') {
                case 'true':
                case 'mostly_true':
                case 'supported': // legacy
                    $supported++;
                    break;
                case 'false':
                case 'mostly_false':
                case 'refuted': // legacy
                    $refuted++;
                    break;
                case 'half_true':
                default:
                    $inconclusive++;
            }
        }

        $totalVerified = count($verdicts);
        $decisive = $supported + $refuted;

        // Overall factuality: weighted average of individual factuality scores
        $overallFactuality = $factualityCount > 0
            ? round($totalFactuality / $factualityCount, 3)
            : null;

        // Confidence in the overall assessment
        $overallConfidence = $totalVerified > 0
            ? round($totalConfidence / $totalVerified, 3)
            : 0;

        // Decisiveness: how many claims had clear verdicts
        $decisiveness = $totalVerified > 0
            ? round($decisive / $totalVerified, 3)
            : 0;

        // Overall verdict
        $overallVerdict = 'inconclusive';
        if ($decisive >= 2) {
            if ($supported > $refuted && $supported >= $totalVerified * 0.5) {
                $overallVerdict = 'mostly_supported';
            } elseif ($refuted > $supported && $refuted >= $totalVerified * 0.5) {
                $overallVerdict = 'mostly_refuted';
            } elseif ($supported > 0 && $refuted > 0) {
                $overallVerdict = 'mixed';
            }
        } elseif ($supported > 0 && $refuted === 0) {
            $overallVerdict = 'supported';
        } elseif ($refuted > 0 && $supported === 0) {
            $overallVerdict = 'refuted';
        }

        return [
            'overall_verdict' => $overallVerdict,
            'overall_factuality_score' => $overallFactuality,
            'overall_confidence' => $overallConfidence,
            'decisiveness' => $decisiveness,
            'total_claims' => count($claims),
            'verified_claims' => $totalVerified,
            'supported_count' => $supported,
            'refuted_count' => $refuted,
            'inconclusive_count' => $inconclusive,
            'verdict_distribution' => [
                'supported' => $totalVerified > 0 ? round($supported / $totalVerified, 3) : 0,
                'refuted' => $totalVerified > 0 ? round($refuted / $totalVerified, 3) : 0,
                'inconclusive' => $totalVerified > 0 ? round($inconclusive / $totalVerified, 3) : 0,
            ],
        ];
    }

    /**
     * Create empty aggregation for documents with no claims
     *
     * @return array Empty aggregation structure
     */
    private function createEmptyAggregation(): array
    {
        return [
            'overall_verdict' => 'no_claims',
            'overall_factuality_score' => null,
            'overall_confidence' => 0,
            'decisiveness' => 0,
            'total_claims' => 0,
            'verified_claims' => 0,
            'supported_count' => 0,
            'refuted_count' => 0,
            'inconclusive_count' => 0,
            'verdict_distribution' => [
                'supported' => 0,
                'refuted' => 0,
                'inconclusive' => 0,
            ],
        ];
    }

    /**
     * Merge user options with default configuration
     *
     * @param array $options User-provided options
     * @return array Merged configuration
     */
    private function mergeConfig(array $options): array
    {
        $config = self::DEFAULT_CONFIG;

        // N82: apply config/factcheck.php overrides to defaults
        $config['limits']['max_claims_per_document'] = config('factcheck.max_claims', $config['limits']['max_claims_per_document']);
        $config['limits']['max_evidence_per_claim']  = config('factcheck.max_evidence', $config['limits']['max_evidence_per_claim']);
        $config['limits']['query_count']             = config('factcheck.query_count', $config['limits']['query_count']);

        // Merge stages
        if (isset($options['stages']) && is_array($options['stages'])) {
            $config['stages'] = array_merge($config['stages'], $options['stages']);
        }

        // Merge thresholds
        if (isset($options['thresholds']) && is_array($options['thresholds'])) {
            $config['thresholds'] = array_merge($config['thresholds'], $options['thresholds']);
        }

        // Merge limits
        if (isset($options['limits']) && is_array($options['limits'])) {
            $config['limits'] = array_merge($config['limits'], $options['limits']);
        }

        // Top-level options
        if (isset($options['persist'])) {
            $config['persist'] = (bool) $options['persist'];
        }
        if (isset($options['parallel_verification'])) {
            $config['parallel_verification'] = (bool) $options['parallel_verification'];
        }
        if (isset($options['consensus_verification'])) {
            $config['consensus_verification'] = (bool) $options['consensus_verification'];
        }

        return $config;
    }

    /**
     * Generate unique pipeline ID
     *
     * @return string Pipeline ID
     */
    private function generatePipelineId(): string
    {
        return 'fcp_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Persist pipeline run start
     *
     * @param string $pipelineId Pipeline ID
     * @param string $text Source text
     * @param array $config Pipeline configuration
     */
    private function persistPipelineStart(string $pipelineId, string $text, array $config): void
    {
        try {
            DB::connection('pgsql_rag')->insert("
                INSERT INTO fact_check_pipeline_runs (
                    pipeline_id, status, source_title, metadata, created_at
                ) VALUES (?, 'running', ?, ?::jsonb, CURRENT_TIMESTAMP)
            ", [
                $pipelineId,
                substr($text, 0, 500),
                json_encode($config),
            ]);
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: Failed to persist start', [
                'pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist pipeline completion
     *
     * @param string $pipelineId Pipeline ID
     * @param array $result Pipeline result
     */
    private function persistPipelineComplete(string $pipelineId, array $result): void
    {
        try {
            $agg = $result['aggregated_result'] ?? [];

            DB::connection('pgsql_rag')->update("
                UPDATE fact_check_pipeline_runs SET
                    status = 'completed',
                    claim_count = ?,
                    supported_count = ?,
                    refuted_count = ?,
                    inconclusive_count = ?,
                    overall_factuality_score = ?,
                    duration_ms = ?,
                    metadata = ?::jsonb,
                    completed_at = CURRENT_TIMESTAMP
                WHERE pipeline_id = ?
            ", [
                $agg['total_claims'] ?? 0,
                $agg['supported_count'] ?? 0,
                $agg['refuted_count'] ?? 0,
                $agg['inconclusive_count'] ?? 0,
                $agg['overall_factuality_score'],
                $result['statistics']['total_duration_ms'] ?? 0,
                json_encode([
                    'overall_confidence' => $agg['overall_confidence'] ?? 0,
                    'overall_verdict' => $agg['overall_verdict'] ?? 'inconclusive',
                    'statistics' => $result['statistics'] ?? [],
                ]),
                $pipelineId,
            ]);
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: Failed to persist completion', [
                'pipeline_id' => $pipelineId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist pipeline error
     *
     * @param string $pipelineId Pipeline ID
     * @param string $error Error message
     */
    private function persistPipelineError(string $pipelineId, string $error): void
    {
        try {
            DB::connection('pgsql_rag')->update("
                UPDATE fact_check_pipeline_runs SET
                    status = 'failed',
                    error_message = ?,
                    completed_at = CURRENT_TIMESTAMP
                WHERE pipeline_id = ?
            ", [$error, $pipelineId]);
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: Failed to persist error', [
                'pipeline_id' => $pipelineId,
                'persist_error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get pipeline statistics summary
     *
     * @param int $days Number of days to look back
     * @return array Statistics summary
     */
    public function getStatistics(int $days = 30): array
    {
        try {
            $result = DB::connection('pgsql_rag')->select("
                SELECT
                    COUNT(*) as total_runs,
                    COUNT(*) FILTER (WHERE status = 'completed') as completed_runs,
                    COUNT(*) FILTER (WHERE status = 'failed') as failed_runs,
                    AVG(duration_ms) FILTER (WHERE status = 'completed') as avg_duration_ms,
                    SUM(claim_count) as total_claims_processed,
                    SUM(supported_count) as total_supported,
                    SUM(refuted_count) as total_refuted,
                    SUM(inconclusive_count) as total_inconclusive,
                    AVG(overall_factuality_score) FILTER (WHERE overall_factuality_score IS NOT NULL) as avg_factuality
                FROM fact_check_pipeline_runs
                WHERE created_at >= NOW() - INTERVAL '1 day' * ?
            ", [$days]);

            return !empty($result) ? (array) $result[0] : [];
        } catch (Exception $e) {
            Log::warning('FactCheckPipeline: fact_check_pipeline_runs table not available', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get default configuration
     *
     * @return array Default pipeline configuration
     */
    public function getDefaultConfig(): array
    {
        return self::DEFAULT_CONFIG;
    }

    /**
     * Validate pipeline configuration
     *
     * @param array $config Configuration to validate
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Validate thresholds
        if (isset($config['thresholds'])) {
            foreach (['checkworthiness_min', 'evidence_confidence_min', 'verdict_confidence_min'] as $threshold) {
                if (isset($config['thresholds'][$threshold])) {
                    $value = $config['thresholds'][$threshold];
                    if (!is_numeric($value) || $value < 0 || $value > 1) {
                        $errors[] = "Threshold '{$threshold}' must be between 0 and 1";
                    }
                }
            }
        }

        // Validate limits
        if (isset($config['limits'])) {
            foreach (['max_claims_per_document', 'max_evidence_per_claim', 'query_count'] as $limit) {
                if (isset($config['limits'][$limit])) {
                    $value = $config['limits'][$limit];
                    if (!is_int($value) || $value < 1) {
                        $errors[] = "Limit '{$limit}' must be a positive integer";
                    }
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }
}
