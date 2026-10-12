<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Bounded native-approval orchestration over existing per-asset receipts. */
class FaceGenerationRestorationBatchService
{
    private const SCHEMA = 'plos.anonymous_face_restoration_batch.v1';

    private const WORKER_RESERVE_SECONDS = 120;

    public function __construct(
        private readonly FaceEmbeddingService $faces,
        private readonly FaceGenerationReconciliationService $reconciliation,
        private readonly SystemConfigService $systemConfig,
        private readonly ?Closure $monotonicClock = null,
    ) {}

    /** Read-only plan. Every requested source must qualify; none are silently skipped. */
    public function plan(array $asset_uuids): array
    {
        try {
            $limit = $this->sourceLimit();
            if (! array_is_list($asset_uuids) || $limit < 1 || count($asset_uuids) < 1 || count($asset_uuids) > $limit) {
                throw new RuntimeException('restoration_batch_limit_invalid');
            }
            foreach ($asset_uuids as $assetUuid) {
                if (! is_string($assetUuid) || ! Str::isUuid($assetUuid)) {
                    throw new RuntimeException('restoration_batch_selector_invalid');
                }
            }
            $providedCount = count($asset_uuids);
            $asset_uuids = array_values(array_unique(array_map('strtolower', $asset_uuids)));
            if (count($asset_uuids) !== $providedCount) {
                throw new RuntimeException('restoration_batch_selector_invalid');
            }
            sort($asset_uuids, SORT_STRING);
            $items = [];
            foreach ($asset_uuids as $assetUuid) {
                $generation = $this->faces->planAnonymousFaceGeneration($assetUuid);
                if (($generation['hold_reason'] ?? null) === 'generation_tile_resource_limit') {
                    throw new RuntimeException('restoration_batch_generation_resource_limit');
                }
                if (in_array($generation['hold_reason'] ?? null, ['protected_named_face_present', 'protected_reviewed_face_present', 'automatic_assignment_custody_unresolved'], true)) {
                    throw new RuntimeException($generation['hold_reason']);
                }
                $requiredHold = 'prior_current_generation_reconciliation_required';
                if (! is_string($generation['source_sha256'] ?? null) || ! $this->hash($generation['source_sha256'])
                    || ! is_string($generation['binding_sha256'] ?? null) || ! $this->hash($generation['binding_sha256'])
                    || ! is_int($generation['file_registry_id'] ?? null) || $generation['file_registry_id'] < 1
                    || ! is_string($generation['generation_key'] ?? null) || ! $this->hash($generation['generation_key'])
                    || ! is_string($generation['detector_version'] ?? null) || ! $this->hash($generation['detector_version'])
                    || ! is_string($generation['coverage_preview_detector_version'] ?? null)
                    || ! $this->hash($generation['coverage_preview_detector_version'])
                    || ($generation['hold_reason'] ?? null) !== $requiredHold) {
                    throw new RuntimeException('restoration_batch_source_not_reconcilable');
                }
                $protection = $this->faces->scheduledCoveragePreviewHoldReason($generation['file_registry_id'], $generation['generation_key']);
                if ($protection !== null && $protection !== $requiredHold) {
                    throw new RuntimeException($protection);
                }
                $retirement = $this->reconciliation->plan($assetUuid);
                if (($retirement['success'] ?? false) !== true || ($retirement['eligible'] ?? false) !== true
                    || ! is_string($retirement['manifest_sha256'] ?? null) || ! $this->hash($retirement['manifest_sha256'])
                    || ! is_int($retirement['face_count'] ?? null) || $retirement['face_count'] < 1
                    || ! is_int($retirement['vector_count'] ?? null) || $retirement['vector_count'] < 0
                    || ($retirement['source_sha256'] ?? null) !== $generation['source_sha256']
                    || ($retirement['binding_sha256'] ?? null) !== $generation['binding_sha256']) {
                    throw new RuntimeException('restoration_batch_preimage_unverified');
                }
                $preview = $this->faces->findScheduledCoveragePreview($assetUuid, $generation['source_sha256'], $generation['coverage_preview_detector_version']);
                if ($preview === null || ($preview['source_sha256'] ?? null) !== $generation['source_sha256']
                    || ($preview['detector_version'] ?? null) !== $generation['coverage_preview_detector_version']
                    || ! is_int($preview['face_count'] ?? null) || $preview['face_count'] < $retirement['face_count']) {
                    throw new RuntimeException($preview === null ? 'restoration_batch_quality_preview_missing' : 'restoration_batch_group_count_drop');
                }
                $budget = $this->budgetComponents($retirement['face_count'], $retirement['vector_count'], $preview['face_count']);
                $items[] = ['asset_uuid' => $assetUuid, 'file_registry_id' => $generation['file_registry_id'],
                    'source_sha256' => $generation['source_sha256'], 'binding_sha256' => $generation['binding_sha256'],
                    'generation_key' => $generation['generation_key'],
                    'detector_version' => $generation['detector_version'],
                    'coverage_preview_detector_version' => $generation['coverage_preview_detector_version'],
                    'manifest_sha256' => $retirement['manifest_sha256'], 'old_face_count' => $retirement['face_count'],
                    'old_vector_count' => $retirement['vector_count'], 'preview_face_count' => $preview['face_count'],
                    ...$budget,
                    'reconciliation_idempotency_key' => (string) Str::uuid(),
                    'generation_idempotency_key' => (string) Str::uuid()];
            }
            $requiredBudget = array_sum(array_column($items, 'total_budget_seconds'));
            if ($requiredBudget > $this->executionBudgetSeconds()) {
                throw new RuntimeException('restoration_batch_budget_exceeded');
            }
            $body = ['schema' => self::SCHEMA, 'items' => $items, 'max_sources' => $limit,
                'success' => true, 'eligible' => true, 'mode' => 'dry_run', 'detector_run' => false,
                'source_originals_mutated' => false];

            return ['batch_plan_sha256' => $this->digest($body), ...$body];
        } catch (Throwable $error) {
            return ['success' => false, 'eligible' => false, 'mode' => 'dry_run', 'detector_run' => false,
                'error' => $this->errorCode($error)];
        }
    }

