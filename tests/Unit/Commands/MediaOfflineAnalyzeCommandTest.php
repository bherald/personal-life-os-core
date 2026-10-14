<?php

namespace Tests\Unit\Commands;

use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerTransportSupervisor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MediaOfflineAnalyzeCommandTest extends TestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagePath = (string) tempnam(sys_get_temp_dir(), 'plos-media-command-');
        file_put_contents($this->imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL=http://ollama-command.test:11434');
        config([
            'database.default' => 'media_command_fixture',
            'database.connections.media_command_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('media_command_fixture');
        Schema::create('llm_instances', function (Blueprint $table): void {
            $table->id();
            $table->string('instance_id');
            $table->string('instance_type');
            $table->string('base_url');
            $table->boolean('is_active');
            $table->boolean('is_healthy');
            $table->string('routability');
            $table->integer('priority');
            $table->text('config');
        });
        Schema::create('ollama_models', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('instance_id');
            $table->string('model_name');
            $table->boolean('is_available');
        });
        $instance = DB::table('llm_instances')->insertGetId([
            'instance_id' => 'vision-command-fixture', 'instance_type' => 'ollama',
            'base_url' => 'http://ollama-command.test:11434', 'is_active' => true,
            'is_healthy' => true, 'routability' => 'allowed', 'priority' => 1,
            'config' => json_encode(['models' => ['vision' => 'registered-vision:1b']]),
        ]);
        foreach (['registered-vision:1b', 'qwen3.5:9b'] as $model) {
            DB::table('ollama_models')->insert(['instance_id' => $instance, 'model_name' => $model, 'is_available' => true]);
        }
        $supervisor = Mockery::mock(ModelHostControllerTransportSupervisor::class);
        $supervisor->shouldReceive('run')->andReturnUsing(
            fn (string $wire, string $endpoint, array $payload, callable $operation) => $operation()
        );
        $this->app->instance(ModelHostControllerTransportSupervisor::class, $supervisor);
        $resources = Mockery::mock(ModelHostControllerResourcePolicyService::class);
        $resources->shouldReceive('assertUnmanagedTransportAllowed')->andReturnNull();
        $this->app->instance(ModelHostControllerResourcePolicyService::class, $resources);
    }

    protected function tearDown(): void
    {
        @unlink($this->imagePath);
        DB::purge('media_command_fixture');
        putenv('OFFLINE_DEV_LOCAL_OLLAMA_URL');

        parent::tearDown();
    }

    public function test_default_mode_is_content_safe_dry_run_without_http(): void
    {
        Http::fake();

        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertSame('plos.offline_media_analysis.v1', $payload['schema']);
        $this->assertSame('validated', $payload['status']);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertSame('registered-vision:1b', $payload['model']);
        $this->assertArrayNotHasKey('analysis', $payload);
        $this->assertStringNotContainsString($this->imagePath, $output);
        $this->assertStringNotContainsString(base64_encode((string) file_get_contents($this->imagePath)), $output);
        Http::assertNothingSent();
    }

    public function test_execute_returns_analysis_and_safe_metrics(): void
    {
        Http::fake([
            'http://ollama-command.test:11434/api/chat' => Http::response([
                'message' => ['content' => 'Offline description.'],
                'eval_count' => 3,
            ]),
        ]);

        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath,
            '--prompt-b64' => base64_encode('Describe safely.'),
            '--model' => 'qwen3.5:9b',
            '--execute' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(0, $exit);
        $this->assertSame('complete', $payload['status']);
        $this->assertSame('Offline description.', $payload['analysis']);
        $this->assertSame(3, $payload['metrics']['eval_count']);
        $this->assertStringNotContainsString('Describe safely.', $output);
        $this->assertStringNotContainsString($this->imagePath, $output);
    }

    public function test_missing_default_role_fails_without_using_environment_or_sending_image(): void
    {
        Http::fake();
        DB::table('llm_instances')->update(['config' => '{}']);
        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath, '--execute' => true, '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(1, $exit);
        $this->assertSame('No eligible registered vision model is configured.', $payload['error']);
        Http::assertNothingSent();
    }

    public function test_default_model_keeps_its_eligible_instance_when_another_host_installs_the_same_model(): void
    {
        $denied = DB::table('llm_instances')->insertGetId([
            'instance_id' => 'higher-priority-vision-denied', 'instance_type' => 'ollama',
            'base_url' => 'http://denied-vision.test:11434', 'is_active' => true,
            'is_healthy' => true, 'routability' => 'allowed', 'priority' => 0,
            'config' => json_encode(['models' => ['vision' => 'registered-vision:1b'], 'route_policy' => ['allowed_model_roles' => ['standard']]]),
        ]);
        DB::table('ollama_models')->insert(['instance_id' => $denied, 'model_name' => 'registered-vision:1b', 'is_available' => true]);
        Http::fake(['*' => Http::response(['message' => ['content' => 'Complete original image.']])]);
        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath, '--prompt' => 'Keep all image evidence.', '--execute' => true, '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame('registered-vision:1b', $payload['model']);
        $this->assertSame('llm_instances:vision-command-fixture', $payload['endpoint_source']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama-command.test:11434/api/chat'
            && $request['model'] === 'registered-vision:1b'
            && $request['messages'][0]['content'] === 'Keep all image evidence.');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'denied-vision.test'));
    }

    public function test_default_model_cannot_borrow_a_higher_priority_hosts_different_vision_mapping(): void
    {
        $other = DB::table('llm_instances')->insertGetId([
            'instance_id' => 'other-default-vision', 'instance_type' => 'ollama',
            'base_url' => 'http://other-vision.test:11434', 'is_active' => true,
            'is_healthy' => true, 'routability' => 'allowed', 'priority' => 0,
            'config' => json_encode(['models' => ['vision' => 'unavailable-other-default:1b'], 'route_policy' => ['allowed_model_roles' => ['vision']]]),
        ]);
        DB::table('ollama_models')->insert(['instance_id' => $other, 'model_name' => 'registered-vision:1b', 'is_available' => true]);
        Http::fake(['*' => Http::response(['models' => [['name' => 'registered-vision:1b']]])]);
        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath, '--probe' => true, '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame('registered-vision:1b', $payload['model']);
        $this->assertSame('llm_instances:vision-command-fixture', $payload['probe']['endpoint_source']);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://ollama-command.test:11434/api/tags');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'other-vision.test'));
    }

    public function test_conflicting_modes_fail_without_http(): void
    {
        Http::fake();

        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath,
            '--execute' => true,
            '--probe' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status']);
        $this->assertSame('Choose only one of --execute, --probe, or --dry-run.', $payload['error']);
        Http::assertNothingSent();
    }

    public function test_dry_run_rejects_invalid_model_before_http(): void
    {
        Http::fake();

        $exit = Artisan::call('media:offline-analyze', [
            '--path' => $this->imagePath,
            '--model' => 'bad model;token=secret',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('Model name is invalid.', $payload['error']);
        $this->assertStringNotContainsString('secret', $payload['error']);
        Http::assertNothingSent();
    }
}
