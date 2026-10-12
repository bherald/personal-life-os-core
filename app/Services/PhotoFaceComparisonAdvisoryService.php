<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Files\PhotoReviewEvidenceService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bounded, source-pinned local comparison of two existing face vectors. */
class PhotoFaceComparisonAdvisoryService
{
    public function __construct(private FaceEmbeddingService $faces, private PhotoReviewEvidenceService $evidence) {}

    /** Read-only plan. The digest binds both current sources, regions, vectors, and producer proofs. */
    public function planLocalFaceComparison(int $sourceEmbeddingId, int $candidateEmbeddingId): array
    {
        try {
            $pair = $this->currentPair($sourceEmbeddingId, $candidateEmbeddingId);

            return ['success' => true, 'mode' => 'dry_run', 'detector_run' => false,
                'source_embedding_id' => $sourceEmbeddingId, 'candidate_embedding_id' => $candidateEmbeddingId,
                'source_pin' => $pair['pins'][0], 'candidate_pin' => $pair['pins'][1],
                'provenance_sha256' => [$pair['provenance_sha256'][0], $pair['provenance_sha256'][1]],
                'compatible_model_contract_sha256' => $pair['contract_sha256'],
                'expected_input_digest' => $pair['digest'], 'confirmation_required' => true];
        } catch (\Throwable $error) {
            return ['success' => false, 'mode' => 'dry_run', 'eligible' => false, 'error_code' => $this->errorCode($error)];
        }
    }

    /** Execute only through the registered, confirmed CLI framework action. */
    public function executeLocalFaceComparison(int $sourceEmbeddingId, int $candidateEmbeddingId, string $expectedInputDigest): array
    {
        $started = microtime(true);
        $helperAdvice = [];
        try {
            $this->assertFrameworkCaller();
            if ($sourceEmbeddingId <= 0 || $candidateEmbeddingId <= 0 || $sourceEmbeddingId === $candidateEmbeddingId
                || ! preg_match('/^[a-f0-9]{64}$/D', $expectedInputDigest)) {
                throw new RuntimeException('comparison_request_invalid');
            }
            $before = $this->currentPair($sourceEmbeddingId, $candidateEmbeddingId);
            if (! hash_equals($before['digest'], $expectedInputDigest)) {
                throw new RuntimeException('comparison_input_changed');
            }

            $helperAdvice = $this->faces->compareSourceFaceRegionsWithAI(
                $before['source_file_id'], $before['source_path'], $before['pins'][0]['source_sha256'],
                $before['pins'][0]['region'], $before['candidate_row'], $before['source_vector'],
                $before['pins'][0]['embedding_model'],
            );

            // Re-read all pins after inference. A changed source or vector must not produce a durable receipt.
            try {
                $after = $this->currentPair($sourceEmbeddingId, $candidateEmbeddingId);
            } catch (\Throwable) {
                throw new RuntimeException('comparison_input_changed');
            }
            if (! hash_equals($before['digest'], $after['digest'])) {
                throw new RuntimeException('comparison_input_changed');
            }
            if (! isset($this->diagnostics($helperAdvice)['route_trace_id'])) {
                return $this->heldResult($helperAdvice, $started, $this->helperErrorCode($helperAdvice));
            }
            $advice = $this->boundedAdvice($helperAdvice, $sourceEmbeddingId, $candidateEmbeddingId, $before['pins']);
            $receipt = $this->evidence->recordFaceComparison($before['source_file_id'], $sourceEmbeddingId, $advice);
            if (($receipt['success'] ?? false) !== true || ! is_string($receipt['advice_id'] ?? null)) {
                throw new RuntimeException('advisory_recording_held');
            }

            $completed = $advice['success'] && $advice['decision'] !== 'abstain';
            $state = $advice['success'] ? ($advice['decision'] === 'abstain' ? 'abstained' : 'recorded') : 'diagnostic_recorded';

            return ['success' => $completed, 'state' => $state, 'inference_completed' => $advice['success'],
                'decision_available' => $completed,
                'model_response_received' => $advice['success'], 'advice_id' => $receipt['advice_id'],
                'replayed' => (bool) ($receipt['replayed'] ?? false), 'expected_input_digest' => $before['digest'],
                'source_embedding_id' => $sourceEmbeddingId, 'candidate_embedding_id' => $candidateEmbeddingId,
                'route_trace_id' => $advice['route_trace_id'] ?? null, 'decision' => $advice['decision'],
                'same_person' => $advice['same_person'], 'confidence' => $advice['confidence'],
                'operator_review_required' => true, ...$this->diagnostics($advice),
                'duration_ms' => $advice['duration_ms'] ?? (int) ((microtime(true) - $started) * 1000)];
        } catch (\Throwable $error) {
            return $this->heldResult($helperAdvice, $started, $this->errorCode($error));
        }
    }

