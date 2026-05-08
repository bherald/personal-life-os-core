<?php

namespace Tests\Feature\Quality;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PublicExportPackagingTest extends TestCase
{
    #[Test]
    public function phpunit_does_not_advertise_private_stabilization_suite(): void
    {
        $phpunit = file_get_contents(base_path('phpunit.xml'));

        $this->assertStringNotContainsString('testsuite name="Stabilization"', $phpunit);
        $this->assertStringNotContainsString('tests/Feature/Stabilization', $phpunit);
    }

    #[Test]
    public function public_export_does_not_ship_private_stabilization_tests(): void
    {
        $script = file_get_contents(base_path('scripts/public-export.sh'));

        $this->assertStringNotContainsString('tests/Feature/Stabilization', $script);
        $this->assertStringContainsString(
            'operator-only stabilization checks',
            $script,
            'PUBLIC_EXPORT_MANIFEST should explain why private stabilization tests are omitted.'
        );
    }

    #[Test]
    public function dry_run_public_export_allowlist_excludes_private_and_generated_paths(): void
    {
        $destination = sys_get_temp_dir().'/plos-public-export-dry-run-'.bin2hex(random_bytes(6));
        $process = new Process([
            base_path('scripts/public-export.sh'),
            '--dry-run',
            $destination,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($destination, 'Dry-run must not create or stage a destination tree.');

        $output = $process->getOutput();
        $this->assertStringContainsString("Public export destination: {$destination}", $output);
        $this->assertStringContainsString('Allowlisted tracked files:', $output);

        $files = $this->publicExportFilesFromDryRunOutput($output);

        foreach ([
            'scripts/guards/public-release-audit.sh',
            'scripts/guards/public-workflow-push-preflight.sh',
            'scripts/guards/github-auth-storage-audit.sh',
            'scripts/guards/public-temp-artifact-cleanup.sh',
        ] as $path) {
            $this->assertContains($path, $files, "{$path} must stay in the public export allowlist.");
        }

        foreach ($files as $file) {
            $this->assertStringStartsNotWith('mcp-server/dist/', $file);
            $this->assertStringStartsNotWith('mcp-server/node_modules/', $file);
            $this->assertStringStartsNotWith('mcp-servers/plos/dist/', $file);
            $this->assertStringStartsNotWith('mcp-servers/plos/node_modules/', $file);
            $this->assertStringStartsNotWith('node_modules/', $file);
            $this->assertStringStartsNotWith('docs/planning/', $file);
            $this->assertDoesNotMatchRegularExpression('/^storage\/.*\.key$/', $file);
        }

        foreach ([
            'mcp-server/dist',
            'mcp-server/node_modules',
            'mcp-servers/plos/dist',
            'mcp-servers/plos/node_modules',
            'node_modules',
            'docs/planning',
        ] as $path) {
            $this->assertNotContains($path, $files);
        }
    }

    #[Test]
    public function public_export_force_refuses_unsafe_destinations(): void
    {
        $home = sys_get_temp_dir().'/plos-public-export-home-'.bin2hex(random_bytes(6));
        mkdir($home, 0700, true);

        foreach ([
            '/',
            base_path(),
            base_path('storage'),
            $home,
            $home.'/.ssh/public-export',
            '/etc',
            '/usr/local/public-export',
        ] as $destination) {
            $process = new Process([
                base_path('scripts/public-export.sh'),
                '--dry-run',
                '--force',
                $destination,
            ]);
            $process->setEnv(['HOME' => $home]);
            $process->setTimeout(30);
            $process->run();

            $this->assertSame(2, $process->getExitCode(), "Expected {$destination} to be rejected.");
            $this->assertStringContainsString('Refusing to export', $process->getErrorOutput());
        }
    }

    #[Test]
    public function public_smoke_runs_the_current_public_quality_tests(): void
    {
        $smokeScript = file_get_contents(base_path('scripts/public-smoke.sh'));
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));
        $workflow = file_get_contents(base_path('.github/workflows/public-readiness.yml'));

        $this->assertStringContainsString('scripts/audit-licenses.sh', $smokeScript);
        $this->assertStringContainsString('scripts/audit-licenses.sh', $workflow);
        $this->assertStringContainsString('scripts/guards/dependency-provenance-check.sh', $smokeScript);
        $this->assertStringContainsString('scripts/guards/dependency-provenance-check.sh', $workflow);
        $this->assertStringContainsString(
            'scripts/guards/dependency-provenance-check.sh',
            $this->publicExportShorterLocalCheck($exportScript)
        );

        $smokePaths = $this->focusedPhpunitTestPaths($smokeScript, 'scripts/public-smoke.sh');
        $exportManifestPaths = $this->focusedPhpunitTestPaths(
            $this->publicExportShorterLocalCheck($exportScript),
            'PUBLIC_EXPORT_MANIFEST shorter local check'
        );
        $workflowPaths = $this->focusedPhpunitTestPaths($workflow, '.github/workflows/public-readiness.yml');

        $this->assertSame($smokePaths, $exportManifestPaths, 'Public smoke and PUBLIC_EXPORT_MANIFEST focused PHPUnit slices must match exactly.');
        $this->assertWorkflowFocusedPhpunitPathsCompatible($smokePaths, $workflowPaths);
        $this->assertContains('tests/Unit/Services/GedZipExportTest.php', $smokePaths);
    }

