<?php

namespace Tests\Feature\Quality;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicGithubMonitorScriptTest extends TestCase
{
    public function test_help_exits_cleanly_without_gh(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/guards/public-github-monitor.sh'), '--help'],
            base_path(),
            ['PATH' => sys_get_temp_dir()]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('Usage:', $process->getOutput());
        $this->assertStringContainsString('Read-only public GitHub release monitor', $process->getOutput());
        $this->assertStringContainsString('--strict-latest-workflows', $process->getOutput());
        $this->assertStringContainsString('--require-workflow', $process->getOutput());
        $this->assertStringContainsString('--require-default-branch', $process->getOutput());
        $this->assertStringContainsString('--require-topic', $process->getOutput());
    }

    public function test_monitor_uses_read_only_github_surfaces_without_printing_tokens(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--run-limit',
                '4',
            ],
            base_path(),
            [
                'GH_TOKEN' => 'fake-session-token',
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
            ]
        );

        $process->run();

        $output = $process->getOutput();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Repository: example/personal-life-os-core', $output);
        $this->assertStringContainsString('visibility=PUBLIC', $output);
        $this->assertStringContainsString('has_issues=true', $output);
        $this->assertStringContainsString('has_discussions=false', $output);
        $this->assertStringContainsString('license=MIT', $output);
        $this->assertStringContainsString('open_issues=0', $output);
        $this->assertStringContainsString('== Latest Workflow Status ==', $output);
        $this->assertStringContainsString('workflow=Public Readiness latest_status=completed latest_conclusion=success branch=main sha=5812537', $output);
        $this->assertStringContainsString('workflow=Public Readiness status=completed conclusion=success', $output);
        $this->assertStringContainsString('views: views=49 uniques=1', $output);
        $this->assertStringContainsString('clones: clones=77 uniques=27', $output);
        $this->assertStringContainsString('top_referrers: github.com=11/1', $output);
        $this->assertStringContainsString('Result: public GitHub monitor completed.', $output);
        $this->assertStringNotContainsString('fake-session-token', $output);
        $this->assertStringNotContainsString('fake-gh-token', $output);

        $calls = file($fixture['call_log'], FILE_IGNORE_NEW_LINES);

        $this->assertContains('repo view example/personal-life-os-core', $calls);
        $this->assertContains('api repos/example/personal-life-os-core', $calls);
        $this->assertContains('issue list --repo example/personal-life-os-core', $calls);
        $this->assertContains('pr list --repo example/personal-life-os-core', $calls);
        $this->assertContains('run list --repo example/personal-life-os-core', $calls);
        $this->assertContains('api repos/example/personal-life-os-core/traffic/views', $calls);
        $this->assertNotContains('auth status', $calls);
    }

    public function test_invalid_repo_fails_before_gh_is_required(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/guards/public-github-monitor.sh'), '--repo', 'not-a-repo'],
            base_path(),
            ['PATH' => sys_get_temp_dir()]
        );

        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('FAIL: repo must be in owner/name form.', $process->getErrorOutput());
    }

    public function test_strict_public_core_passes_for_expected_settings(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-public-core',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
            ]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Strict public-core check: pass', $process->getOutput());
    }

    public function test_strict_public_core_passes_with_required_default_branch_and_topics(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--require-default-branch',
                'main',
                '--require-topic',
                'laravel',
                '--require-topic',
                'rag',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
            ]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Strict public-core check: pass', $process->getOutput());
    }

    public function test_repo_view_failure_does_not_print_gh_stderr_or_tokens(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
            ],
            base_path(),
            [
                'GH_TOKEN' => 'fake-session-token',
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_REPO_VIEW' => 'unavailable',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'FAIL: repository metadata unavailable for example/personal-life-os-core.',
            $process->getErrorOutput()
        );
        $this->assertStringNotContainsString('fake-session-token', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('fake-gh-token', $process->getOutput().$process->getErrorOutput());
        $this->assertStringNotContainsString('gh repo view leaked stderr', $process->getOutput().$process->getErrorOutput());
    }

    public function test_strict_public_core_fails_on_settings_drift(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-public-core',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_DISCUSSIONS' => 'true',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'STRICT FAIL: GitHub Discussions expected has_discussions=false',
            $process->getOutput()
        );
    }

    public function test_strict_public_core_fails_when_required_default_branch_drifts(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--require-default-branch',
                'main',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_DEFAULT_BRANCH' => 'develop',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'STRICT FAIL: repository default branch expected default_branch=main',
            $process->getOutput()
        );
    }

    public function test_strict_public_core_fails_when_required_topic_is_missing(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--require-topic',
                'laravel',
                '--require-topic',
                'vue',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_TOPICS' => 'laravel,local-first,rag',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'STRICT FAIL: repository topic expected vue',
            $process->getOutput()
        );
    }

    public function test_pr_endpoint_unavailable_stays_informational_for_strict_checks(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-public-core',
                '--strict-latest-workflows',
                '--require-workflow',
                'Public Readiness',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_PR_LIST' => 'unavailable',
            ]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('open_prs=unavailable', $process->getOutput());
        $this->assertStringContainsString('Strict public-core check: pass', $process->getOutput());
        $this->assertStringContainsString('Strict latest-workflows check: pass', $process->getOutput());
    }

    public function test_traffic_endpoint_failure_is_redacted_and_informational(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
            ],
            base_path(),
            [
                'GH_TOKEN' => 'fake-session-token',
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_TRAFFIC_VIEWS' => 'unavailable',
            ]
        );

        $process->run();

        $combined = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('views: unavailable', $process->getOutput());
        $this->assertStringContainsString('clones: clones=77 uniques=27', $process->getOutput());
        $this->assertStringNotContainsString('fake-session-token', $combined);
        $this->assertStringNotContainsString('fake-gh-token', $combined);
        $this->assertStringNotContainsString('traffic leaked stderr', $combined);
    }

    public function test_strict_latest_workflows_passes_when_latest_runs_are_green(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
            ]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Strict latest-workflows check: pass', $process->getOutput());
    }

    public function test_strict_latest_workflows_fails_when_latest_run_is_not_green(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_RUN_CONCLUSION' => 'failure',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'STRICT FAIL: Public Readiness latest_status=completed latest_conclusion=failure branch=main sha=5812537',
            $process->getOutput()
        );
    }

    public function test_strict_latest_workflows_fails_when_workflow_run_listing_is_unavailable(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
            ],
            base_path(),
            [
                'GH_TOKEN' => 'fake-session-token',
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_RUN_LIST' => 'unavailable',
            ]
        );

        $process->run();

        $combined = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString("== Latest Workflow Status ==\nunavailable", $process->getOutput());
        $this->assertStringContainsString("== Recent Workflow Runs ==\nunavailable", $process->getOutput());
        $this->assertStringContainsString('STRICT FAIL: workflow runs unavailable', $process->getOutput());
        $this->assertStringNotContainsString('fake-session-token', $combined);
        $this->assertStringNotContainsString('fake-gh-token', $combined);
        $this->assertStringNotContainsString('run list leaked stderr', $combined);
    }

    public function test_strict_latest_workflows_fails_when_workflow_run_listing_is_empty(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_RUN_LIST' => 'empty',
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString("== Latest Workflow Status ==\nnone", $process->getOutput());
        $this->assertStringContainsString("== Recent Workflow Runs ==\nnone", $process->getOutput());
        $this->assertStringContainsString('STRICT FAIL: no workflow runs available', $process->getOutput());
    }

    public function test_strict_latest_workflows_passes_when_documented_required_workflows_are_present(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
                '--require-workflow',
                'Repository Governance',
                '--require-workflow',
                'Public Readiness',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
                'PUBLIC_GITHUB_MONITOR_FAKE_INCLUDE_GOVERNANCE' => 'true',
            ]
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('workflow=Repository Governance latest_status=completed latest_conclusion=success branch=main sha=5812537', $process->getOutput());
        $this->assertStringContainsString('workflow=Public Readiness latest_status=completed latest_conclusion=success branch=main sha=5812537', $process->getOutput());
        $this->assertStringContainsString('Strict latest-workflows check: pass', $process->getOutput());
    }

    public function test_strict_latest_workflows_fails_when_required_workflow_is_missing(): void
    {
        $fixture = $this->makeFixture();

        $process = new Process(
            [
                'bash',
                base_path('scripts/guards/public-github-monitor.sh'),
                '--repo',
                'example/personal-life-os-core',
                '--strict-latest-workflows',
                '--require-workflow',
                'Repository Governance',
            ],
            base_path(),
            [
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
                'PUBLIC_GITHUB_MONITOR_CALL_LOG' => $fixture['call_log'],
            ]
        );

        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(
            'STRICT FAIL: Repository Governance required workflow missing from latest run window',
            $process->getOutput()
        );
    }

    public function test_missing_repo_fails_before_gh_is_required(): void
    {
        $process = new Process(
            ['bash', base_path('scripts/guards/public-github-monitor.sh')],
            base_path(),
            [
                'PATH' => sys_get_temp_dir(),
                'PLOS_PUBLIC_GITHUB_REPO' => '',
            ]
        );

        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('FAIL: provide --repo owner/name or set PLOS_PUBLIC_GITHUB_REPO.', $process->getErrorOutput());
    }

    /**
     * @return array{root:string,bin:string,call_log:string}
     */
    private function makeFixture(): array
    {
        $root = sys_get_temp_dir().'/plos-public-github-monitor-'.str_replace('.', '', uniqid('', true));
        $bin = $root.'/bin';
        $callLog = $root.'/gh-calls.log';

        mkdir($bin, 0700, true);

        file_put_contents($bin.'/gh', <<<'SH'
#!/usr/bin/env bash
log_call() {
    if [[ -n "${PUBLIC_GITHUB_MONITOR_CALL_LOG:-}" ]]; then
        printf '%s\n' "$1" >> "$PUBLIC_GITHUB_MONITOR_CALL_LOG"
    fi
}

if [[ "${1:-}" == "repo" && "${2:-}" == "view" ]]; then
    log_call "repo view ${3:-}"
    if [[ "${PUBLIC_GITHUB_MONITOR_FAKE_REPO_VIEW:-available}" == "unavailable" ]]; then
        printf 'gh repo view leaked stderr Token: fake-gh-token\n' >&2
        exit 1
    fi
    printf 'url=https://github.com/example/personal-life-os-core\n'
    printf 'visibility=PUBLIC\n'
    printf 'private=false\n'
    printf 'default_branch=%s\n' "${PUBLIC_GITHUB_MONITOR_FAKE_DEFAULT_BRANCH:-main}"
    printf 'release=v0.1.0\n'
    printf 'stars=0\n'
    printf 'forks=0\n'
    printf 'watchers=0\n'
    printf 'topics=%s\n' "${PUBLIC_GITHUB_MONITOR_FAKE_TOPICS:-laravel,local-first,rag}"
    exit 0
fi

if [[ "${1:-}" == "issue" && "${2:-}" == "list" ]]; then
    log_call "issue list --repo ${4:-}"
    printf '0\n'
    exit 0
fi

if [[ "${1:-}" == "pr" && "${2:-}" == "list" ]]; then
    log_call "pr list --repo ${4:-}"
    if [[ "${PUBLIC_GITHUB_MONITOR_FAKE_PR_LIST:-available}" == "unavailable" ]]; then
        printf 'pr list unavailable\n' >&2
        exit 1
    fi
    printf '0\n'
    exit 0
fi

if [[ "${1:-}" == "run" && "${2:-}" == "list" ]]; then
    log_call "run list --repo ${4:-}"
    case "${PUBLIC_GITHUB_MONITOR_FAKE_RUN_LIST:-available}" in
        unavailable)
            printf 'run list leaked stderr Token: fake-gh-token\n' >&2
            exit 1
            ;;
        empty)
            exit 0
            ;;
    esac
    printf '2026-05-03T13:29:00Z workflow=Public Readiness status=%s conclusion=%s branch=main sha=5812537 title=docs: tighten issue template privacy prompts\n' "${PUBLIC_GITHUB_MONITOR_FAKE_RUN_STATUS:-completed}" "${PUBLIC_GITHUB_MONITOR_FAKE_RUN_CONCLUSION:-success}"
    if [[ "${PUBLIC_GITHUB_MONITOR_FAKE_INCLUDE_GOVERNANCE:-false}" == "true" ]]; then
        printf '2026-05-03T13:28:00Z workflow=Repository Governance status=completed conclusion=success branch=main sha=5812537 title=docs: tighten issue template privacy prompts\n'
    fi
    exit 0