    /** Execute the exact readback plan only under the native worker's approved caller envelope. */
    public function execute(array $plan): array
    {
        $results = [];
        try {
            $this->assertApprovedPlan($plan);
            $limit = $this->sourceLimit();
            $items = $plan['items'];
            if (count($items) < 1 || count($items) > $limit || ($plan['max_sources'] ?? null) !== $limit) {
                throw new RuntimeException('restoration_batch_limit_changed');
            }
            $workBudget = $this->executionBudgetSeconds();
            $totalBudget = array_sum(array_column($items, 'total_budget_seconds'));
            if ($totalBudget > $workBudget) {
                throw new RuntimeException('restoration_batch_budget_exceeded');
            }
            $deadline = $this->nowSeconds() + $workBudget;
            $remainingPreflight = array_sum(array_column($items, 'preflight_budget_seconds'));
            $remainingWrites = array_sum(array_column($items, 'write_budget_seconds'));

            // Revalidate the entire approved cohort before the first mutation.
            $prepared = [];
            foreach ($items as $item) {
                if ($this->nowSeconds() + $remainingPreflight + $remainingWrites > $deadline) {
                    throw new RuntimeException('restoration_batch_deadline_before_write');
                }
                $prepared[] = $this->preflightItem($item);
                $remainingPreflight -= $item['preflight_budget_seconds'];
            }

            foreach ($prepared as $entry) {
                if ($this->nowSeconds() + $remainingWrites > $deadline) {
                    $results[] = $this->terminalItem($entry['item'], 'held', 'restoration_batch_deadline_reached');

                    return $this->batchResult($plan, $results, count($prepared) - count($results));
                }
                $result = $this->executeItem($entry, $deadline);
                $remainingWrites -= $entry['item']['write_budget_seconds'];
                $results[] = $result;
                if (! in_array($result['state'], ['complete', 'reused_complete'], true)) {
                    return $this->batchResult($plan, $results, count($prepared) - count($results));
                }
            }

            return $this->batchResult($plan, $results, 0);
        } catch (Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['success' => false, 'state' => 'held', 'reason' => $this->errorCode($error),
                'batch_plan_sha256' => $plan['batch_plan_sha256'] ?? null,
                'processed' => count($results), 'items' => $results, 'identity_decisions_performed' => false,
                'automatic_replay_allowed' => false];
        }
    }

