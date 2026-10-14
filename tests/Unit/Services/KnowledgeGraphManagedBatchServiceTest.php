<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\KnowledgeGraphManagedBatchService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class KnowledgeGraphManagedBatchServiceTest extends TestCase
{
    public function test_three_documents_run_serially_without_an_outer_inference_permit(): void
    {
        $indexes = [];
        $receipt = (new KnowledgeGraphManagedBatchService)->run(3, function (int $index) use (&$indexes): void {
            $this->assertFalse(AIService::hasManagedInferenceContract());
            $indexes[] = $index;
        });
        $this->assertSame([0, 1, 2], $indexes);
        $this->assertSame('native_transport_per_request', $receipt['admission_owner']);
        $this->assertSame(3, $receipt['processed_count']);
        $this->assertArrayNotHasKey('release_result', $receipt);
        $this->assertArrayNotHasKey('results', $receipt);
    }

    public function test_admission_stop_preserves_original_and_never_starts_a_later_document(): void
    {
        $stop = new RuntimeException('synthetic admission stop');
        $indexes = [];
        try {
            (new KnowledgeGraphManagedBatchService)->run(3, function (int $index) use (&$indexes, $stop): never {
                $indexes[] = $index;
                throw $stop;
            });
            $this->fail('The original admission stop must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame($stop, $error);
            $this->assertSame([0], $indexes);
        }
    }

    public function test_budget_stop_prevents_later_document_and_reports_actual_count(): void
    {
        $indexes = [];
        $receipt = (new KnowledgeGraphManagedBatchService)->run(3, function (int $index) use (&$indexes): bool {
            $indexes[] = $index;

            return $index === 0;
        });
        $this->assertSame([0, 1], $indexes);
        $this->assertSame(1, $receipt['processed_count']);
    }

    public function test_inherited_outer_permit_is_rejected_before_document_work(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot inherit an outer inference permit');
        AIService::withInferenceProgressKeeper(fn () => AIService::withInferenceTransportValidator(
            fn () => (new KnowledgeGraphManagedBatchService)->run(1, fn () => $this->fail('No document can run.')),
            static function (): void {},
        ), static function (): void {});
    }

    public function test_oversized_batch_is_rejected_before_document_work(): void
    {
        $this->expectException(RuntimeException::class);
        (new KnowledgeGraphManagedBatchService)->run(4, fn () => $this->fail('No document can run.'));
    }
}