    private function currentPair(int $sourceId, int $candidateId): array
    {
        if ($sourceId <= 0 || $candidateId <= 0 || $sourceId === $candidateId) {
            throw new RuntimeException('comparison_request_invalid');
        }
        $rows = [];
        $pins = [];
        $provenanceHashes = [];
        $sourcePathHashes = [];
        $contract = [];
        foreach ([$sourceId, $candidateId] as $id) {
            $evidence = $this->faces->comparisonEmbeddingContractEvidence($id);
            $row = $evidence['row'];
            $pin = $evidence['pin'];
            $provenance = $evidence['generation_provenance'];
            $contract[] = $evidence['contract'];
            $file = DB::table('file_registry')->where('id', (int) $row->file_registry_id)->where('status', 'active')->first(['id', 'current_path']);
            if (! $file || ! is_string($file->current_path) || $file->current_path === '') {
                throw new RuntimeException('comparison_source_unavailable');
            }
            $localPath = app(NextcloudFileApiService::class)->localPath($file->current_path);
            $resolvedPath = is_string($localPath) ? realpath($localPath) : false;
            if (! is_string($resolvedPath) || ! is_file($resolvedPath) || ! is_readable($resolvedPath)) {
                throw new RuntimeException('comparison_source_unavailable');
            }
            $rows[] = ['row' => $row, 'file' => $file, 'local_path' => $resolvedPath];
            $pins[] = $pin;
            $provenanceHashes[] = hash('sha256', $this->canonicalJson($provenance));
            $sourcePathHashes[] = hash('sha256', $file->current_path."\0".$resolvedPath);
        }
        if ($contract[0] !== $contract[1]) {
            throw new RuntimeException('comparison_model_contract_incompatible');
        }
        $binding = ['schema' => 'plos.local_face_comparison.v1', 'source_embedding_id' => $sourceId,
            'candidate_embedding_id' => $candidateId, 'pins' => $pins, 'provenance_sha256' => $provenanceHashes,
            'source_path_sha256' => $sourcePathHashes,
            'contract' => $contract[0]];

        return ['pins' => $pins, 'provenance_sha256' => $provenanceHashes,
            'contract_sha256' => hash('sha256', $this->canonicalJson($contract[0])),
            'digest' => hash('sha256', $this->canonicalJson($binding)), 'source_file_id' => (int) $rows[0]['file']->id,
            'source_path' => $rows[0]['local_path'], 'source_vector' => is_string($rows[0]['row']->embedding)
                ? json_decode($rows[0]['row']->embedding, true, 128, JSON_THROW_ON_ERROR) : $rows[0]['row']->embedding,
            'candidate_row' => $this->candidateArguments($rows[1]['row'])];
    }

    private function candidateArguments(object $row): array
    {
        return ['face_id' => (int) $row->id, 'person_cluster_id' => $row->person_cluster_id === null ? null : (int) $row->person_cluster_id, 'file_registry_face_id' => (int) $row->file_registry_face_id,
            'file_registry_id' => (int) $row->file_registry_id, 'source_sha256' => $row->source_sha256,
            'face_region_convention' => $row->face_region_convention, 'source_current' => $row->source_current,
            'superseded_at' => $row->superseded_at, 'region_x' => $row->region_x, 'region_y' => $row->region_y,
            'region_w' => $row->region_w, 'region_h' => $row->region_h];
    }

    private function boundedAdvice(array $advice, int $sourceId, int $candidateId, array $pins): array
    {
        $suppliedPins = $advice['source_evidence'] ?? null;
        if (! is_array($suppliedPins) || ! array_is_list($suppliedPins) || count($suppliedPins) !== 2
            || $this->canonicalJson($suppliedPins) !== $this->canonicalJson($pins)) {
            throw new RuntimeException('comparison_source_evidence_changed');
        }
        $same = $advice['same_person'] ?? null;
        $confidence = $advice['confidence'] ?? null;
        $success = ($advice['success'] ?? false) === true
            && (is_bool($same) || $same === null)
            && (is_int($confidence) || is_float($confidence)) && is_finite((float) $confidence)
            && $confidence >= 0 && $confidence <= 1;
        $same = $success ? $same : null;
        $confidence = $success ? (float) $confidence : null;
        $result = ['success' => $success, 'decision' => ! $success || $same === null ? 'abstain' : ($same ? 'same_person' : 'different_person'),
            'same_person' => $same, 'confidence' => $confidence, 'operator_review_required' => true,
            'candidate_embedding_id' => $candidateId, 'source_evidence' => $suppliedPins,
            'crop_sha256' => ($success && is_array($advice['crop_sha256'] ?? null)) ? $advice['crop_sha256'] : [],
            'source_embedding_id' => $sourceId];
        foreach ($this->diagnostics($advice) as $key => $value) {
            $result[$key] = $value;
        }
        $error = $advice['error_code'] ?? null;
        $result['error_code'] = is_string($error) && preg_match('/^[a-z_]{1,80}$/D', $error) ? $error : null;

        return $result;
    }

