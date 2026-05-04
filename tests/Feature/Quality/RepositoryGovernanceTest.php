<?php

namespace Tests\Feature\Quality;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RepositoryGovernanceTest extends TestCase
{
    #[Test]
    public function repository_governance_workflow_runs_commit_message_guard_with_full_history(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/repository-governance.yml'));

        $this->assertStringContainsString('fetch-depth: 0', $workflow);
        $this->assertStringContainsString('bash -n scripts/guards/production-fix-commit-message-check.sh', $workflow);
        $this->assertStringContainsString('scripts/guards/production-fix-commit-message-check.sh', $workflow);
        $this->assertStringContainsString('PLOS_GOVERNANCE_PR_BASE', $workflow);
        $this->assertStringContainsString('PLOS_GOVERNANCE_PUSH_BEFORE', $workflow);
    }

    #[Test]
    public function public_readiness_and_smoke_lint_the_commit_message_guard(): void
    {
        $guard = 'scripts/guards/production-fix-commit-message-check.sh';

        $this->assertStringContainsString($guard, file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString($guard, file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString($guard, file_get_contents(base_path('scripts/public-export.sh')));
    }

    #[Test]
    public function fix_commit_touching_production_paths_without_required_body_fails(): void
    {
        $repo = $this->makeRepo();

        try {
            $this->writeFile($repo, 'app/Services/DemoService.php', "<?php\n\nfinal class DemoService {}\n");
            $this->commit($repo, 'fix: guard demo service');

            $process = $this->runGuard($repo);

            $this->assertFalse($process->isSuccessful(), $process->getOutput());
            $this->assertStringContainsString('Root cause:', $process->getErrorOutput());
            $this->assertStringContainsString('Behavior changed:', $process->getErrorOutput());
            $this->assertStringContainsString('Verification:', $process->getErrorOutput());
            $this->assertStringContainsString('Deployment/rollback:', $process->getErrorOutput());
        } finally {
            $this->removeTree($repo);
        }
    }

    #[Test]
    public function fix_commit_touching_production_paths_with_required_body_passes(): void
    {
        $repo = $this->makeRepo();

        try {
            $this->writeFile($repo, 'app/Services/DemoService.php', "<?php\n\nfinal class DemoService {}\n");
            $this->commit(
                $repo,
                'fix: guard demo service',
                "Root cause:\n- Demo drifted.\n\nBehavior changed:\n- Demo is guarded.\n\nVerification:\n- Focused test.\n\nDeployment/rollback:\n- No special rollback."
            );

            $process = $this->runGuard($repo);

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('PASS:', $process->getOutput());
        } finally {
            $this->removeTree($repo);
        }
    }

    #[Test]
    public function fix_commit_touching_production_paths_with_inline_required_body_passes(): void
    {
        $repo = $this->makeRepo();

        try {
            $this->writeFile($repo, 'app/Services/DemoService.php', "<?php\n\nfinal class DemoService {}\n");
            $this->commit(
                $repo,
                'fix: guard demo service',
                "Root cause: Demo drifted.\n\nBehavior changed: Demo is guarded.\n\nVerification: Focused test.\n\nDeployment/rollback: No special rollback."
            );

            $process = $this->runGuard($repo);

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringContainsString('PASS:', $process->getOutput());
        } finally {
            $this->removeTree($repo);
        }
    }

    #[Test]
    public function docs_only_fix_commit_without_required_body_passes(): void
    {
        $repo = $this->makeRepo();

        try {
            $this->writeFile($repo, 'docs/demo.md', "# Demo\n");
            $this->commit($repo, 'fix: clarify docs');

            $process = $this->runGuard($repo);

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            $this->removeTree($repo);
        }
    }

    #[Test]
    public function non_fix_commit_touching_production_paths_passes(): void
    {
        $repo = $this->makeRepo();

        try {
            $this->writeFile($repo, 'app/Services/DemoService.php', "<?php\n\nfinal class DemoService {}\n");
            $this->commit($repo, 'chore: update demo service');

            $process = $this->runGuard($repo);

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            $this->removeTree($repo);
        }
    }

    private function makeRepo(): string
    {
        $repo = sys_get_temp_dir().'/plos-repository-governance-'.bin2hex(random_bytes(6));
        mkdir($repo, 0777, true);

        $this->mustRun(['git', 'init', '-q'], $repo);
        $this->mustRun(['git', 'config', 'user.email', 'plos-test@example.invalid'], $repo);
        $this->mustRun(['git', 'config', 'user.name', 'PLOS Test'], $repo);
        $this->writeFile($repo, 'README.md', "# Test Repo\n");
        $this->mustRun(['git', 'add', '-A'], $repo);
        $this->mustRun(['git', 'commit', '-q', '-m', 'chore: seed repo'], $repo);

        return $repo;
    }

    private function writeFile(string $repo, string $relativePath, string $contents): void
    {
        $path = $repo.'/'.$relativePath;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function commit(string $repo, string $subject, ?string $body = null): void
    {
        $this->mustRun(['git', 'add', '-A'], $repo);

        $command = ['git', 'commit', '-q', '-m', $subject];
        if ($body !== null) {
            $command[] = '-m';
            $command[] = $body;
        }

        $this->mustRun($command, $repo);
    }

    private function runGuard(string $repo): Process
    {
        $process = new Process([
            'bash',
            base_path('scripts/guards/production-fix-commit-message-check.sh'),
            '--range',
            'HEAD~1..HEAD',
        ], $repo);
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    private function mustRun(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->mustRun();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