fi

if [[ "${1:-}" == "api" ]]; then
    log_call "api ${2:-}"
    case "${2:-}" in
        repos/example/personal-life-os-core)
            printf 'has_issues=true\n'
            printf 'has_discussions=%s\n' "${PUBLIC_GITHUB_MONITOR_FAKE_DISCUSSIONS:-false}"
            printf 'license=MIT\n'
            printf 'archived=false\n'
            printf 'disabled=false\n'
            ;;
        repos/example/personal-life-os-core/traffic/views)
            if [[ "${PUBLIC_GITHUB_MONITOR_FAKE_TRAFFIC_VIEWS:-available}" == "unavailable" ]]; then
                printf 'traffic leaked stderr Token: fake-gh-token\n' >&2
                exit 1
            fi
            printf 'views=49 uniques=1\n'
            ;;
        repos/example/personal-life-os-core/traffic/clones)
            printf 'clones=77 uniques=27\n'
            ;;
        repos/example/personal-life-os-core/traffic/popular/referrers)
            printf 'github.com=11/1\n'
            ;;
        repos/example/personal-life-os-core/traffic/popular/paths)
            printf '/example/personal-life-os-core=14/1,/example/personal-life-os-core/actions=5/1\n'
            ;;
        *)
            printf 'Token: fake-gh-token\n'
            exit 1
            ;;
    esac
    exit 0
fi

printf 'Token: fake-gh-token\n'
exit 2
SH);
        chmod($bin.'/gh', 0700);

        return [
            'root' => $root,
            'bin' => $bin,
            'call_log' => $callLog,
        ];
    }
}