    private function diagnostics(array $advice): array
    {
        $diagnostics = [];
        $trace = $advice['route_trace_id'] ?? null;
        if (is_string($trace) && preg_match('/^[a-f0-9]{32}$/D', $trace)) {
            $diagnostics['route_trace_id'] = $trace;
        }
        if (($advice['request_kind'] ?? null) === 'vision') {
            $diagnostics['request_kind'] = 'vision';
        }
        if (is_int($advice['duration_ms'] ?? null)) {
            $diagnostics['duration_ms'] = max(0, min(86_400_000, $advice['duration_ms']));
        }
        if (is_bool($advice['fallback_suppressed'] ?? null)) {
            $diagnostics['fallback_suppressed'] = $advice['fallback_suppressed'];
        }
        foreach (['provider', 'model'] as $field) {
            $value = $advice[$field] ?? null;
            if (is_string($value) && strlen($value) <= 128 && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\/+\-]{0,127}\z/', $value)) {
                $diagnostics[$field] = $value;
            }
        }
        if (is_array($advice['attempts'] ?? null)) {
            $attempts = [];
            $allowed = ['circuit_open', 'busy_or_locked', 'timeout', 'model_not_advertised', 'admission_failed',
                'privacy_fallback_disabled', 'provider_unavailable', 'unknown'];
            foreach (array_slice($advice['attempts'], 0, 8, true) as $name => $outcome) {
                if (is_string($name) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}\z/', $name)
                    && is_string($outcome) && in_array($outcome, $allowed, true)) {
                    $attempts[$name] = $outcome;
                }
            }
            $diagnostics['attempts'] = $attempts;
        }
        if (is_array($advice['controller_failure'] ?? null)) {
            $diagnostics['controller_failure'] = InferenceAdmissionException::sanitizeControllerFailure($advice['controller_failure']);
        }

        return $diagnostics;
    }

    private function heldResult(array $advice, float $started, string $errorCode): array
    {
        $diagnostics = $this->diagnostics($advice);
        $diagnostics['duration_ms'] ??= max(0, min(86_400_000, (int) ((microtime(true) - $started) * 1000)));

        return ['success' => false, 'state' => 'held', 'error_code' => $errorCode, 'operator_review_required' => true,
            'inference_completed' => false, ...$diagnostics];
    }

    private function helperErrorCode(array $advice): string
    {
        $error = $advice['error_code'] ?? null;

        return is_string($error) && preg_match('/^[a-z_]{1,80}$/D', $error) ? $error : 'comparison_inference_not_started';
    }

    private function assertFrameworkCaller(): void
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $scope = CliWorkflowExecutionScope::current();
        if (! is_array($caller) || count($caller) !== 5
            || array_diff(array_keys($caller), ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256']) !== []) {
            throw new RuntimeException('trusted_framework_caller_required');
        }
        if (! is_array($scope) || ($scope['settings']['schema'] ?? null) !== CliWorkflowExecutionScope::SCHEMA) {
            throw new RuntimeException('framework_scope_invalid');
        }
        foreach (['session_id', 'turn_id', 'action_id'] as $field) {
            if (! is_string($caller[$field] ?? null) || $caller[$field] === '' || strlen($caller[$field]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$field])) {
                throw new RuntimeException('framework_caller_invalid');
            }
        }
        foreach (['arguments_sha256', 'approval_scope_sha256'] as $field) {
            if (! is_string($caller[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $caller[$field])) {
                throw new RuntimeException('framework_caller_invalid');
            }
        }
    }

    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function errorCode(\Throwable $error): string
    {
        $safe = ['comparison_request_invalid', 'comparison_source_unavailable', 'comparison_model_proof_unavailable',
            'comparison_model_contract_incompatible', 'comparison_input_changed', 'trusted_framework_caller_required',
            'comparison_source_changed', 'comparison_source_evidence_changed',
            'framework_caller_invalid', 'framework_scope_invalid', 'advisory_recording_held'];

        return in_array($error->getMessage(), $safe, true) ? $error->getMessage() : 'comparison_unavailable';
    }
}
