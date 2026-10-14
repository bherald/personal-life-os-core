<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Exceptions\ModelHostControllerUnavailableException;
use App\Services\AIService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use App\Services\OfflineMediaAnalysisService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OfflineMediaAnalysisServiceTest extends TestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->byDefault()->andReturnUsing(
            fn (string $wire, string $endpoint, array $payload, callable $operation) => $operation()
        );
        $this->app->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('assertUnmanagedTransportAllowed')->byDefault()->andReturnNull();
        $this->app->instance(ModelHostControllerResourcePolicyService::class, $resources);

        $this->imagePath = (string) tempnam(sys_get_temp_dir(), 'plos-media-');
        file_put_contents($this->imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        config([
            'database.default' => 'media_registered_fixture',
            'database.connections.media_registered_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('media_registered_fixture');
        Schema::create('llm_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('instance_id');
            $table->string('instance_type');
            $table->string('base_url');
            $table->boolean('is_active');
            $table->string('routability');
            $table->boolean('is_healthy');
            $table->integer('priority');
            $table->text('config')->nullable();
        });
        Schema::create('ollama_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->string('model_name');
            $table->boolean('is_available');
        });
        $provider = DB::table('llm_instances')->insertGetId([
            'instance_id' => 'registered-vision-fixture', 'instance_type' => 'ollama',
            'base_url' => 'http://ollama.test:11434', 'is_active' => true, 'routability' => 'allowed',
            'is_healthy' => true, 'priority' => 1,
            'config' => json_encode(['models' => ['vision' => 'ministral-3:3b']]),
        ]);
        foreach (['qwen3.5:9b', 'ministral-3:3b'] as $model) {
            DB::table('ollama_models')->insert(['instance_id' => $provider, 'model_name' => $model, 'is_available' => true]);
        }
        putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL=http://ollama.test:11434');
        putenv('PLOS_MEDIA_OLLAMA_URL');
        putenv('OLLAMA_API_URL');
    }

    protected function tearDown(): void
    {
        DB::purge('media_registered_fixture');
        @unlink($this->imagePath);
        putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL');
        putenv('PLOS_MEDIA_OLLAMA_URL');
        putenv('OLLAMA_API_URL');

        parent::tearDown();
    }

    public function test_inspection_is_local_and_returns_content_safe_metadata(): void
    {
        Http::fake();

        $result = (new OfflineMediaAnalysisService)->inspectImage($this->imagePath);

        $this->assertSame('image/png', $result['mime_type']);
        $this->assertSame(1, $result['width']);
        $this->assertSame(1, $result['height']);
        $this->assertSame(64, strlen($result['sha256']));
        $this->assertStringNotContainsString($this->imagePath, json_encode($result));
        Http::assertNothingSent();
    }

    public function test_omitted_model_uses_fresh_registered_vision_role_without_http(): void
    {
        Http::fake();
        $service = new OfflineMediaAnalysisService;
        $this->assertSame(['model' => 'ministral-3:3b', 'instance_id' => 'registered-vision-fixture'], $service->resolveRoute());
        DB::table('llm_instances')->update(['config' => json_encode(['models' => ['vision' => 'qwen3.5:9b']])]);
        $this->assertSame(['model' => 'qwen3.5:9b', 'instance_id' => 'registered-vision-fixture'], $service->resolveRoute());
        $this->assertSame(['model' => 'explicit-vision:1b', 'instance_id' => null], $service->resolveRoute('explicit-vision:1b'));
        Http::assertNothingSent();
    }

    public function test_omitted_model_refuses_disabled_missing_or_role_denied_registration(): void
    {
        Http::fake();
        foreach ([
            ['is_active' => false],
            ['is_active' => true, 'is_healthy' => false],
            ['is_healthy' => true, 'routability' => 'blocked'],
            ['routability' => 'allowed', 'config' => json_encode(['models' => ['vision' => 'not-installed:1b']])],
            ['config' => json_encode(['models' => ['vision' => 'ministral-3:3b'], 'route_policy' => ['allowed_model_roles' => ['standard']]])],
            ['config' => '{}'],
        ] as $change) {
            DB::table('llm_instances')->update($change);
            try {
                (new OfflineMediaAnalysisService)->resolveRoute();
                $this->fail('Expected missing eligible vision role refusal.');
            } catch (\RuntimeException $error) {
                $this->assertSame('No eligible registered vision model is configured.', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_omitted_model_refuses_unavailable_inventory_without_environment_fallback(): void
    {
        Http::fake();
        Schema::drop('llm_instances');
        try {
            (new OfflineMediaAnalysisService)->resolveRoute();
            $this->fail('Expected unavailable vision inventory refusal.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Registered vision model inventory is unavailable.', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_selected_default_route_refuses_fresh_mapping_or_eligibility_drift_without_fallback(): void
    {
        Http::fake();
        $service = new OfflineMediaAnalysisService;
        $route = $service->resolveRoute();
        $alternate = DB::table('llm_instances')->insertGetId([
            'instance_id' => 'healthy-alternate', 'instance_type' => 'ollama',
            'base_url' => 'http://alternate-vision.test:11434', 'is_active' => true,
            'routability' => 'allowed', 'is_healthy' => true, 'priority' => 2,
            'config' => json_encode(['models' => ['vision' => $route['model']]]),
        ]);
        DB::table('ollama_models')->insert(['instance_id' => $alternate, 'model_name' => $route['model'], 'is_available' => true]);
        foreach ([
            ['is_active' => false],
            ['is_healthy' => false],
            ['routability' => 'blocked'],
            ['config' => json_encode(['models' => ['vision' => 'qwen3.5:9b']])],
            ['config' => json_encode(['models' => ['vision' => $route['model']], 'route_policy' => ['allowed_model_roles' => ['standard']]])],
        ] as $change) {
            DB::table('llm_instances')->where('instance_id', $route['instance_id'])->update([
                'is_active' => true, 'is_healthy' => true, 'routability' => 'allowed',
                'config' => json_encode(['models' => ['vision' => $route['model']]]),
                ...$change,
            ]);
            try {
                $service->analyze($this->imagePath, 'Full original evidence.', $route['model'], 30, $route['instance_id']);
                $this->fail('Expected selected route drift refusal.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('No registered local Ollama model endpoint is admitted.', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_probe_checks_selected_installed_model_without_sending_image(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'qwen3.5:9b'],
                    ['name' => 'ministral-3:3b'],
                ],
            ]),
        ]);

        $result = (new OfflineMediaAnalysisService)->probe('qwen3.5:9b');

        $this->assertTrue($result['reachable']);
        $this->assertTrue($result['model_installed']);
        $this->assertSame(2, $result['installed_model_count']);
        $this->assertSame('llm_instances:registered-vision-fixture', $result['endpoint_source']);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'http://ollama.test:11434/api/tags'
            && ! str_contains($request->body(), 'images'));
    }

    public function test_execute_uses_native_vision_chat_contract_and_omits_base64_from_result(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/chat' => Http::response([
                'message' => ['role' => 'assistant', 'content' => 'A one-pixel test image.'],
                'done' => true,
                'done_reason' => 'stop',
                'total_duration' => 123,
                'load_duration' => 20,
                'prompt_eval_count' => 9,
                'prompt_eval_duration' => 30,
                'eval_count' => 6,
                'eval_duration' => 70,
            ]),
        ]);

        $result = (new OfflineMediaAnalysisService)->analyze(
            $this->imagePath,
            'What is shown?',
            'qwen3.5:9b',
        );

        $this->assertSame('A one-pixel test image.', $result['analysis']);
        $this->assertSame(6, $result['metrics']['eval_count']);
        $this->assertStringNotContainsString(base64_encode((string) file_get_contents($this->imagePath)), json_encode($result));
        $this->assertStringNotContainsString($this->imagePath, json_encode($result));
        Http::assertSent(function ($request): bool {
            $messages = $request['messages'];

            return $request->url() === 'http://ollama.test:11434/api/chat'
                && $request['model'] === 'qwen3.5:9b'
                && $request['stream'] === false
                && $request['think'] === false
                && $messages[0]['role'] === 'user'
                && $messages[0]['content'] === 'What is shown?'
                && base64_decode($messages[0]['images'][0], true) === file_get_contents($this->imagePath);
        });
    }

    public function test_registered_model_endpoint_wins_over_generic_ollama_fallback(): void
    {
        $originalDefault = config('database.default');
        putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL');
        putenv('OLLAMA_API_URL=http://generic-ollama.test:11434');

        config([
            'database.default' => 'offline_media_test',
            'database.connections.offline_media_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('offline_media_test');

        try {
            Schema::create('llm_instances', function (Blueprint $table): void {
                $table->id();
                $table->string('instance_id');
                $table->string('instance_type');
                $table->string('base_url');
                $table->boolean('is_active');
                $table->string('routability');
                $table->boolean('is_healthy');
                $table->integer('priority');
                $table->text('config')->default('{}');
            });
            Schema::create('ollama_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('instance_id');
                $table->string('model_name');
                $table->boolean('is_available');
            });

            $blockedId = DB::table('llm_instances')->insertGetId([
                'instance_id' => 'blocked_ollama',
                'instance_type' => 'ollama',
                'base_url' => 'http://blocked-ollama.test:11434',
                'is_active' => 1,
                'routability' => 'blocked',
                'is_healthy' => 1,
                'priority' => 1,
            ]);
            DB::table('ollama_models')->insert([
                'instance_id' => $blockedId,
                'model_name' => 'qwen3.5:9b',
                'is_available' => 1,
            ]);
            $instanceId = DB::table('llm_instances')->insertGetId([
                'instance_id' => 'ollama_secondary',
                'instance_type' => 'ollama',
                'base_url' => 'http://registered-ollama.test:11434',
                'is_active' => 1,
                'routability' => 'allowed',
                'is_healthy' => 1,
                'priority' => 20,
            ]);
            DB::table('ollama_models')->insert([
                'instance_id' => $instanceId,
                'model_name' => 'qwen3.5:9b',
                'is_available' => 1,
            ]);

            Http::fake([
                'http://registered-ollama.test:11434/api/tags' => Http::response([
                    'models' => [['name' => 'qwen3.5:9b']],
                ]),
            ]);

            $result = (new OfflineMediaAnalysisService)->probe('qwen3.5:9b');

            $this->assertSame('llm_instances:ollama_secondary', $result['endpoint_source']);
            Http::assertSent(fn ($request): bool => $request->url() === 'http://registered-ollama.test:11434/api/tags');
        } finally {
            DB::disconnect('offline_media_test');
            DB::purge('offline_media_test');
            config(['database.default' => $originalDefault]);
            putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL=http://ollama.test:11434');
            putenv('OLLAMA_API_URL');
        }
    }

    public function test_missing_registered_model_never_uses_environment_endpoint(): void
    {
        DB::table('llm_instances')->update(['is_active' => false]);
        Http::fake();
        try {
            (new OfflineMediaAnalysisService)->probe('qwen3.5:9b');
            $this->fail('Expected missing registered route refusal.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('registered', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_controller_refusal_preserves_type_and_sends_no_image(): void
    {
        Http::fake();
        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->once()->andThrow(new ModelHostControllerUnavailableException('host_busy'));
        $this->app->instance(ModelHostControllerTransportSupervisor::class, $supervisor);

        try {
            (new OfflineMediaAnalysisService)->analyze($this->imagePath, 'Analyze.', 'qwen3.5:9b');
            $this->fail('Expected controller refusal.');
        } catch (ModelHostControllerUnavailableException $error) {
            $this->assertSame('host_busy', $error->reason);
            $this->assertFalse($error->dispatched);
        }
        Http::assertNothingSent();
    }

    public function test_supervised_vision_validates_transport_before_sending_original_payload(): void
    {
        Http::fake(['*' => Http::response(['message' => ['content' => 'Observed.'], 'done' => true])]);
        $validated = false;
        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->once()->andReturnUsing(
            function (string $wire, string $endpoint, array $payload, callable $operation) use (&$validated) {
                $this->assertSame('ollama_chat', $wire);
                $this->assertSame('qwen3.5:9b', $payload['model']);
                $this->assertSame('Keep original evidence.', $payload['messages'][0]['content']);
                $this->assertSame(file_get_contents($this->imagePath), base64_decode($payload['messages'][0]['images'][0], true));

                return AIService::withInferenceTransportValidator($operation,
                    function (string $actualWire, string $actualEndpoint, array $settings) use ($wire, $endpoint, $payload, &$validated): void {
                        Http::assertNothingSent();
                        $this->assertSame($wire, $actualWire);
                        $this->assertSame($endpoint, $actualEndpoint);
                        $this->assertSame(array_diff_key($payload, ['messages' => true]), $settings);
                        $validated = true;
                    });
            }
        );
        $this->app->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $result = (new OfflineMediaAnalysisService)->analyze($this->imagePath, 'Keep original evidence.', 'qwen3.5:9b');
        $this->assertTrue($validated);
        $this->assertSame('Observed.', $result['analysis']);
        Http::assertSentCount(1);
    }

    public function test_managed_response_failures_cannot_leave_custody_as_successful(): void
    {
        foreach ([
            [500, ['error' => 'upstream failure']],
            [200, ['message' => ['content' => 'Partial.'], 'done' => false]],
            [200, ['done' => true]],
        ] as [$status, $body]) {
            Http::fake(['*' => Http::response($body, $status)]);
            $completed = false;
            $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
            $supervisor->shouldReceive('run')->once()->andReturnUsing(
                function (string $wire, string $endpoint, array $payload, callable $operation) use (&$completed) {
                    $result = AIService::withInferenceProgressKeeper(
                        fn () => AIService::withInferenceTransportValidator($operation, static fn () => null),
                        static fn () => null
                    );
                    $completed = true;

                    return $result;
                }
            );
            $this->app->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
            try {
                (new OfflineMediaAnalysisService)->analyze($this->imagePath, 'Analyze.', 'qwen3.5:9b');
                $this->fail('Expected incomplete managed vision rejection.');
            } catch (InferenceAdmissionException $error) {
                $this->assertStringContainsString('no replay', $error->getMessage());
            }
            $this->assertFalse($completed);
        }
    }

    public function test_invalid_mime_is_rejected_before_any_http_request(): void
    {
        file_put_contents($this->imagePath, 'not really an image');
        Http::fake();

        try {
            (new OfflineMediaAnalysisService)->analyze($this->imagePath, 'Analyze.', 'qwen3.5:9b');
            $this->fail('Expected invalid image rejection.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('type is not allowed', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_upstream_error_does_not_expose_response_body_or_endpoint(): void
    {
        Http::fake([
            '*' => Http::response(['error' => 'secret-upstream-token'], 500),
        ]);

        try {
            (new OfflineMediaAnalysisService)->analyze($this->imagePath, 'Analyze.', 'qwen3.5:9b');
            $this->fail('Expected upstream failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Local Ollama inference returned HTTP 500.', $exception->getMessage());
            $this->assertStringNotContainsString('secret-upstream-token', $exception->getMessage());
            $this->assertStringNotContainsString('ollama.test', $exception->getMessage());
        }
    }
}
