<?php

namespace Tests\Unit\Nodes\YouTube;

use App\Engine\AIRouter;
use App\Exceptions\AI\InferenceAdmissionException;
use App\Nodes\YouTube\YouTubeKeyPointsPostProcessor;
use App\Services\AIService;
use App\Services\CircuitBreaker;
use App\Services\JoplinYouTubeOrganizer;
use App\Services\LLMPoolManagerService;
use App\Services\LlmRoutePolicyService;
use App\Services\ModelHostControllerAutomaticSelectionService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\ModelHostControllerWaitService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliWorkflowModelSelection;
use App\Services\YouTubeJoplinDeliveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\CreatesYouTubeSourceCustodySchema;
use Tests\TestCase;

class YouTubeKeyPointsPostProcessorRuntimePolicyTest extends TestCase
{
    use CreatesYouTubeSourceCustodySchema;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'runtime_policy_test', 'database.connections.runtime_policy_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('runtime_policy_test');
        $this->createSourceCustodySchema();
        $this->createKeyPointTranscriptSchema([['id' => 7, 'video_id' => 'abcdefghijk',
            'content' => 'This transcript contains enough detail for the key points extractor to accept it and generate useful bullets for the note.']]);
    }

    protected function tearDown(): void
    {
        DB::purge('runtime_policy_test');
        Mockery::close();

        parent::tearDown();
    }

    public function test_key_points_post_processor_uses_table_policy_defaults_when_config_is_absent(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')
            ->with('youtube.key_points_post_processor_runtime_policy', Mockery::type('array'))
            ->once()
            ->andReturn([
                'execute_default_limit' => 1,
                'direct_process_default_limit' => 20,
                'dry_run_default' => true,
                'file_batch_size' => 2,
                'max_seconds' => 900,
                'title_preview_chars' => 25,
                'transcript_min_chars' => 10,
                'transcript_max_chars' => 200,
                'ai_max_tokens' => 77,
                'ai_temperature' => 0.2,
            ]);
        $this->app->instance(SystemConfigService::class, $config);

        $organizer = $this->organizerMock(2);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(
                fn (array $config): bool => ($config['max_tokens'] ?? null) === 77
                    && ($config['temperature'] ?? null) === 0.2
            ))
            ->andReturn([
                'success' => true,
                'response' => "- First key point.\n- Second key point.\n- Third key point.",
            ]);
        $this->app->instance(AIService::class, $ai);

        $result = (new YouTubeKeyPointsPostProcessor)->execute(['data' => []]);

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['meta']['limit']);
        $this->assertTrue($result['meta']['dry_run']);
        $this->assertSame(900, $result['meta']['max_seconds']);
        $this->assertSame('system_configs.youtube.key_points_post_processor_runtime_policy', $result['meta']['runtime_policy_source']);
        $this->assertSame(1, $result['meta']['notes_updated']);
        $this->assertSame(1, $result['data']['key_points_stats']['found']);
    }

    public function test_key_points_post_processor_keeps_explicit_workflow_overrides(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->never();
        $this->app->instance(SystemConfigService::class, $config);

        $organizer = $this->organizerMock(4);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')
            ->once()
            ->with(Mockery::type('string'), Mockery::on(
                fn (array $config): bool => ($config['max_tokens'] ?? null) === 123
                    && ($config['temperature'] ?? null) === 0.4
            ))
            ->andReturn([
                'success' => true,
                'response' => "- First explicit key point.\n- Second explicit key point.\n- Third explicit key point.",
            ]);
        $this->app->instance(AIService::class, $ai);

        $result = (new YouTubeKeyPointsPostProcessor([
            'limit' => 1,
            'dry_run' => true,
            'file_batch_size' => 4,
            'max_seconds' => 30,
            'title_preview_chars' => 30,
            'transcript_min_chars' => 10,
            'transcript_max_chars' => 300,
            'ai_max_tokens' => 123,
            'ai_temperature' => 0.4,
        ]))->execute(['data' => []]);

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['meta']['limit']);
        $this->assertTrue($result['meta']['dry_run']);
        $this->assertSame(30, $result['meta']['max_seconds']);
        $this->assertSame(1, $result['meta']['notes_updated']);
    }

    public function test_key_points_post_processor_zero_max_seconds_stops_before_ai_work(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->never();
        $this->app->instance(SystemConfigService::class, $config);

        $organizer = $this->organizerMock(3);
        $this->app->instance(JoplinYouTubeOrganizer::class, $organizer);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('process')->never();
        $this->app->instance(AIService::class, $ai);

        $result = (new YouTubeKeyPointsPostProcessor([
            'limit' => 1,
            'dry_run' => true,
            'file_batch_size' => 3,
            'max_seconds' => 0,
            'title_preview_chars' => 30,
            'transcript_min_chars' => 10,
            'transcript_max_chars' => 300,
            'ai_max_tokens' => 123,
            'ai_temperature' => 0.4,
        ]))->execute(['data' => []]);

        $this->assertNull($result['error']);
        $this->assertSame(1, $result['meta']['placeholders_found']);
        $this->assertSame(0, $result['meta']['notes_updated']);
        $this->assertSame(0, $result['meta']['max_seconds']);
    }

    public function test_table_owned_thousand_token_request_reaches_normal_pipeline_and_is_held_by_128_token_contract(): void
    {
        config(['services.ai.use_dynamic_pool' => true, 'services.ai.cache_enabled' => false]);
        $delivery = Mockery::mock(YouTubeJoplinDeliveryService::class);
        $delivery->shouldReceive('heldSourceWrites')->andReturn([]);
        $delivery->shouldReceive('placeholderSource')->once()->andReturn(['video_id' => 'abcdefghijk',
            'note_id' => str_repeat('a', 32), 'source_id' => 7,
            'content' => 'This transcript contains enough detail for the key points extractor to accept it and generate useful bullets for the note.']);
        $this->app->instance(YouTubeJoplinDeliveryService::class, $delivery);
        DB::shouldReceive('purge')->with('runtime_policy_test')->andReturnNull();
        DB::shouldReceive('selectOne')->andReturn(null);
        DB::shouldReceive('select')->andReturn([]);
        Http::preventStrayRequests();
        Http::fake();
        $system = Mockery::mock(SystemConfigService::class);
        $system->shouldReceive('get')->andReturnUsing(fn ($key, $default = null) => match ($key) {
            'routing.offline_mode' => 'enabled',
            'youtube.key_points_post_processor_runtime_policy' => array_replace($default, ['ai_max_tokens' => 1000]),
            default => $default,
        });
        app()->instance(SystemConfigService::class, $system);
        app()->instance(JoplinYouTubeOrganizer::class, $this->organizerMock(2));
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('managedProvider')->andReturn((object) ['instance_id' => 'fixture']);
        app()->instance(ModelHostControllerResourcePolicyService::class, $resources);
        $selection = Mockery::mock(ModelHostControllerAutomaticSelectionService::class);
        $selection->shouldNotReceive('dispatchExact');
        $selection->shouldNotReceive('beginExact');
        $supervisor = new class($selection, $resources, Mockery::mock(ModelHostControllerWaitService::class)) extends ModelHostControllerTransportSupervisor
        {
            public array $observed = [];

            public bool $deniedByOutputEnvelope = false;

            protected function open(string $wire, string $endpoint, array $payload, array $context = []): array
            {
                $this->observed[] = $payload;
                try {
                    self::assertPayload($wire, $payload, ['model' => 'fixture-model', 'max_output_tokens' => 128,
                        'context_window' => 4096, 'native_tools_enabled' => false, 'input_modalities' => ['text'],
                        'ollama_options' => ['num_ctx' => 4096, 'num_predict' => 128]], []);
                } catch (InferenceAdmissionException $error) {
                    $this->deniedByOutputEnvelope = $error->getMessage() === 'Requested output exceeds the admitted capacity envelope.';
                    throw $error;
                }
                throw new \RuntimeException('Unqualified request passed its contract.');
            }
        };
        app()->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $router = Mockery::mock(AIRouter::class);
        $router->shouldNotReceive('processWithAI');
        $pool = Mockery::mock(LLMPoolManagerService::class);
        $pool->shouldReceive('selectInstance')->andReturn(['all_candidates' => [['instance_id' => 'fixture']]]);
        $ai = Mockery::mock(AIService::class, [$router, $pool, Mockery::mock(CircuitBreaker::class)])->makePartial();
        $ai->shouldReceive('getHealthyOllamaInstances')->andReturn([['id' => 'fixture', 'name' => 'fixture',
            'url' => 'http://fixture', 'priority' => 1, 'supported_models' => ['fixture-model'], 'default_model' => 'fixture-model',
            'config' => ['models' => ['standard' => 'fixture-model'],
                'role_runtime' => ['standard' => ['max_tokens' => 128, 'options' => ['num_ctx' => 4096, 'num_predict' => 128]]],
                'route_policy' => ['allowed_model_roles' => ['standard'], 'allow_generic_fallback' => false]]]]);
        app()->instance(AIService::class, $ai);
        try {
            (new YouTubeKeyPointsPostProcessor(['limit' => 1, 'dry_run' => true, 'file_batch_size' => 2,
                'max_seconds' => 30, 'title_preview_chars' => 30, 'transcript_min_chars' => 10, 'transcript_max_chars' => 300]))->execute(['data' => []]);
            $this->fail('The admission stop must reach the workflow.');
        } catch (InferenceAdmissionException $stop) {
            $this->assertTrue(InferenceAdmissionException::isAdmissionFailure($stop));
        }
        $this->assertCount(1, $supervisor->observed);
        $this->assertTrue($supervisor->deniedByOutputEnvelope);
        $this->assertSame(1000, $supervisor->observed[0]['options']['num_predict']);
        Http::assertNothingSent();
    }

    public function test_ordinary_node_forwards_its_configured_role_without_changing_output_budget(): void
    {
        $request = $this->captureOrdinaryNodeRequest(['model_role' => 'fixture_quality']);

        $this->assertSame('fixture_quality', $request['model_role'] ?? null);
        $this->assertSame(1000, $request['max_tokens']);
        $this->assertSame(0.0, $request['temperature']);
    }

    public function test_unconfigured_ordinary_node_keeps_existing_default_selection(): void
    {
        $request = $this->captureOrdinaryNodeRequest();

        $this->assertArrayNotHasKey('model_role', $request);
        $this->assertArrayNotHasKey('model', $request);
        $this->assertSame(1000, $request['max_tokens']);
    }

    public function test_explicit_cli_scope_still_overrides_the_ordinary_node_role_and_restores_it(): void
    {
        $request = AIService::withWorkflowModelRole('fixture_operator',
            fn () => $this->captureOrdinaryNodeRequest(['model_role' => 'fixture_quality']),
            resolveModel: static fn (array $request): array => [
                'model_role' => 'fixture_operator', 'model' => 'fixture-operator-model',
                'auto_select_model' => false,
            ]);

        $this->assertSame('fixture_operator', $request['model_role']);
        $this->assertSame('fixture-operator-model', $request['model']);
        $this->assertFalse($request['auto_select_model']);
        $this->assertSame('fixture_quality', $this->captureOrdinaryNodeRequest(['model_role' => 'fixture_quality'])['model_role'] ?? null);
    }

    public function test_ordinary_node_instance_preferences_reach_the_existing_explicit_pool_policy(): void
    {
        $config = ['models' => ['fixture_quality' => 'fixture-model'], 'route_policy' => [
            'require_explicit_instance' => true, 'require_model_role' => true,
            'allowed_model_roles' => ['fixture_quality'], 'allow_generic_fallback' => false,
        ]];
        $request = $this->captureOrdinaryNodeRequest([
            'model_role' => 'fixture_quality', 'allowed_ollama_instance_ids' => ['fixture-primary'],
            'preferred_ollama_instance_ids' => ['fixture-primary'],
        ]);
        $normalizer = (new \ReflectionClass(AIService::class))->newInstanceWithoutConstructor();
        $normalized = (new \ReflectionMethod(AIService::class, 'applyPreferredOllamaInstanceOrder'))
            ->invoke($normalizer, $request, 'fixture_quality');
        $policy = new LlmRoutePolicyService;
        $this->assertFalse($policy->allowsInstance($config, 'fixture-primary', false, null));
        $this->assertTrue($policy->allowsInstance($config, 'fixture-primary',
            $normalized['_ollama_instance_selection_explicit'], $normalized['preferred_ollama_instance_ids'][0] ?? null));
        $this->assertSame(['fixture-primary'], $normalized['allowed_ollama_instance_ids']);
        $this->assertSame(['fixture-primary'], $normalized['preferred_ollama_instance_ids']);
        $this->assertSame('fixture_quality', $normalized['model_role']);
        $this->assertSame(1000, $normalized['max_tokens']);
        $this->assertArrayNotHasKey('model', $normalized);
    }

    public function test_explicit_cli_full_selection_overrides_stale_node_preferences_and_restores_them(): void
    {
        $this->seedExactNodeSelections();
        DB::table('llm_instances')->where('instance_id', 'fixture-primary')->update(['is_healthy' => 0]);
        $node = ['model_role' => 'fixture_quality', 'allowed_ollama_instance_ids' => ['fixture-primary'],
            'preferred_ollama_instance_ids' => ['fixture-primary']];
        $request = AIService::withWorkflowModelRole('fixture_operator',
            fn () => $this->captureOrdinaryNodeRequest($node),
            resolveModel: static fn (array $request): array => (new CliWorkflowModelSelection)->resolve(
                'fixture-operator::operator-model', 'auto', $request));

        $this->assertSame('fixture_operator', $request['model_role']);
        $this->assertSame('operator-model', $request['model']);
        $this->assertSame(['fixture-operator'], $request['allowed_ollama_instance_ids']);
        $this->assertSame(['fixture-operator'], $request['preferred_ollama_instance_ids']);
        $restored = $this->captureOrdinaryNodeRequest($node);
        $this->assertSame('fixture_quality', $restored['model_role']);
        $this->assertSame(['fixture-primary'], $restored['allowed_ollama_instance_ids']);
        $this->assertSame(['fixture-primary'], $restored['preferred_ollama_instance_ids']);
        $this->assertArrayNotHasKey('model', $restored);
    }

    public function test_role_without_instance_preferences_does_not_relax_explicit_instance_policy(): void
    {
        $request = $this->captureOrdinaryNodeRequest(['model_role' => 'fixture_quality']);
        $this->assertArrayNotHasKey('allowed_ollama_instance_ids', $request);
        $this->assertArrayNotHasKey('preferred_ollama_instance_ids', $request);
        $this->assertFalse((new LlmRoutePolicyService)->allowsInstance(
            ['route_policy' => ['require_explicit_instance' => true]], 'fixture-primary',
            ! empty($request['allowed_ollama_instance_ids']), $request['preferred_ollama_instance_ids'][0] ?? null));
    }

    private function seedExactNodeSelections(): void
    {
        Schema::create('llm_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('instance_id');
            $table->string('instance_type');
            $table->boolean('is_active');
            $table->boolean('is_healthy');
            $table->string('routability');
            $table->text('supported_models');
            $table->text('config');
        });
        foreach ([['fixture-primary', 'fixture_quality', 'fixture-model'], ['fixture-operator', 'fixture_operator', 'operator-model']] as [$instance, $role, $model]) {
            $config = ['models' => [$role => $model], 'route_policy' => [
                'require_explicit_instance' => true, 'require_model_role' => true,
                'allowed_model_roles' => [$role], 'allow_generic_fallback' => false,
            ]];
            DB::table('llm_instances')->insert(['instance_id' => $instance, 'instance_type' => 'ollama',
                'is_active' => 1, 'is_healthy' => 1, 'routability' => 'allowed',
                'supported_models' => json_encode([$model]), 'config' => json_encode($config)]);
        }

    }

    /** Run the ordinary node; stop after the real CLI request-scope transformation, before provider I/O. */
    private function captureOrdinaryNodeRequest(array $overrides = []): array
    {
        $system = Mockery::mock(SystemConfigService::class);
        $system->shouldReceive('get')->never();
        $this->app->instance(SystemConfigService::class, $system);
        $this->app->instance(JoplinYouTubeOrganizer::class, $this->organizerMock(2));
        $ai = new class extends AIService
        {
            public array $captured = [];

            public function __construct() {}

            public function process(string $prompt, array $config = []): array
            {
                $this->captured = (new \ReflectionMethod(AIService::class, 'workflowModelConfig'))->invoke(null, $config);
                $this->captured['captured_prompt'] = $prompt;

                return ['success' => true, 'response' => "- First complete source fact.\n- Second complete source fact.\n- Third complete source fact."];
            }
        };
        $this->app->instance(AIService::class, $ai);
        $result = (new YouTubeKeyPointsPostProcessor(array_replace([
            'limit' => 1, 'dry_run' => true, 'file_batch_size' => 2,
            'max_seconds' => 30, 'title_preview_chars' => 30,
            'transcript_min_chars' => 10, 'transcript_max_chars' => 20,
            'ai_max_tokens' => 1000, 'ai_temperature' => 0.0,
        ], $overrides)))->execute(['data' => []]);
        $this->assertNull($result['error']);
        $this->assertSame(1, $result['meta']['notes_updated']);
        $this->assertStringContainsString('This transcript contains enough detail for the key points extractor to accept it and generate useful bullets for the note.', $ai->captured['captured_prompt']);

        return $ai->captured;
    }

    private function organizerMock(int $expectedBatchSize): JoplinYouTubeOrganizer
    {
        $organizer = Mockery::mock(JoplinYouTubeOrganizer::class);
        $files = ['folder.md', 'note.md'];
        $folderContent = "id: watch-child\ntype_: 2\nparent_id: watch-root\n";
        $noteContent = $this->noteContent();

        $organizer->shouldReceive('getJoplinPath')->andReturn('/joplin/');
        $organizer->shouldReceive('getWatchLaterFolderId')->andReturn('watch-root');
        $organizer->shouldReceive('listDirectory')->with('/joplin/')->andReturn($files);
        $organizer->shouldReceive('getFileContentsBatch')->with($files, $expectedBatchSize)->andReturn([
            'folder.md' => $folderContent,
            'note.md' => $noteContent,
        ]);
        $organizer->shouldReceive('parseNote')->andReturnUsing(function (string $content): array {
            if (str_contains($content, 'type_: 2')) {
                return [
                    'id' => 'watch-child',
                    'type' => 2,
                    'parent_id' => 'watch-root',
                    'title' => 'Folder',
                    'raw' => $content,
                ];
            }

            return [
                'id' => str_repeat('a', 32),
                'type' => 1,
                'parent_id' => 'watch-root',
                'title' => 'A long enough video title for testing',
                'raw' => $content,
                'content' => $content,
            ];
        });
        $organizer->shouldReceive('putFileContent')->never();

        return $organizer;
    }

    private function noteContent(): string
    {
        return <<<'NOTE'
id: note-1
type_: 1
parent_id: watch-root
title: Runtime Policy Video

# Runtime Policy Video

**Video:** https://youtu.be/abcdefghijk

## Key Points

*Key points will be extracted*

---

## Full Transcript

This transcript contains enough detail for the key points extractor to accept it and generate useful bullets for the note.

---
NOTE;
    }
}
