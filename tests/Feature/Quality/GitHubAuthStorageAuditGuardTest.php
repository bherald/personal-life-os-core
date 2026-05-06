<?php

namespace Tests\Feature\Quality;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class GitHubAuthStorageAuditGuardTest extends TestCase
{
    public function test_help_exits_cleanly_without_invoking_gh_or_reading_auth_files(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--help']);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('Usage:', $result->getOutput());
        $this->assertStringContainsString('session-scoped GH_TOKEN/GITHUB_TOKEN', $result->getOutput());
        $this->assertStringNotContainsString('Token:', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_require_session_token_fails_without_session_token_and_redacts_gh_output(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-session-token']);

        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString('FAIL: no session-scoped GH_TOKEN/GITHUB_TOKEN is present.', $result->getOutput());
        $this->assertStringContainsString('Token: [redacted]', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_allow_plaintext_with_session_token_passes_without_printing_tokens(): void
    {
        $fixture = $this->makeFixture();
        $hostsDir = $fixture['config'].'/gh';
        mkdir($hostsDir, 0700, true);
        file_put_contents($hostsDir.'/hosts.yml', "github.com:\n    oauth_token: stored-secret\n");
        chmod($hostsDir.'/hosts.yml', 0600);

        $result = $this->runGuard($fixture, ['--allow-plaintext', '--require-session-token'], [
            'GH_TOKEN' => 'session-secret',
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN is present', $result->getOutput());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN can authenticate gh without persistent hosts.yml.', $result->getOutput());
        $this->assertStringContainsString('WARN: plaintext GitHub CLI token key is present', $result->getOutput());
        $this->assertStringContainsString('Token: [redacted]', $result->getOutput());
        $this->assertStringNotContainsString('stored-secret', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_clean_session_token_posture_passes_without_hosts_file(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-session-token'], [
            'GITHUB_TOKEN' => 'session-secret',
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN is present', $result->getOutput());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN can authenticate gh without persistent hosts.yml.', $result->getOutput());
        $this->assertStringContainsString('OK: no GitHub CLI hosts.yml file found', $result->getOutput());
        $this->assertStringContainsString('Token: [redacted]', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_session_token_is_redacted_from_nonstandard_gh_status_lines(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-session-token'], [
            'GITHUB_TOKEN' => 'session-secret',
            'FAKE_GH_LEAK_SESSION_TOKEN' => '1',
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('debug-session-token=[redacted]', $result->getOutput());
        $this->assertStringContainsString('session-gh: debug-session-token=[redacted]', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_require_session_token_fails_when_isolated_session_auth_fails(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-session-token'], [
            'GITHUB_TOKEN' => 'session-secret',
            'FAKE_GH_FAIL_ISOLATED' => '1',
        ]);

        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN is present', $result->getOutput());
        $this->assertStringContainsString('FAIL: session-scoped GH_TOKEN/GITHUB_TOKEN did not authenticate gh with isolated config.', $result->getOutput());
        $this->assertStringContainsString('session-gh: Token: [redacted]', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('isolated-fake-gh-token', $result->getOutput());
    }

    public function test_require_session_token_uses_isolated_config_when_caller_sets_gh_config_dir(): void
    {
        $fixture = $this->makeFixture();
        $persistentConfigDir = $fixture['root'].'/persistent-gh-config';
        mkdir($persistentConfigDir, 0700, true);
        file_put_contents($persistentConfigDir.'/hosts.yml', "github.com:\n    oauth_token: stored-secret\n");
        chmod($persistentConfigDir.'/hosts.yml', 0600);
        $callLog = $fixture['root'].'/gh-calls.log';

        $result = $this->runGuard($fixture, ['--allow-plaintext', '--require-session-token'], [
            'GH_CONFIG_DIR' => $persistentConfigDir,
            'GH_TOKEN' => 'session-secret',
            'FAKE_GH_CALL_LOG' => $callLog,
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('INFO: GitHub CLI hosts file: '.$persistentConfigDir.'/hosts.yml', $result->getOutput());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN can authenticate gh without persistent hosts.yml.', $result->getOutput());
        $this->assertStringNotContainsString('stored-secret', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());

        $calls = file($callLog, FILE_IGNORE_NEW_LINES);

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('GH_CONFIG_DIR='.$persistentConfigDir, $calls[0]);
        $this->assertStringStartsWith('GH_CONFIG_DIR=', $calls[1]);

        $isolatedConfigDir = explode(' ', substr($calls[1], strlen('GH_CONFIG_DIR=')), 2)[0];

        $this->assertNotSame($persistentConfigDir, $isolatedConfigDir);
        $this->assertStringContainsString('plos-gh-auth-session.', $isolatedConfigDir);
    }

    public function test_env_host_is_used_for_persistent_and_isolated_auth_checks(): void
    {
        $fixture = $this->makeFixture();
        $callLog = $fixture['root'].'/gh-calls.log';

        $result = $this->runGuard($fixture, ['--require-session-token'], [
            'GH_AUTH_AUDIT_HOST' => 'github.internal.example',
            'GH_TOKEN' => 'session-secret',
            'FAKE_GH_CALL_LOG' => $callLog,
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('INFO: GitHub host: github.internal.example', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());

        $calls = file($callLog, FILE_IGNORE_NEW_LINES);

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('HOST=github.internal.example', $calls[0]);
        $this->assertStringContainsString('HOST=github.internal.example', $calls[1]);
    }

    public function test_cli_host_overrides_env_host_for_persistent_and_isolated_auth_checks(): void
    {
        $fixture = $this->makeFixture();
        $callLog = $fixture['root'].'/gh-calls.log';

        $result = $this->runGuard($fixture, ['--host', 'github.enterprise.example', '--require-session-token'], [
            'GH_AUTH_AUDIT_HOST' => 'github.internal.example',
            'GH_TOKEN' => 'session-secret',
            'FAKE_GH_CALL_LOG' => $callLog,
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('INFO: GitHub host: github.enterprise.example', $result->getOutput());
        $this->assertStringNotContainsString('github.internal.example', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());

        $calls = file($callLog, FILE_IGNORE_NEW_LINES);

        $this->assertCount(2, $calls);
        $this->assertStringContainsString('HOST=github.enterprise.example', $calls[0]);
        $this->assertStringContainsString('HOST=github.enterprise.example', $calls[1]);
    }

    public function test_option_like_env_host_fails_before_invoking_gh(): void
    {
        $fixture = $this->makeFixture();
        $callLog = $fixture['root'].'/gh-calls.log';

        $result = $this->runGuard($fixture, [], [
            'GH_AUTH_AUDIT_HOST' => '--help',
            'FAKE_GH_CALL_LOG' => $callLog,
        ]);

        $this->assertSame(2, $result->getExitCode());
        $this->assertStringContainsString('GitHub host must be a hostname', $result->getErrorOutput());
        $this->assertFileDoesNotExist($callLog);
        $this->assertStringNotContainsString('Token:', $result->getOutput().$result->getErrorOutput());
    }

    public function test_option_like_cli_host_fails_before_invoking_gh(): void
    {
        $fixture = $this->makeFixture();
        $callLog = $fixture['root'].'/gh-calls.log';

        $result = $this->runGuard($fixture, ['--host', '--require-session-token'], [
            'FAKE_GH_CALL_LOG' => $callLog,
            'GH_TOKEN' => 'session-secret',
        ]);

        $this->assertSame(2, $result->getExitCode());
        $this->assertStringContainsString('Usage:', $result->getErrorOutput());
        $this->assertFileDoesNotExist($callLog);
        $this->assertStringNotContainsString('session-secret', $result->getOutput().$result->getErrorOutput());
    }

    public function test_require_workflow_scope_fails_when_scope_is_not_reported(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-workflow-scope']);

        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString('FAIL: gh did not report token scopes; cannot confirm workflow scope.', $result->getOutput());
        $this->assertStringContainsString('Token: [redacted]', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_require_workflow_scope_fails_when_token_lacks_scope(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-workflow-scope'], [
            'FAKE_GH_SCOPES' => "'gist', 'read:org', 'repo'",
        ]);

        $this->assertSame(1, $result->getExitCode());
        $this->assertStringContainsString('FAIL: gh token is missing workflow scope.', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_require_workflow_scope_passes_for_isolated_session_token(): void
    {
        $fixture = $this->makeFixture();

        $result = $this->runGuard($fixture, ['--require-session-token', '--require-workflow-scope'], [
            'GITHUB_TOKEN' => 'session-secret',
            'FAKE_GH_SCOPES' => "'gist', 'read:org', 'repo'",
            'FAKE_GH_ISOLATED_SCOPES' => "'repo', 'workflow'",
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('INFO: workflow scope will be checked against the isolated session token, not the persistent gh bridge.', $result->getOutput());
        $this->assertStringContainsString('OK: session-gh token includes workflow scope.', $result->getOutput());
        $this->assertStringContainsString('OK: session-scoped GH_TOKEN/GITHUB_TOKEN can authenticate gh without persistent hosts.yml.', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    public function test_session_workflow_scope_can_pass_when_persistent_bridge_lacks_workflow_scope(): void
    {
        $fixture = $this->makeFixture();
        $hostsDir = $fixture['config'].'/gh';
        mkdir($hostsDir, 0700, true);
        file_put_contents($hostsDir.'/hosts.yml', "github.com:\n    oauth_token: stored-secret\n");
        chmod($hostsDir.'/hosts.yml', 0600);

        $result = $this->runGuard($fixture, ['--allow-plaintext', '--require-session-token', '--require-workflow-scope'], [
            'GH_TOKEN' => 'session-secret',
            'FAKE_GH_SCOPES' => "'gist', 'read:org', 'repo'",
            'FAKE_GH_ISOLATED_SCOPES' => "'repo', 'workflow'",
        ]);

        $this->assertSame(0, $result->getExitCode());
        $this->assertStringContainsString('INFO: workflow scope will be checked against the isolated session token, not the persistent gh bridge.', $result->getOutput());
        $this->assertStringContainsString('OK: session-gh token includes workflow scope.', $result->getOutput());
        $this->assertStringContainsString('WARN: plaintext GitHub CLI token key is present', $result->getOutput());
        $this->assertStringNotContainsString('stored-secret', $result->getOutput());
        $this->assertStringNotContainsString('session-secret', $result->getOutput());
        $this->assertStringNotContainsString('fake-gh-token', $result->getOutput());
    }

    /**
     * @return array{root:string,bin:string,home:string,config:string}
     */
    private function makeFixture(): array
    {
        $root = sys_get_temp_dir().'/plos-gh-auth-guard-'.str_replace('.', '', uniqid('', true));
        $bin = $root.'/bin';
        $home = $root.'/home';
        $config = $root.'/config';

        mkdir($bin, 0700, true);
        mkdir($home, 0700, true);
        mkdir($config, 0700, true);

        file_put_contents($bin.'/gh', <<<'SH'
#!/usr/bin/env bash
if [[ "${1:-}" == "auth" && "${2:-}" == "status" ]]; then
    host=""
    for ((i = 3; i <= $#; i++)); do
        arg="${!i}"
        if [[ "$arg" == "-h" || "$arg" == "--hostname" ]]; then
            next=$((i + 1))
            host="${!next:-}"
            break
        fi
    done
    if [[ -n "${FAKE_GH_CALL_LOG:-}" ]]; then
        printf 'GH_CONFIG_DIR=%s HOST=%s\n' "${GH_CONFIG_DIR:-}" "$host" >> "$FAKE_GH_CALL_LOG"
    fi
    if [[ -n "${FAKE_GH_FAIL_ISOLATED:-}" && "${GH_CONFIG_DIR:-}" == *plos-gh-auth-session.* ]]; then
        echo "Logged in to github.com"
        echo "Token: isolated-fake-gh-token"
        exit 1
    fi
    echo "Logged in to github.com"
    echo "Token: fake-gh-token"
    if [[ -n "${FAKE_GH_LEAK_SESSION_TOKEN:-}" ]]; then
        echo "debug-session-token=${GH_TOKEN:-${GITHUB_TOKEN:-}}"
    fi
    scopes="${FAKE_GH_SCOPES:-}"
    if [[ "${GH_CONFIG_DIR:-}" == *plos-gh-auth-session.* && -n "${FAKE_GH_ISOLATED_SCOPES:-}" ]]; then
        scopes="$FAKE_GH_ISOLATED_SCOPES"
    fi
    if [[ -n "$scopes" ]]; then
        echo "Token scopes: $scopes"
    fi
    exit 0
fi
exit 2
SH);
        chmod($bin.'/gh', 0700);

        return [
            'root' => $root,
            'bin' => $bin,
            'home' => $home,
            'config' => $config,
        ];
    }

    /**
     * @param  array{root:string,bin:string,home:string,config:string}  $fixture
     * @param  list<string>  $arguments
     * @param  array<string,string>  $extraEnv
     */
    private function runGuard(array $fixture, array $arguments, array $extraEnv = []): Process
    {
        $process = new Process(
            array_merge(['bash', base_path('scripts/guards/github-auth-storage-audit.sh')], $arguments),
            base_path(),
            array_merge([
                'FAKE_GH_CALL_LOG' => '',
                'FAKE_GH_FAIL_ISOLATED' => '',
                'FAKE_GH_ISOLATED_SCOPES' => '',
                'FAKE_GH_SCOPES' => '',
                'GH_AUTH_AUDIT_HOST' => '',
                'GH_CONFIG_DIR' => '',
                'GH_TOKEN' => '',
                'GITHUB_TOKEN' => '',
                'HOME' => $fixture['home'],
                'XDG_CONFIG_HOME' => $fixture['config'],
                'PATH' => $fixture['bin'].PATH_SEPARATOR.getenv('PATH'),
            ], $extraEnv)
        );
        $process->run();

        return $process;
    }
}
