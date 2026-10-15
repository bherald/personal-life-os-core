<?php

namespace Tests\Unit\Services;

use App\Logging\SensitiveLogContextProcessor;
use App\Services\AIService;
use App\Services\FileCategorizationRAGService;
use App\Services\RAGService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FileCategorizationRAGFailureDiagnosticTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public static function failures(): array
    {
        return [
            'file database failure' => [false, true, false],
            'genealogy database failure' => [true, true, false],
            'genealogy ordinary failure' => [true, false, false],
            'file invalid error-info fields' => [false, true, true],
        ];
    }

    #[DataProvider('failures')]
    public function test_actual_index_failure_retains_only_closed_safe_diagnostics(bool $genealogy, bool $database, bool $invalid): void
    {
        $pdo = new PDOException('private previous text /private/source api_secret');
        $pdo->errorInfo = $invalid ? ['private_sqlstate', 'private_driver', 'private errorInfo message']
            : ['42P01', 7, 'private errorInfo message'];
        $error = $database ? new QueryException('private_connection', 'SELECT private_sql WHERE value=?', ['private_binding'], $pdo)
            : new RuntimeException('private exception text /private/source api_secret');
        DB::shouldReceive('selectOne')->once()->andThrow($error);
        $safe = null;
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use (&$safe, $genealogy): bool {
            $this->assertSame($genealogy ? 'FileCategorizationRAG: Failed to index genealogy media'
                : 'FileCategorizationRAG: Failed to index file', $message);
            $this->assertArrayNotHasKey('error', $context);
            $safe = (new SensitiveLogContextProcessor)->redact($context);

            return true;
        });
        $service = new FileCategorizationRAGService(Mockery::mock(RAGService::class), Mockery::mock(AIService::class));
        $result = $genealogy ? $service->indexGenealogyMediaFile(1) : $service->indexFile('synthetic-asset');
        $this->assertFalse($result['success']);
        $this->assertSame($database ? 'media_rag_database_query_failed' : 'media_rag_index_failed', $safe['failure_reason']);
        $this->assertSame($error::class, $safe['exception']['type']);
        $this->assertFalse($safe['exception']['message_logged']);
        $this->assertFalse($safe['exception']['stacktrace_logged']);
        $this->assertSame($database && ! $invalid ? ['sqlstate' => '42P01', 'driver_code' => 7] : [], $safe['database_diagnostic']);
        $encoded = json_encode($safe, JSON_THROW_ON_ERROR);
        foreach (['private_sql', 'private_binding', 'private_connection', 'private previous', 'private exception', 'private errorInfo', '/private/source', 'api_secret', 'private_driver'] as $private) {
            $this->assertStringNotContainsString($private, $encoded);
        }
    }
}