    private function preflightItem(array $item): array
    {
        $this->validateItem($item);
        $generation = $this->faces->planAnonymousFaceGeneration($item['asset_uuid']);
        if (! is_string($generation['source_sha256'] ?? null) || $generation['source_sha256'] !== $item['source_sha256']
            || ! is_string($generation['binding_sha256'] ?? null) || $generation['binding_sha256'] !== $item['binding_sha256']
            || ($generation['file_registry_id'] ?? null) !== $item['file_registry_id']
            || ($generation['generation_key'] ?? null) !== $item['generation_key']
            || ! is_string($generation['detector_version'] ?? null) || $generation['detector_version'] !== $item['detector_version']
            || ($generation['coverage_preview_detector_version'] ?? null) !== $item['coverage_preview_detector_version']) {
            throw new RuntimeException('restoration_batch_source_binding_changed');
        }
        if (($generation['hold_reason'] ?? null) === 'generation_tile_resource_limit') {
            throw new RuntimeException('restoration_batch_generation_resource_limit');
        }
        $protection = $this->faces->scheduledCoveragePreviewHoldReason($generation['file_registry_id'], $generation['generation_key']);
        if ($protection !== null && $protection !== 'prior_current_generation_reconciliation_required') {
            throw new RuntimeException($protection);
        }
        $reconciliationRun = DB::table('file_registry_face_reconciliation_runs')
            ->where('idempotency_key', $item['reconciliation_idempotency_key'])->first();
        $reconciliationRun ??= DB::table('file_registry_face_reconciliation_runs')
            ->where('asset_uuid', $item['asset_uuid'])->where('manifest_sha256', $item['manifest_sha256'])->first();
        if ($reconciliationRun !== null) {
            if ($reconciliationRun->asset_uuid !== $item['asset_uuid'] || $reconciliationRun->source_sha256 !== $item['source_sha256']
                || (int) $reconciliationRun->file_registry_id !== $item['file_registry_id']
                || $reconciliationRun->binding_sha256 !== $item['binding_sha256'] || $reconciliationRun->manifest_sha256 !== $item['manifest_sha256']) {
                throw new RuntimeException('restoration_batch_child_receipt_conflict');
            }
            if ($reconciliationRun->stage !== 'complete') {
                throw new RuntimeException('restoration_batch_reconciliation_incomplete_no_replay');
            }
        } else {
            $retirement = $this->reconciliation->plan($item['asset_uuid']);
            if (($retirement['success'] ?? false) !== true || ($retirement['manifest_sha256'] ?? null) !== $item['manifest_sha256']
                || ($retirement['source_sha256'] ?? null) !== $item['source_sha256']
                || ($retirement['binding_sha256'] ?? null) !== $item['binding_sha256']
                || ($retirement['face_count'] ?? null) !== $item['old_face_count']
                || ($retirement['vector_count'] ?? null) !== $item['old_vector_count']) {
                throw new RuntimeException('restoration_batch_preimage_changed');
            }
        }
        $preview = $this->faces->findScheduledCoveragePreview($item['asset_uuid'], $item['source_sha256'], $item['coverage_preview_detector_version']);
        if ($preview === null || ($preview['source_sha256'] ?? null) !== $item['source_sha256']
            || ($preview['detector_version'] ?? null) !== $item['coverage_preview_detector_version']
            || ($preview['face_count'] ?? null) !== $item['preview_face_count']
            || $preview['face_count'] < $item['old_face_count']) {
            throw new RuntimeException('restoration_batch_quality_preview_changed');
        }
        $generationRun = DB::table('file_registry_face_generation_runs')->where('idempotency_key', $item['generation_idempotency_key'])->first();
        $generationKeyRun = DB::table('file_registry_face_generation_runs')->where('file_registry_id', $item['file_registry_id'])
            ->where('generation_key', $item['generation_key'])->first();
        if ($generationRun !== null && $generationKeyRun !== null && $generationRun->id !== $generationKeyRun->id) {
            throw new RuntimeException('restoration_batch_child_receipt_conflict');
        }
        $generationRun ??= $generationKeyRun;
        if ($generationRun !== null) {
            if ($generationRun->asset_uuid !== $item['asset_uuid'] || $generationRun->source_sha256 !== $item['source_sha256']
                || $generationRun->binding_sha256 !== $item['binding_sha256'] || (int) $generationRun->file_registry_id !== $item['file_registry_id']
                || $generationRun->generation_key !== $item['generation_key']
                || $generationRun->detector_version !== $item['detector_version']) {
                throw new RuntimeException('restoration_batch_child_receipt_conflict');
            }
            if ($generationRun->stage !== 'complete') {
                throw new RuntimeException('restoration_batch_generation_incomplete_no_replay');
            }
            if ((int) $generationRun->face_count < $item['old_face_count']) {
                throw new RuntimeException('restoration_batch_group_count_drop');
            }
        }

        return ['item' => $item, 'generation' => $generation, 'reconciliation_run' => $reconciliationRun,
            'generation_run' => $generationRun];
    }

