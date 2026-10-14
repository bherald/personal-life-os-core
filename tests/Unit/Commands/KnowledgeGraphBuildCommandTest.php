<?php

namespace Tests\Unit\Commands;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\HyperGraphService;
use App\Services\KnowledgeGraphControllerBatchLeaseService;
use App\Services\KnowledgeGraphService;
use App\Services\SystemConfigService;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class KnowledgeGraphBuildCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.nextcloud.url' => 'http://localhost',
            'services.nextcloud.username' => 'test',
            'services.nextcloud.password' => 'test',
        ]);
        $settings = Mockery::mock(SystemConfigService::class);
        $settings->shouldReceive('get')->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $default);
        $settings->shouldReceive('llmRoutingBinding')->with('knowledge_graph')->andReturn([
            'default_alias' => 'primary',
            'aliases' => ['primary' => ['ollama_primary'], 'secondary' => ['ollama_secondary'], 'any' => null],
            'managed' => ['instance_id' => 'ollama_secondary'],
        ]);
        $this->app->instance(SystemConfigService::class, $settings);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_dry_run_can_target_stale_backlog_with_stale_first_order(): void
    {
        $this->app->instance(KnowledgeGraphService::class, Mockery::mock(KnowledgeGraphService::class));
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        DB::shouldReceive('selectOne')
            ->andReturnUsing(function ($sql, $bindings = []) {
                if (str_contains($sql, 'AVG(duration_seconds / items_processed)')) {
                    return (object) ['avg_seconds' => null, 'sample_count' => 0];
                }

                if (str_contains($sql, "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'knowledge_graph_build'")) {
                    return (object) ['timeout_minutes' => 289];
                }

                if (str_contains($sql, 'SELECT COUNT(*) as cnt FROM rag_documents')) {
                    return (object) ['cnt' => 1];
                }

                return null;
            });

        DB::shouldReceive('connection')->with('pgsql_rag')->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (...$args) {
                $sql = $args[0] ?? '';
                $bindings = $args[1] ?? [];

                return str_contains($sql, 'kg_extracted_at IS NOT NULL')
                    && str_contains($sql, 'content_hash IS DISTINCT FROM kg_content_hash')
                    && str_contains($sql, 'CASE')
                    && str_contains($sql, 'COALESCE(kg_extracted_at, created_at) ASC')
                    && ($bindings[count($bindings) - 1] ?? null) === 10;
            })
            ->andReturn([
                (object) [
                    'id' => 42,
                    'title' => 'Stale doc',
                    'content' => str_repeat('A', 300),
                    'document_type' => 'file_catalog',
                    'content_hash' => 'new-hash',
                    'kg_content_hash' => 'old-hash',
                    'kg_extracted_at' => '2026-04-19 10:00:00',
                    'content_length' => 300,
                ],
            ]);

        $this->artisan('rag:build-knowledge-graph', [
            '--dry-run' => true,
            '--limit' => 10,
            '--backlog' => 'stale',
            '--order' => 'stale-first',
            '--sleep' => 0,
        ])->assertExitCode(0);
    }

    public function test_dry_run_can_target_fresh_backlog_oldest_first(): void
    {
        $this->app->instance(KnowledgeGraphService::class, Mockery::mock(KnowledgeGraphService::class));
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        DB::shouldReceive('selectOne')
            ->andReturnUsing(function ($sql, $bindings = []) {
                if (str_contains($sql, 'AVG(duration_seconds / items_processed)')) {
                    return (object) ['avg_seconds' => null, 'sample_count' => 0];
                }

                if (str_contains($sql, "SELECT timeout_minutes FROM scheduled_jobs WHERE name = 'knowledge_graph_build'")) {
                    return (object) ['timeout_minutes' => 289];
                }

                if (str_contains($sql, 'SELECT COUNT(*) as cnt FROM rag_documents')) {
                    return (object) ['cnt' => 1];
                }

                return null;
            });

        DB::shouldReceive('connection')->with('pgsql_rag')->andReturnSelf();

        DB::shouldReceive('select')
            ->once()
            ->withArgs(function (...$args) {
                $sql = $args[0] ?? '';
                $bindings = $args[1] ?? [];

                return str_contains($sql, 'kg_extracted_at IS NULL')
                    && ! str_contains($sql, 'kg_extracted_at IS NOT NULL AND content_hash IS NOT NULL AND content_hash IS DISTINCT FROM kg_content_hash')
                    && str_contains($sql, 'ORDER BY created_at ASC, id ASC')
                    && ($bindings[count($bindings) - 1] ?? null) === 5;
            })
            ->andReturn([
                (object) [
                    'id' => 7,
                    'title' => 'Fresh doc',
                    'content' => str_repeat('B', 350),
                    'document_type' => 'file_catalog',
                    'content_hash' => 'hash-1',
                    'kg_content_hash' => null,
                    'kg_extracted_at' => null,
                    'content_length' => 350,
                ],
            ]);

        $this->artisan('rag:build-knowledge-graph', [
            '--dry-run' => true,
            '--limit' => 5,
            '--backlog' => 'fresh',
            '--order' => 'oldest',
            '--sleep' => 0,
        ])->assertExitCode(0);
    }

    public function test_managed_secondary_processes_three_documents_under_one_controller_lease(): void
    {
        $this->mockRouteBinding([
            'default_alias' => 'remote_lane',
            'aliases' => ['remote_lane' => ['registered_remote']],
            'managed' => ['instance_id' => 'registered_remote'],
        ]);
        $documents = array_map(static fn (int $id): object => (object) [
            'id' => $id,
            'title' => "Document {$id}",
            'content' => str_repeat('K', 200),
            'document_type' => 'file_catalog',
            'content_hash' => "hash-{$id}",
            'kg_content_hash' => null,
            'kg_extracted_at' => null,
            'content_length' => 200,
        ], [1, 2, 3]);

        DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql): ?object {
            if (str_contains($sql, 'AVG(duration_seconds / items_processed)')) {
                return (object) ['avg_seconds' => 30, 'sample_count' => 2];
            }
            if (str_contains($sql, 'timeout_minutes')) {
                return (object) ['timeout_minutes' => 85];
            }
            if (str_contains($sql, 'COUNT(*) as cnt')) {
                return (object) ['cnt' => 3];
            }

            return null;
        });
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('select')->once()->withArgs(function (string $sql): bool {
            return str_contains($sql, 'FROM rag_documents') && str_contains($sql, 'LIMIT ?');
        })->andReturn($documents);
        DB::shouldReceive('select')->byDefault()->andReturn([]);
        DB::shouldReceive('update')->times(3)->andReturn(1);

        config(['cache.default' => 'array']);

        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->times(3)->withArgs(function (string $text, array $options): bool {
            return $text === str_repeat('K', 200)
                && ($options['preferred_instance'] ?? null) === 'remote_lane'
                && ($options['managed_model_host'] ?? null) === true
                && ($options['scheduled_batch'] ?? null) === true;
        })->andReturn(['success' => true, 'entities' => [], 'relationships' => []]);
        $hyper = Mockery::mock(HyperGraphService::class);
        $batch = Mockery::mock(KnowledgeGraphControllerBatchLeaseService::class);
        $batch->shouldReceive('run')->once()->withArgs(function (int $size, callable $executor): bool {
            $this->assertSame(3, $size);
            foreach (range(0, 2) as $offset) {
                $executor($offset, []);
            }

            return true;
        })->andReturn([
            'result' => 'green',
            'results' => array_fill(0, 3, ['result' => 'green', 'release_result' => 'released', 'target' => 'ollama_qwen3_8b_87']),
        ]);
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, $hyper);
        $this->app->instance(KnowledgeGraphControllerBatchLeaseService::class, $batch);

        $this->artisan('rag:build-knowledge-graph', [
            '--limit' => 3,
            '--sleep' => 0,
            '--instance' => 'managed-secondary',
            '--model-role' => 'standard',
        ])->expectsOutputToContain('[MHC_BATCH_LEASE:green target=ollama_qwen3_8b_87 size=3 cleanup=released]')
            ->assertExitCode(0);
    }

    public function test_terminal_admission_stop_aborts_batch_before_later_document(): void
    {
        $this->mockPendingDocuments(2);
        config(['cache.default' => 'array']);

        $stop = new InferenceAdmissionException('synthetic terminal admission stop');
        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->once()->andThrow($stop);
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        try {
            $this->artisan('rag:build-knowledge-graph', [
                '--limit' => 2,
                '--sleep' => 0,
                '--budget-minutes' => 1,
                '--instance' => 'primary',
            ]);
            $this->fail('The terminal admission stop should escape the batch command.');
        } catch (\Throwable $caught) {
            $this->assertSame($stop, $caught);
        }
    }

    public function test_ordinary_document_failure_still_allows_later_document(): void
    {
        $this->mockPendingDocuments(2);
        config(['cache.default' => 'array']);
        DB::shouldReceive('update')->once()->andReturn(1);

        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->twice()->andReturn(
            ['success' => false, 'entities' => [], 'relationships' => []],
            ['success' => true, 'entities' => [], 'relationships' => []]
        );
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        $this->artisan('rag:build-knowledge-graph', [
            '--limit' => 2,
            '--sleep' => 0,
            '--budget-minutes' => 1,
            '--instance' => 'primary',
        ])->assertExitCode(0);
    }

    public function test_terminal_admission_stop_escapes_managed_lease_catcher(): void
    {
        $this->mockPendingDocuments(2);
        config(['cache.default' => 'array']);

        $stop = new InferenceAdmissionException('synthetic terminal admission stop');
        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->once()->andThrow($stop);
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));
        $lease = Mockery::mock(KnowledgeGraphControllerBatchLeaseService::class);
        $lease->shouldReceive('run')->once()->andReturnUsing(function (int $size, callable $executor) {
            $this->assertSame(2, $size);
            $executor(0, []);
        });
        $this->app->instance(KnowledgeGraphControllerBatchLeaseService::class, $lease);

        try {
            $this->artisan('rag:build-knowledge-graph', [
                '--limit' => 2,
                '--sleep' => 0,
                '--budget-minutes' => 1,
                '--instance' => 'managed-secondary',
            ]);
            $this->fail('The terminal admission stop should escape the managed batch command.');
        } catch (\Throwable $caught) {
            $this->assertSame($stop, $caught);
        }
    }

    public function test_omitted_instance_uses_registered_custom_default_alias(): void
    {
        $this->mockRouteBinding(['default_alias' => 'remote_lane', 'aliases' => ['remote_lane' => ['registered_remote']]]);
        $this->mockPendingDocuments(1);
        config(['cache.default' => 'array']);
        DB::shouldReceive('update')->once()->andReturn(1);
        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->once()->withArgs(static fn (string $text, array $options): bool => $options['preferred_instance'] === 'remote_lane' && $options['managed_model_host'] === false)
            ->andReturn(['success' => true, 'entities' => [], 'relationships' => []]);
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        $this->artisan('rag:build-knowledge-graph', ['--limit' => 1, '--sleep' => 0, '--budget-minutes' => 1])
            ->assertExitCode(0);
    }

    public function test_explicit_registered_alias_keeps_caller_choice_over_table_default(): void
    {
        $this->mockRouteBinding(['default_alias' => 'remote_lane', 'aliases' => ['remote_lane' => ['registered_remote'], 'primary' => ['registered_original']]]);
        $this->mockPendingDocuments(1);
        config(['cache.default' => 'array']);
        DB::shouldReceive('update')->once()->andReturn(1);
        $kg = Mockery::mock(KnowledgeGraphService::class);
        $kg->shouldReceive('extractEntities')->once()->withArgs(static fn (string $text, array $options): bool => $options['preferred_instance'] === 'primary')
            ->andReturn(['success' => true, 'entities' => [], 'relationships' => []]);
        $this->app->instance(KnowledgeGraphService::class, $kg);
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));

        $this->artisan('rag:build-knowledge-graph', ['--instance' => 'primary', '--limit' => 1, '--sleep' => 0, '--budget-minutes' => 1])
            ->assertExitCode(0);
    }

    public function test_missing_default_alias_does_not_invent_primary_route(): void
    {
        $this->mockRouteBinding(['aliases' => ['primary' => ['registered_original']]]);
        $this->app->instance(KnowledgeGraphService::class, Mockery::mock(KnowledgeGraphService::class));
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));
        DB::shouldReceive('connection')->never();

        $this->artisan('rag:build-knowledge-graph', ['--budget-minutes' => 1])
            ->expectsOutputToContain('route alias is unavailable')->assertExitCode(1);
    }

    public function test_unregistered_explicit_alias_is_rejected_before_processing(): void
    {
        $this->mockRouteBinding(['default_alias' => 'remote_lane', 'aliases' => ['remote_lane' => ['registered_remote']]]);
        $this->app->instance(KnowledgeGraphService::class, Mockery::mock(KnowledgeGraphService::class));
        $this->app->instance(HyperGraphService::class, Mockery::mock(HyperGraphService::class));
        DB::shouldReceive('connection')->never();

        $this->artisan('rag:build-knowledge-graph', ['--instance' => 'primary', '--budget-minutes' => 1])
            ->expectsOutputToContain('route alias is unavailable')->assertExitCode(1);
    }

    private function mockRouteBinding(array $binding): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $default);
        $config->shouldReceive('llmRoutingBinding')->once()->with('knowledge_graph')->andReturn($binding);
        $this->app->instance(SystemConfigService::class, $config);
    }

    private function mockPendingDocuments(int $count): void
    {
        $documents = array_map(static fn (int $id): object => (object) [
            'id' => $id,
            'title' => "Document {$id}",
            'content' => str_repeat('K', 200),
            'document_type' => 'file_catalog',
            'content_hash' => "hash-{$id}",
            'kg_content_hash' => null,
            'kg_extracted_at' => null,
            'content_length' => 200,
        ], range(1, $count));

        DB::shouldReceive('selectOne')->andReturnUsing(static function (string $sql) use ($count): ?object {
            if (str_contains($sql, 'AVG(duration_seconds / items_processed)')) {
                return (object) ['avg_seconds' => null, 'sample_count' => 0];
            }
            if (str_contains($sql, 'COUNT(*) as cnt')) {
                return (object) ['cnt' => $count];
            }

            return null;
        });
        DB::shouldReceive('connection')->with('pgsql_rag')->andReturnSelf();
        DB::shouldReceive('select')->once()->withArgs(function (string $sql): bool {
            return str_contains($sql, 'FROM rag_documents') && str_contains($sql, 'LIMIT ?');
        })->andReturn($documents);
        DB::shouldReceive('select')->byDefault()->andReturn([]);
    }
}
