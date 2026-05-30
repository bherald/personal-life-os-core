<?php

namespace Tests\Feature\Console;

use App\Services\AIService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
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

        $payload = json_decode((string) Artisan::output(), true);

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
    }

    public function test_episodic_memory_json_mode_rejects_mutating_options(): void
    {
        $exit = Artisan::call('episodic:memory', [
            '--archive' => true,
            '--json' => true,
            '--compact' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'The --json/--compact options are only supported for read-only stats.',
            (string) Artisan::output()
        );
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
            'requires --confirm',
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
