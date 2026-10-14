<?php

namespace App\Services;

use RuntimeException;

/** Bounded serial coordination; the existing native transport owns each request admission and cleanup. */
class KnowledgeGraphManagedBatchService
{
    private const MAX_BATCH_SIZE = 3;

    /**
     * The executor must use the exact table-bound managed KG route. This wrapper
     * never grants an inference permit or claims a controller lease receipt.
     *
     * @param  callable(int):bool|void  $executor
     * @return array<string,mixed>
     */
    public function run(int $batchSize, callable $executor): array
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new RuntimeException('Knowledge-graph managed batch size must be between 1 and 3.');
        }
        if (AIService::hasManagedInferenceContract()) {
            throw new RuntimeException('Knowledge-graph managed batch cannot inherit an outer inference permit.');
        }

        $processed = 0;
        for ($index = 0; $index < $batchSize; $index++) {
            if ($executor($index) === false) {
                break;
            }
            $processed++;
        }

        return [
            'result' => 'completed',
            'execution_mode' => 'serial',
            'admission_owner' => 'native_transport_per_request',
            'processed_count' => $processed,
            'content_free' => true,
        ];
    }
}