    private function executeItem(array $entry, float $deadline): array
    {
        $item = $entry['item'];
        if ($entry['generation_run'] !== null && $entry['generation_run']->stage === 'complete') {
            return $this->terminalItem($item, 'reused_complete', null, (int) $entry['generation_run']->face_count,
                $entry['reconciliation_run']->run_uuid ?? null, $entry['generation_run']->run_uuid);
        }
        if ($entry['reconciliation_run'] === null) {
            if ($this->nowSeconds() + $item['retirement_budget_seconds'] + $item['generation_budget_seconds'] > $deadline) {
                return $this->terminalItem($item, 'held', 'restoration_batch_deadline_before_retirement');
            }
            $retirement = $this->reconciliation->execute($item['asset_uuid'], $item['source_sha256'], $item['binding_sha256'],
                $item['manifest_sha256'], $item['reconciliation_idempotency_key']);
            $receipt = DB::table('file_registry_face_reconciliation_runs')->where('idempotency_key', $item['reconciliation_idempotency_key'])->first();
            if (($retirement['success'] ?? false) !== true || ($retirement['state'] ?? null) !== 'complete'
                || $receipt === null || $receipt->stage !== 'complete' || $receipt->asset_uuid !== $item['asset_uuid']
                || (int) $receipt->file_registry_id !== $item['file_registry_id'] || $receipt->source_sha256 !== $item['source_sha256']
                || $receipt->binding_sha256 !== $item['binding_sha256'] || $receipt->manifest_sha256 !== $item['manifest_sha256']) {
                return $this->terminalItem($item, $receipt === null ? 'held' : 'incomplete',
                    $receipt->failure_code ?? $retirement['error'] ?? 'restoration_batch_reconciliation_terminal', null,
                    $receipt->run_uuid ?? null);
            }
        }
        if ($this->nowSeconds() + $item['generation_budget_seconds'] > $deadline) {
            return $this->terminalItem($item, 'held', 'restoration_batch_deadline_before_generation');
        }
        $generation = $this->faces->planAnonymousFaceGeneration($item['asset_uuid']);
        if (($generation['eligible'] ?? false) !== true || ($generation['source_sha256'] ?? null) !== $item['source_sha256']
            || ($generation['binding_sha256'] ?? null) !== $item['binding_sha256']
            || ($generation['file_registry_id'] ?? null) !== $item['file_registry_id']
            || ($generation['generation_key'] ?? null) !== $item['generation_key']
            || ($generation['detector_version'] ?? null) !== $item['detector_version']) {
            return $this->terminalItem($item, 'held', 'restoration_batch_fresh_plan_changed', null,
                DB::table('file_registry_face_reconciliation_runs')->where('idempotency_key', $item['reconciliation_idempotency_key'])->value('run_uuid'));
        }
        $generated = $this->faces->restoreAnonymousFaceGeneration($item['asset_uuid'], $item['source_sha256'],
            $item['binding_sha256'], $item['generation_idempotency_key'], null, $item['old_face_count']);
        $generationReceipt = DB::table('file_registry_face_generation_runs')->where('idempotency_key', $item['generation_idempotency_key'])->first();
        if (($generated['success'] ?? false) !== true || ($generated['state'] ?? null) !== 'complete'
            || $generationReceipt === null || $generationReceipt->stage !== 'complete'
            || $generationReceipt->asset_uuid !== $item['asset_uuid']
            || (int) $generationReceipt->file_registry_id !== $item['file_registry_id']
            || $generationReceipt->source_sha256 !== $item['source_sha256']
            || $generationReceipt->binding_sha256 !== $item['binding_sha256']
            || $generationReceipt->generation_key !== $item['generation_key']
            || $generationReceipt->detector_version !== $item['detector_version']
            || (int) $generationReceipt->face_count < $item['old_face_count']) {
            return $this->terminalItem($item, $generationReceipt === null ? 'held' : 'incomplete',
                $generationReceipt->failure_code ?? $generated['error'] ?? 'restoration_batch_generation_terminal', null,
                DB::table('file_registry_face_reconciliation_runs')->where('idempotency_key', $item['reconciliation_idempotency_key'])->value('run_uuid'),
                $generationReceipt->run_uuid ?? null);
        }

        return $this->terminalItem($item, 'complete', null, (int) $generationReceipt->face_count,
            DB::table('file_registry_face_reconciliation_runs')->where('idempotency_key', $item['reconciliation_idempotency_key'])->value('run_uuid'),
            $generationReceipt->run_uuid);
    }

