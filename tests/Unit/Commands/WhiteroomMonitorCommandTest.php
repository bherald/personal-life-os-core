<?php

namespace Tests\Unit\Commands;

use App\Services\UpstreamPrivatePreparationWorkerService;
use App\Services\UpstreamSourceDriftRuntimePolicyService;
use App\Services\UpstreamSourceDriftService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WhiteroomMonitorCommandTest extends TestCase
{
    public function test_scheduled_preparation_consumes_the_bounded_worker_cycle_after_metadata(): void
    {
        $sequence = [];
        $this->mock(UpstreamSourceDriftRuntimePolicyService::class, function ($mock): void {
            $mock->shouldReceive('preparation')->once()->andReturn(['enabled' => true]);
        });
        $this->mock(UpstreamSourceDriftService::class, function ($mock) use (&$sequence): void {
            $mock->shouldReceive('prepareScheduled')->once()->andReturnUsing(function () use (&$sequence): array {
                $sequence[] = 'metadata';

                return ['schema' => 'metadata', 'checks' => [['private' => 'must omit']]];
            });
        });
        $this->mock(UpstreamPrivatePreparationWorkerService::class, function ($mock) use (&$sequence): void {
            $mock->shouldReceive('consumeScheduled')->once()->andReturnUsing(function () use (&$sequence): array {
                $sequence[] = 'worker';

                return ['status' => 'disabled', 'invoked' => 0];
            });
        });
        $this->assertSame(0, Artisan::call('whiteroom:monitor', ['--format' => 'json', '--compact' => true, '--prepare' => true]));
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(['metadata', 'worker'], $sequence);
        $this->assertArrayNotHasKey('checks', $payload);
        $this->assertSame('disabled', $payload['worker_cycle']['status']);
        $this->assertSame(0, $payload['worker_cycle']['invoked']);
    }

    public function test_json_output_lists_monitor_projects_with_cadence(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--as-of' => '2026-06-25',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $repos = array_column($payload['projects'], 'repo');
        $playwright = collect($payload['projects'])->firstWhere('key', 'playwright-mcp');

        $this->assertSame(0, $exit);
        $this->assertSame('plos_whiteroom_monitor.v1', $payload['schema']);
        $this->assertSame('2026-06-25', $payload['as_of']);
        $this->assertGreaterThanOrEqual(80, $payload['summary']['total']);
        $this->assertSame(0, $payload['summary']['due']);
        $this->assertContains('https://github.com/microsoft/playwright-mcp', $repos);
        $this->assertContains('https://github.com/modelcontextprotocol/inspector', $repos);
        $this->assertContains('https://github.com/modelcontextprotocol/typescript-sdk', $repos);
        $this->assertContains('https://github.com/modelcontextprotocol/modelcontextprotocol', $repos);
        $this->assertContains('https://github.com/whitequark/ipaddr.js', $repos);
        $this->assertContains('https://github.com/open-telemetry/semantic-conventions-genai', $repos);
        $this->assertContains('https://github.com/promptfoo/promptfoo', $repos);
        $this->assertContains('https://github.com/QwenLM/Qwen3-Embedding', $repos);
        $this->assertContains('https://github.com/infiniflow/ragflow', $repos);
        $this->assertContains('https://github.com/OHF-Voice/piper1-gpl', $repos);
        $this->assertContains('https://github.com/thewh1teagle/kokoro-onnx', $repos);
        $this->assertContains('https://github.com/KittenML/KittenTTS', $repos);
        $this->assertContains('https://github.com/NVIDIA/skills', $repos);
        $this->assertContains('https://github.com/snyk/agent-scan', $repos);
        $this->assertContains('https://github.com/microsoft/agent-governance-toolkit', $repos);
        $this->assertContains('https://github.com/eugeneshilow/rubench', $repos);
        $this->assertContains('https://github.com/MeherBhaskar/RigorBench', $repos);
        $this->assertContains('https://github.com/rbonghi/jetson_stats', $repos);
        $this->assertContains('https://github.com/a2aproject/A2A', $repos);
        $this->assertContains('https://github.com/Ayanami0730/arag', $repos);
        $this->assertSame('monitor_watchlist', $playwright['registry']);
        $this->assertGreaterThanOrEqual(9, $payload['summary']['by_source_type']['paper']);
        $this->assertGreaterThanOrEqual(5, $payload['summary']['by_source_type']['standard']);
        $this->assertSame('browser_mcp', $playwright['area']);
        $this->assertFalse($playwright['due']);
        $this->assertNotEmpty($playwright['breakthrough_signals']);

        $mcpSpec = collect($payload['projects'])->firstWhere('key', 'model-context-protocol-spec');
        $this->assertSame('spec_version', $mcpSpec['identifier_scheme']);
        $this->assertSame('2025-11-25', $mcpSpec['source_version']);
        $this->assertSame('external_result_schema_validation_integrated', $mcpSpec['status']);

        $mcpSdk = collect($payload['projects'])->firstWhere('key', 'mcp-typescript-sdk');
        $this->assertSame('1.30.1', $mcpSdk['release_version']);
        $this->assertSame('sdk_stdio_contract_probe_integrated', $mcpSdk['status']);
        $this->assertFalse($mcpSdk['due']);

        $ruBench = collect($payload['projects'])->firstWhere('key', 'rubench');
        $this->assertSame('2849a1740cbd404552e460f82f339c1103244a7d', $ruBench['source_version']);
        $this->assertSame('product_configuration_identity_integrated', $ruBench['status']);

        $feaBench = collect($payload['projects'])->firstWhere('key', 'fea-bench');
        $this->assertSame('fb3c11274796f6057bf447410540fcf2fa1d90b1', $feaBench['source_version']);
        $this->assertSame('MIT code; benchmark tasks and source repositories separately licensed', $feaBench['license']);
        $this->assertSame('protected_feature_extension_eval_integrated', $feaBench['status']);
    }

    public function test_nvidia_skill_trust_sources_keep_pinned_revisions_and_integrated_status(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--area' => ['skill_intake_security'],
            '--as-of' => '2026-07-14',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $projects = collect($payload['projects'])->keyBy('key');

        $this->assertSame(0, $exit);
        $this->assertSame([
            'nvidia-skills',
            'skillspector',
        ], $projects->keys()->sort()->values()->all());
        $this->assertSame('container_trust_gate_integrated', $projects['skillspector']['status']);
        $this->assertSame('recurring_audit_debt_visibility_integrated', $projects['nvidia-skills']['status']);
        $this->assertSame('8f534e2951e0b7d0b8fb8e84832cd3605f95c032', $projects['skillspector']['source_version']);
        $this->assertSame('f3f7ce4ecffa751f151bfb9b6c865c51f2b40557', $projects['nvidia-skills']['source_version']);

        foreach ($projects as $project) {
            $this->assertSame('git_commit', $project['identifier_scheme']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $project['source_version']);
            $this->assertContains($project['last_reviewed_at'], ['2026-07-14', '2026-07-15', '2026-07-16']);
            $this->assertFalse($project['due']);
        }
    }

    public function test_filters_first_class_research_sources_by_type(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--source-type' => ['paper'],
            '--as-of' => '2026-07-13',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $paper = collect($payload['projects'])->firstWhere('key', 'procedure-aware-evaluation-paper');
        $geneaPaper = collect($payload['projects'])->firstWhere('key', 'historical-coreference-disambiguation-paper');
        $engramPaper = collect($payload['projects'])->firstWhere('key', 'engram-bitemporal-memory-paper');
        $loopPaper = collect($payload['projects'])->firstWhere('key', 'infinite-agentic-loops-paper');
        $failurePaper = collect($payload['projects'])->firstWhere('key', 'cli-agent-failure-process-paper');
        $toolMazePaper = collect($payload['projects'])->firstWhere('key', 'toolmaze-paper');
        $traceProbe = collect($payload['projects'])->firstWhere('key', 'traceprobe-paper');
        $feaBench = collect($payload['projects'])->firstWhere('key', 'fea-bench-paper');
        $recoveryPaper = collect($payload['projects'])->firstWhere('key', 'failure-anchored-recovery-paper');
        $repeatabilityPaper = collect($payload['projects'])->firstWhere('key', 'coding-agent-context-paper');
        $suppressionPaper = collect($payload['projects'])->firstWhere('key', 'static-analysis-alert-suppressions-paper');
        $rigorBenchPaper = collect($payload['projects'])->firstWhere('key', 'rigorbench-process-discipline-paper');

        $this->assertSame(0, $exit);
        $this->assertSame(61, $payload['summary']['total']);
        $this->assertSame(['paper' => 61], $payload['summary']['by_source_type']);
        $this->assertSame(['research_watchlist' => 61], $payload['summary']['by_registry']);
        $this->assertSame('2606.22678', $rigorBenchPaper['identifier']);
        $this->assertSame('v2', $rigorBenchPaper['source_version']);
        $this->assertSame('write_checkpoint_validation_diagnostic_integrated', $rigorBenchPaper['status']);
        $this->assertSame('runtime_policy_definition_context_integrated', $suppressionPaper['status']);
        $this->assertSame('repeat_consistency_promotion_gate_integrated', $repeatabilityPaper['status']);
        $this->assertSame('paper', $paper['source_type']);
        $this->assertSame('arxiv', $paper['identifier_scheme']);
        $this->assertSame('2603.03116', $paper['identifier']);
        $this->assertSame('v1', $paper['source_version']);
        $this->assertSame('2026-03-03', $paper['source_updated_at']);
        $this->assertSame('https://arxiv.org/abs/2603.03116', $paper['source_url']);
        $this->assertNotEmpty($paper['citation']);
        $this->assertSame('doi', $geneaPaper['identifier_scheme']);
        $this->assertSame('10.18653/v1/2024.emnlp-main.355', $geneaPaper['identifier']);
        $this->assertSame('identity_bridge_gate_integrated', $geneaPaper['status']);
        $this->assertSame('2606.09900', $engramPaper['identifier']);
        $this->assertSame('v1', $engramPaper['source_version']);
        $this->assertSame('CC-BY-4.0', $engramPaper['license']);
        $this->assertSame('bitemporal_integrity_audit_integrated', $engramPaper['status']);
        $this->assertSame('2607.01641', $loopPaper['identifier']);
        $this->assertSame('periodic_loop_guard_integrated', $loopPaper['status']);
        $this->assertSame('CC-BY-4.0', $failurePaper['license']);
        $this->assertSame('2606.05806', $toolMazePaper['identifier']);
        $this->assertSame('tool_recovery_eval_integrated', $toolMazePaper['status']);
        $this->assertSame('2607.06184', $traceProbe['identifier']);
        $this->assertSame('structural_trajectory_diagnostics_integrated', $traceProbe['status']);
        $this->assertSame('10.18653/v1/2025.acl-long.839', $feaBench['identifier']);
        $this->assertSame('CC-BY-4.0', $feaBench['license']);
        $this->assertSame('protected_feature_extension_eval_integrated', $feaBench['status']);
        $this->assertSame('2605.08717', $recoveryPaper['identifier']);
        $this->assertSame('v2', $recoveryPaper['source_version']);
        $this->assertSame('failure_anchored_recovery_guidance_integrated', $recoveryPaper['status']);
        $this->assertNotEmpty($paper['legal_basis']);
        $this->assertFalse($paper['due']);

        $mcpSecurityBench = collect($payload['projects'])->firstWhere('key', 'mcp-security-bench-paper');
        $this->assertSame('2510.15994', $mcpSecurityBench['identifier']);
        $this->assertSame('v2', $mcpSecurityBench['source_version']);
        $this->assertSame('native_tool_contract_gate_integrated', $mcpSecurityBench['status']);

        $executionControl = collect($payload['projects'])->firstWhere('key', 'mcp-execution-control-invariants-paper');
        $this->assertSame('2606.29073', $executionControl['identifier']);
        $this->assertSame('native_scoped_invocation_gate_integrated', $executionControl['status']);

        $coreBench = collect($payload['projects'])->firstWhere('key', 'core-bench-paper');
        $this->assertSame('2606.11864', $coreBench['identifier']);
        $this->assertSame('v2', $coreBench['source_version']);
        $this->assertSame('repo_context_eval_integrated', $coreBench['status']);

        $rig = collect($payload['projects'])->firstWhere('key', 'repository-intelligence-graph-paper');
        $this->assertSame('CC-BY-4.0', $rig['license']);

        $sweExplore = collect($payload['projects'])->firstWhere('key', 'swe-explore-paper');
        $this->assertSame('CC-BY-NC-ND-4.0; factual evaluation taxonomy used without copying or adapting the paper or dataset', $sweExplore['license']);

        $agentsMd = collect($payload['projects'])->firstWhere('key', 'agents-md-evaluation-paper');
        $this->assertSame('v2', $agentsMd['source_version']);
        $this->assertSame('exact_project_scoped_offline_repository_guidance_integrated', $agentsMd['status']);

        $contextBench = collect($payload['projects'])->firstWhere('key', 'contextbench-paper');
        $this->assertSame('v3', $contextBench['source_version']);
        $this->assertSame('repo_context_precision_integrated', $contextBench['status']);

        $ruBench = collect($payload['projects'])->firstWhere('key', 'rubench-paper');
        $this->assertSame('2607.06411', $ruBench['identifier']);
        $this->assertSame('product_configuration_identity_integrated', $ruBench['status']);
    }

    public function test_human_review_repositories_retain_reviewed_commit_metadata(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--area' => ['human_review_workflow'],
            '--as-of' => '2026-07-13',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $repositories = collect($payload['projects'])
            ->where('source_type', 'repository')
            ->keyBy('key');

        $this->assertSame(0, $exit);
        $this->assertSame(
            ['ag-ui-protocol', 'argilla', 'cleanlab', 'fiftyone', 'inception', 'label-studio'],
            $repositories->keys()->sort()->values()->all()
        );

        foreach ($repositories as $repository) {
            $this->assertSame('git_commit', $repository['identifier_scheme']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $repository['identifier']);
            $this->assertSame($repository['identifier'], $repository['source_version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $repository['source_updated_at']);
        }

        $this->assertSame(
            'interrupted_tool_lifecycle_ordering_integrated_dotnet_docs_reviewed',
            $repositories['ag-ui-protocol']['status']
        );
        $this->assertSame(
            'b646b463445520e2c492eda17a948edf11f32cbb',
            $repositories['ag-ui-protocol']['identifier']
        );
        $this->assertSame(
            'review_focus_age_observability_integrated_video_vector_docs_repeated',
            $repositories['label-studio']['status']
        );
        $this->assertSame(
            'review_focus_age_observability_integrated',
            $repositories['argilla']['status']
        );
        $this->assertSame(
            'tree_scoped_picture_packet_pressure_integrated',
            $repositories['fiftyone']['status']
        );
        $this->assertSame(
            'review_workload_state_reference',
            $repositories['inception']['status']
        );
    }

    public function test_filters_by_area_and_excludes_other_areas(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--area' => ['agent_memory_eval'],
            '--as-of' => '2026-06-25',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $keys = array_column($payload['projects'], 'key');

        $this->assertSame(0, $exit);
        $this->assertContains('graphiti', $keys);
        $this->assertContains('engram-memory', $keys);
        $this->assertContains('engram-bitemporal-memory-paper', $keys);
        $this->assertContains('omnicoreagent', $keys);
        $this->assertContains('infinite-agentic-loops-paper', $keys);
        $this->assertContains('cli-agent-failure-process-paper', $keys);
        $this->assertContains('toolmaze-paper', $keys);
        $this->assertContains('openinference', $keys);
        $this->assertNotContains('playwright-mcp', $keys);
        $this->assertSame($payload['summary']['total'], $payload['summary']['by_area']['agent_memory_eval']);
    }

    public function test_laravel_ai_sdk_stays_on_monitor_followup_list(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--area' => ['laravel_ai'],
            '--as-of' => '2026-06-29',
        ]);

        $payload = json_decode(Artisan::output(), true);
        $laravelAi = collect($payload['projects'])->firstWhere('key', 'laravel-ai');

        $this->assertSame(0, $exit);
        $this->assertNotNull($laravelAi);
        $this->assertSame('https://github.com/laravel/ai', $laravelAi['repo']);
        $this->assertSame('multi_step_stream_boundary_integrated', $laravelAi['status']);
        $this->assertSame('laravel_ai', $laravelAi['area']);
        $this->assertStringContainsString('distinct tool-loop iterations', $laravelAi['next_action']);

        $vercelAi = collect($payload['projects'])->firstWhere('key', 'vercel-ai-sdk');
        $this->assertNotNull($vercelAi);
        $this->assertSame('kimi_k3_provider_delta_reviewed_no_route', $vercelAi['status']);
        $this->assertSame('Apache-2.0', $vercelAi['license']);
    }

    public function test_due_filter_uses_review_cadence_and_limit(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--due' => true,
            '--priority' => ['P0'],
            '--limit' => 5,
            '--as-of' => '2026-07-30',
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(5, $payload['summary']['total']);
        $this->assertSame(5, $payload['summary']['due']);

        foreach ($payload['projects'] as $project) {
            $this->assertSame('P0', $project['priority']);
            $this->assertTrue($project['due']);
            $this->assertGreaterThanOrEqual($project['monitor_cadence_days'], $project['days_since_review']);
        }
    }

    public function test_compact_json_is_scheduler_safe(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--compact' => true,
            '--due' => true,
            '--priority' => ['P0'],
            '--limit' => 5,
            '--as-of' => '2026-07-30',
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame('plos_whiteroom_monitor_compact.v1', $payload['schema']);
        $this->assertTrue($payload['compact']);
        $this->assertSame(5, $payload['summary']['total']);
        $this->assertSame(5, $payload['summary']['due']);
        $this->assertCount(5, $payload['watch_refs']);
        $this->assertArrayHasKey('project_key', $payload['watch_refs'][0]);
        $this->assertArrayHasKey('source_type', $payload['watch_refs'][0]);
        $this->assertArrayHasKey('identifier_scheme', $payload['watch_refs'][0]);
        $this->assertArrayHasKey('source_version', $payload['watch_refs'][0]);
        $this->assertArrayHasKey('source_updated_at', $payload['watch_refs'][0]);
        $this->assertArrayNotHasKey('projects', $payload);
        $this->assertSame('lawful_direct_reuse_preferred', $payload['posture']['adoption_policy']);
        $this->assertTrue($payload['posture']['source_reuse_allowed_when_lawful']);
        $this->assertArrayNotHasKey('source_reuse_approved', $payload['posture']);

        $forbiddenKeys = ['name', 'url', 'urls', 'rows', 'samples', 'items', 'title', 'path', 'paths'];
        $this->assertSame([], $this->forbiddenKeysIn($payload, $forbiddenKeys));
    }

    public function test_compact_research_output_keeps_identifiers_content_free(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--compact' => true,
            '--source-type' => ['paper'],
            '--limit' => 2,
            '--as-of' => '2026-08-13',
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(61, $payload['filtered_total']);
        $this->assertSame(2, $payload['summary']['total']);
        $this->assertSame(['paper' => 2], $payload['summary']['by_source_type']);
        $this->assertSame(['research_watchlist' => 2], $payload['summary']['by_registry']);
        $this->assertCount(2, $payload['watch_refs']);

        foreach ($payload['watch_refs'] as $source) {
            $this->assertSame('paper', $source['source_type']);
            $this->assertSame('arxiv', $source['identifier_scheme']);
            $this->assertMatchesRegularExpression('/^v\d+$/', $source['source_version']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $source['source_updated_at']);
            $this->assertTrue($source['due']);
            $this->assertArrayNotHasKey('identifier', $source);
            $this->assertArrayNotHasKey('citation', $source);
        }
    }

    public function test_compact_requires_json_format(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--compact' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('The --compact option is only supported with --format=json.', Artisan::output());
    }

    public function test_invalid_as_of_fails_cleanly(): void
    {
        $exit = Artisan::call('whiteroom:monitor', [
            '--format' => 'json',
            '--as-of' => 'not-a-date',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --as-of. Use YYYY-MM-DD.', Artisan::output());
    }

    /**
     * @param  array<int, string>  $forbiddenKeys
     * @return array<int, string>
     */
    private function forbiddenKeysIn(mixed $value, array $forbiddenKeys): array
    {
        $hits = [];

        $walk = function (mixed $node) use (&$walk, &$hits, $forbiddenKeys): void {
            if (! is_array($node)) {
                return;
            }

            foreach ($node as $key => $child) {
                if (in_array((string) $key, $forbiddenKeys, true)) {
                    $hits[] = (string) $key;
                }

                $walk($child);
            }
        };

        $walk($value);

        sort($hits);

        return array_values(array_unique($hits));
    }
}
