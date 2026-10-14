<?php

namespace Tests\Unit\Services;

use App\Engine\MCPRouter;
use App\Services\RepoDevMCPService;
use App\Services\RepoEditRecoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\Support\PreservesSystemConfig;
use Tests\TestCase;

class RepoDevMCPServiceTest extends TestCase
{
    use PreservesSystemConfig;

    private RepoDevMCPService $service;

    private string $tempRelativePath = 'storage/framework/testing/repo-dev-mcp-test.txt';

    private string $tempPhpRelativePath = 'storage/framework/testing/repo-dev-mcp-test.php';

    private string $contextMapFixtureRelativePath = 'tests/Fixtures/dev-agent/repo-context-map-fixture.php';

    private string $excludedContextMapDirectory = 'tests/Fixtures/dev-agent/.venv';

    private string $excludedContextMapFile = 'tests/Fixtures/dev-agent/.venv/hidden-runtime-context-decoy.php';

    /** @var list<string> */
    private array $recoveryTokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(RepoDevMCPService::class);
        Cache::flush();
        $this->setActiveProfile('offline_dev_assist', 'Repo dev MCP test profile');
        config()->set('dev_agent.repo_edit_recovery.enabled', false);
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->tempRelativePath));
        @unlink(base_path($this->tempPhpRelativePath));
        @unlink(base_path($this->excludedContextMapFile));
        @rmdir(base_path($this->excludedContextMapDirectory));
        foreach ($this->recoveryTokens as $token) {
            @unlink(storage_path('app/dev-agent/recovery/'.$token.'.json'));
        }
        $this->restorePreservedSystemConfigs();
        Cache::flush();
        parent::tearDown();
    }

    public function test_list_routes_can_filter_frontend_scope(): void
    {
        $result = $this->service->listRoutes('frontend');

        $this->assertIsArray($result['routes']);
        $this->assertGreaterThan(0, $result['count']);
        $this->assertTrue(
            collect($result['routes'])->contains(fn (array $route): bool => ($route['uri'] ?? null) === 'review/{token}')
        );
        $this->assertFalse(
            collect($result['routes'])->contains(fn (array $route): bool => str_starts_with((string) ($route['uri'] ?? ''), 'api/'))
        );
    }

    public function test_repo_dev_catalog_exposes_the_bounded_summary_contract(): void
    {
        $tool = collect(app(MCPRouter::class)->getAvailableTools())
            ->first(fn (array $candidate): bool => ($candidate['server'] ?? null) === 'repo-dev'
                && ($candidate['name'] ?? null) === 'summarize_repo_edits');

        $this->assertIsArray($tool);
        $this->assertSame(['tokens'], $tool['parameters']['required']);
        $this->assertSame('array', $tool['parameters']['properties']->tokens['type']);
        $this->assertSame(1, $tool['parameters']['properties']->tokens['minItems']);
        $this->assertSame(50, $tool['parameters']['properties']->tokens['maxItems']);
    }

    public function test_read_repo_file_returns_numbered_lines(): void
    {
        $result = $this->service->readRepoFile('routes/web.php', 1, 5);

        $this->assertSame('routes/web.php', $result['path']);
        $this->assertSame(1, $result['line_start']);
        $this->assertSame(5, $result['line_end']);
        $this->assertCount(5, $result['lines']);
        $this->assertSame(1, $result['lines'][0]['line']);
        $this->assertStringContainsString('<?php', $result['lines'][0]['text']);
    }

    public function test_context_map_ranks_matching_files_and_php_declarations_without_source_bodies(): void
    {
        $task = 'Find NebulaContextTarget and resolveNebulaSignal for the local agent';
        $result = $this->service->contextMap($task, 'tests/Fixtures/dev-agent', 5);

        $this->assertSame('plos.repo_context_map.v1', $result['schema']);
        $this->assertTrue($result['read_only']);
        $this->assertSame(hash('sha256', $task), $result['task_sha256']);
        $this->assertSame($this->contextMapFixtureRelativePath, $result['files'][0]['path']);
        $this->assertContains(
            'Tests\\Fixtures\\DevAgent\\NebulaContextTarget',
            array_column($result['files'][0]['declarations'], 'name')
        );
        $this->assertContains(
            'resolveNebulaSignal',
            array_column($result['files'][0]['declarations'], 'name')
        );
        $this->assertSame(2, $result['files'][0]['declaration_count']);
        $this->assertSame('complete', $result['coverage']['status']);
        $this->assertTrue($result['coverage']['search_space_complete']);
        $this->assertTrue($result['coverage']['inventory']['discovered_count_exact']);
        $this->assertSame(0, $result['coverage']['inventory']['omitted_files_minimum']);
        $this->assertSame([], $result['coverage']['reason_codes']);
        $this->assertSame(60000, $result['summary']['runtime_budget_ms']);
        $this->assertGreaterThanOrEqual(0, $result['summary']['duration_ms']);
        $this->assertTrue($result['posture']['runtime_deadline_enforced']);
        $this->assertFalse($result['posture']['partial_results_returned_after_deadline']);
        $this->assertTrue($result['posture']['supplemental_context_selection_not_prompt_compression']);
        $this->assertTrue($result['posture']['coverage_claims_complete_only_when_proven']);
        $this->assertStringNotContainsString($task, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('source-body-must-not-be-returned', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_context_map_rejects_tasks_without_usable_terms(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('usable search terms');

        $this->service->contextMap('the this with', '.');
    }

    public function test_context_map_can_be_disabled_by_policy(): void
    {
        config()->set('dev_agent.repo_context_map.enabled', false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled');

        $this->service->contextMap('repository context', '.');
    }

    public function test_context_map_fails_closed_after_the_overall_runtime_budget(): void
    {
        config()->set('dev_agent.repo_context_map.max_runtime_ms', 1);
        Process::fake(function ($process) {
            usleep(10000);

            return Process::result($this->contextMapFixtureRelativePath."\n");
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeded its runtime budget');

        $this->service->contextMap('Find NebulaContextTarget', 'tests/Fixtures/dev-agent', 5);
    }

    public function test_context_map_uses_ignore_aware_ripgrep_inventory_without_git_metadata(): void
    {
        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($command, 'git ls-files')) {
                return Process::result(exitCode: 1);
            }
            if (str_contains($command, '--files-with-matches')) {
                return Process::result("RepoDevMCPService.php\n");
            }
            if (str_contains($command, 'rg --files')) {
                return Process::result("RepoDevMCPService.php\n");
            }

            return Process::result(exitCode: 1);
        });

        $result = $this->service->contextMap('repo context map service', 'app/Services', 5);

        $this->assertSame('ripgrep', $result['inventory_source']);
        $this->assertSame('app/Services/RepoDevMCPService.php', $result['files'][0]['path']);
        $this->assertTrue($result['posture']['ignored_files_excluded']);
    }

    public function test_context_map_uses_bounded_native_inventory_without_git_or_ripgrep(): void
    {
        Process::fake(fn () => Process::result(exitCode: 127));

        $result = $this->service->contextMap(
            'Find NebulaContextTarget and resolveNebulaSignal',
            'tests/Fixtures/dev-agent',
            5
        );

        $this->assertSame('php', $result['inventory_source']);
        $this->assertSame($this->contextMapFixtureRelativePath, $result['files'][0]['path']);
        $this->assertFalse($result['posture']['ignored_files_excluded']);
        $this->assertTrue($result['posture']['native_inventory_exclusions_applied']);
        $this->assertFalse($result['posture']['native_inventory_candidate_cap_applied']);
        $this->assertFalse($result['posture']['native_content_search_complete']);
        $this->assertFalse($result['coverage']['search_space_complete']);
        $this->assertContains('native_content_search_unavailable', $result['coverage']['reason_codes']);
        $this->assertSame(1, $result['summary']['candidate_files']);
    }

    public function test_native_inventory_uses_exact_file_grep_for_content_only_matches(): void
    {
        Process::fake(function ($process) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === 'grep') {
                return Process::result($this->contextMapFixtureRelativePath."\0"."2\n");
            }

            return Process::result(exitCode: 127);
        });

        $result = $this->service->contextMap(
            'Find NebulaContextTarget and resolveNebulaSignal',
            'tests/Fixtures/dev-agent',
            5
        );

        $this->assertSame('php', $result['inventory_source']);
        $this->assertSame('grep', $result['posture']['native_content_search_source']);
        $this->assertTrue($result['posture']['native_declaration_prescore_applied']);
        $this->assertFalse($result['posture']['native_inventory_candidate_cap_applied']);
        $this->assertTrue($result['posture']['native_content_search_complete']);
        $this->assertTrue($result['coverage']['search_space_complete']);
        $this->assertSame(1, $result['summary']['candidate_files']);
        $this->assertSame(
            'global_path_content_bounded_declaration_prescore',
            $result['coverage']['candidate_selection']['source']
        );
        $this->assertSame(1, $result['coverage']['candidate_selection']['declaration_prescored_files']);
        $this->assertSame(
            ['inventory', 'path_scoring', 'content_search_and_candidate_selection', 'candidate_analysis_and_ranking'],
            array_keys($result['summary']['phase_duration_ms'])
        );
        $this->assertSame($this->contextMapFixtureRelativePath, $result['files'][0]['path']);
    }

    public function test_native_context_inventory_excludes_generated_environment_segments(): void
    {
        @mkdir(base_path($this->excludedContextMapDirectory), 0775, true);
        file_put_contents(
            base_path($this->excludedContextMapFile),
            "<?php\n\nfunction hiddenRuntimeContextDecoy(): void {}\n"
        );
        Process::fake(fn () => Process::result(exitCode: 127));

        $result = $this->service->contextMap(
            'Find hiddenRuntimeContextDecoy in generated environment files',
            'tests/Fixtures/dev-agent',
            5
        );

        $this->assertSame('php', $result['inventory_source']);
        $this->assertNotContains($this->excludedContextMapFile, array_column($result['files'], 'path'));
        $this->assertNotContains('.venv', config('dev_agent.repo_context_map.native_excluded_paths'));
        $this->assertContains('.venv', config('dev_agent.repo_context_map.native_excluded_segments'));
    }

    public function test_context_map_keeps_large_frontend_matches_as_metadata_only_candidates(): void
    {
        $result = $this->service->contextMap(
            'Where does the genealogy picture review UI approve and link a photo to a person?',
            '.',
            10
        );
        $view = collect($result['files'])->firstWhere('path', 'resources/js/src/views/GenealogyView.vue');

        $this->assertIsArray($view);
        $this->assertTrue($view['selection']['metadata_only']);
        $this->assertSame([], $view['declarations']);
        $this->assertGreaterThan(0, $result['summary']['metadata_only_files']);
        $this->assertTrue($result['posture']['oversized_matches_metadata_only']);
        $this->assertArrayHasKey('candidate_analysis', $result['coverage']);
        $this->assertArrayHasKey('result_window', $result['coverage']);
        $this->assertFalse($result['posture']['source_bodies_included']);
    }

    public function test_write_repo_file_writes_inside_repo_scope(): void
    {
        $result = $this->service->writeRepoFile($this->tempRelativePath, "alpha\nbeta\n", true);

        $this->assertSame($this->tempRelativePath, $result['path']);
        $this->assertFileExists(base_path($this->tempRelativePath));
        $this->assertSame("alpha\nbeta\n", file_get_contents(base_path($this->tempRelativePath)));
        $this->assertSame('not_applicable', $result['diagnostics']['status']);
    }

    public function test_write_repo_file_returns_passing_php_syntax_diagnostics(): void
    {
        $result = $this->service->writeRepoFile($this->tempPhpRelativePath, "<?php\n\nreturn true;\n", true);

        $this->assertSame('passed', $result['diagnostics']['status']);
        $this->assertSame(1, $result['diagnostics']['checked_files']);
        $this->assertSame(0, $result['diagnostics']['failed_files']);
        $this->assertSame('php-syntax', $result['diagnostics']['results'][0]['runner']);
        $this->assertTrue($result['diagnostics']['results'][0]['success']);
    }

    public function test_write_repo_file_surfaces_broken_php_syntax_without_hiding_the_edit(): void
    {
        $result = $this->service->writeRepoFile($this->tempPhpRelativePath, "<?php\n\nfunction broken( {\n", true);

        $this->assertFileExists(base_path($this->tempPhpRelativePath));
        $this->assertSame('failed', $result['diagnostics']['status']);
        $this->assertSame(1, $result['diagnostics']['checked_files']);
        $this->assertSame(1, $result['diagnostics']['failed_files']);
        $this->assertFalse($result['diagnostics']['results'][0]['success']);
    }

    public function test_write_repo_file_fails_closed_for_unknown_diagnostic_runner(): void
    {
        config()->set('dev_agent.post_edit_diagnostics.runners', [
            'untrusted-runner' => ['extensions' => ['php']],
        ]);

        $result = $this->service->writeRepoFile($this->tempPhpRelativePath, "<?php\n\nreturn true;\n", true);

        $this->assertSame('failed', $result['diagnostics']['status']);
        $this->assertSame(1, $result['diagnostics']['failed_files']);
        $this->assertSame('Diagnostic runner is not allowlisted.', $result['diagnostics']['results'][0]['error']);
    }

    public function test_write_repo_file_refuses_protected_paths(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected');

        $this->service->writeRepoFile('.env', 'APP_NAME=bad');
    }

    public function test_restore_repo_edit_preserves_the_preexisting_dirty_file_state(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "operator change\n");

        $write = $this->service->writeRepoFile($this->tempRelativePath, "agent change\n", true);
        $token = $this->trackRecovery($write);

        $this->assertSame([$this->tempRelativePath], $write['recovery']['paths']);
        $this->assertSame(0600, fileperms($this->packetPath($token)) & 0777);

        $preview = $this->service->restoreRepoEdit($token, checkOnly: true);
        $this->assertSame('ready', $preview['status']);
        $this->assertSame("agent change\n", file_get_contents(base_path($this->tempRelativePath)));

        $restore = $this->service->restoreRepoEdit($token);
        $this->assertTrue($restore['restored']);
        $this->assertSame('restored', $restore['status']);
        $this->assertSame("operator change\n", file_get_contents(base_path($this->tempRelativePath)));
        $this->assertFileDoesNotExist($this->packetPath($token));
    }

    public function test_write_repo_file_returns_a_content_free_change_summary(): void
    {
        $this->enableRecovery();

        $write = $this->service->writeRepoFile($this->tempRelativePath, "alpha\nbeta\n", true);
        $token = $this->trackRecovery($write);
        $summary = $write['change_summary'];

        $this->assertSame('plos.repo_edit_summary.v1', $summary['schema']);
        $this->assertSame('ok', $summary['status']);
        $this->assertSame(1, $summary['operation_count']);
        $this->assertSame(1, $summary['files_changed']);
        $this->assertSame(2, $summary['additions']);
        $this->assertSame(0, $summary['deletions']);
        $this->assertSame('added', $summary['files'][0]['change']);
        $this->assertFalse($summary['posture']['content_included']);
        $this->assertFalse($summary['posture']['recovery_tokens_included']);
        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('alpha', $encoded);
        $this->assertStringNotContainsString($token, $encoded);
    }

    public function test_summarize_repo_edits_returns_the_net_effect_of_an_ordered_chain(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "alpha\n");

        $first = $this->service->writeRepoFile($this->tempRelativePath, "alpha\nbeta\n", true);
        $firstToken = $this->trackRecovery($first);
        $second = $this->service->writeRepoFile($this->tempRelativePath, "alpha\nbeta\ngamma\n", true);
        $secondToken = $this->trackRecovery($second);

        $summary = $this->service->summarizeRepoEdits([$firstToken, $secondToken]);

        $this->assertSame(2, $summary['operation_count']);
        $this->assertSame(1, $summary['files_changed']);
        $this->assertSame(2, $summary['additions']);
        $this->assertSame(0, $summary['deletions']);
        $this->assertSame($this->tempRelativePath, $summary['files'][0]['path']);
        $this->assertSame('modified', $summary['files'][0]['change']);
        $this->assertTrue($summary['posture']['continuous_ordered_chain']);
    }

    public function test_summarize_repo_edits_refuses_a_discontinuous_file_chain(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "before\n");
        $first = $this->service->writeRepoFile($this->tempRelativePath, "agent one\n", true);
        $firstToken = $this->trackRecovery($first);

        file_put_contents(base_path($this->tempRelativePath), "operator change\n");
        $second = $this->service->writeRepoFile($this->tempRelativePath, "agent two\n", true);
        $secondToken = $this->trackRecovery($second);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('continuous ordered edit chain');

        $this->service->summarizeRepoEdits([$firstToken, $secondToken]);
    }

    public function test_change_summary_does_not_mislabel_missing_git_stats_as_binary(): void
    {
        $this->enableRecovery();
        Process::fake(fn () => Process::result(exitCode: 127));

        $write = $this->service->writeRepoFile($this->tempRelativePath, "plain text\n", true);
        $this->trackRecovery($write);
        $summary = $write['change_summary'];

        $this->assertFalse($summary['line_stats_complete']);
        $this->assertSame(1, $summary['line_stats_unavailable_files']);
        $this->assertSame(0, $summary['binary_files']);
        $this->assertFalse($summary['files'][0]['binary']);
        $this->assertFalse($summary['files'][0]['line_stats_available']);
        $this->assertNull($summary['files'][0]['additions']);
        $this->assertNull($summary['files'][0]['deletions']);
    }

    public function test_restore_repo_edit_removes_a_file_created_by_the_agent(): void
    {
        $this->enableRecovery();

        $write = $this->service->writeRepoFile($this->tempRelativePath, "created\n", true);
        $token = $this->trackRecovery($write);

        $restore = $this->service->restoreRepoEdit($token);

        $this->assertTrue($restore['restored']);
        $this->assertFileDoesNotExist(base_path($this->tempRelativePath));
    }

    public function test_unchanged_write_does_not_create_a_recovery_packet(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "same\n");

        $write = $this->service->writeRepoFile($this->tempRelativePath, "same\n", true);

        $this->assertFalse($write['recovery']['available']);
        $this->assertSame('no_changes', $write['recovery']['reason']);
    }

    public function test_restore_repo_edit_refuses_to_overwrite_a_later_operator_change(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "before\n");
        $write = $this->service->writeRepoFile($this->tempRelativePath, "agent\n", true);
        $token = $this->trackRecovery($write);
        file_put_contents(base_path($this->tempRelativePath), "operator later\n");

        $restore = $this->service->restoreRepoEdit($token);

        $this->assertFalse($restore['restored']);
        $this->assertSame('conflict', $restore['status']);
        $this->assertSame([$this->tempRelativePath], $restore['conflicts']);
        $this->assertSame("operator later\n", file_get_contents(base_path($this->tempRelativePath)));
        $this->assertFileExists($this->packetPath($token));
    }

    public function test_historical_signed_recovery_readback_preserves_expired_original_without_restoring(): void
    {
        $this->enableRecovery();
        $write = $this->service->writeRepoFile($this->tempRelativePath, "prepared\n", true);
        $token = $this->trackRecovery($write);
        $path = $this->packetPath($token);
        $packet = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unset($packet['hmac']);
        $packet['expires_at'] = time() - 1;
        $packet['hmac'] = hash_hmac('sha256', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), config('app.key'));
        $bytes = json_encode($packet, JSON_THROW_ON_ERROR);
        file_put_contents($path, $bytes);
        $recovery = app(RepoEditRecoveryService::class);
        $evidence = $recovery->load($token, historicalEvidence: true);
        $this->assertSame($token, $evidence['token']);
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame("prepared\n", file_get_contents(base_path($this->tempRelativePath)));
        try {
            $recovery->load($token);
            $this->fail('Historical readback must not extend normal restore permission.');
        } catch (RuntimeException $error) {
            $this->assertSame('Recovery packet has expired.', $error->getMessage());
        }
    }

    public function test_restore_repo_edit_rejects_a_tampered_packet(): void
    {
        $this->enableRecovery();
        $write = $this->service->writeRepoFile($this->tempRelativePath, "agent\n", true);
        $token = $this->trackRecovery($write);
        $packet = json_decode((string) file_get_contents($this->packetPath($token)), true, flags: JSON_THROW_ON_ERROR);
        $packet['files'][0]['path'] = '.env';
        file_put_contents($this->packetPath($token), json_encode($packet, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check failed');

        $this->service->restoreRepoEdit($token);
    }

    public function test_repo_edit_lock_times_out_instead_of_overlapping_another_edit(): void
    {
        $directory = storage_path('app/dev-agent/recovery');
        @mkdir($directory, 0700, true);
        $handle = fopen($directory.'/.lock', 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        config()->set('dev_agent.repo_edit_recovery.lock_timeout_seconds', 1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('lock timed out');
            $this->service->writeRepoFile($this->tempRelativePath, "blocked\n", true);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_apply_repo_patch_updates_file_without_full_rewrite(): void
    {
        $this->service->writeRepoFile($this->tempRelativePath, "alpha\nbeta\n", true);

        $patch = <<<PATCH
diff --git a/{$this->tempRelativePath} b/{$this->tempRelativePath}
--- a/{$this->tempRelativePath}
+++ b/{$this->tempRelativePath}
@@ -1,2 +1,2 @@
 alpha
-beta
+gamma
PATCH;

        $check = $this->service->applyRepoPatch($patch, checkOnly: true);

        $this->assertFalse($check['applied']);
        $this->assertSame('not_run', $check['diagnostics']['status']);
        $this->assertSame('check_only', $check['diagnostics']['reason']);

        $result = $this->service->applyRepoPatch($patch);

        $this->assertTrue($result['applied']);
        $this->assertSame([$this->tempRelativePath], $result['paths']);
        $this->assertSame("alpha\ngamma\n", file_get_contents(base_path($this->tempRelativePath)));
        $this->assertSame('not_applicable', $result['diagnostics']['status']);
    }

    public function test_apply_repo_patch_returns_post_edit_php_syntax_diagnostics(): void
    {
        $this->service->writeRepoFile($this->tempPhpRelativePath, "<?php\n\nreturn true;\n", true);

        $patch = <<<PATCH
diff --git a/{$this->tempPhpRelativePath} b/{$this->tempPhpRelativePath}
--- a/{$this->tempPhpRelativePath}
+++ b/{$this->tempPhpRelativePath}
@@ -1,3 +1,3 @@
 <?php

-return true;
+return false;
PATCH;

        $result = $this->service->applyRepoPatch($patch);

        $this->assertTrue($result['applied']);
        $this->assertSame('passed', $result['diagnostics']['status']);
        $this->assertSame(1, $result['diagnostics']['checked_files']);
        $this->assertSame('php-syntax', $result['diagnostics']['results'][0]['runner']);
    }

    public function test_apply_repo_patch_returns_a_recovery_token_that_restores_the_patch(): void
    {
        $this->enableRecovery();
        file_put_contents(base_path($this->tempRelativePath), "alpha\nbeta\n");

        $patch = <<<PATCH
diff --git a/{$this->tempRelativePath} b/{$this->tempRelativePath}
--- a/{$this->tempRelativePath}
+++ b/{$this->tempRelativePath}
@@ -1,2 +1,2 @@
 alpha
-beta
+gamma
PATCH;

        $result = $this->service->applyRepoPatch($patch);
        $token = $this->trackRecovery($result);
        $this->assertSame("alpha\ngamma\n", file_get_contents(base_path($this->tempRelativePath)));

        $restore = $this->service->restoreRepoEdit($token);

        $this->assertTrue($restore['restored']);
        $this->assertSame("alpha\nbeta\n", file_get_contents(base_path($this->tempRelativePath)));
    }

    public function test_apply_repo_patch_refuses_protected_paths(): void
    {
        $patch = <<<'PATCH'
diff --git a/.env b/.env
--- a/.env
+++ b/.env
@@ -1 +1 @@
-APP_NAME=PLOS
+APP_NAME=bad
PATCH;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('protected');

        $this->service->applyRepoPatch($patch, checkOnly: true);
    }

    public function test_run_verification_allows_diff_check(): void
    {
        $result = $this->service->runVerification('diff-check', timeoutSeconds: 30);

        $this->assertSame('diff-check', $result['runner']);
        $this->assertSame(['git', 'diff', '--check'], $result['command']);
        $this->assertTrue($result['result']['success']);
    }

    public function test_run_verification_keeps_utf8_safe_output_tail_when_bounded(): void
    {
        config()->set('dev_agent.process_output.max_bytes', 64);
        config()->set('dev_agent.process_output.head_percent', 50);
        Process::fake(fn () => Process::result(
            output: 'verification-start '.str_repeat('🔥', 30).' final compiler error'
        ));

        $result = $this->service->runVerification('diff-check', timeoutSeconds: 30);
        $output = $result['result']['output'];

        $this->assertTrue(mb_check_encoding($output, 'UTF-8'));
        $this->assertStringStartsWith('verification-start ', $output);
        $this->assertStringContainsString('bytes; output tail follows', $output);
        $this->assertStringEndsWith('final compiler error', $output);
    }

    public function test_run_verification_targeted_tests_must_stay_under_tests(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tests/');

        $this->service->runVerification('phpunit-target', 'app/Services/RepoDevMCPService.php');
    }

    public function test_run_verification_pao_targeted_tests_must_stay_under_tests(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tests/');

        $this->service->runVerification('phpunit-pao-target', 'app/Services/RepoDevMCPService.php');
    }

    public function test_run_verification_phpstan_pao_target_scope_is_gated(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repo source');

        $this->service->runVerification('phpstan-pao', 'composer.lock');
    }

    public function test_write_via_repo_internal_symlink_to_etc_is_refused(): void
    {
        // Finding H1: a symlink inside the repo that points at /etc/hosts
        // used to pass as repo_write because resolvePath() normalized
        // lexically and never followed the symlink. realpath() must refuse.
        $linkRelative = 'storage/framework/testing/h1_repo_mcp_link';
        $linkAbsolute = base_path($linkRelative);
        @unlink($linkAbsolute);
        $this->assertTrue(symlink('/etc/hosts', $linkAbsolute));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('protected');
            $this->service->writeRepoFile($linkRelative, 'pwned');
        } finally {
            @unlink($linkAbsolute);
        }
    }

    private function enableRecovery(): void
    {
        config()->set('dev_agent.repo_edit_recovery.enabled', true);
        config()->set('app.key', 'base64:repo-edit-recovery-test-key');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function trackRecovery(array $result): string
    {
        $this->assertTrue($result['recovery']['available']);
        $token = (string) $result['recovery']['token'];
        $this->assertMatchesRegularExpression('/^rec_[a-f0-9]{32}$/', $token);
        $this->recoveryTokens[] = $token;

        return $token;
    }

    private function packetPath(string $token): string
    {
        return storage_path('app/dev-agent/recovery/'.$token.'.json');
    }
}
