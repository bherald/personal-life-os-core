<?php

namespace Tests\Feature\Scheduler;

use App\Console\Commands\FileEnrichmentCommand;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ScheduledJobService;
use App\Services\UnifiedCli\CliOllamaRuntimePolicy;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Extends only the per-test SQLite fixture; runs no shared DB or inference. */
class ScheduledTaskBindingCheckpointTest extends ScheduledUnknownPhysicalClosureTest
{
    private ScheduledJobService $bindingService;

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE system_configs ADD COLUMN data_type TEXT');
        DB::statement('ALTER TABLE system_configs ADD COLUMN description TEXT');
        DB::statement('ALTER TABLE system_configs ADD COLUMN created_at TEXT');
        DB::statement('CREATE UNIQUE INDEX fixture_config_identity ON system_configs(section, config_key)');
        DB::statement('CREATE TABLE llm_instances (id INTEGER PRIMARY KEY, instance_id TEXT, instance_type TEXT, base_url TEXT, is_active INTEGER, is_healthy INTEGER, routability TEXT, supported_models TEXT, config TEXT, total_requests INTEGER, last_health_check TEXT)');
        DB::statement('CREATE TABLE ollama_models (id INTEGER PRIMARY KEY, instance_id INTEGER, model_name TEXT, capabilities TEXT, context_length INTEGER)');
        $container = Container::getInstance();
        $container->instance('db.schema', DB::connection()->getSchemaBuilder());
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_14_163000_register_scheduled_task_binding_checkpoint_policy.php';
        $migration->up();
        $migration->up();
        DB::table('scheduled_jobs')->where('id', 7)->update(['command' => 'files:enrich --type=ai --limit=1']);
        $config = ['models' => ['vision' => 'fixture-model'],
            'route_policy' => ['require_explicit_instance' => true, 'require_model_role' => true,
                'allow_generic_fallback' => false, 'allowed_model_roles' => ['vision']],
            'role_runtime' => ['vision' => ['options' => ['num_ctx' => 2048, 'num_predict' => 32],
                'input_modalities' => ['text', 'image'], 'native_tools_enabled' => false]],
            'controller_catalog' => ['targets' => ['fixture-target' => ['target_id' => 'fixture-target',
                'model' => 'fixture-model', 'engine' => 'ollama', 'provider_instance_id' => 'fixture-instance',
                'port' => 11435, 'controller_host_id' => 'fixture-host']]]];
        $provider = ['id' => 1, 'instance_id' => 'fixture-instance', 'instance_type' => 'ollama',
            'base_url' => 'http://127.0.0.1:11435', 'is_active' => 1, 'is_healthy' => 1,
            'routability' => 'allowed', 'supported_models' => '["fixture-model"]', 'config' => json_encode($config),
            'total_requests' => 0, 'last_health_check' => '2020-01-01 00:00:00'];
        DB::table('llm_instances')->insert($provider);
        DB::table('ollama_models')->insert(['id' => 1, 'instance_id' => 1, 'model_name' => 'fixture-model',
            'capabilities' => '["vision"]', 'context_length' => 2048]);
        $runtime = ['max_iterations' => 2, 'max_tool_calls' => 1, 'max_output_tokens' => 32];
        $contract = (new CliOllamaRuntimePolicy)->resolve(['configuration_eligible' => true, 'instance' => $provider,
            'instance_id' => 'fixture-instance', 'model' => 'fixture-model', 'role' => 'vision', 'native_tools_configured' => false,
            'model_definition' => ['capabilities' => ['vision'], 'context_length' => 2048]], ['cli.runtime_policy' => $runtime]);
        foreach ([['files', 'ai_auto_tag_vision_model_selection', 'fixture-instance::fixture-model', 'string'],
            ['cli', 'runtime_policy', json_encode($runtime), 'json'],
            ['ai', 'model_host_controller_resource_policy', json_encode(['profiles' => ['fixture-profile' => [
                'qualified' => true, 'request_contracts' => [ModelHostControllerResourcePolicyService::requestContractIdentity($contract)],
            ]]]), 'json']] as [$section, $key, $value, $type]) {
            DB::table('system_configs')->insert(['section' => $section, 'config_key' => $key, 'config_value' => $value, 'data_type' => $type]);
        }
        $this->bindingService = new ScheduledJobService;
    }

    public function test_trusted_empty_scope_checkpoint_allows_fresh_work_and_preserves_original_unknown(): void
    {
        // This inherited case tests the original review command, not the vision fixture.
        DB::table('scheduled_jobs')->where('id', 7)->update(['command' => 'genealogy:weak-source-add-cleanup --tree=4 --execute --confirm']);
        parent::test_trusted_empty_scope_checkpoint_allows_fresh_work_and_preserves_original_unknown();
    }

    private function parentHelper(string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod(ScheduledUnknownPhysicalClosureTest::class, $method))->invokeArgs($this, $args);
    }

    private function publishSyntheticCheckpoint(): void
    {
        $this->parentHelper('persistPhysicalProof');
        $preview = $this->bindingService->reconcilePhysicallyClosedRun(99);
        $this->bindingService->reconcilePhysicallyClosedRun(99, $preview['snapshot_sha256'], false);
        $job = DB::table('scheduled_jobs')->where('id', 7)->first();
        $snapshot = (new \ReflectionMethod($this->bindingService, 'registeredTaskBindingSnapshot'))->invoke($this->bindingService, $job, 'file_ai_analysis');
        $this->assertIsArray($snapshot);
        $this->parentHelper('persistSpecialCheckpoint', ['plos.scheduled_task_binding_checkpoint.v1',
            'current_registered_native_task_binding', FileEnrichmentCommand::class,
            ['binding_id' => 'file_ai_analysis', 'binding_snapshot_sha256' => $this->parentHelper('hash', [$snapshot]),
                'required_classes' => $snapshot['binding']['required_source_classes']]]);
    }

    public function test_task_binding_current_native_route_admits_fresh_work_without_changing_unknown(): void
    {
        $this->publishSyntheticCheckpoint();
        $before = $this->parentHelper('rows');
        $job = DB::table('scheduled_jobs')->where('id', 7)->first();
        $this->assertTrue((new \ReflectionMethod($this->bindingService, 'qualifiedUnknownNewWork'))->invoke($this->bindingService, $job, 81));
        $this->assertSame($before, $this->parentHelper('rows'));
        $this->assertSame('unknown', $before['run']['status']);
        $this->assertNull($before['custody']['outcome']);
        $this->assertSame(1, DB::table('system_configs')->where('section', 'scheduler')->where('config_key', 'unknown_task_binding_checkpoint_policy')->count());
    }

    public function test_task_binding_health_telemetry_can_advance_but_current_health_is_required(): void
    {
        $this->publishSyntheticCheckpoint();
        DB::table('llm_instances')->where('id', 1)->update(['total_requests' => 40, 'last_health_check' => '2026-10-14 12:00:00']);
        $job = DB::table('scheduled_jobs')->where('id', 7)->first();
        $method = new \ReflectionMethod($this->bindingService, 'qualifiedUnknownNewWork');
        $this->assertTrue($method->invoke($this->bindingService, $job, 81));
        DB::table('llm_instances')->where('id', 1)->update(['is_healthy' => 0]);
        $this->assertFalse($method->invoke($this->bindingService, $job, 81));
    }

    public static function bindingDrift(): array
    {
        return array_map(fn ($kind) => [$kind], ['empty_selection', 'role_mapping', 'resource_qualification', 'command', 'policy', 'source_hash', 'historical_replay']);
    }

    #[DataProvider('bindingDrift')]
    public function test_task_binding_drift_and_replay_remain_held(string $kind): void
    {
        $this->publishSyntheticCheckpoint();
        if ($kind === 'empty_selection') {
            DB::table('system_configs')->where('section', 'files')->update(['config_value' => '']);
        } elseif ($kind === 'role_mapping') {
            $config = json_decode(DB::table('llm_instances')->where('id', 1)->value('config'), true);
            $config['models']['vision'] = 'other-model';
            DB::table('llm_instances')->where('id', 1)->update(['config' => json_encode($config)]);
        } elseif ($kind === 'resource_qualification') {
            $policy = json_decode(DB::table('system_configs')->where('section', 'ai')->value('config_value'), true);
            $policy['profiles']['fixture-profile']['qualified'] = false;
            DB::table('system_configs')->where('section', 'ai')->update(['config_value' => json_encode($policy)]);
        } elseif ($kind === 'command') {
            DB::table('scheduled_jobs')->where('id', 7)->update(['command' => 'files:enrich --type=ai --limit=2']);
        } elseif ($kind === 'policy') {
            $policy = json_decode(DB::table('system_configs')->where('section', 'scheduler')->value('config_value'), true);
            $policy['enabled'] = false;
            DB::table('system_configs')->where('section', 'scheduler')->update(['config_value' => json_encode($policy)]);
        } else {
            $envelope = json_decode(DB::table('task_custody_records')->where('id', 99)->value('result_envelope'), true);
            if ($kind === 'source_hash') {
                $envelope['new_work_checkpoint']['source_classes_sha256'][ScheduledJobService::class] = str_repeat('0', 64);
            } else {
                $envelope['new_work_checkpoint']['historical_replay_authorized'] = true;
            }
            DB::table('task_custody_records')->where('id', 99)->update(['result_envelope' => json_encode($envelope)]);
        }
        $before = $this->parentHelper('rows');
        $this->assertFalse((new \ReflectionMethod($this->bindingService, 'qualifiedUnknownNewWork'))->invoke($this->bindingService, DB::table('scheduled_jobs')->where('id', 7)->first(), 81));
        $this->assertSame($before, $this->parentHelper('rows'));
    }
}