    #[Test]
    public function public_export_manifest_short_check_keeps_typed_remediation_smoke_slice(): void
    {
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));
        $smokeScript = file_get_contents(base_path('scripts/public-smoke.sh'));

        $manifestStart = strpos($exportScript, 'For a shorter local check inside this exported tree');
        $manifestEnd = strpos($exportScript, 'EOF', $manifestStart);

        $this->assertIsInt($manifestStart);
        $this->assertIsInt($manifestEnd);

        $manifestBlock = substr($exportScript, $manifestStart, $manifestEnd - $manifestStart);

        foreach ([
            'tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php',
            'tests/Feature/Console/GenealogyTypedRemediationMaterializeCommandTest.php',
        ] as $path) {
            $this->assertStringContainsString($path, $smokeScript, "{$path} must stay in public smoke.");
            $this->assertStringContainsString($path, $manifestBlock, "{$path} must stay in PUBLIC_EXPORT_MANIFEST shorter local check.");
        }
    }

    /**
     * @return list<string>
     */
    private function focusedPhpunitTestPaths(string $body, string $source): array
    {
        $lines = preg_split('/\R/', $body) ?: [];

        foreach ($lines as $index => $line) {
            $offset = strpos($line, 'php artisan test');

            if ($offset === false) {
                continue;
            }

            $paths = [];
            $tail = substr($line, $offset + strlen('php artisan test'));

            do {
                $trimmed = trim($tail);
                $continued = str_ends_with($trimmed, '\\');
                $trimmed = rtrim($trimmed, "\\ \t");

                foreach (preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                    if (str_starts_with($token, 'tests/')) {
                        $paths[] = $token;
                    }
                }

                $index++;
                $tail = $lines[$index] ?? '';
            } while ($continued);

            $this->assertNotSame([], $paths, "{$source} must include focused PHPUnit test paths.");
            $this->assertSame($paths, array_values(array_unique($paths)), "{$source} must not repeat focused PHPUnit test paths.");

            return $paths;
        }

        $this->fail("{$source} must include a php artisan test command.");
    }

    /**
     * @param  list<string>  $smokePaths
     * @param  list<string>  $workflowPaths
     */
    private function assertWorkflowFocusedPhpunitPathsCompatible(array $smokePaths, array $workflowPaths): void
    {
        if ($smokePaths === $workflowPaths) {
            $this->addToAssertionCount(1);

            return;
        }

        // Workflow pushes require an operator token with the GitHub workflow scope.
        // Until that token is available, accept only the exact last public workflow slice.
        $workflowScopeHeldPaths = [
            'tests/Unit/Setup',
            'tests/Unit/Commands/RagRetrievalEvidenceCommandTest.php',
            'tests/Unit/Commands/RagScaleReviewCommandTest.php',
            'tests/Unit/Nodes/PushoverNotifyTest.php',
            'tests/Unit/Services/MetadataWritebackSafetyTest.php',
            'tests/Feature/Console/AwoReplayCommandTest.php',
            'tests/Feature/Console/GenealogyReviewPacketMaterializeCommandTest.php',
            'tests/Feature/Console/OpsMcpHealthCommandTest.php',
            'tests/Feature/Console/OpsReviewBacklogReportCommandTest.php',
            'tests/Feature/Console/SetupDoctorCommandTest.php',
            'tests/Feature/Quality/FixturesProvenanceTest.php',
            'tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php',
            'tests/Feature/Quality/PublicExportPackagingTest.php',
            'tests/Feature/Quality/PublicGithubMonitorScriptTest.php',
            'tests/Feature/Quality/PublicMcpWorkspaceReadmeTest.php',
            'tests/Feature/Quality/RepositoryGovernanceTest.php',
        ];

        $this->assertSame(
            $workflowScopeHeldPaths,
            $workflowPaths,
            'Public readiness workflow must match public smoke, or the exact workflow-scope-held public slice.'
        );
    }

    private function publicExportShorterLocalCheck(string $exportScript): string
    {
        $manifestStart = strpos($exportScript, 'For a shorter local check inside this exported tree');
        $manifestEnd = strpos($exportScript, 'EOF', $manifestStart);

        $this->assertIsInt($manifestStart);
        $this->assertIsInt($manifestEnd);

        return substr($exportScript, $manifestStart, $manifestEnd - $manifestStart);
    }

    #[Test]
    public function public_temp_cleanup_guard_stays_dry_run_first_and_syntax_checked(): void
    {
        $script = file_get_contents(base_path('scripts/guards/public-temp-artifact-cleanup.sh'));
        $smokeScript = file_get_contents(base_path('scripts/public-smoke.sh'));
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));

        $this->assertStringContainsString('execute=false', $script);
        $this->assertStringContainsString('mode="dry-run"', $script);
        $this->assertStringContainsString('personal-life-os-core-export-*', $script);
        $this->assertStringContainsString('personal-life-os-core-smoke-*', $script);
        $this->assertStringNotContainsString('personal-life-os-core-github-sync-*', $script);
        $this->assertStringContainsString('scripts/guards/public-temp-artifact-cleanup.sh', $smokeScript);
        $this->assertStringContainsString('scripts/guards/public-temp-artifact-cleanup.sh', $exportScript);
        $this->assertStringContainsString('tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php', $smokeScript);
        $this->assertStringContainsString('tests/Feature/Quality/PublicTempArtifactCleanupScriptTest.php', $exportScript);
    }

    #[Test]
    public function public_readiness_ci_prepares_passport_keys(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/public-readiness.yml'));

        $this->assertStringContainsString('php artisan passport:keys --force --no-interaction', $workflow);
        $this->assertStringContainsString('WEB_UI_MASTER_PASSWORD=public-readiness-password', $workflow);
        foreach (['ctype', 'openssl', 'pdo', 'tokenizer'] as $extension) {
            $this->assertStringContainsString($extension, $workflow, "Public readiness CI should request ext-{$extension} explicitly.");
        }
    }

    #[Test]
    public function public_support_copy_stays_neutral_and_non_promissory(): void
    {
        foreach ($this->publicSupportPosturePaths() as $path) {
            $body = file_get_contents(base_path($path));
            $lines = preg_split('/\R/', $body) ?: [];

            foreach ($lines as $index => $line) {
                foreach ([
                    '/\bdonations?\b/i' => 'donation wording',
                    '/\btax[- ]deductible\b/i' => 'tax-deductible claim',
                    '/\bSLA\b/i' => 'SLA promise',
                    '/\bguaranteed support\b/i' => 'guaranteed support promise',
                    '/\bpaid[- ]support[- ]level\b/i' => 'paid support-level claim',
                    '/\bsupport[- ]level promises?\b/i' => 'support-level promise',
                    '/\bcommercial support\b/i' => 'commercial support claim',
                ] as $pattern => $label) {
                    $this->assertDoesNotMatchRegularExpression(
                        $pattern,
                        $line,
                        sprintf('%s:%d must not contain %s.', $path, $index + 1, $label)
                    );
                }

                foreach ([
                    '/\benterprise support\b/i' => 'enterprise support',
                    '/\bpaid support\b/i' => 'paid support',
                    '/\bcustom commercial integration work\b/i' => 'custom commercial integration',
                ] as $pattern => $label) {
                    if (! preg_match($pattern, $line)) {
                        continue;
                    }

                    $this->assertMatchesRegularExpression(
                        '/\b(?:does not imply|do not imply|not|no|without|never)\b/i',
                        $line,
                        sprintf('%s:%d may mention %s only as a non-promise boundary.', $path, $index + 1, $label)
                    );
                }
            }
        }

        $this->assertStringContainsString('GitHub Sponsors', file_get_contents(base_path('README.md')));
        $this->assertStringContainsString('github: [b'.'herald]', file_get_contents(base_path('.github/FUNDING.yml')));
    }

    #[Test]
    public function public_readiness_ci_validates_docker_compose_without_dumping_env(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/public-readiness.yml'));

        $this->assertStringContainsString('docker-compose-config:', $workflow);
        $this->assertStringContainsString('name: Docker Compose Config', $workflow);
        $this->assertStringContainsString('docker compose --env-file .env config --quiet', $workflow);
        $this->assertStringNotContainsString('docker compose config'."\n", $workflow);
    }

    #[Test]
    public function public_smoke_replaces_python_binary_without_duplicate_env_key(): void
    {
        $smokeScript = file_get_contents(base_path('scripts/public-smoke.sh'));
        $workflow = file_get_contents(base_path('.github/workflows/public-readiness.yml'));

        $this->assertStringContainsString('set_env_var PYTHON_BINARY .venv/bin/python .env', $smokeScript);
        $this->assertStringContainsString("printf '%s=%s\\n'", $smokeScript);
        $this->assertStringNotContainsString("printf '\\nPYTHON_BINARY=.venv/bin/python\\n' >> .env", $smokeScript);
        $this->assertStringContainsString("sed -i 's#^PYTHON_BINARY=.*#PYTHON_BINARY=.venv/bin/python#' .env", $workflow);
        $this->assertStringNotContainsString("printf '\\nPYTHON_BINARY=.venv/bin/python\\n' >> .env", $workflow);
        $this->assertStringContainsString('-c requirements-core.constraints.txt -r requirements-core.txt', $smokeScript);
        $this->assertStringContainsString('-c requirements-core.constraints.txt -r requirements-core.txt', $workflow);
        $this->assertStringContainsString('actions/checkout@v6', $workflow);
        $this->assertStringContainsString('actions/setup-node@v6', $workflow);
        $this->assertStringContainsString('node-version: "24"', $workflow);
        $this->assertStringContainsString('actions/setup-python@v6', $workflow);
        $this->assertStringContainsString('python-version: "3.12"', $workflow);
        $this->assertStringContainsString('cache-dependency-path: |', $workflow);
        $this->assertStringContainsString('requirements-core.txt', $workflow);
        $this->assertStringContainsString('requirements-core.constraints.txt', $workflow);
        $this->assertStringContainsString('npm ci --prefix mcp-server', $workflow);
        $this->assertStringContainsString('npm ci --prefix mcp-servers/plos', $workflow);
        $this->assertStringContainsString('test -x .venv/bin/python', $smokeScript);
        $this->assertStringContainsString('test -x .venv/bin/python', $workflow);
        $this->assertLessThan(
            strpos($smokeScript, 'php artisan setup:doctor --profile=core --skip-services --json'),
            strpos($smokeScript, 'npm run build'),
            'Public smoke should build frontend assets before setup doctor, matching public readiness CI.'
        );
        $this->assertLessThan(
            strpos($workflow, 'php artisan setup:doctor --profile=core --skip-services --json'),
            strpos($workflow, 'npm run build'),
            'Public readiness CI should build frontend assets before setup doctor.'
        );
    }

    #[Test]
    public function public_compose_pins_container_internal_runtime_env(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertSame(3, substr_count($compose, 'DB_PORT: 3306'));
        $this->assertSame(3, substr_count($compose, 'RAG_DB_PORT: 5432'));
        $this->assertSame(3, substr_count($compose, 'REDIS_PORT: 6379'));
        $this->assertSame(3, substr_count($compose, 'PYTHON_BINARY: python3'));
    }

    #[Test]
    public function public_smoke_and_ci_include_media_local_asset_doctor(): void
    {
        $expected = 'php artisan setup:doctor --profile=media --skip-services --only=assets,browser,docker --json';

        $this->assertStringContainsString($expected, file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString($expected, file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString($expected, file_get_contents(base_path('scripts/public-export.sh')));
    }

    #[Test]
    public function public_docker_docs_pin_setup_doctor_profile_paths(): void
    {
        $profileCommands = [
            'core' => 'php artisan setup:doctor --profile=core',
            'media' => 'php artisan setup:doctor --profile=media',
            'gpu' => 'php artisan setup:doctor --profile=gpu',
            'full' => 'php artisan setup:doctor --profile=full',
        ];

        foreach ([
            'README.md',
            'docs/quickstart.md',
            'docs/public-install-prerequisites.md',
            'docker/README.md',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach ($profileCommands as $profile => $command) {
                $this->assertStringContainsString($command, $body, "{$path} must document the {$profile} setup doctor path.");
            }
        }

        $quickstart = file_get_contents(base_path('docs/quickstart.md'));
        $dockerReadme = file_get_contents(base_path('docker/README.md'));

        foreach ([
            'docker compose --profile full up -d',
            'personal',
            'public CI',
        ] as $token) {
            $this->assertStringContainsString($token, $quickstart.$dockerReadme, "Docker docs must keep {$token} visible.");
        }

        foreach ([
            'setup:doctor --profile=core --skip-services',
            'setup:doctor --profile=media --skip-services --only=assets,browser,docker',
            'GPU and full profile evidence',
        ] as $token) {
            $this->assertStringContainsString($token, file_get_contents(base_path('docs/public-release-readiness.md')));
        }

        $this->assertStringContainsString('tag-gate work', file_get_contents(base_path('docs/public-github-first-push-checklist.md')));
        $this->assertStringContainsString('Core profile without live service probes', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('Media local slice only', file_get_contents(base_path('scripts/public-smoke.sh')));
    }

    #[Test]
    public function public_bound_docs_do_not_reference_private_runtime_paths(): void
    {
        foreach ([
            'docs/AGENT-SAFETY-CARDS.md',
            'docs/AIService-LLM-Gateway.md',
            'docs/model-runtime-license-map.md',
            'docs/OLLAMA-COMPATIBILITY.md',
            'docs/plos-task-lease-contract.md',
            'docs/queue-placement-policy.md',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach (['vendor/ollama/', 'docs/planning/', '.252', '.87', 'APL #', 'Pushover', 'Max subscription', 'v3.33.0'] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not contain {$token}");
            }
        }
    }

    #[Test]
    public function public_bound_source_avoids_private_path_defaults(): void
    {
        foreach ([
            'AGENTS.md',
            '.env.example',
            'docker-compose.yml',
            'docker/README.md',
            'config/services.php',
            'config/genealogy.php',
            'config/offline_policy.php',
            'app/Services/MediaUrlService.php',
            'app/Services/Genealogy/GenealogyMediaService.php',
            'app/Services/Genealogy/GenealogyDocumentIngestionService.php',
            'app/Console/Commands/OpsRuntimeDiagnosticsCommand.php',
            'database/migrations/2026_04_16_202238_seed_offline_mode_config.php',
            'database/migrations/2026_04_17_134457_seed_routing_profile_configs.php',
            'app/Console/Commands/FileEnrichmentCommand.php',
            'app/Http/Controllers/Api/MediaBrowserController.php',
            'resources/js/src/views/FinanceView.vue',
            'resources/js/src/views/HealthView.vue',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach ([
                '/srv/nextcloud/data/plos/files',
                'D:\\master',
                '/srv/genealogy/Public_Family_Tree',
                'prod1.sh',
                'docs/PROJECT.md',
                'docs/planning/',
                'mcp-server/dist',
            ] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not contain {$token}");
            }
        }
    }

    #[Test]
    public function public_bound_runtime_does_not_advertise_private_billing_assumptions(): void
    {
        foreach ([
            'app/Engine/AIRouter.php',
            'app/Services/AIService.php',
            'app/Console/Commands/TestAIServices.php',
            'database/migrations/2026_02_01_000002_create_llm_instances_table.php',
            'resources/agents/skills/ai-ops/SKILL.md',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach ([
                'Max subscription',
                'Max Subscription',
                'Claude Max',
                'Included in Max',
                'no API costs',
            ] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not advertise private provider billing/access assumptions.");
            }
        }
    }

    #[Test]
    public function llm_instances_seed_uses_neutral_optional_provider_label(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_02_01_000002_create_llm_instances_table.php'));

        $this->assertStringNotContainsString("'Claude CLI (Max Subscription)'", $migration);
        $this->assertStringNotContainsString('Included in Max subscription', $migration);
        $this->assertStringContainsString("'Claude CLI (optional)'", $migration);
    }

    #[Test]
    public function public_genealogy_config_uses_neutral_library_defaults(): void
    {
        $body = file_get_contents(base_path('config/genealogy.php'));

        $this->assertStringContainsString('NEXTCLOUD_LIBRARY_ROOT', $body);
        $this->assertStringNotContainsString('/MASTER', $body);
    }

    #[Test]
    public function unsupported_genealogy_api_integrations_are_not_public_runtime_surfaces(): void
    {
        $this->assertFileDoesNotExist(base_path('app/Services/Genealogy/FamilySearchHintSyncService.php'));
        $this->assertFileDoesNotExist(base_path('app/Services/Genealogy/Providers/FamilySearchProvider.php'));
        $this->assertFileDoesNotExist(base_path('app/Services/Genealogy/Providers/AncestryDnaProvider.php'));

        foreach ([
            '.env.example',
            'config/services.php',
            'config/setup.php',
            'docker-compose.yml',
            'docker-compose.personal.example.yml',
            'routes/api.php',
            'app/Http/Controllers/GenealogyController.php',
            'app/Services/Genealogy/GenealogyResearchService.php',
            'app/Services/Genealogy/GenealogySourceService.php',
            'app/Services/Genealogy/Providers/GenealogyProviderManager.php',
            'resources/js/src/views/GenealogyView.vue',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach ([
                'FAMILYSEARCH_APP_KEY',
                'FAMILYSEARCH_APP_SECRET',
                'FAMILYSEARCH_REDIRECT_URI',
                'FAMILYSEARCH_ENVIRONMENT',
                'FAMILYSEARCH_BASE_URL',
                'ANCESTRY_PASSWORD',
                'ANCESTRY_DNA_TEST_GUID',
                'getFamilySearchAuthUrl',
                'syncFamilySearchHints',
                'connectFamilySearch',
                'searchFamilySearchPublic',
                'searchFamilySearch(',
                'searchAncestry(',
                'AncestryDnaProvider',
                'FamilySearchProvider',
                'Ancestry API needed',
                'FamilySearch images can be downloaded via their API',
                'OAuth2 flow (FamilySearch',
                'future Ancestry',
            ] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not advertise unsupported genealogy API integrations.");
            }
        }
    }

    #[Test]
    public function public_genealogy_source_docs_do_not_advertise_stale_integration_claims(): void
    {
        $paths = [
            'README.md',
            'docs/public-install-prerequisites.md',
            'docs/genealogy-research-methodology.md',
            'docs/papers-and-newsletters/white-paper-plos.md',
            'docs/papers-and-newsletters/linkedin-article-plos.md',
        ];

        $checked = 0;

        foreach ($paths as $path) {
            if (! is_file(base_path($path))) {
                continue;
            }

            $checked++;
            $body = file_get_contents(base_path($path));

            foreach ([
                '/\b\d+\s+integrated\s+(?:genealogy\s+)?sources\b/i',
                '/\b14\s+(?:source|provider)\s+integrations\b/i',
                '/\b(?:integrates|searches|queries)\s+14\s+(?:genealogy\s+)?sources\b/i',
                '/\bintegrated\s+(?:free\/open\s+|public\s+|genealogy\s+)*sources\b/i',
            ] as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $body,
                    "{$path} must split automated sources, manual targets, and private opt-ins instead of advertising stale integrated-source claims."
                );
            }

            foreach (preg_split('/\R/', $body) as $index => $line) {
                if (! preg_match('/\bFamilySearch\b.*\b(?:OAuth|API)\b|\b(?:OAuth|API)\b.*\bFamilySearch\b/i', $line)) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/manual(?:\/|-| )only|absent|no automated API|no .*OAuth|not part|disabled|private opt-in|rather than autonomous APIs/i',
                    $line,
                    sprintf(
                        '%s:%d may mention FamilySearch OAuth/API only to say it is absent, manual-only, disabled, or not an autonomous API.',
                        $path,
                        $index + 1
                    )
                );
            }
        }

        $this->assertGreaterThanOrEqual(2, $checked, 'Expected at least the exported public README and install docs to be scanned.');
    }

    #[Test]
    public function public_joplin_adapter_is_optional_interoperability_only(): void
    {
        foreach ([
            'composer.json',
            'composer.lock',
            'package.json',
            'package-lock.json',
            'mcp-server/package.json',
            'mcp-server/package-lock.json',
        ] as $path) {
            $body = file_get_contents(base_path($path));
            $this->assertStringNotContainsString('@joplin/', $body, "{$path} must not vendor Joplin packages.");
            $this->assertStringNotContainsString('joplinapp-desktop', $body, "{$path} must not bundle Joplin apps.");
            $this->assertStringNotContainsString('joplin-server', $body, "{$path} must not bundle Joplin Server.");
        }

        foreach ([
            'app/Services/JoplinLockHandler.php',
            'app/Services/JoplinFilesService.php',
            'app/Services/JoplinWriteService.php',
            'app/Services/JoplinSyncService.php',
            'app/Services/JoplinTagsService.php',
            'app/Services/JoplinAttachmentService.php',
            'app/Services/JoplinYouTubeOrganizer.php',
        ] as $path) {
            $body = file_get_contents(base_path($path));
            $this->assertStringNotContainsString('Based on lock implementation from Joplin Desktop', $body, "{$path} must not imply copied upstream implementation.");
            $this->assertStringNotContainsString("Joplin's BaseItem.serialize() format from source code", $body, "{$path} must not imply copied upstream implementation.");
            $this->assertStringNotContainsString('Licensed under MIT License', $body, "{$path} must not carry stale Joplin license claims.");
            $this->assertStringNotContainsString('Copyright (c) Laurent', $body, "{$path} must not contain upstream Joplin source headers.");
        }

        $attachmentService = file_get_contents(base_path('app/Services/JoplinAttachmentService.php'));
        $this->assertStringContainsString('$this->aiService->process(', $attachmentService);
        $this->assertStringNotContainsString('claudeCliPath', $attachmentService);
        $this->assertStringNotContainsString('analyzeWithClaude', $attachmentService);
        $this->assertStringNotContainsString('joplin_claude_rate_limit', $attachmentService);

        $this->assertFileExists(base_path('app/Support/JoplinPaths.php'));
        $this->assertStringContainsString('NEXTCLOUD_JOPLIN_PATH', file_get_contents(base_path('config/services.php')));
        $this->assertStringNotContainsString('JOPLIN_CLAUDE_RATE_LIMIT', file_get_contents(base_path('config/services.php')));
        $this->assertStringContainsString('JOPLIN_WATCH_LATER_FOLDER_ID', file_get_contents(base_path('config/services.php')));
        $this->assertStringContainsString('NEXTCLOUD_JOPLIN_PATH', file_get_contents(base_path('config/setup.php')));
        $this->assertStringNotContainsString('JOPLIN_CLAUDE_RATE_LIMIT', file_get_contents(base_path('.env.example')));
        $this->assertStringContainsString('JOPLIN_WATCH_LATER_FOLDER_ID', file_get_contents(base_path('.env.example')));
        $personalConnectors = file_get_contents(base_path('docs/personal-connectors.md'));
        $this->assertStringContainsString('interoperability', $personalConnectors);
        $this->assertStringContainsString('not a bundled public service', $personalConnectors);
        $this->assertStringContainsString('| Joplin |', file_get_contents(base_path('THIRD_PARTY.md')));
    }

    #[Test]
    public function public_joplin_routes_require_authentication(): void
    {
        $joplinRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/joplin/')
                || str_starts_with($route->uri(), 'api/media/joplin/'));

        $this->assertNotEmpty($joplinRoutes, 'Expected public route collection to include Joplin routes.');

        foreach ($joplinRoutes as $route) {
            $this->assertContains(
                'auth:web',
                $route->gatherMiddleware(),
                "{$route->uri()} must be authenticated because Joplin notes are personal data."
            );
        }
    }

    #[Test]
    public function public_research_provenance_doc_tracks_referenced_project_boundaries(): void
    {
        $doc = file_get_contents(base_path('docs/research-provenance.md'));

        foreach ([
            'Topola',
            'Laravel',
            'Laravel Horizon',
            'Laravel Passport',
            'Laravel Tinker',
            'Laravel Pint',
            'Laravel Pail',
            'Laravel Sail',
            'Laravel Vite Plugin',
            'Vue',
            'Tailwind CSS',
            'PostgreSQL',
            'MySQL',
            'MariaDB',
            'Redis',
            'Apache Tika',
            'Nextcloud',
            'Thunderbird',
            'SearXNG',
            'Model Context Protocol',
            'Graphlit MCP server',
            'Nextcloud MCP server',
            'pgvector',
            'Ollama',
            'mrmysql/youtube-transcript',
            'dlib and face_recognition',
            'hdbscan',
            'FamilySearch GEDCOM 7',
            'FindAGrave',
            'BillionGraves',
            'WikiTree',
            'Graph RAG',
            'RAPTOR',
            'The 2025 AI Agent Index',
            'ExifTool',
            'IPTC',
        ] as $token) {
            $this->assertStringContainsString($token, $doc, "Research provenance doc must track {$token}.");
        }

        $this->assertStringContainsString('docs/research-provenance.md', file_get_contents(base_path('README.md')));
        $this->assertStringContainsString('research-provenance.md', file_get_contents(base_path('docs/README.md')));
        $this->assertStringContainsString('docs/research-provenance.md', file_get_contents(base_path('THIRD_PARTY.md')));
        $this->assertStringContainsString('docs/research-provenance.md', file_get_contents(base_path('scripts/public-export.sh')));
    }

    #[Test]
    public function public_audit_flags_copied_inspiration_project_language(): void
    {
        $auditScript = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));

        foreach ([
            'PhotoPrism-inspired',
            'adapted from PhotoPrism',
            'adapted from LibrePhotos',
            'adapted from digiKam',
            'adapted from Joplin',
            'ported from Joplin',
            'Dev-agent reference files requiring public-release review',
            '.continue',
            '.cline',
            'adapted from (Claude Code',
            'Claude Code source tree',
        ] as $token) {
            $this->assertStringContainsString($token, $auditScript, "Public audit must flag {$token}.");
        }
    }

    #[Test]
    public function public_notice_and_license_audit_are_release_surfaces(): void
    {
        $notice = file_get_contents(base_path('NOTICE.md'));

        foreach ([
            'PLOS source code is released under the MIT license',
            'Run `scripts/audit-licenses.sh`',
            'scripts/guards/dependency-provenance-check.sh',
            'passed with 12 warnings',
            'phpoffice/phpword',
            'smalot/pdfparser',
            'tecnickcom/tcpdf',
            '@mistralai/mistralai',
            'cohere-ai',
            'Gramps',
            'PhotoPrism',
            'Joplin',
            'requirements-core.constraints.txt',
            'requirements-media.constraints.txt',
            'requirements-gpu.constraints.txt',
            'docs/model-runtime-license-map.md',
            'docs/python-constraints-license-snapshot.md',
            'docs/native-ml-package-review.md',
            'docs/research-provenance.md',
            'tests/Fixtures/PROVENANCE.md',
            'scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"',
        ] as $token) {
            $this->assertStringContainsString($token, $notice, "NOTICE.md must track {$token}.");
        }
        $this->assertStringNotContainsString('passed with 16 warnings', $notice);

        $auditScript = file_get_contents(base_path('scripts/audit-licenses.sh'));

        foreach ([
            'composer licenses --format=json',
            'package-lock.json',
            'npm-license-snapshot.json',
            'python-license-snapshot-${tier}.json',
            'scripts/snapshot-python-licenses.sh --tier=core',
            'requirements-core.txt',
            'requirements-core.constraints.txt',
            'requirements-media.constraints.txt',
            'requirements-gpu.constraints.txt',
            'Python media constraints are a resolver snapshot only',
            'GPL-signaled graph packages',
            'LGPL-signaled psycopg2-binary',
            'Python GPU constraints are platform-sensitive',
            'NVIDIA software-license/proprietary package signals',
            'docs/model-runtime-license-map.md',
            'docs/native-ml-package-review.md',
            'lgpl',
            'UNKNOWN',
            'wtfpl',
        ] as $token) {
            $this->assertStringContainsString($token, $auditScript, "license audit must track {$token}.");
        }

        foreach ([
            'scripts/public-export.sh',
            'scripts/public-smoke.sh',
            '.github/workflows/public-readiness.yml',
            'README.md',
            'docs/README.md',
            'THIRD_PARTY.md',
        ] as $path) {
            $this->assertStringContainsString('scripts/audit-licenses.sh', file_get_contents(base_path($path)), "{$path} must reference the license audit.");
        }

        foreach ([
            'NOTICE.md',
            'docs/README.md',
            'docs/public-github-first-push-checklist.md',
        ] as $path) {
            $this->assertStringContainsString(
                'scripts/guards/dependency-provenance-check.sh',
                file_get_contents(base_path($path)),
                "{$path} must reference the dependency provenance guard."
            );
        }

        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));
        $this->assertStringContainsString('NOTICE.md', $exportScript);
        $this->assertStringContainsString('docs/model-runtime-license-map.md', $exportScript);
        $this->assertStringContainsString('docs/python-constraints-license-snapshot.md', $exportScript);
        $this->assertStringContainsString('docs/public-release/npm-license-snapshot.json', $exportScript);
        $this->assertStringContainsString('docs/public-release/npm-license-snapshot.md', $exportScript);
        $this->assertStringContainsString('docs/public-release/python-license-snapshot-core.json', $exportScript);
        $this->assertStringContainsString('docs/public-release/python-license-snapshot-core.md', $exportScript);
        $this->assertStringContainsString('docs/public-release/python-license-snapshot-media.json', $exportScript);
        $this->assertStringContainsString('docs/public-release/python-license-snapshot-media.md', $exportScript);
        $this->assertStringContainsString('docs/public-release/final-signoff-trail-2026-05-01.md', $exportScript);
        $this->assertStringContainsString('docs/public-release/privacy-secret-scan-baseline-2026-04-29.md', $exportScript);
        $this->assertStringContainsString('docs/native-ml-package-review.md', $exportScript);
        $this->assertStringContainsString('docs/public-github-first-push-checklist.md', $exportScript);
        foreach ([
            'docs/quickstart.md',
            'docs/operation.md',
            'docs/troubleshooting.md',
            'docs/roadmap.md',
            'docs/security-privacy.md',
            'docs/clean-room-references.md',
            'docs/architecture.md',
        ] as $path) {
            $this->assertStringContainsString($path, $exportScript, "{$path} must be exported.");
            $this->assertStringContainsString(basename($path), file_get_contents(base_path('docs/README.md')), "{$path} must be indexed.");
        }
        $this->assertStringContainsString('docs/plos-runtime-architecture.md', $exportScript);
        $this->assertStringContainsString('architecture.md', file_get_contents(base_path('docs/plos-runtime-architecture.md')));
        $this->assertStringContainsString('requirements-core.constraints.txt', $exportScript);
        $this->assertStringContainsString('requirements-media.constraints.txt', $exportScript);
        $this->assertStringContainsString('requirements-gpu.constraints.txt', $exportScript);
        $this->assertStringContainsString('scripts/audit-licenses.sh', $exportScript);
        $this->assertStringContainsString('scripts/snapshot-npm-licenses.sh', $exportScript);
        $this->assertStringContainsString('scripts/snapshot-python-licenses.sh', $exportScript);
        $this->assertStringContainsString('tests/Feature/Quality/GitHubAuthStorageAuditGuardTest.php', $exportScript);
        $this->assertStringContainsString('scripts/snapshot-npm-licenses.sh --check', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/snapshot-python-licenses.sh --tier=core --check', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('npm ci --prefix mcp-server', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('npm ci --prefix mcp-servers/plos', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/snapshot-npm-licenses.sh --check', file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString('scripts/snapshot-python-licenses.sh --tier=core --check', file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString('npm audit --prefix mcp-server', file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString('npm audit --prefix mcp-servers/plos', file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString('npm audit --prefix mcp-server', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('npm audit --prefix mcp-servers/plos', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/snapshot-npm-licenses.sh scripts/snapshot-python-licenses.sh scripts/audit-licenses.sh', file_get_contents(base_path('.github/workflows/public-readiness.yml')));
        $this->assertStringContainsString('bash -n scripts/public-export.sh scripts/public-smoke.sh scripts/snapshot-npm-licenses.sh scripts/snapshot-python-licenses.sh scripts/audit-licenses.sh', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/guards/public-github-monitor.sh', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/guards/github-auth-storage-audit.sh', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/guards/public-workflow-push-preflight.sh', file_get_contents(base_path('scripts/public-smoke.sh')));
        $this->assertStringContainsString('scripts/guards/public-workflow-push-preflight.sh', file_get_contents(base_path('scripts/public-export.sh')));
        $this->assertStringContainsString('scripts/guards/public-workflow-push-preflight.sh', file_get_contents(base_path('docs/public-github-first-push-checklist.md')));
        $this->assertStringContainsString('public-release/npm-license-snapshot.md', file_get_contents(base_path('docs/README.md')));
        $docsReadme = file_get_contents(base_path('docs/README.md'));
        $this->assertStringContainsString('public-release/python-license-snapshot-core.md', $docsReadme);
        $this->assertStringContainsString('public-release/python-license-snapshot-media.md', $docsReadme);
        $this->assertStringContainsString('public-release/final-signoff-trail-2026-05-01.md', $docsReadme);
        $this->assertStringContainsString('public-release/privacy-secret-scan-baseline-2026-04-29.md', file_get_contents(base_path('docs/README.md')));
        $this->assertStringContainsString('public-release/privacy-secret-scan-baseline-2026-04-29.md', file_get_contents(base_path('docs/public-release-readiness.md')));
        $this->assertStringContainsString('public-release/privacy-secret-scan-baseline-2026-04-29.md', file_get_contents(base_path('docs/public-github-first-push-checklist.md')));
        $this->assertFileExists(base_path('.github/FUNDING.yml'));
        $this->assertStringContainsString('github: [b'.'herald]', file_get_contents(base_path('.github/FUNDING.yml')));

        $privacyBaseline = file_get_contents(base_path('docs/public-release/privacy-secret-scan-baseline-2026-04-29.md'));
        foreach ([
            'Private source public-candidate set',
            'History-free export tree',
            'non-placeholder secret assignments',
            'provider token shapes',
            'credentialed URLs',
            'private paths, LAN hosts, usernames, compute labels',
            'GitHub `Public Readiness` workflow',
        ] as $token) {
            $this->assertStringContainsString($token, $privacyBaseline, "Privacy scan baseline must track {$token}.");
        }
        foreach ([
            '/home/'.'bill',
            '192.168.'.'8.',
            'b'.'herald',
            '/'.'MASTER',
        ] as $token) {
            $this->assertStringNotContainsString($token, $privacyBaseline, 'Privacy scan baseline must stay sanitized.');
        }

        $auditGuard = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));
        foreach ([
            'Real secret assignments with non-placeholder values',
            'Provider/platform token shapes',
            'Private keys, certificates, or encrypted key material',
            'Credentialed URLs',
            'Private paths, hosts, users, and compute labels',
            'Public fixture private tokens requiring rewrite',
        ] as $token) {
            $this->assertStringContainsString($token, $auditGuard, "Public audit guard must keep {$token}.");
        }

        $npmSnapshot = json_decode(file_get_contents(base_path('docs/public-release/npm-license-snapshot.json')), true, flags: JSON_THROW_ON_ERROR);
        $rootPackages = [];
        foreach ($npmSnapshot['trees'] as $tree) {
            if ($tree['label'] !== 'root') {
                continue;
            }

            foreach ($tree['packages'] as $package) {
                $rootPackages[$package['name']] = $package;
            }
        }
        $this->assertSame('Apache-2.0', $rootPackages['@mistralai/mistralai']['license']);
        $this->assertSame('license-file', $rootPackages['@mistralai/mistralai']['source']);
        $this->assertSame('MIT', $rootPackages['cohere-ai']['license']);
        $this->assertSame('license-file', $rootPackages['cohere-ai']['source']);
        $this->assertSame('Apache-2.0', $rootPackages['mcp-agent']['license']);
        $this->assertSame('permissive', $rootPackages['mcp-agent']['bucket']);

        $npmSnapshotMd = file_get_contents(base_path('docs/public-release/npm-license-snapshot.md'));
        $this->assertStringNotContainsString('| root | @mistralai/mistralai', $npmSnapshotMd);
        $this->assertStringNotContainsString('| root | cohere-ai', $npmSnapshotMd);
        $this->assertStringNotContainsString('| root | mcp-agent', $npmSnapshotMd);

        $installDoc = file_get_contents(base_path('docs/public-install-prerequisites.md'));
        $this->assertStringContainsString('requirements-media.constraints.txt', $installDoc);
        $this->assertStringContainsString('requirements-gpu.constraints.txt', $installDoc);
        $this->assertStringContainsString('Linux x86_64, Python 3.12, default-PyPI resolver dry run', $installDoc);
        $this->assertStringContainsString('setuptools==80.9.0', $installDoc);

        $pythonSnapshot = file_get_contents(base_path('docs/python-constraints-license-snapshot.md'));
        foreach (['igraph', 'leidenalg', 'psycopg2-binary', 'NVIDIA CUDA package family', 'pkg_resources', 'setuptools'] as $token) {
            $this->assertStringContainsString($token, $pythonSnapshot, "Python constraints license snapshot must track {$token}.");
        }

        $corePythonSnapshot = file_get_contents(base_path('docs/public-release/python-license-snapshot-core.md'));
        foreach (['psycopg2-binary', 'LGPL with exceptions', 'tqdm', 'MPL-2.0 AND MIT'] as $token) {
            $this->assertStringContainsString($token, $corePythonSnapshot, "Core Python license snapshot must track {$token}.");
        }
        $this->assertStringContainsString('"python_version": "3.12"', file_get_contents(base_path('docs/public-release/python-license-snapshot-core.json')));

        $mediaPythonSnapshot = file_get_contents(base_path('docs/public-release/python-license-snapshot-media.md'));
        foreach (['igraph', 'leidenalg', 'psycopg2-binary'] as $token) {
            $this->assertStringContainsString($token, $mediaPythonSnapshot, "Media Python license snapshot must track {$token}.");
        }
        $mediaPythonSnapshotJson = file_get_contents(base_path('docs/public-release/python-license-snapshot-media.json'));
        foreach (['"tier": "media"', '"name": "dlib"', '"name": "face_recognition_models"'] as $token) {
            $this->assertStringContainsString($token, $mediaPythonSnapshotJson, "Media Python license snapshot JSON must track {$token}.");
        }

        foreach ([
            'README.md',
            'NOTICE.md',
            'docs/quickstart.md',
            'docs/public-github-first-push-checklist.md',
            'docs/public-install-prerequisites.md',
            'docs/public-release/privacy-secret-scan-baseline-2026-04-29.md',
            'docs/public-release-readiness.md',
            'docs/python-constraints-license-snapshot.md',
            'requirements-core.constraints.txt',
            'requirements-media.constraints.txt',
            'requirements-gpu.constraints.txt',
            'scripts/public-export.sh',
            'scripts/public-smoke.sh',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach ([
                '/tmp/plos-core-public-smoke',
                'plos-core-public',
                'personal-life-os-core-public',
                'this workstation',
                'on this workstation',
            ] as $stalePathToken) {
                $this->assertStringNotContainsString($stalePathToken, $body, "{$path} must use the selected public release temp path.");
            }
        }

        $readinessDoc = file_get_contents(base_path('docs/public-release-readiness.md'));
        foreach ([
            'Status on 2026-05-02',
            'npm audit gates for exported workspaces',
            '130 tests / 19,901 assertions',
            '131 tests / 20,082 assertions',
            '138 tests / 22,089 assertions',
            'foreign VM proof',
            'COMPOSE_PROJECT_NAME=personal_life_os_core_public_proof',
        ] as $token) {
            $this->assertStringContainsString($token, $readinessDoc, "Public release readiness must track {$token}.");
        }
        foreach ([
            'Status on 2026-04-27',
            '125 tests / 5,299 assertions',
            '127 tests / 5,539 assertions',
            'COMPOSE_PROJECT_NAME=plos_public_proof',
        ] as $token) {
            $this->assertStringNotContainsString($token, $readinessDoc, "Public release readiness must not retain stale {$token}.");
        }

        $nativeReview = file_get_contents(base_path('docs/native-ml-package-review.md'));
        foreach ([
            'source-only MIT public repository',
            'igraph',
            'leidenalg',
            'psycopg2-binary',
            'NVIDIA CUDA Python package family',
            'face_recognition_models',
            'operator-installed',
        ] as $token) {
            $this->assertStringContainsString($token, $nativeReview, "Native/ML package review must track {$token}.");
        }

        $mediaConstraints = file_get_contents(base_path('requirements-media.constraints.txt'));
        $gpuConstraints = file_get_contents(base_path('requirements-gpu.constraints.txt'));
        $this->assertStringContainsString('setuptools==80.9.0', $mediaConstraints);
        $this->assertStringContainsString('setuptools==80.9.0', $gpuConstraints);
        $this->assertStringNotContainsString('setuptools==82.0.1', $mediaConstraints);
        $this->assertStringNotContainsString('setuptools==81.0.0', $gpuConstraints);

        $process = new Process([base_path('scripts/audit-licenses.sh')]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }

    #[Test]
    public function public_first_push_checklist_is_exported_and_manifest_references_it(): void
    {
        $checklist = file_get_contents(base_path('docs/public-github-first-push-checklist.md'));
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));

        foreach ([
            'Do not add a public remote to the private source',
            'scripts/public-export.sh --force "$HOME/tmp/personal-life-os-core"',
            'scripts/public-smoke.sh --force "$HOME/tmp/personal-life-os-core-smoke"',
            'git remote add origin <new-public-repo-url>',
            'git push -u origin main',
            'Public Readiness',
            'Docker Compose Config',
        ] as $token) {
            $this->assertStringContainsString($token, $checklist, "First-push checklist must include {$token}.");
        }

        foreach ([
            'docs/public-github-first-push-checklist.md',
            'Do not add a public remote to the',
            'git remote add origin <new-public-repo-url>',
            'git push -u origin main',
            'Public Readiness',
            'Docker Compose Config',
            'docker compose --env-file .env.example config --quiet',
        ] as $token) {
            $this->assertStringContainsString($token, $exportScript, "Export manifest template must include {$token}.");
        }

        $this->assertStringContainsString('public-github-first-push-checklist.md', file_get_contents(base_path('docs/README.md')));
        $this->assertStringContainsString('public-github-first-push-checklist.md', file_get_contents(base_path('docs/public-release-readiness.md')));
    }

    #[Test]
    public function public_database_seeding_contract_is_explicit_and_safe(): void
    {
        $this->assertFileExists(base_path('database/seeders/PublicBaselineSeeder.php'));

        $databaseSeeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
        $publicSeeder = file_get_contents(base_path('database/seeders/PublicBaselineSeeder.php'));

        $this->assertStringContainsString('PublicBaselineSeeder::class', $databaseSeeder);

        foreach ([
            'system_configs',
            'llm_instances',
            'llm_model_profiles',
            'genealogy_research_providers',
            'ollama_primary',
            'routing',
            'offline_mode',
            'pushover_enabled',
            'Optional Pushover notifications. Disabled by default',
            'Public baseline local AI row',
            'Optional cloud provider. Public baseline leaves this disabled.',
            'Manual/private source reference only',
        ] as $token) {
            $this->assertStringContainsString($token, $publicSeeder, "PublicBaselineSeeder must include {$token}.");
        }

        foreach ([
            'FAMILYSEARCH_APP_KEY',
            'FAMILYSEARCH_APP_SECRET',
            'FAMILYSEARCH_ENVIRONMENT',
            'FAMILYSEARCH_BASE_URL',
            'ANCESTRY_PASSWORD',
            'ANCESTRY_DNA_TEST_GUID',
            'PUSHOVER_API_TOKEN',
            'PUSHOVER_USER_KEY',
            '192'.'.168'.'.8.',
            '/MASTER',
        ] as $token) {
            $this->assertStringNotContainsString($token, $publicSeeder, "PublicBaselineSeeder must not seed private or unsupported integration token {$token}.");
        }

        $readme = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('php artisan db:seed --class=PublicBaselineSeeder', $readme);
        $this->assertStringContainsString('Pushover', $readme);
        $this->assertStringContainsString('not a required public dependency', $readme);

        $installDoc = file_get_contents(base_path('docs/public-install-prerequisites.md'));
        $this->assertStringContainsString('php artisan db:seed --class=PublicBaselineSeeder', $installDoc);
        $this->assertStringContainsString('Pushover is the mobile notification/review adapter; it is seeded disabled', $installDoc);
    }

    #[Test]
    public function public_joplin_youtube_defaults_are_neutral(): void
    {
        $organizer = file_get_contents(base_path('app/Services/JoplinYouTubeOrganizer.php'));
        $categories = file_get_contents(base_path('config/joplin_youtube.php'));
        $privateFolderId = '1d4d'.'b0c6'.'a0ef'.'030e'.'0bb2'.'0475'.'7c9c'.'f143';

        $this->assertStringNotContainsString($privateFolderId, $organizer);

        foreach (['pro'.'state', 'cor'.'tisol', 'roth'.'schild', 'al'.'di', 'navi'.'dad', 'dis'.'appear'] as $token) {
            $this->assertStringNotContainsString($token, strtolower($categories), "Public Joplin YouTube defaults must not contain operator-specific keyword {$token}.");
        }
    }

    #[Test]
    public function public_export_omits_private_newspapers_browser_automation(): void
    {
        $script = file_get_contents(base_path('scripts/public-export.sh'));
        $exportProbe = rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/').'/tmp/personal-life-os-core-test';

        $this->assertStringContainsString('scripts/newspapers-scraper.cjs', $script);

        $process = new Process([
            base_path('scripts/public-export.sh'),
            '--dry-run',
            $exportProbe,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $listing = $process->getOutput().$process->getErrorOutput();

        $this->assertStringNotContainsString('scripts/newspapers-scraper.cjs', $listing);
    }

    #[Test]
    public function public_export_and_audit_block_dev_agent_trace_storage(): void
    {
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));
        $auditScript = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));
        $gitignore = file_get_contents(base_path('.gitignore'));

        $this->assertStringContainsString('Tracked runtime storage payloads requiring public-extraction review', $auditScript);
        $this->assertStringContainsString('scan_tracked_runtime_storage_payloads', $auditScript);
        $this->assertStringContainsString('storage/app/dev-agent', $exportScript);
        $this->assertStringContainsString('storage/app/dev-agent/traces', $exportScript);
        $this->assertStringContainsString('storage/app/testing-offline-dev-assist-traces', $exportScript);
        $this->assertStringContainsString('storage/claude-work', $exportScript);
        $this->assertStringContainsString('storage/app/dev-agent/traces', $auditScript);
        $this->assertStringContainsString('/storage/app/dev-agent/traces/', $gitignore);

        $allowedStoragePlaceholders = [
            'storage/app/.gitignore',
            'storage/app/private/.gitignore',
            'storage/app/public/.gitignore',
            'storage/framework/.gitignore',
            'storage/framework/cache/.gitignore',
            'storage/framework/cache/data/.gitignore',
            'storage/framework/sessions/.gitignore',
            'storage/framework/testing/.gitignore',
            'storage/framework/views/.gitignore',
            'storage/logs/.gitignore',
        ];

        foreach ($this->publicExportFiles() as $file) {
            if (str_starts_with($file, 'storage/')) {
                $this->assertContains($file, $allowedStoragePlaceholders);
            }

            $this->assertStringStartsNotWith('storage/app/dev-agent/', $file);
            $this->assertStringStartsNotWith('storage/app/dev-agent/traces/', $file);
            $this->assertStringStartsNotWith('storage/app/testing-offline-dev-assist-traces/', $file);
            $this->assertStringStartsNotWith('storage/agent-handoffs/', $file);
            $this->assertStringStartsNotWith('storage/claude-work/', $file);
            $this->assertStringStartsNotWith('storage/tools/', $file);
        }
    }

    #[Test]
    public function prompt_compressor_mcp_workspace_stays_opt_in_and_out_of_public_export(): void
    {
        $workspacePath = base_path('mcp-servers/prompt-compressor');
        $workspaceGitignorePath = $workspacePath.'/.gitignore';
        $publicSmoke = file_get_contents(base_path('scripts/public-smoke.sh'));
        $publicWorkflow = file_get_contents(base_path('.github/workflows/public-readiness.yml'));

        if (is_file($workspaceGitignorePath)) {
            $workspaceGitignore = file_get_contents($workspaceGitignorePath);

            foreach ([
                'node_modules/',
                'dist/',
                '.env',
                '.context-store/',
            ] as $ignoredPath) {
                $this->assertStringContainsString($ignoredPath, $workspaceGitignore, "prompt-compressor must ignore {$ignoredPath}.");
            }
        } else {
            $this->assertFalse(is_dir($workspacePath), 'Public export must omit the local prompt-compressor workspace.');
        }

        foreach ($this->publicExportFiles() as $file) {
            $this->assertStringStartsNotWith('mcp-servers/prompt-compressor/', $file);
        }

        $this->assertStringNotContainsString('npm ci --prefix mcp-servers/prompt-compressor', $publicSmoke);
        $this->assertStringNotContainsString('npm audit --prefix mcp-servers/prompt-compressor', $publicWorkflow);
    }

    #[Test]
    public function public_file_catalog_defaults_use_configured_library_root(): void
    {
        foreach ([
            'app/Console/Commands/FileCatalogSyncCommand.php',
            'app/Console/Commands/FileEnrichmentCommand.php',
            'app/Console/Commands/FileManagementCommand.php',
            'app/Console/Commands/FileRegistryCommand.php',
            'app/Http/Controllers/Api/FileCatalogController.php',
            'app/Http/Controllers/Api/MediaBrowserController.php',
            'app/Jobs/OpsMaintenanceJob.php',
            'app/Services/FileQuarantineService.php',
            'app/Services/FileRegistryService.php',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            $this->assertStringContainsString('services.nextcloud.library_root', $body, "{$path} must use the configured library root.");
            $this->assertStringNotContainsString('/MASTER', $body, "{$path} must not assume the private top-level library folder.");
        }
    }

    #[Test]
    public function public_genealogy_media_defaults_use_configured_roots(): void
    {
        $config = file_get_contents(base_path('config/genealogy.php'));
        foreach (['GENEALOGY_NEXTCLOUD_ROOT', 'GENEALOGY_FACE_SYNC_ROOT', 'GENEALOGY_FT_REFERENCE_ROOT'] as $token) {
            $this->assertStringContainsString($token, $config);
        }

        foreach ([
            'app/Console/Commands/GenealogyBootstrapTree.php',
            'app/Console/Commands/GenealogyFaceScan.php',
            'app/Console/Commands/GenealogyFaceSync.php',
            'app/Console/Commands/GenealogyFileInventory.php',
            'app/Console/Commands/GenealogyIngestBackfillNewlyAllowed.php',
            'app/Console/Commands/GenealogyIngestDocuments.php',
            'app/Console/Commands/GenealogyMediaConsolidate.php',
            'app/Console/Commands/GenealogyMediaMigrateCommand.php',
            'app/Console/Commands/GenealogyMediaValidate.php',
            'app/Services/Genealogy/GenealogyIntakePacketRegistryService.php',
            'app/Services/Genealogy/GenealogyMediaService.php',
            'app/Services/Genealogy/GenealogySourceService.php',
            'database/migrations/2026_01_31_000004_update_file_catalog_scheduled_jobs.php',
            'database/migrations/2026_02_15_000002_raise_processing_limits_for_local_nextcloud.php',
            'database/migrations/2026_02_25_180000_add_file_ops_agent_tools_and_schedule.php',
            'resources/js/src/components/knowledge/KnowledgeContentGrid.vue',
            'resources/js/src/components/knowledge/KnowledgeFolderBrowser.vue',
            'resources/js/src/views/FileCatalogView.vue',
            'resources/js/src/views/GenealogyView.vue',
            'resources/js/src/views/KnowledgeHubView.vue',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            $this->assertStringNotContainsString('/MASTER', $body, "{$path} must not assume the private top-level library folder.");
            $this->assertStringNotContainsString('MASTER/', $body, "{$path} must not assume the private top-level library folder.");
        }
    }

    #[Test]
    public function public_optional_importers_use_configured_library_paths(): void
    {
        foreach ([
            'app/Console/Commands/EmailRagIndexCommand.php' => 'services.thunderbird.archive_profile_path',
            'app/Services/InternetArchiveService.php' => 'genealogy.nextcloud_root',
        ] as $path => $configKey) {
            $body = file_get_contents(base_path($path));

            $this->assertStringContainsString($configKey, $body, "{$path} must use {$configKey}.");
            $this->assertStringNotContainsString('/MASTER', $body, "{$path} must not assume the private top-level library folder.");
            $this->assertStringNotContainsString('MASTER/', $body, "{$path} must not assume the private top-level library folder.");
        }
    }

    #[Test]
    public function public_export_surface_has_no_operator_username_or_master_root(): void
    {
        $files = $this->publicExportFiles();

        $scanExtensions = ['php', 'vue', 'js', 'ts', 'md', 'yml', 'yaml', 'json', 'sql', 'sh'];
        $masterRootExempt = [
            'scripts/guards/public-release-audit.sh',
            'tests/Feature/Quality/FixturesProvenanceTest.php',
            'tests/Feature/Quality/PublicExportPackagingTest.php',
        ];
        $billWordExempt = [
            'app/Console/Commands/EmailSuggestionsCommand.php',
            'app/Http/Controllers/Api/EmailController.php',
            'app/Nodes/ResearchTopicRunner.php',
            'app/Services/AIAutoTagService.php',
            'app/Services/BillDetectionService.php',
            'app/Services/EmailSuggestionService.php',
            'app/Services/FaceMatcherService.php',
            'app/Services/Genealogy/DuplicateDetectionService.php',
            'app/Services/Genealogy/GenealogyService.php',
            'app/Services/Genealogy/GenealogyMediaService.php',
            'app/Services/Genealogy/NameVariantService.php',
            'app/Services/Genealogy/Support/GivenNameVariants.php',
            'resources/agents/skills/file-curator/SKILL.md',
            'resources/js/src/views/EmailQueueView.vue',
            'scripts/guards/public-release-audit.sh',
            'tests/Feature/Quality/PublicExportPackagingTest.php',
        ];

        foreach ($files as $relative) {
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! in_array($extension, $scanExtensions, true)) {
                continue;
            }

            $body = @file_get_contents(base_path($relative));
            if ($body === false) {
                continue;
            }

            if (! in_array($relative, $masterRootExempt, true)) {
                $this->assertStringNotContainsString('/MASTER', $body, "{$relative} leaks the private Nextcloud root.");
            }
            $this->assertDoesNotMatchRegularExpression("/\\?\\? *'bill'|= *'bill';|files:scan +bill|trashbin:cleanup +bill/", $body, "{$relative} defaults an operator account to bill.");

            if (! in_array($relative, $billWordExempt, true)) {
                $this->assertDoesNotMatchRegularExpression('/\bBill\b/', $body, "{$relative} leaks the operator first name.");
            }
        }
    }

    #[Test]
    public function public_export_surface_omits_private_planning_paths_and_compute_labels(): void
    {
        $guardExemptions = [
            'scripts/guards/public-release-audit.sh',
            'tests/Feature/Quality/PublicExportPackagingTest.php',
        ];

        foreach ($this->publicExportFiles() as $relative) {
            if (in_array($relative, $guardExemptions, true)) {
                continue;
            }

            $body = @file_get_contents(base_path($relative));
            if ($body === false) {
                continue;
            }

            foreach ([
                'docs/planning/',
                'prod_252',
                'gpu_252',
                'cpu_252',
                'prod_87',
                'gpu_87',
                'cpu_87',
                '.252_only',
                '.87_only',
            ] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$relative} must not contain private planning paths or compute labels.");
            }
        }
    }

    #[Test]
    public function public_audit_uses_broader_brand_guard_without_noisy_substring_terms(): void
    {
        $script = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));

        foreach (['starfleet', 'tricorder', 'viewscreen', 'warp[ _-]?drive', 'impulse[ _-]?engine'] as $token) {
            $this->assertStringContainsString($token, $script);
        }

        $this->assertStringContainsString('(^|[^[:alnum:]_])(tng|voy|ds9)([^[:alnum:]_]|$)', $script);
        $this->assertStringNotContainsString('(lcars|star trek|tng|voy)', $script);
    }

    #[Test]
    public function public_audit_contains_high_confidence_privacy_and_secret_blockers(): void
    {
        $script = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));

        foreach ([
            'Scope: tracked public-extraction candidate files only',
            'Private-only docs are excluded by path',
            'load_public_candidate_scan_paths',
            'scripts/public-export.sh --dry-run',
            'Real secret assignments with non-placeholder values',
            'Provider/platform token shapes',
            'Private keys, certificates, or encrypted key material',
            'Credentialed URLs',
            'Private paths, hosts, users, and compute labels',
            'Public-candidate files referencing non-exported planning paths',
            ':!docs/planning/**',
        ] as $token) {
            $this->assertStringContainsString($token, $script, "Public audit must document or include {$token}.");
        }

        foreach ([
            'api[_-]?key',
            'client[_-]?secret',
            'refresh[_-]?token',
            'sk-(?:proj-)?',
            'github_pat_',
            'xox[baprs]-',
            'AKIA[0-9A-Z]{16}',
            '-----BEGIN (RSA |DSA |EC |OPENSSH |PGP )?PRIVATE KEY-----',
            'BEGIN AGE ENCRYPTED FILE',
            '[a-z][a-z0-9+.-]*://[^/[:space:]:@]+:[^/[:space:]:@]+@',
            '*.tgz',
            '*.7z',
            '*.sqlite',
            '*.db',
            '*.dump',
            '*.pem',
            '*.key',
            '*.p12',
            '*.pfx',
            '/Users/bill',
            'D:\\\\master',
            'prod_252',
            'gpu_252',
            'cpu_252',
            'prod_87',
            'gpu_87',
            'cpu_87',
            '\\.252_only',
            '\\.87_only',
            'id_ed25519',
        ] as $token) {
            $this->assertStringContainsString($token, $script, "Public audit must flag {$token}.");
        }
    }

    #[Test]
    public function public_audit_excludes_private_planning_docs_from_broad_doc_scans(): void
    {
        $script = file_get_contents(base_path('scripts/guards/public-release-audit.sh'));

        $this->assertStringContainsString(
            "git ls-files app resources routes config database docs tailwind.config.js ':!docs/planning' ':!docs/planning/**'",
            $script,
            'Brand path scan must not include private planning docs.'
        );
        $this->assertStringContainsString(
            ':!docs/active-priority-list.md',
            $script,
            'Private operator TODOs must stay excluded from broad public audit scans.'
        );

        foreach ([
            'Files containing private paths, LAN hosts, usernames, or machine-specific values',
            'Files containing private database names or historical credential literals',
            'Brand/trademark terms to replace or private-only gate',
            'Legacy private project brand terms to replace or private-only gate',
            'PhotoPrism provenance language requiring review',
        ] as $title) {
            $start = strpos($script, 'flag_lines "'.$title.'"');
            $this->assertNotFalse($start, "Public audit section {$title} must exist.");

            $next = strpos($script, "\n\nflag_lines", $start + 1);
            $section = substr($script, $start, $next === false ? null : $next - $start);

            if ($title === 'Files containing private paths, LAN hosts, usernames, or machine-specific values') {
                $this->assertStringContainsString('public_username_scan_excludes', $section, "{$title} must use the shared username exclusions.");
                $this->assertStringContainsString(':!.github/FUNDING.yml', $script, 'GitHub Sponsors username is allowed only in FUNDING.yml.');

                continue;
            }

            $this->assertStringContainsString(':!docs/planning', $section, "{$title} must exclude docs/planning.");
            $this->assertStringContainsString(':!docs/planning/**', $section, "{$title} must exclude docs/planning descendants.");
        }
    }

    #[Test]
    public function public_bound_source_avoids_private_compute_instance_labels(): void
    {
        foreach ([
            'app/Console/Commands/KnowledgeGraphBuildCommand.php',
            'app/Services/AIService.php',
            'app/Services/ComputeRouterService.php',
            'app/Services/Genealogy/HtrTranscriptionService.php',
            'app/Services/KnowledgeGraphService.php',
            'app/Services/LLMPoolManagerService.php',
            'config/ollama_eval.php',
            'database/migrations/2026_03_08_000000_N106_compute_router.php',
            'database/migrations/2026_03_08_100000_N106b_compute_shares_gpu_column.php',
            'database/migrations/2026_04_17_130548_add_compatibility_authority_to_llm_instances.php',
            'database/migrations/2026_04_17_133522_backfill_ollama_compat_authority_by_url.php',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach (['gpu_252', 'cpu_252', 'gpu_87', '.252_only', '.87_only'] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not contain {$token}");
            }
        }
    }

    #[Test]
    public function public_export_omits_operator_specific_regional_news_scraper(): void
    {
        $exportScript = file_get_contents(base_path('scripts/public-export.sh'));

        foreach ([
            'app/Nodes/PressEnterpriseScraper.php',
            'app/Services/PressEnterpriseScraperService.php',
            'database/migrations/2026_04_04_173000_stabilize_news_workflows.php',
            'database/seeders/UpdateAntiHallucinationPromptsSeeder.php',
        ] as $path) {
            $this->assertStringContainsString("':(exclude){$path}'", $exportScript);
        }

        foreach ([
            'config/services.php',
            'resources/js/src/utils/nodeSchemas.js',
            'resources/js/src/views/WorkflowEditorView.vue',
        ] as $path) {
            $body = file_get_contents(base_path($path));

            foreach (['PressEnterprise', 'press_enterprise', 'PRESS_ENTERPRISE'] as $token) {
                $this->assertStringNotContainsString($token, $body, "{$path} must not contain {$token}");
            }
        }
    }

    #[Test]
    public function schema_reference_generation_is_host_neutral(): void
    {
        $schemaReference = file_get_contents(base_path('docs/schema-reference.md'));
        $command = file_get_contents(base_path('app/Console/Commands/SyncSchemaReferenceCommand.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/Auto-generated from live database on .+\\([^)]+\\)\\./',
            $schemaReference
        );
        $this->assertStringNotContainsString('gethostname()', $command);
    }

    #[Test]
    public function mysql_schema_dump_has_unique_migration_names(): void
    {
        $schema = file_get_contents(base_path('database/schema/mysql-schema.sql'));
        preg_match_all(
            "/INSERT INTO `migrations` \\(`id`, `migration`, `batch`\\) VALUES \\(\\d+,'([^']+)',\\d+\\);/",
            $schema,
            $matches
        );

        $counts = array_count_values($matches[1] ?? []);
        $duplicates = array_keys(array_filter($counts, fn (int $count): bool => $count > 1));

        $this->assertSame([], $duplicates, 'Migration names in mysql-schema.sql must not be duplicated.');
    }

    /**
     * @return list<string>
     */
    private function publicExportFiles(): array
    {
        $exportProbe = rtrim((string) (getenv('HOME') ?: sys_get_temp_dir()), '/').'/tmp/personal-life-os-core-test';
        $process = new Process([
            base_path('scripts/public-export.sh'),
            '--dry-run',
            $exportProbe,
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());

        return $this->publicExportFilesFromDryRunOutput($process->getOutput());
    }

    /**
     * @return list<string>
     */
    private function publicExportFilesFromDryRunOutput(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), function (string $line): bool {
            return $line !== ''
                && ! str_starts_with($line, 'Public export destination:')
                && ! str_starts_with($line, 'Allowlisted tracked files:');
        }));
    }

    /**
     * @return list<string>
     */
    private function publicSupportPosturePaths(): array
    {
        return [
            'README.md',
            '.github/FUNDING.yml',
            '.github/ISSUE_TEMPLATE/bug_report.yml',
            '.github/ISSUE_TEMPLATE/config.yml',
            '.github/ISSUE_TEMPLATE/docs_gap.yml',
            '.github/ISSUE_TEMPLATE/feature_request.yml',
            '.github/ISSUE_TEMPLATE/install_help.yml',
            '.github/ISSUE_TEMPLATE/maintainer_roadmap_task.yml',
            '.github/ISSUE_TEMPLATE/provenance_license_concern.yml',
            '.github/ISSUE_TEMPLATE/security_privacy_redirect.yml',
            '.github/PULL_REQUEST_TEMPLATE.md',
            'docs/README.md',
            'docs/quickstart.md',
            'docs/operation.md',
            'docs/troubleshooting.md',
            'docs/roadmap.md',
            'docs/security-privacy.md',
            'docs/public-release-readiness.md',
            'docs/public-github-first-push-checklist.md',
            'docs/public-install-prerequisites.md',
        ];
    }
}
