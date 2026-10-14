<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ModelHostControllerCanaryService
{
    private const LOCK_KEY = 'model_host_controller:mhc05:manual_canary';

    public function __construct(
        private readonly ModelHostControllerCanaryPolicyService $policies,
        private readonly ModelHostControllerClient $controller,
        private readonly ModelHostControllerQueueSnapshotService $queues,
        private readonly Qwen38DurableSmokeService $qwen,
        private readonly OllamaCoexistenceSmokeService $ollama,
        private readonly BitLlmPrivacyGateService $bitnet,
        private readonly ModelHostControllerBitNetCanaryAdmissionService $bitnetAdmission,
    ) {}

    /** @return array<string,mixed> */
    public function run(string $target, string $requestId, string $leaseId): array
    {
        $target = trim($target);
        $requestId = $this->identity($requestId, 'request');
        $leaseId = $this->identity($leaseId, 'lease');
        if (strlen($requestId) > 110) {
            throw new RuntimeException('MHC-05 request identity is too long for lifecycle suffixes.');
        }
        $planRequestId = $requestId.'-plan';
        $activationRequestId = $requestId.'-activate';
        $releaseRequestId = $requestId.'-release';
        $policy = $this->policies->required();
        $targetPolicy = $policy['targets'][$target] ?? null;
        if (! is_array($targetPolicy)) {
            throw new RuntimeException('MHC-05 target is not allowlisted.');
        }

        $lock = Cache::lock(self::LOCK_KEY, (int) $policy['lock_ttl_seconds']);
        $result = $lock->get(function () use ($target, $requestId, $planRequestId, $activationRequestId, $releaseRequestId, $leaseId, $targetPolicy): array {
            return $this->runLocked($target, $requestId, $planRequestId, $activationRequestId, $releaseRequestId, $leaseId, $targetPolicy);
        });
        if ($result === false) {
            throw new RuntimeException('Another MHC-05 manual canary is active.');
        }

        return $result;
    }

    /** @param array<string,mixed> $targetPolicy @return array<string,mixed> */
    private function runLocked(string $target, string $baseRequestId, string $planRequestId, string $activationRequestId, string $releaseRequestId, string $leaseId, array $targetPolicy): array
    {
        $before = $this->queues->capture();
        $activationAttempted = false;
        $release = null;
        $smoke = null;
        $plan = null;
        $activation = null;
        $failure = null;

        try {
            $plan = $this->controller->plan($target, $planRequestId);
            $this->assertControllerResponse($plan, $planRequestId, $target, null, $targetPolicy);
            $this->assertControllerResult($plan, ['ready']);
            $activationAttempted = true;
            $activation = $this->controller->activate($target, $activationRequestId, $leaseId);
            $this->assertControllerResponse($activation, $activationRequestId, $target, $leaseId, $targetPolicy);
            $this->assertControllerResult($activation, ['activated', 'already_ready']);
            $smoke = $this->runAdapter((string) $targetPolicy['adapter'], $baseRequestId);
            $this->assertSmoke($target, $targetPolicy, $smoke);
        } catch (\Throwable $exception) {
            $failure = $exception;
        } finally {
            if ($activationAttempted) {
                try {
                    $release = $this->controller->release($releaseRequestId, $leaseId);
                    $this->assertControllerResponse($release, $releaseRequestId, null, $leaseId);
                    $this->assertControllerResult($release, ['released']);
                } catch (\Throwable $releaseException) {
                    $failure = new RuntimeException('MHC-05 controller release cleanup failed.', 0, $releaseException);
                }
            }
        }

        $after = $this->queues->capture();
        if ($this->preservationHash($before) !== $this->preservationHash($after)) {
            $failure = new RuntimeException('MHC-05 queue snapshot changed during canary.');
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (! is_array($plan) || ! is_array($activation) || ! is_array($smoke) || ! is_array($release)) {
            throw new RuntimeException('MHC-05 canary did not complete its exact lifecycle.');
        }

        return $this->receipt($target, $targetPolicy, $baseRequestId, $plan, $activation, $smoke, $release, $before, $after);
    }

    /** @return array<string,mixed> */
    private function runAdapter(string $adapter, string $requestId): array
    {
        return match ($adapter) {
            'qwen' => $this->qwen->run(),
            'ollama' => $this->ollama->run(),
            'bitnet' => $this->bitnetAdmission->run($requestId, fn (): array => $this->runBitnet()),
            default => throw new RuntimeException('MHC-05 canary adapter is not allowlisted.'),
        };
    }

    /** @return array<string,mixed> */
    private function runBitnet(): array
    {
        $eligible = $this->bitnet->eligibleProviders();
        if (count($eligible) !== 1 || ($eligible[0]['instance_id'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')) {
            throw new RuntimeException('MHC-05 BitNet canary requires the exact eligible bitnet_87 provider.');
        }

        $evaluation = $this->bitnet->evaluate(0, ['required_instance_id' => app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')]);
        $summary = is_array($evaluation['summary'] ?? null) ? $evaluation['summary'] : [];
        if ((int) ($summary['total'] ?? 0) !== 120
            || (int) ($summary['dangerous_false_allows'] ?? -1) !== 0
            || collect($evaluation['rows'] ?? [])->contains(
                static fn (mixed $row): bool => ! is_array($row) || ($row['provider'] ?? null) !== app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id')
            )) {
            throw new RuntimeException('MHC-05 BitNet privacy evaluation failed exact provider or safety gates.');
        }

        return [
            'schema' => 'plos.mhc05_bitnet_canary.v1',
            'target' => app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id'),
            'provider_instance_id' => app(SystemConfigService::class)->llmRoutingValue('bitnet_canary', 'instance_id'),
            'total_cases' => 120,
            'correct' => (int) ($summary['correct'] ?? 0),
            'accuracy' => (float) ($summary['accuracy'] ?? 0.0),
            'dangerous_false_allows' => 0,
            'safe_false_denies' => (int) ($summary['safe_false_denies'] ?? 0),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => 'green',
        ];
    }

    /** @param array<string,mixed> $targetPolicy @param array<string,mixed> $smoke */
    private function assertSmoke(string $target, array $targetPolicy, array $smoke): void
    {
        if (($smoke['content_free'] ?? false) !== true
            || ($smoke['contains_prompt_or_response_content'] ?? true) !== false
            || ($smoke['result'] ?? null) !== 'green'
            || ($smoke['instance_id'] ?? $smoke['provider_instance_id'] ?? null) !== $targetPolicy['provider_instance_id']
        ) {
            throw new RuntimeException("MHC-05 {$target} canary returned unsafe or mismatched evidence.");
        }
    }

    /** @param array<string,mixed> $response @param array<string,mixed>|null $targetPolicy */
    private function assertControllerResponse(array $response, string $requestId, ?string $target, ?string $leaseId, ?array $targetPolicy = null): void
    {
        if (($response['request_id'] ?? null) !== $requestId
            || ($target !== null && ($response['target'] ?? null) !== $target)
            || ($leaseId !== null && ($response['lease_id'] ?? null) !== $leaseId)
            || ! is_string($response['result'] ?? null)
            || ($targetPolicy !== null && isset($response['model']) && $response['model'] !== $targetPolicy['model'])
            || ($targetPolicy !== null && isset($response['engine']) && $response['engine'] !== $targetPolicy['engine'])
        ) {
            throw new RuntimeException('MHC-05 controller returned an inexact lifecycle response.');
        }
    }

    /** @param array<string,mixed> $response @param list<string> $allowed */
    private function assertControllerResult(array $response, array $allowed): void
    {
        if (! in_array($response['result'] ?? null, $allowed, true)) {
            throw new RuntimeException('MHC-05 controller returned a non-green lifecycle result.');
        }
    }

    /** @param array<string,mixed> $targetPolicy @param array<string,mixed> $plan @param array<string,mixed> $activation @param array<string,mixed> $smoke @param array<string,mixed> $release @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,mixed> */
    private function receipt(string $target, array $targetPolicy, string $baseRequestId, array $plan, array $activation, array $smoke, array $release, array $before, array $after): array
    {
        $preserved = $this->preservationHash($before) === $this->preservationHash($after);
        if (! $preserved) {
            throw new RuntimeException('MHC-05 queue snapshot changed during canary.');
        }

        return [
            'schema' => 'plos.model_host_controller.canary_receipt.v1',
            'phase' => 'MHC-05',
            'target' => $target,
            'provider_instance_id' => $targetPolicy['provider_instance_id'],
            'model' => $targetPolicy['model'],
            'engine' => $targetPolicy['engine'],
            'request_id' => $baseRequestId,
            'plan_request_id' => $plan['request_id'] ?? null,
            'activation_request_id' => $activation['request_id'] ?? null,
            'release_request_id' => $release['request_id'] ?? null,
            'lease_id' => $activation['lease_id'] ?? null,
            'plan_result' => $plan['result'] ?? null,
            'activation_result' => $activation['result'] ?? null,
            'release_result' => $release['result'] ?? null,
            'smoke' => $smoke,
            'execution_mode' => 'serial',
            'automatic_selection_enabled' => false,
            'normal_routing' => false,
            'tools_enabled' => false,
            'fallback_enabled' => false,
            'queue_preserved' => $preserved,
            'queue_before_sha256' => $before['snapshot_sha256'] ?? null,
            'queue_after_sha256' => $after['snapshot_sha256'] ?? null,
            'queue_preservation_before_sha256' => $this->preservationHash($before),
            'queue_preservation_after_sha256' => $this->preservationHash($after),
            'background_scheduler_activity_observed' => ($before['snapshot_sha256'] ?? null) !== ($after['snapshot_sha256'] ?? null),
            'content_free' => true,
            'contains_prompt_or_response_content' => false,
            'result' => 'green',
        ];
    }

    private function identity(string $value, string $kind): string
    {
        $value = trim($value);
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}\z/', $value) !== 1) {
            throw new RuntimeException("MHC-05 {$kind} identity is invalid.");
        }

        return $value;
    }

    /** @param array<string,mixed> $snapshot */
    private function preservationHash(array $snapshot): mixed
    {
        return $snapshot['preservation_sha256'] ?? $snapshot['snapshot_sha256'] ?? null;
    }
}
