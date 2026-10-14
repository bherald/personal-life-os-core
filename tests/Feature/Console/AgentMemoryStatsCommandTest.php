<?php

namespace Tests\Feature\Console;

use App\Services\AgentEpisodicMemoryService;
use App\Services\AIService;
use App\Services\EpisodicMemoryMaintenanceRuntimePolicyService;
use App\Services\SystemConfigService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentMemoryStatsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createAgentEpisodeSummariesTable();
        $this->createAgentEpisodesTable();
        $this->createAgentProceduresTable();

        $systemConfig = new class($this->maintenancePolicy()) extends SystemConfigService
        {
            /** @param array<string,mixed> $policy */
            public function __construct(private readonly array $policy) {}

            public function get(string $key, mixed $default = null, bool $strict = false): mixed
            {
                return $key === EpisodicMemoryMaintenanceRuntimePolicyService::CONFIG_KEY
                    ? $this->policy
                    : $default;
            }
        };
        $this->app->instance(
            EpisodicMemoryMaintenanceRuntimePolicyService::class,
            new EpisodicMemoryMaintenanceRuntimePolicyService($systemConfig),
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        parent::tearDown();
    }

    public function test_episodic_memory_stats_emit_compact_json(): void
    {
        DB::table('agent_episode_summaries')->insert([
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'task' => 'Summarize research',
                'summary' => 'Completed alpha task.',
                'outcome' => 'success',
                'importance' => 0.90,
                'tool_count' => 3,
                'tokens_used' => 500,
                'duration_ms' => 1200,
                'is_archived' => 0,
                'created_at' => now(),
            ],
            [
                'agent_id' => 'beta-agent',
                'session_id' => 'beta-session',
                'task' => 'Retry task',
                'summary' => 'Failed beta task.',
                'outcome' => 'error',
                'importance' => 0.70,
                'tool_count' => 1,
                'tokens_used' => 100,
                'duration_ms' => 300,
                'is_archived' => 1,
                'created_at' => now(),
            ],
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--stats' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('episodic:memory', $payload['command']);
        $this->assertTrue($payload['compact']);
        $this->assertSame(2, $payload['summary']['total']);
        $this->assertSame(1, $payload['summary']['active']);
        $this->assertSame(1, $payload['summary']['archived']);
        $this->assertSame(2, $payload['summary']['outcome_count']);
        $this->assertArrayHasKey('top_agents', $payload);
        $this->assertArrayNotHasKey('outcomes', $payload);
        $this->assertStringNotContainsString('"agent_id":', $output);
        $this->assertStringNotContainsString('alpha-agent', $output);
    }

    public function test_episodic_memory_archive_requires_dry_run_or_confirmation(): void
    {
        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Maintenance requires --dry-run or --confirm.',
            (string) Artisan::output()
        );
    }

    public function test_episodic_memory_archive_compact_dry_run_is_bounded_and_does_not_write(): void
    {
        DB::table('agent_episode_summaries')->insert([
            'agent_id' => 'archive-agent',
            'session_id' => 'archive-session',
            'task' => 'Old low-importance task',
            'summary' => 'Old low-importance summary.',
            'outcome' => 'success',
            'importance' => 0.40,
            'tool_count' => 0,
            'tokens_used' => 0,
            'duration_ms' => 0,
            'episode_count' => 1,
            'is_archived' => 0,
            'created_at' => now()->subDays(100),
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--dry-run' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit, $output);
        $this->assertSame('archive', $payload['mode']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(1, $payload['summary']['candidates_selected']);
        $this->assertSame(0, $payload['summary']['summaries_archived']);
        $this->assertSame(50, $payload['summary']['limit']);
        $this->assertSame(0, DB::table('agent_episode_summaries')->where('is_archived', 1)->count());
        $this->assertStringNotContainsString('archive-agent', $output);
        $this->assertStringNotContainsString('archive-session', $output);
    }

    public function test_confirmed_archive_requires_explicit_limit_before_service_access(): void
    {
        $service = $this->mock(AgentEpisodicMemoryService::class);
        $service->shouldNotReceive('archiveOldEpisodes');

        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--confirm' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires an explicit --limit', (string) Artisan::output());
    }

    public function test_confirmed_archive_changes_only_the_explicit_bounded_selection(): void
    {
        DB::table('agent_episode_summaries')->insert([
            'agent_id' => 'archive-agent',
            'session_id' => 'archive-session',
            'task' => 'Old low-importance task',
            'summary' => 'Old low-importance summary.',
            'outcome' => 'success',
            'importance' => 0.40,
            'tool_count' => 0,
            'tokens_used' => 0,
            'duration_ms' => 0,
            'episode_count' => 1,
            'is_archived' => 0,
            'created_at' => now()->subDays(100),
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--limit' => 1,
            '--confirm' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit, $output);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['confirmed']);
        $this->assertSame(1, $payload['summary']['summaries_archived']);
        $this->assertSame(1, $payload['summary']['limit']);
        $this->assertSame(1, DB::table('agent_episode_summaries')->where('is_archived', 1)->count());
        $this->assertStringNotContainsString('archive-agent', $output);
        $this->assertStringNotContainsString('archive-session', $output);
    }

    public function test_episodic_memory_rejects_conflicting_maintenance_modes(): void
    {
        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--backfill' => true,
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Choose exactly one episodic memory mode', (string) Artisan::output());
    }

    public function test_episodic_memory_rejects_mode_inapplicable_options(): void
    {
        $statsExit = Artisan::call('episodic:memory', ['--limit' => 5]);
        $this->assertSame(1, $statsExit);
        $this->assertStringContainsString('Maintenance options require', (string) Artisan::output());

        $agentExit = Artisan::call('episodic:memory', ['--agent' => 'genealogy']);
        $this->assertSame(1, $agentExit);
        $this->assertStringContainsString('Maintenance options require', (string) Artisan::output());

        $archiveExit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--days' => 10,
            '--dry-run' => true,
        ]);
        $this->assertSame(1, $archiveExit);
        $this->assertStringContainsString('supported only with --backfill', (string) Artisan::output());
    }

    public function test_archived_embedding_prune_requires_dry_run_or_confirmation(): void
    {
        $exit = Artisan::call('episodic:memory', [
            '--prune-archived-embeddings' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('requires --dry-run or --confirm', (string) Artisan::output());
    }

    public function test_archived_embedding_prune_compact_dry_run_exposes_counts_without_ids(): void
    {
        $this->mock(AgentEpisodicMemoryService::class, function ($mock): void {
            $mock->shouldReceive('pruneArchivedEmbeddings')
                ->once()
                ->with(50, false, $this->maintenancePolicy()['prune'])
                ->andReturn([
                    'status' => 'pass',
                    'execute' => false,
                    'scan_count' => 1200,
                    'candidate_count' => 50,
                    'deleted_count' => 0,
                    'more_possible' => true,
                    'summaries_changed' => 0,
                    'memory_links_changed' => 0,
                ]);
        });

        $exit = Artisan::call('episodic:memory', [
            '--prune-archived-embeddings' => true,
            '--dry-run' => true,
            '--limit' => 50,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('prune_archived_embeddings', $payload['mode']);
        $this->assertTrue($payload['dry_run']);
        $this->assertSame(50, $payload['summary']['archived_embedding_candidates']);
        $this->assertSame(0, $payload['summary']['embeddings_deleted']);
        $this->assertSame(0, $payload['summary']['summaries_changed']);
        $this->assertSame(0, $payload['summary']['memory_links_changed']);
        $this->assertStringNotContainsString('summary_id', $output);
    }

    public function test_episodic_memory_backfill_requires_confirm_without_dry_run(): void
    {
        DB::table('agent_episodes')->insert([
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'event_type' => 'task_completed',
                'summary' => 'Completed task',
                'details' => null,
                'tokens_used' => 25,
                'duration_ms' => 1250,
                'created_at' => now()->subMinutes(10),
            ],
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--backfill' => true,
            '--days' => 2,
        ]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, DB::table('agent_episode_summaries')->count());
        $this->assertStringContainsString(
            'requires --dry-run or --confirm',
            (string) Artisan::output()
        );
    }

    public function test_episodic_memory_backfill_dry_run_reports_candidates_without_writes(): void
    {
        DB::table('agent_episodes')->insert([
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'event_type' => 'task_started',
                'summary' => 'Dry-run alpha task',
                'details' => null,
                'tokens_used' => 10,
                'duration_ms' => 100,
                'created_at' => now()->subMinutes(10),
            ],
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'event_type' => 'task_completed',
                'summary' => 'Dry-run completed',
                'details' => null,
                'tokens_used' => 20,
                'duration_ms' => 200,
                'created_at' => now()->subMinutes(9),
            ],
        ]);

        Cache::put('operator_evidence:agent_doctor:v1', ['stale' => true], now()->addMinutes(15));

        $exit = Artisan::call('episodic:memory', [
            '--backfill' => true,
            '--dry-run' => true,
            '--days' => 2,
            '--limit' => 1,
        ]);

        $output = (string) Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame(0, DB::table('agent_episode_summaries')->count());
        $this->assertStringContainsString('Found 1 candidate session(s)', $output);
        $this->assertStringContainsString('Dry run complete', $output);
        $this->assertStringContainsString('alpha-agent', $output);
        $this->assertStringNotContainsString('alpha-session', $output);
        $this->assertTrue(Cache::has('operator_evidence:agent_doctor:v1'));
    }

    public function test_episodic_memory_backfill_prioritizes_oldest_undistilled_session(): void
    {
        DB::table('agent_episodes')->insert([
            [
                'agent_id' => 'oldest-agent',
                'session_id' => 'oldest-session',
                'event_type' => 'task_completed',
                'summary' => 'Old task',
                'details' => null,
                'tokens_used' => 10,
                'duration_ms' => 100,
                'created_at' => now()->subHours(8),
            ],
            [
                'agent_id' => 'newest-agent',
                'session_id' => 'newest-session',
                'event_type' => 'task_completed',
                'summary' => 'New task',
                'details' => null,
                'tokens_used' => 10,
                'duration_ms' => 100,
                'created_at' => now()->subHour(),
            ],
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--backfill' => true,
            '--dry-run' => true,
            '--days' => 2,
            '--limit' => 1,
        ]);

        $output = (string) Artisan::output();
        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('oldest-agent', $output);
        $this->assertStringNotContainsString('newest-agent', $output);
    }

    public function test_episodic_memory_backfill_json_mode_emits_compact_payload_without_progress_output(): void
    {
        $this->mock(AIService::class, function ($mock): void {
            $mock->shouldReceive('generateEmbedding')
                ->andReturn(['success' => false]);
        });

        DB::table('agent_episodes')->insert([
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'event_type' => 'task_started',
                'summary' => 'Backfill alpha task',
                'details' => null,
                'tokens_used' => 10,
                'duration_ms' => 100,
                'created_at' => now()->subMinutes(10),
            ],
            [
                'agent_id' => 'alpha-agent',
                'session_id' => 'alpha-session',
                'event_type' => 'task_completed',
                'summary' => 'Alpha completed',
                'details' => null,
                'tokens_used' => 20,
                'duration_ms' => 200,
                'created_at' => now()->subMinutes(9),
            ],
        ]);

        Cache::put('operator_evidence:agent_doctor:v1', ['stale' => true], now()->addMinutes(15));

        $exit = Artisan::call('episodic:memory', [
            '--backfill' => true,
            '--days' => 2,
            '--limit' => 5,
            '--confirm' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = trim((string) Artisan::output());
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit, $output);
        $this->assertStringStartsWith('{', $output);
        $this->assertIsArray($payload);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('backfill', $payload['mode']);
        $this->assertTrue($payload['compact']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['confirmed']);
        $this->assertSame(1, $payload['summary']['candidate_sessions']);
        $this->assertSame(2, $payload['summary']['candidate_episodes']);
        $this->assertSame(1, $payload['summary']['processed_sessions']);
        $this->assertSame(1, $payload['summary']['summaries_created']);
        $this->assertSame(0, $payload['summary']['failed']);
        $this->assertSame(1, $payload['summary']['agent_rollup_count']);
        $this->assertSame('oldest_undistilled_first', $payload['summary']['selection_policy']);
        $this->assertSame(1, $payload['agent_rollup'][0]['rank']);
        $this->assertSame(1, DB::table('agent_episode_summaries')->count());
        $this->assertStringNotContainsString('0/1', $output);
        $this->assertStringNotContainsString('"agent_id":', $output);
        $this->assertStringNotContainsString('alpha-agent', $output);
        $this->assertStringNotContainsString('alpha-session', $output);
        $this->assertFalse(Cache::has('operator_evidence:agent_doctor:v1'));
    }

    public function test_episodic_memory_backfill_matches_existing_summaries_by_agent_and_skips_null_sessions(): void
    {
        $this->mock(AIService::class, function ($mock): void {
            $mock->shouldReceive('generateEmbedding')
                ->andReturn(['success' => false]);
        });

        DB::table('agent_episode_summaries')->insert([
            'agent_id' => 'alpha-agent',
            'session_id' => 'shared-session',
            'task' => 'Existing alpha task',
            'summary' => 'Existing alpha summary.',
            'outcome' => 'success',
            'importance' => 0.50,
            'tool_count' => 0,
            'tokens_used' => 0,
            'duration_ms' => 0,
            'episode_count' => 1,
            'is_archived' => 0,
            'created_at' => now()->subHour(),
        ]);

        DB::table('agent_episodes')->insert([
            [
                'agent_id' => 'beta-agent',
                'session_id' => 'shared-session',
                'event_type' => 'task_started',
                'summary' => 'Backfill beta task',
                'details' => null,
                'tokens_used' => 0,
                'duration_ms' => 0,
                'created_at' => now()->subMinutes(10),
            ],
            [
                'agent_id' => 'beta-agent',
                'session_id' => 'shared-session',
                'event_type' => 'task_completed',
                'summary' => 'Beta completed',
                'details' => null,
                'tokens_used' => 25,
                'duration_ms' => 1250,
                'created_at' => now()->subMinutes(9),
            ],
            [
                'agent_id' => 'null-session-agent',
                'session_id' => null,
                'event_type' => 'task_completed',
                'summary' => 'No session should not be backfilled',
                'details' => null,
                'tokens_used' => 25,
                'duration_ms' => 1250,
                'created_at' => now()->subMinutes(8),
            ],
        ]);

        $exit = Artisan::call('episodic:memory', [
            '--backfill' => true,
            '--days' => 2,
            '--limit' => 50,
            '--confirm' => true,
        ]);

        $this->assertSame(0, $exit, (string) Artisan::output());
        $this->assertSame(2, DB::table('agent_episode_summaries')->count());
        $this->assertSame(1, DB::table('agent_episode_summaries')
            ->where('agent_id', 'beta-agent')
            ->where('session_id', 'shared-session')
            ->where('outcome', 'success')
            ->count());
        $this->assertSame(0, DB::table('agent_episode_summaries')
            ->where('agent_id', 'null-session-agent')
            ->count());
    }

    public function test_procedural_memory_stats_emit_compact_json_without_procedure_details(): void
    {
        DB::table('agent_procedures')->insert([
            [
                'agent_id' => 'alpha-agent',
                'name' => 'Raw procedure name should not leak',
                'trigger_pattern' => 'Raw trigger pattern should not leak',
                'action_sequence' => json_encode([['tool' => 'secret_tool']]),
                'procedure_type' => 'success',
                'is_retired' => 0,
                'is_canonical' => 1,
                'success_rate' => 0.8000,
                'times_used' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'agent_id' => 'alpha-agent',
                'name' => 'Failure procedure',
                'trigger_pattern' => 'Failure trigger',
                'action_sequence' => json_encode([['tool' => 'failure_tool']]),
                'procedure_type' => 'failure',
                'is_retired' => 0,
                'is_canonical' => 0,
                'success_rate' => 0.2000,
                'times_used' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'agent_id' => 'beta-agent',
                'name' => 'Retired procedure',
                'trigger_pattern' => 'Retired trigger',
                'action_sequence' => json_encode([['tool' => 'retired_tool']]),
                'procedure_type' => 'success',
                'is_retired' => 1,
                'is_canonical' => 0,
                'success_rate' => 1.0000,
                'times_used' => 9,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $exit = Artisan::call('agent:procedures', [
            '--stats' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $output = (string) Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertSame('pass', $payload['status']);
        $this->assertSame('agent:procedures', $payload['command']);
        $this->assertTrue($payload['compact']);
        $this->assertSame(3, $payload['summary']['total']);
        $this->assertSame(2, $payload['summary']['active']);
        $this->assertSame(1, $payload['summary']['retired']);
        $this->assertSame(1, $payload['summary']['canonical']);
        $this->assertSame(1, $payload['summary']['failure_memories']);
        $this->assertArrayHasKey('top_agents', $payload);
        $this->assertArrayNotHasKey('per_agent', $payload);
        $this->assertStringNotContainsString('Raw procedure name should not leak', $output);
        $this->assertStringNotContainsString('Raw trigger pattern should not leak', $output);
        $this->assertStringNotContainsString('secret_tool', $output);
    }

    public function test_procedural_memory_json_mode_rejects_mutating_options(): void
    {
        $exit = Artisan::call('agent:procedures', [
            '--consolidate' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'The --json/--compact options are only supported for read-only stats.',
            (string) Artisan::output()
        );
    }

    /** @return array<string,mixed> */
    private function maintenancePolicy(): array
    {
        return [
            'schema' => EpisodicMemoryMaintenanceRuntimePolicyService::SCHEMA,
            'archive' => [
                'retention_days' => 90,
                'importance_below' => 0.6,
                'preview_default_limit' => 50,
                'max_limit' => 5000,
            ],
            'backfill' => [
                'default_days' => 30,
                'max_days' => 3650,
                'preview_default_limit' => 50,
                'max_limit' => 500,
            ],
            'prune' => [
                'preview_default_limit' => 50,
                'max_limit' => 5000,
                'scan_batch' => 1000,
                'scan_cap' => 100000,
                'delete_batch' => 500,
            ],
        ];
    }

    private function createAgentEpisodesTable(): void
    {
        Schema::create('agent_episodes', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id', 100);
            $table->string('session_id', 100)->nullable();
            $table->string('event_type', 50);
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createAgentEpisodeSummariesTable(): void
    {
        Schema::create('agent_episode_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id', 100);
            $table->string('session_id', 100)->nullable();
            $table->string('task', 500)->nullable();
            $table->text('summary')->nullable();
            $table->string('outcome', 20)->default('success');
            $table->decimal('importance', 3, 2)->default(0.50);
            $table->json('tools_used')->nullable();
            $table->unsignedSmallInteger('tool_count')->default(0);
            $table->unsignedInteger('tokens_used')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedSmallInteger('episode_count')->default(0);
            $table->boolean('is_archived')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    private function createAgentProceduresTable(): void
    {
        Schema::create('agent_procedures', function (Blueprint $table): void {
            $table->id();
            $table->string('agent_id', 100);
            $table->string('name', 200)->nullable();
            $table->string('trigger_pattern', 500)->nullable();
            $table->json('action_sequence')->nullable();
            $table->string('procedure_type', 20)->default('success');
            $table->boolean('is_retired')->default(false);
            $table->boolean('is_canonical')->default(false);
            $table->decimal('success_rate', 5, 4)->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('times_succeeded')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
}
