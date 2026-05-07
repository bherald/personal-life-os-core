<?php

namespace Tests\Feature\Quality;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicTempArtifactCleanupScriptTest extends TestCase
{
    public function test_help_exits_cleanly(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/guards/public-temp-artifact-cleanup.sh'), '--help'],
            base_path()
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('Usage:', $process->getOutput());
        $this->assertStringContainsString('Dry-run-first cleanup', $process->getOutput());
        $this->assertStringContainsString('personal-life-os-core-smoke-*', $process->getOutput());
        $this->assertStringContainsString('Symlinked candidates are reported and never deleted.', $process->getOutput());
    }

    public function test_dry_run_keeps_filesystem_unchanged_and_ignores_protected_names(): void
    {
        $fixture = $this->makeFixture();

        $process = $this->runCleanup($fixture['root'], ['--keep-latest', '1', '--dry-run']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Mode: dry-run', $process->getOutput());
        $this->assertStringContainsString('keep kind=export', $process->getOutput());
        $this->assertStringContainsString('keep kind=smoke', $process->getOutput());
        $this->assertStringContainsString('delete_candidate kind=export', $process->getOutput());
        $this->assertStringContainsString('delete_candidate kind=smoke', $process->getOutput());
        $this->assertStringContainsString('Dry-run only.', $process->getOutput());
        $this->assertStringNotContainsString('personal-life-os-core-github-sync', $process->getOutput());
        $this->assertStringNotContainsString('personal-life-os-core-first-push', $process->getOutput());

        foreach ($fixture['paths'] as $path) {
            $this->assertDirectoryExists($path);
        }
    }

    public function test_execute_removes_only_old_generated_artifacts(): void
    {
        $fixture = $this->makeFixture();

        $process = $this->runCleanup($fixture['root'], ['--keep-latest', '1', '--execute']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Mode: execute', $process->getOutput());
        $this->assertStringContainsString('Summary: candidates=4 delete_candidates=2 symlink_refused=0', $process->getOutput());

        $this->assertDirectoryDoesNotExist($fixture['paths']['old_export']);
        $this->assertDirectoryDoesNotExist($fixture['paths']['old_smoke']);
        $this->assertDirectoryExists($fixture['paths']['new_export']);
        $this->assertDirectoryExists($fixture['paths']['new_smoke']);
        $this->assertDirectoryExists($fixture['paths']['sync_clone']);
        $this->assertDirectoryExists($fixture['paths']['first_push']);
    }

    public function test_refuses_dangerous_root_before_delete(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/guards/public-temp-artifact-cleanup.sh'), '--root', '/', '--execute'],
            base_path()
        );

        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Refusing to scan dangerous temp root', $process->getErrorOutput());
    }

    public function test_rejects_invalid_keep_latest(): void
    {
        $fixture = $this->makeFixture();

        $process = $this->runCleanup($fixture['root'], ['--keep-latest', 'latest']);

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Usage:', $process->getErrorOutput());
    }

    public function test_symlinked_generated_artifact_names_are_reported_and_never_deleted(): void
    {
        $fixture = $this->makeFixture();
        $target = $fixture['root'].'/redirected-export';
        mkdir($target, 0700, true);
        file_put_contents($target.'/README.txt', 'redirected');
        $symlink = $fixture['root'].'/personal-life-os-core-export-symlink';
        symlink($target, $symlink);

        $process = $this->runCleanup($fixture['root'], ['--keep-latest', '1', '--execute']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('symlink_refused path='.$symlink, $process->getOutput());
        $this->assertStringContainsString('symlink_refused=1', $process->getOutput());
        $this->assertTrue(is_link($symlink));
        $this->assertDirectoryExists($target);
    }

    private function runCleanup(string $root, array $arguments): Process
    {
        $process = new Process(
            array_merge([
                'bash',
                base_path('scripts/guards/public-temp-artifact-cleanup.sh'),
                '--root',
                $root,
            ], $arguments),
            base_path()
        );

        $process->run();

        return $process;
    }

    /**
     * @return array{root: string, paths: array<string, string>}
     */
    private function makeFixture(): array
    {
        $root = sys_get_temp_dir().'/plos-public-temp-cleanup-'.bin2hex(random_bytes(5));
        mkdir($root, 0700, true);

        $paths = [
            'old_export' => $root.'/personal-life-os-core-export-old',
            'new_export' => $root.'/personal-life-os-core-export-new',
            'old_smoke' => $root.'/personal-life-os-core-smoke-old',
            'new_smoke' => $root.'/personal-life-os-core-smoke-new',
            'sync_clone' => $root.'/personal-life-os-core-github-sync-123',
            'first_push' => $root.'/personal-life-os-core-first-push',
        ];

        foreach ($paths as $path) {
            mkdir($path, 0700, true);
            file_put_contents($path.'/README.txt', basename($path));
        }

        touch($paths['old_export'], time() - 400);
        touch($paths['new_export'], time() - 300);
        touch($paths['old_smoke'], time() - 200);
        touch($paths['new_smoke'], time() - 100);

        return ['root' => $root, 'paths' => $paths];
    }
}
