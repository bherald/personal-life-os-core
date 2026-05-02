<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\DatabaseChecker;
use RuntimeException;
use Tests\TestCase;

class DatabaseCheckerTest extends TestCase
{
    public function test_required_postgres_extension_missing_fails(): void
    {
        $checker = new class extends DatabaseChecker
        {
            protected function postgresExtensionExists(string $connection, string $extension): bool
            {
                return false;
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                'postgres_extensions' => [
                    ['connection' => 'pgsql_rag', 'extension' => 'vector', 'required' => true],
                ],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
        $this->assertSame('pgsql_rag.vector', $results[0]->name);
    }

    public function test_present_postgres_extension_passes(): void
    {
        $checker = new class extends DatabaseChecker
        {
            protected function postgresExtensionExists(string $connection, string $extension): bool
            {
                return true;
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                'postgres_extensions' => [
                    ['connection' => 'pgsql_rag', 'extension' => 'vector', 'required' => true],
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
    }

    public function test_optional_postgres_extension_probe_error_warns(): void
    {
        $checker = new class extends DatabaseChecker
        {
            protected function postgresExtensionExists(string $connection, string $extension): bool
            {
                throw new RuntimeException('connection refused');
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                'postgres_extensions' => [
                    ['connection' => 'pgsql_rag', 'extension' => 'vector', 'required' => false],
                ],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('could not verify', $results[0]->message);
    }

    public function test_multiple_postgres_extensions_report_independently(): void
    {
        $checker = new class extends DatabaseChecker
        {
            protected function postgresExtensionExists(string $connection, string $extension): bool
            {
                return in_array($extension, ['vector', 'fuzzystrmatch'], true);
            }
        };

        $results = $checker->run('media', [
            'core' => [],
            'media' => [
                'postgres_extensions' => [
                    ['connection' => 'pgsql_rag', 'extension' => 'vector', 'required' => true],
                    ['connection' => 'pgsql_rag', 'extension' => 'fuzzystrmatch', 'required' => true],
                    ['connection' => 'pgsql_rag', 'extension' => 'pg_trgm', 'required' => false],
                ],
            ],
        ]);

        $statuses = array_column(array_map(fn ($r) => ['name' => $r->name, 'status' => $r->status], $results), 'status', 'name');

        $this->assertSame('pass', $statuses['pgsql_rag.vector']);
        $this->assertSame('pass', $statuses['pgsql_rag.fuzzystrmatch']);
        $this->assertSame('warn', $statuses['pgsql_rag.pg_trgm']);
    }

    public function test_gpu_profile_includes_media_postgres_extensions(): void
    {
        $checker = new class extends DatabaseChecker
        {
            protected function postgresExtensionExists(string $connection, string $extension): bool
            {
                return true;
            }
        };

        $results = $checker->run('gpu', [
            'core' => [],
            'media' => [
                'postgres_extensions' => [
                    ['connection' => 'pgsql_rag', 'extension' => 'vector', 'required' => true],
                    ['connection' => 'pgsql_rag', 'extension' => 'fuzzystrmatch', 'required' => true],
                    ['connection' => 'pgsql_rag', 'extension' => 'pg_trgm', 'required' => false],
                ],
            ],
            'gpu' => [],
        ]);

        $this->assertSame(
            ['pgsql_rag.vector', 'pgsql_rag.fuzzystrmatch', 'pgsql_rag.pg_trgm'],
            array_map(fn ($result) => $result->name, $results)
        );
    }
}
