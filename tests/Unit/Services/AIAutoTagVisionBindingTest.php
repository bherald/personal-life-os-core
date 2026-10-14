<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AIAutoTagService;
use App\Services\AIService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

class AIAutoTagVisionBindingTest extends TestCase
{
    private string $previousConnection;

    private string $image;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.auto_tag_vision_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('auto_tag_vision_test');
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('system_configs', function (Blueprint $table): void {
            $table->id();
            foreach (['section', 'config_key', 'config_value', 'data_type', 'description'] as $field) {
                $table->text($field);
            }
            $table->unique(['section', 'config_key']);
            $table->timestamps();
        });
        $schema->create('llm_instances', function (Blueprint $table): void {
            $table->id();
            foreach (['instance_id', 'instance_type', 'routability', 'supported_models', 'config'] as $field) {
                $table->text($field);
            }
            $table->boolean('is_active');
            $table->boolean('is_healthy');
        });
        DB::table('llm_instances')->insert(['instance_id' => 'fixture-vision', 'instance_type' => 'ollama',
            'routability' => 'allowed', 'is_active' => true, 'is_healthy' => true, 'supported_models' => '["fixture-image-model"]',
            'config' => json_encode(['models' => ['vision' => 'fixture-image-model'],
                'route_policy' => ['require_explicit_instance' => true, 'require_model_role' => true,
                    'allowed_model_roles' => ['vision'], 'allow_generic_fallback' => false]])]);
        $this->image = tempnam(sys_get_temp_dir(), 'auto-tag-vision-fixture-');
        file_put_contents($this->image, 'Synthetic complete image bytes');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        unlink($this->image);
        DB::setDefaultConnection($this->previousConnection);
        DB::purge('auto_tag_vision_test');
        parent::tearDown();
    }

    public function test_migration_keeps_route_unconfigured_and_preserves_operator_binding(): void
    {
        $migration = require database_path('migrations/2026_10_14_140000_register_ai_auto_tag_exact_vision_binding.php');
        $migration->up();
        $this->assertSame('', DB::table('system_configs')->value('config_value'));
        $this->bind('fixture-vision::fixture-image-model');
        $migration->up();
        $migration->down();
        $this->assertSame('fixture-vision::fixture-image-model', DB::table('system_configs')->value('config_value'));
    }

    public function test_exact_registered_task_binding_retains_private_local_and_no_alternate_constraints(): void
    {
        $this->bind('fixture-vision::fixture-image-model');
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('processImage')->once()->with('Synthetic complete image bytes', Mockery::type('string'),
            Mockery::on(fn ($config) => $config['model'] === 'fixture-image-model'
                && $config['model_override'] === 'fixture-image-model' && $config['model_role'] === 'vision'
                && $config['allowed_ollama_instance_ids'] === ['fixture-vision']
                && $config['preferred_ollama_instance_ids'] === ['fixture-vision']
                && $config['ollama_required_no_fallback'] === true && $config['disable_cascade'] === true
                && $config['local_only'] === true && $config['sensitive_data'] === true && $config['disable_external_fallback'] === true))
            ->andReturn(['success' => true, 'response' => '{"description":"Synthetic image","tags":[]}', 'provider' => 'fixture-vision']);
        $this->assertTrue($this->service($ai)->analyzeImage($this->image)['success']);
    }

    public static function heldBindings(): array
    {
        return ['missing' => [null, []], 'empty' => ['', []], 'auto' => ['auto', []], 'malformed' => ['invalid', []],
            'inactive' => ['fixture-vision::fixture-image-model', ['is_active' => false]],
            'unhealthy' => ['fixture-vision::fixture-image-model', ['is_healthy' => false]],
            'blocked' => ['fixture-vision::fixture-image-model', ['routability' => 'blocked']],
            'wrong model' => ['fixture-vision::other-model', []],
            'external route' => ['fixture-vision::fixture-image-model', ['instance_type' => 'openai_compatible']],
            'wrong role' => ['fixture-vision::fixture-image-model', ['config' => '{"models":{"standard":"fixture-image-model"},"route_policy":{"require_model_role":true,"allowed_model_roles":["standard"],"allow_generic_fallback":false}}']]];
    }

    #[DataProvider('heldBindings')]
    public function test_unconfigured_or_unadmitted_binding_holds_before_any_model_call(?string $selection, array $changes): void
    {
        if ($selection !== null) {
            $this->bind($selection);
        }
        if ($changes !== []) {
            DB::table('llm_instances')->where('instance_id', 'fixture-vision')->update($changes);
        }
        $ai = Mockery::mock(AIService::class);
        $ai->shouldNotReceive('processImage');
        $this->expectException(InferenceAdmissionException::class);
        $this->service($ai)->analyzeImage($this->image);
    }

    private function bind(string $selection): void
    {
        DB::table('system_configs')->updateOrInsert(['section' => 'files', 'config_key' => 'ai_auto_tag_vision_model_selection'],
            ['config_value' => $selection, 'data_type' => 'string', 'description' => 'Synthetic task binding']);
    }

    private function service(AIService $ai): AIAutoTagService
    {
        $service = (new ReflectionClass(AIAutoTagService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(AIAutoTagService::class, 'aiService'))->setValue($service, $ai);

        return $service;
    }
}
