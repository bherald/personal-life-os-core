<?php

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Opens one MHC-06 lease for a bounded knowledge-graph batch. Document content
 * remains inside the executor and is never added to controller receipts.
 */
class KnowledgeGraphControllerBatchLeaseService
{
    private const MAX_BATCH_SIZE = 3;

    public function __construct(
        private readonly ModelHostControllerAutomaticSelectionService $selection,
    ) {}

    /**
     * @param  callable(int,array<string,mixed>):void  $executor
     * @return array<string,mixed>
     */
    public function run(int $batchSize, callable $executor): array
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new RuntimeException('Knowledge-graph controller batch size must be between 1 and 3.');
        }

        $binding = app(SystemConfigService::class)->llmRoutingBinding('knowledge_graph')['managed'] ?? null;
        if (! is_array($binding) || ! is_string($binding['target_id'] ?? null) || ! is_string($binding['instance_id'] ?? null) || ! is_string($binding['model'] ?? null)) {
            throw new RuntimeException('Managed knowledge-graph route binding is unavailable.');
        }

        $leaseId = 'kg-lease-'.Str::uuid()->toString();
        $requests = [];
        $requestIndexes = [];
        for ($index = 0; $index < $batchSize; $index++) {
            $requestId = 'kg-request-'.Str::uuid()->toString();
            $requests[] = [
                'role' => 'fast',
                'request_id' => $requestId,
                'lease_id' => $leaseId,
            ];
            $requestIndexes[$requestId] = $index;
        }

        $receipt = $this->selection->run(
            $requests,
            function (array $request, array $target) use ($requestIndexes, $executor, $binding): void {
                if (($target['target'] ?? null) !== $binding['target_id']
                    || ($target['provider_instance_id'] ?? null) !== $binding['instance_id']
                    || ($target['provider_model_role'] ?? null) !== 'standard'
                    || ($target['model'] ?? null) !== $binding['model']
                    || ($target['engine'] ?? null) !== 'ollama') {
                    throw new RuntimeException('Knowledge-graph controller selected an inexact .87 Ollama target.');
                }

                $requestId = (string) ($request['request_id'] ?? '');
                if (! array_key_exists($requestId, $requestIndexes)) {
                    throw new RuntimeException('Knowledge-graph controller returned an unknown batch request.');
                }

                $executor($requestIndexes[$requestId], $target);
            }
        );

        if (($receipt['result'] ?? null) !== 'green'
            || ($receipt['execution_mode'] ?? null) !== 'serial'
            || ($receipt['tools_enabled'] ?? null) !== false
            || ($receipt['fallback_enabled'] ?? null) !== false
            || ($receipt['content_free'] ?? null) !== true
            || ($receipt['contains_prompt_or_response_content'] ?? null) !== false
            || count((array) ($receipt['results'] ?? [])) !== $batchSize) {
            $reason = preg_replace('/[^a-z0-9_]/', '', (string) ($receipt['reason'] ?? 'unknown')) ?: 'unknown';
            $resultReasons = array_values(array_unique(array_filter(array_map(
                static fn (mixed $result): string => is_array($result)
                    ? (preg_replace('/[^a-z0-9_]/', '', (string) ($result['reason'] ?? '')) ?: '')
                    : '',
                (array) ($receipt['results'] ?? [])
            ))));
            $detail = $resultReasons === [] ? $reason : implode(',', $resultReasons);
            throw new RuntimeException("Knowledge-graph controller batch lease did not finish cleanly ({$detail}).");
        }

        foreach ($receipt['results'] as $result) {
            if (($result['result'] ?? null) !== 'green'
                || ($result['target'] ?? null) !== $binding['target_id']
                || ($result['provider_instance_id'] ?? null) !== $binding['instance_id']
                || ($result['batched_request_count'] ?? null) !== $batchSize
                || ($result['release_result'] ?? null) !== 'released') {
                throw new RuntimeException('Knowledge-graph controller batch receipt is inexact.');
            }
        }

        return $receipt;
    }
}
