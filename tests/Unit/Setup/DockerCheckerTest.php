<?php

namespace Tests\Unit\Setup;

use App\Services\Setup\DockerChecker;
use Tests\TestCase;

class DockerCheckerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir().'/plos_docker_'.bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    public function test_required_file_missing_fails(): void
    {
        $checker = $this->makeChecker();

        $results = $checker->run('core', [
            'core' => [
                'compose_files' => [],
                'required_files' => ['docker/README.md'],
                'required_dirs' => [],
            ],
        ]);

        $this->assertSame('fail', $results[0]->status);
    }

    public function test_compose_file_missing_warns(): void
    {
        $checker = $this->makeChecker();

        $results = $checker->run('core', [
            'core' => [
                'compose_files' => ['docker-compose.yml'],
                'required_files' => [],
                'required_dirs' => [],
            ],
        ]);

        $this->assertSame('warn', $results[0]->status);
    }

    public function test_present_file_and_dir_pass(): void
    {
        mkdir($this->tmpRoot.'/docker/php', 0700, true);
        file_put_contents($this->tmpRoot.'/docker/README.md', "# README\n");

        $checker = $this->makeChecker();

        $results = $checker->run('core', [
            'core' => [
                'compose_files' => [],
                'required_files' => ['docker/README.md'],
                'required_dirs' => ['docker/php'],
            ],
        ]);

        $statuses = array_column(array_map(fn ($r) => ['name' => $r->name, 'status' => $r->status], $results), 'status', 'name');

        $this->assertSame('pass', $statuses['docker/README.md']);
        $this->assertSame('pass', $statuses['docker/php']);
    }

    public function test_missing_optional_docker_binary_warns(): void
    {
        $checker = new class($this->tmpRoot) extends DockerChecker
        {
            public function __construct(private string $root) {}

            protected function resolveBinary(string $bin): ?string
            {
                return null;
            }

            protected function basePath(string $relative): string
            {
                return rtrim($this->root, '/').'/'.ltrim($relative, '/');
            }
        };

        $results = $checker->run('core', [
            'core' => [
                'engine' => ['required' => false, 'compose' => true, 'daemon' => true],
            ],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('docker.binary', $results[0]->name);
        $this->assertSame('warn', $results[0]->status);
    }

    public function test_docker_compose_and_daemon_checks_pass_when_commands_succeed(): void
    {
        $checker = new class($this->tmpRoot) extends DockerChecker
        {
            public function __construct(private string $root) {}

            protected function resolveBinary(string $bin): ?string
            {
                return '/usr/bin/docker';
            }

            protected function commandSucceeds(array $command): bool
            {
                return true;
            }

            protected function basePath(string $relative): string
            {
                return rtrim($this->root, '/').'/'.ltrim($relative, '/');
            }
        };

        $results = $checker->run('core', [
            'core' => [
                'engine' => ['required' => false, 'compose' => true, 'daemon' => true],
            ],
        ]);

        $statuses = array_column(array_map(fn ($r) => ['name' => $r->name, 'status' => $r->status], $results), 'status', 'name');

        $this->assertSame('pass', $statuses['docker.binary']);
        $this->assertSame('pass', $statuses['docker.compose']);
        $this->assertSame('pass', $statuses['docker.daemon']);
    }

    public function test_docker_compose_and_daemon_checks_warn_when_commands_fail(): void
    {
        $checker = new class($this->tmpRoot) extends DockerChecker
        {
            public function __construct(private string $root) {}

            protected function resolveBinary(string $bin): ?string
            {
                return '/usr/bin/docker';
            }

            protected function commandSucceeds(array $command): bool
            {
                return false;
            }

            protected function basePath(string $relative): string
            {
                return rtrim($this->root, '/').'/'.ltrim($relative, '/');
            }
        };

        $results = $checker->run('core', [
            'core' => [
                'engine' => ['required' => false, 'compose' => true, 'daemon' => true],
            ],
        ]);

        $statuses = array_column(array_map(fn ($r) => ['name' => $r->name, 'status' => $r->status], $results), 'status', 'name');

        $this->assertSame('pass', $statuses['docker.binary']);
        $this->assertSame('warn', $statuses['docker.compose']);
        $this->assertSame('warn', $statuses['docker.daemon']);
    }

    private function makeChecker(): DockerChecker
    {
        $root = $this->tmpRoot;

        return new class($root) extends DockerChecker
        {
            public function __construct(private string $root) {}

            protected function basePath(string $relative): string
            {
                return rtrim($this->root, '/').'/'.ltrim($relative, '/');
            }
        };
    }

    private function rrmdir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