    private function assertApprovedPlan(array $plan): void
    {
        $requiredKeys = ['batch_plan_sha256', 'schema', 'items', 'max_sources', 'success', 'eligible', 'mode',
            'detector_run', 'source_originals_mutated'];
        if (($plan['schema'] ?? null) !== self::SCHEMA || ($plan['eligible'] ?? null) !== true
            || array_diff(array_keys($plan), $requiredKeys) !== [] || array_diff($requiredKeys, array_keys($plan)) !== []
            || ! is_array($plan['items'] ?? null) || ! array_is_list($plan['items'])
            || ! is_int($plan['max_sources'] ?? null) || $plan['max_sources'] < 1
            || ($plan['success'] ?? null) !== true || ($plan['mode'] ?? null) !== 'dry_run'
            || ($plan['detector_run'] ?? null) !== false || ($plan['source_originals_mutated'] ?? null) !== false
            || ! is_string($plan['batch_plan_sha256'] ?? null)
            || ! hash_equals($plan['batch_plan_sha256'], $this->digest(array_intersect_key($plan,
                array_flip(['schema', 'items', 'max_sources', 'success', 'eligible', 'mode', 'detector_run', 'source_originals_mutated']))))) {
            throw new RuntimeException('restoration_batch_plan_invalid');
        }
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if (! is_array($caller) || ! is_string($caller['arguments_sha256'] ?? null)
            || ! hash_equals($caller['arguments_sha256'], hash('sha256', json_encode(['plan' => $plan], JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('trusted_framework_caller_required');
        }
        foreach ($plan['items'] as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('restoration_batch_plan_invalid');
            }
            $this->validateItem($item);
        }
        $assetUuids = array_column($plan['items'], 'asset_uuid');
        $sortedAssetUuids = $assetUuids;
        sort($sortedAssetUuids, SORT_STRING);
        if ($assetUuids !== $sortedAssetUuids || count($assetUuids) !== count(array_unique($assetUuids))) {
            throw new RuntimeException('restoration_batch_plan_invalid');
        }
    }

    private function validateItem(array $item): void
    {
        $required = ['asset_uuid', 'file_registry_id', 'source_sha256', 'binding_sha256', 'generation_key', 'detector_version', 'coverage_preview_detector_version',
            'manifest_sha256', 'old_face_count', 'old_vector_count', 'preview_face_count',
            'preflight_budget_seconds', 'retirement_budget_seconds', 'generation_budget_seconds', 'write_budget_seconds', 'total_budget_seconds',
            'reconciliation_idempotency_key', 'generation_idempotency_key'];
        if (array_diff(array_keys($item), $required) !== [] || array_diff($required, array_keys($item)) !== []
            || ! Str::isUuid($item['asset_uuid'] ?? '') || strtolower($item['asset_uuid']) !== $item['asset_uuid']
            || ! Str::isUuid($item['reconciliation_idempotency_key'] ?? '') || ! Str::isUuid($item['generation_idempotency_key'] ?? '')
            || $item['reconciliation_idempotency_key'] === $item['generation_idempotency_key']
            || ! is_int($item['file_registry_id'] ?? null) || $item['file_registry_id'] < 1
            || ! is_int($item['old_face_count'] ?? null) || $item['old_face_count'] < 1
            || ! is_int($item['old_vector_count'] ?? null) || $item['old_vector_count'] < 0
            || ! is_int($item['preview_face_count'] ?? null) || $item['preview_face_count'] < $item['old_face_count']) {
            throw new RuntimeException('restoration_batch_item_invalid');
        }
        foreach (['source_sha256', 'binding_sha256', 'generation_key', 'detector_version', 'coverage_preview_detector_version', 'manifest_sha256'] as $hash) {
            if (! $this->hash($item[$hash] ?? null)) {
                throw new RuntimeException('restoration_batch_item_invalid');
            }
        }
        $budget = $this->budgetComponents($item['old_face_count'], $item['old_vector_count'], $item['preview_face_count']);
        foreach ($budget as $key => $seconds) {
            if (($item[$key] ?? null) !== $seconds) {
                throw new RuntimeException('restoration_batch_budget_changed');
            }
        }
    }

    private function batchResult(array $plan, array $items, int $remaining): array
    {
        $complete = count($items) === count($plan['items']) && array_reduce($items,
            fn ($all, $item) => $all && in_array($item['state'], ['complete', 'reused_complete'], true), true);

        return ['success' => $complete, 'state' => $complete ? 'complete' : 'held',
            'reason' => $complete ? null : ($items[count($items) - 1]['reason'] ?? 'restoration_batch_incomplete'),
            'batch_plan_sha256' => $plan['batch_plan_sha256'], 'processed' => count($items), 'remaining' => $remaining,
            'items' => $items, 'identity_decisions_performed' => false, 'automatic_replay_allowed' => false];
    }

    private function terminalItem(array $item, string $state, ?string $reason, ?int $faceCount = null,
        ?string $reconciliationRunUuid = null, ?string $generationRunUuid = null): array
    {
        return ['asset_uuid' => $item['asset_uuid'], 'state' => $state, 'reason' => $reason,
            'old_face_count' => $item['old_face_count'], 'old_vector_count' => $item['old_vector_count'],
            'face_count' => $faceCount, 'reconciliation_run_uuid' => $reconciliationRunUuid,
            'generation_run_uuid' => $generationRunUuid];
    }

    private function sourceLimit(): int
    {
        $policy = $this->systemConfig->get('cli.framework_services', []);
        $timeoutMs = is_array($policy) ? ($policy['tools']['file_face_restoration_batch_execute']['timeout_ms'] ?? null) : null;
        $schemaCap = $this->registeredSchemaCap();
        $minimumBudget = $this->budgetComponents(1, 0, 1)['total_budget_seconds'];
        if (! is_int($timeoutMs) || $timeoutMs > 3600000 || $schemaCap < 1 || $minimumBudget < 1) {
            return 0;
        }

        $available = (int) floor($timeoutMs / 1000) - self::WORKER_RESERVE_SECONDS;

        return min($schemaCap, max(0, intdiv($available, $minimumBudget)));
    }

    private function executionBudgetSeconds(): int
    {
        $policy = $this->systemConfig->get('cli.framework_services', []);
        $timeoutMs = is_array($policy) ? ($policy['tools']['file_face_restoration_batch_execute']['timeout_ms'] ?? null) : null;
        if (! is_int($timeoutMs) || $timeoutMs <= self::WORKER_RESERVE_SECONDS * 1000 || $timeoutMs > 3600000) {
            throw new RuntimeException('restoration_batch_worker_deadline_unavailable');
        }

        return max(0, (int) floor($timeoutMs / 1000) - self::WORKER_RESERVE_SECONDS);
    }

    /** Reserve detector/engine ceilings and one PG statement/lock allowance per write stage; this is not a wall-clock bound. */
    private function budgetComponents(int $oldFaces, int $oldVectors, int $previewFaces): array
    {
        $detectorTimeout = config('file_registry.face_preview.timeout_seconds');
        $engineTimeout = config('file_registry.face_generation.engine_version_timeout_seconds');
        $leaseSeconds = config('file_registry.face_generation.lease_seconds');
        $lockTimeoutMs = config('file_registry.face_generation.reconciliation_lock_timeout_ms');
        if (! is_int($detectorTimeout) || $detectorTimeout < 1 || $detectorTimeout > 300
            || ! is_int($engineTimeout) || $engineTimeout < 1 || $engineTimeout > 60
            || ! is_int($leaseSeconds) || $leaseSeconds < 30
            || ! is_int($lockTimeoutMs) || $lockTimeoutMs < 100 || $lockTimeoutMs > 30000
            || $oldFaces < 1 || $oldVectors < 0 || $previewFaces < $oldFaces) {
            throw new RuntimeException('restoration_batch_runtime_bounds_unavailable');
        }
        $postgresqlStatementTimeout = min(60, $leaseSeconds);
        $postgresqlLockTimeout = max(1, (int) ceil($lockTimeoutMs / 1000));
        $preflightEngineProbes = 2 * $engineTimeout;
        $retirementEngineProbes = 3 * $engineTimeout;
        $generationEngineProbes = 2 * $engineTimeout;
        $preflight = $preflightEngineProbes;
        $retirement = $retirementEngineProbes + $postgresqlStatementTimeout + $postgresqlLockTimeout;
        $generation = $detectorTimeout + $generationEngineProbes + $postgresqlStatementTimeout + $postgresqlLockTimeout;
        $write = $retirement + $generation;

        return ['preflight_budget_seconds' => $preflight, 'retirement_budget_seconds' => $retirement,
            'generation_budget_seconds' => $generation, 'write_budget_seconds' => $write,
            'total_budget_seconds' => $preflight + $write];
    }

    private function registeredSchemaCap(): int
    {
        try {
            $raw = DB::table('agent_tool_registry')->where('name', 'file_face_restoration_batch_execute')
                ->where('enabled', 1)->value('parameters');
            $schema = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : $raw;
            $cap = $schema['properties']['plan']['properties']['items']['maxItems'] ?? null;

            return is_int($cap) && $cap > 0 ? $cap : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function nowSeconds(): float
    {
        return $this->monotonicClock === null ? hrtime(true) / 1e9 : ($this->monotonicClock)();
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function errorCode(Throwable $error): string
    {
        return $error instanceof RuntimeException && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $error->getMessage())
            ? $error->getMessage() : 'restoration_batch_held';
    }
}
