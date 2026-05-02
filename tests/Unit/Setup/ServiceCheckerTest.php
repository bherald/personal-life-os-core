<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\ServiceChecker;
use Tests\TestCase;

class ServiceCheckerTest extends TestCase
{
    public function test_passes_when_localhost_service_reachable(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return true;
            }
        };

        $results = $checker->run('core', [
            'connect_timeout_seconds' => 1,
            'core' => [
                ['name' => 'mysql', 'host_default' => '127.0.0.1', 'port_default' => 3306, 'required' => true],
            ],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('pass', $results[0]->status);
    }

    public function test_fails_required_service_when_unreachable(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return false;
            }
        };

        $results = $checker->run('core', [
            'connect_timeout_seconds' => 1,
            'core' => [
                ['name' => 'mysql', 'host_default' => '127.0.0.1', 'port_default' => 3306, 'required' => true],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_warns_optional_service_when_unreachable(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return false;
            }
        };

        $results = $checker->run('media', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'media' => [
                ['name' => 'tika', 'url_default' => 'http://127.0.0.1:9998', 'required' => false],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_skips_non_localhost_service_with_warn(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                throw new \RuntimeException('probe should not be invoked for remote hosts');
            }
        };

        $results = $checker->run('core', [
            'connect_timeout_seconds' => 1,
            'core' => [
                ['name' => 'mysql', 'host_default' => '10.0.0.5', 'port_default' => 3306, 'required' => true],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
        $this->assertStringContainsString('non-localhost', $results[0]->message);
    }

    public function test_resolves_url_default_to_host_port(): void
    {
        $captured = [];
        $checker = new class($captured) extends ServiceChecker
        {
            /** @param array<int, array{0:string,1:int}> $captured */
            public function __construct(private array &$captured) {}

            protected function probe(string $host, int $port, int $timeout): bool
            {
                $this->captured[] = [$host, $port];

                return true;
            }
        };

        $checker->run('media', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'media' => [
                ['name' => 'tika', 'url_default' => 'http://127.0.0.1:9998', 'required' => false],
            ],
        ]);

        $this->assertSame([['127.0.0.1', 9998]], $captured);
    }

    public function test_reachable_service_version_passes_when_minimum_met(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return true;
            }

            protected function fetchUrl(string $url, int $timeout): ?string
            {
                return 'Apache Tika 2.9.2.1';
            }
        };

        $results = $checker->run('media', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'media' => [
                [
                    'name' => 'tika',
                    'url_default' => 'http://127.0.0.1:9998',
                    'required' => false,
                    'version_path' => '/version',
                    'min_version' => '2.9',
                    'version_regex' => '/Apache Tika\s+(\d+\.\d+(?:\.\d+){0,2})/',
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('pass', $results[1]->status);
        $this->assertSame('tika.version', $results[1]->name);
        $this->assertSame('2.9.2.1', $results[1]->context['version']);
    }

    public function test_reachable_service_version_warns_when_below_minimum(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return true;
            }

            protected function fetchUrl(string $url, int $timeout): ?string
            {
                return 'Apache Tika 1.28';
            }
        };

        $results = $checker->run('media', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'media' => [
                [
                    'name' => 'tika',
                    'url_default' => 'http://127.0.0.1:9998',
                    'required' => false,
                    'version_path' => '/version',
                    'min_version' => '2.9',
                    'version_regex' => '/Apache Tika\s+(\d+\.\d+(?:\.\d+){0,2})/',
                ],
            ],
        ]);

        $this->assertSame('warn', $results[1]->status);
        $this->assertStringContainsString('below recommended', $results[1]->message);
    }

    public function test_ollama_model_list_passes_when_configured_models_are_installed(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return true;
            }

            protected function fetchUrl(string $url, int $timeout): ?string
            {
                return json_encode([
                    'models' => [
                        ['name' => 'llama3.1:8b'],
                        ['name' => 'nomic-embed-text'],
                    ],
                ]);
            }
        };

        $results = $checker->run('gpu', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'gpu' => [
                [
                    'name' => 'ollama',
                    'url_default' => 'http://127.0.0.1:11434',
                    'required' => false,
                    'model_tags_path' => '/api/tags',
                    'model_envs' => [
                        ['env' => 'PLOS_TEST_OLLAMA_MODEL', 'default' => 'llama3.1:8b'],
                        ['env' => 'PLOS_TEST_OLLAMA_EMBEDDING_MODEL', 'default' => 'nomic-embed-text'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('pass', $results[0]->status);
        $this->assertSame('ollama.models', $results[1]->name);
        $this->assertSame('pass', $results[1]->status);
    }

    public function test_ollama_model_list_warns_when_configured_model_is_missing(): void
    {
        $checker = new class extends ServiceChecker
        {
            protected function probe(string $host, int $port, int $timeout): bool
            {
                return true;
            }

            protected function fetchUrl(string $url, int $timeout): ?string
            {
                return json_encode([
                    'models' => [
                        ['name' => 'llama3.1:8b'],
                    ],
                ]);
            }
        };

        $results = $checker->run('gpu', [
            'connect_timeout_seconds' => 1,
            'core' => [],
            'gpu' => [
                [
                    'name' => 'ollama',
                    'url_default' => 'http://127.0.0.1:11434',
                    'required' => false,
                    'model_tags_path' => '/api/tags',
                    'model_envs' => [
                        ['env' => 'PLOS_TEST_OLLAMA_MODEL', 'default' => 'llama3.1:8b'],
                        ['env' => 'PLOS_TEST_OLLAMA_EMBEDDING_MODEL', 'default' => 'nomic-embed-text'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('ollama.models', $results[1]->name);
        $this->assertSame('warn', $results[1]->status);
        $this->assertStringContainsString('ollama pull nomic-embed-text', $results[1]->message);
    }
}
