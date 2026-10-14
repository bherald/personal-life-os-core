<?php

namespace Tests\Unit\Commands;

use App\Console\Commands\RagBatchIndexCommand;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\ComputeRouterRuntimePolicyService;
use App\Services\RAGService;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class RagBatchCompleteFileContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tika.max_file_size' => 2 * 1024 * 1024]);
        $policy = Mockery::mock(ComputeRouterRuntimePolicyService::class);
        $policy->shouldReceive('captureLimits')->andReturn(['raw_bytes' => 1024 * 1024]);
        $this->app->instance(ComputeRouterRuntimePolicyService::class, $policy);
    }

    public static function rejectedPolicies(): array
    {
        return ['file byte limit' => [4, 1024], 'memory capture limit' => [1024, 4],
            'unavailable file policy' => [null, 1024]];
    }

    #[DataProvider('rejectedPolicies')]
    public function test_file_over_budget_is_held_before_content_construction(?int $maximum, int $budget): void
    {
        config(['services.tika.max_file_size' => $maximum]);
        $policy = Mockery::mock(ComputeRouterRuntimePolicyService::class);
        $policy->shouldReceive('captureLimits')->andReturn(['raw_bytes' => $budget]);
        $this->app->instance(ComputeRouterRuntimePolicyService::class, $policy);
        $path = tempnam(sys_get_temp_dir(), 'rag-limit-fixture-');
        try {
            file_put_contents($path, 'Complete source after the configured limit');
            $this->expectException(InferenceAdmissionException::class);
            (new ReflectionMethod(RagBatchIndexCommand::class, 'buildContent'))
                ->invoke(new RagBatchIndexCommand, 'files', (object) ['extension' => 'txt', 'current_path' => $path]);
        } finally {
            unlink($path);
        }
    }

    public function test_batch_admission_hold_leaves_file_rag_stamp_pending_without_embedding(): void
    {
        config(['database.connections.lossless_files_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'services.tika.max_file_size' => 4]);
        $connection = DB::connection('lossless_files_test');
        $connection->getSchemaBuilder()->create('file_fixture', function (Blueprint $table): void {
            $table->integer('id');
            $table->text('extension');
            $table->text('current_path');
            $table->timestamp('rag_indexed_at')->nullable();
        });
        $path = tempnam(sys_get_temp_dir(), 'rag-pending-fixture-');
        try {
            file_put_contents($path, 'Complete over-budget file');
            $connection->table('file_fixture')->insert(['id' => 1, 'extension' => 'txt', 'current_path' => $path]);
            $rag = Mockery::mock(RAGService::class);
            $rag->shouldNotReceive('indexDocument');
            $command = new RagBatchIndexCommand;
            $command->setLaravel($this->app);
            $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
            (new \ReflectionProperty(RagBatchIndexCommand::class, 'ragService'))->setValue($command, $rag);
            try {
                (new ReflectionMethod(RagBatchIndexCommand::class, 'indexDomain'))->invoke($command, 'files',
                    ['table' => 'file_fixture', 'connection' => 'lossless_files_test'], 1, false, microtime(true), 0);
                $this->fail('Over-budget file was accepted by the batch.');
            } catch (InferenceAdmissionException) {
                $this->assertNull($connection->table('file_fixture')->value('rag_indexed_at'));
            }
        } finally {
            unlink($path);
            DB::purge('lossless_files_test');
        }
    }

    public function test_sparse_multigigabyte_file_is_held_before_content_capture(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-sparse-fixture-');
        try {
            $stream = fopen($path, 'wb');
            ftruncate($stream, 3 * 1024 * 1024 * 1024);
            fclose($stream);
            $this->expectException(InferenceAdmissionException::class);
            (new ReflectionMethod(RagBatchIndexCommand::class, 'readCompleteFileContent'))
                ->invoke(new RagBatchIndexCommand, $path);
        } finally {
            unlink($path);
        }
    }

    public function test_missing_text_file_is_held_instead_of_indexing_only_its_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-missing-fixture-');
        unlink($path);
        $this->expectException(InferenceAdmissionException::class);
        (new ReflectionMethod(RagBatchIndexCommand::class, 'buildContent'))
            ->invoke(new RagBatchIndexCommand, 'files', (object) ['extension' => 'txt', 'current_path' => $path]);
    }

    public function test_file_content_includes_evidence_after_both_old_prefix_limits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rag-complete-fixture-');
        $content = '  '.str_repeat("日本語\r\nComplete file evidence. ", 2500)."\nCRITICAL FILE TAIL  ";
        try {
            file_put_contents($path, $content);
            $record = (object) ['extension' => 'txt', 'current_path' => $path];
            $result = (new ReflectionMethod(RagBatchIndexCommand::class, 'buildContent'))
                ->invoke(new RagBatchIndexCommand, 'files', $record);
            $this->assertGreaterThan(50000, strlen($content));
            $this->assertSame(hash('sha256', "Path: {$path}\n\nType: txt\n\nContent:\n".$content), hash('sha256', $result));
        } finally {
            unlink($path);
        }
    }
}
