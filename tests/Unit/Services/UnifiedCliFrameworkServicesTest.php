<?php

namespace Tests\Unit\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\AgentDSGovernanceService;
use App\Services\AgentGuardrailService;
use App\Services\AgentToolRegistryService;
use App\Services\CodexExecConnectorService;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliFrameworkRemoteAction;
use App\Services\UnifiedCli\CliFrameworkServicePolicy;
use App\Services\UnifiedCli\CliFrameworkServices;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliSystemConfigService;
use App\Services\UnifiedCli\CliToolCatalog;
use App\Services\UnifiedCli\CliToolOutcomeUnknown;
use App\Services\UnifiedCli\CliWindowsActionProcess;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use App\Services\UnifiedCli\RegistrySnapshotValidator;
use Composer\Autoload\ClassLoader;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UnifiedCliFrameworkServicesTest extends TestCase
{
    private string $directory;

    private array $rules = [];

    private array $definition;

    private array $policy;

    private CliStateStore $store;

    private array $session;

    protected function setUp(): void
    {
        parent::setUp();
        DB::shouldReceive('select', 'selectOne', 'insert', 'table', 'connection')->never();
        $this->directory = sys_get_temp_dir().'/cli-framework-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->policy = ['targets' => ['prod' => ['transport' => 'ssh', 'host' => 'operator@10.23.45.10',
            'root' => '/srv/plos-demo/release', 'production' => true], 'local' => ['transport' => 'local']],
            'tools' => ['fixture_echo' => ['target' => 'workflow', 'tree_scoped' => false, 'references' => []]]];
        $this->definition = ['service' => CliFrameworkFixture::class, 'method' => 'echoOriginal',
            'parameters' => ['text' => ['type' => 'string', 'required' => true]], 'permissions' => [],
            'risk_level' => 'read', 'requires_confirmation' => false, 'mcp_server' => null, 'mcp_tool' => null];
        $tables = array_fill_keys(RegistrySnapshotValidator::REQUIRED_TABLES, []);
        $tables['system_configs'] = [
            ['section' => 'routing', 'config_key' => 'active_profile', 'config_value' => 'offline_dev_assist', 'data_type' => 'string'],
            ['section' => 'cli', 'config_key' => 'framework_services', 'config_value' => json_encode($this->policy), 'data_type' => 'json'],
        ];
        $this->store = new CliStateStore(':memory:');
        $generation = $this->store->activate(['schema' => RegistrySnapshotValidator::SCHEMA, 'source' => 'fixture',
            'exported_at' => gmdate('c'), 'tables' => $tables]);
        $this->session = $this->store->createSession(['generation' => $generation,
            'overrides' => ['cli.skill' => 'fixture', 'cli.network' => 'lan', 'cli.tree_id' => 4, 'cli.workflow_target' => 'prod']]);
        $settings = new CliSystemConfigService($this->store, $this->session['id']);
        $this->app->instance(SystemConfigService::class, $settings);
        $this->app->instance(AgentDSGovernanceService::class, new AgentDSGovernanceService($settings));
        $this->app->instance(AgentGuardrailService::class, new AgentGuardrailService(fn () => $this->rules, fn () => null));
        config(['services.guardrails.rules' => [], 'services.guardrails.log_operations' => ['*']]);
        CliFrameworkFixture::$calls = 0;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_camel_case_arguments_do_not_relax_table_or_column_identifiers(): void
    {
        $policy = $this->policy;
        $rule = ['target' => 'workflow', 'tree_scoped' => true, 'tree_argument' => 'treeId',
            'references' => ['personId' => ['table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
        $policy['tools']['fixture_echo'] = $rule;
        CliFrameworkServicePolicy::validate($policy);
        $this->addToAssertionCount(1);
        foreach (['table', 'id_column', 'tree_column'] as $field) {
            $policy['tools']['fixture_echo'] = $rule;
            $policy['tools']['fixture_echo']['references']['personId'][$field] = 'id OR 1=1';
            try {
                CliFrameworkServicePolicy::validate($policy);
                $this->fail('SQL identifier syntax was accepted as a table binding.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('tree reference rule', $error->getMessage());
            }
        }
        foreach (['details..ids', 'details.ids[]', 'details._ids'] as $path) {
            $policy['tools']['fixture_echo'] = $rule;
            $policy['tools']['fixture_echo']['references'] = [$path => $rule['references']['personId']];
            try {
                CliFrameworkServicePolicy::validate($policy);
                $this->fail('Invalid nested reference syntax was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('tree reference rule', $error->getMessage());
            }
        }
        foreach (['tree.id', 'treeId[]', 'treeId;DROP', '_treeId'] as $argument) {
            $policy['tools']['fixture_echo'] = $rule;
            $policy['tools']['fixture_echo']['tree_argument'] = $argument;
            try {
                CliFrameworkServicePolicy::validate($policy);
                $this->fail('Invalid argument identifier was accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('Tree argument', $error->getMessage());
            }
        }
    }

    public function test_worker_uses_existing_dispatcher_and_retains_complete_service_output(): void
    {
        $original = str_repeat("  José\r\n\t  ", 10000).' exact tail ';
        $request = $this->request(['text' => $original]);
        $result = $this->worker()->execute($request);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame($original, $result['result']);
        $this->assertSame($original, $result['result_text']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_workflow_role_reaches_worker_envelope_and_changed_role_invalidates_prepared_context(): void
    {
        $this->session['overrides']['cli.workflow_model_role'] = 'deep_offline';
        $this->session = $this->store->saveSession($this->session);
        $requests = [];
        $runtime = new CliFrameworkServices($this->store, $this->session['id'], base_path(), '/usr/bin/node',
            new CliArtifactStore($this->directory.'/artifacts'), function ($launch) use (&$requests): Process {
                $requests[] = json_decode($launch['input'], true);
                $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                    'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1',
                        'result' => ['success' => true, 'result' => 'complete']]))];

                return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo '.var_export(json_encode($packet), true).';']);
            });
        $context = $runtime->context('fixture_echo', $this->definition);
        $result = $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
        $this->assertTrue($result['success']);
        $this->assertSame('deep_offline', $requests[0]['model_role']);
        $this->assertSame(['text' => 'complete'], $requests[0]['arguments']);
        $this->session['overrides']['cli.workflow_model_role'] = 'quality';
        $this->session = $this->store->saveSession($this->session);
        $this->assertNotSame($context, $runtime->context('fixture_echo', $this->definition));
        try {
            $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
            $this->fail('A prepared action executed after its workflow role changed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('prepared execution target', $error->getMessage());
        }
        $this->assertCount(1, $requests);
    }

    public static function modelSelectionFields(): array
    {
        return [['cli.workflow_model', 'model_selection'], ['cli.workflow_vision_model', 'vision_model_selection'], ['cli.workflow_embedding_model', 'embedding_model_selection']];
    }

    #[DataProvider('modelSelectionFields')]
    public function test_exact_workflow_model_reaches_worker_envelope_and_change_invalidates_prepared_context(string $setting, string $field): void
    {
        $this->session['overrides'][$setting] = 'fixture::exact-model';
        $this->session = $this->store->saveSession($this->session);
        $requests = [];
        $runtime = new CliFrameworkServices($this->store, $this->session['id'], base_path(), '/usr/bin/node',
            new CliArtifactStore($this->directory.'/artifacts'), function ($launch) use (&$requests): Process {
                $requests[] = json_decode($launch['input'], true);
                $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                    'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1',
                        'result' => ['success' => true, 'result' => 'complete']]))];

                return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo '.var_export(json_encode($packet), true).';']);
            });
        $context = $runtime->context('fixture_echo', $this->definition);
        $result = $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
        $this->assertTrue($result['success']);
        $this->assertSame('fixture::exact-model', $requests[0][$field]);
        $this->assertSame('auto', $requests[0]['model_role']);
        $this->assertSame(['text' => 'complete'], $requests[0]['arguments']);
        $this->session['overrides'][$setting] = 'auto';
        $this->session = $this->store->saveSession($this->session);
        $this->assertNotSame($context, $runtime->context('fixture_echo', $this->definition));
        try {
            $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
            $this->fail('A prepared action executed after its supporting model changed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('prepared execution target', $error->getMessage());
        }
        $this->assertCount(1, $requests);
    }

    public function test_compute_selection_reaches_worker_envelope_and_change_invalidates_prepared_context(): void
    {
        $this->session['overrides']['cli.compute_instance'] = 'fixture-compute';
        $this->session = $this->store->saveSession($this->session);
        $requests = [];
        $runtime = new CliFrameworkServices($this->store, $this->session['id'], base_path(), '/usr/bin/node',
            new CliArtifactStore($this->directory.'/artifacts'), function ($launch) use (&$requests): Process {
                $requests[] = json_decode($launch['input'], true);
                $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                    'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1',
                        'result' => ['success' => true, 'result' => 'complete']]))];

                return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo '.var_export(json_encode($packet), true).';']);
            });
        $context = $runtime->context('fixture_echo', $this->definition);
        $result = $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
        $this->assertTrue($result['success']);
        $this->assertSame('fixture-compute', $requests[0]['compute_selection']);
        $this->assertSame('auto', $requests[0]['model_role']);
        $this->assertSame(['text' => 'complete'], $requests[0]['arguments']);
        $this->session['overrides']['cli.compute_instance'] = 'auto';
        $this->session = $this->store->saveSession($this->session);
        $this->assertNotSame($context, $runtime->context('fixture_echo', $this->definition));
        try {
            $runtime->call('fixture_echo', ['text' => 'complete'], $context, $this->definition);
            $this->fail('A prepared action executed after its compute selection changed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('prepared execution target', $error->getMessage());
        }
        $this->assertCount(1, $requests);
    }

    public static function processKinds(): array
    {
        return [['compute', false], ['codex', false], ['compute', true], ['codex', true]];
    }

    #[DataProvider('processKinds')]
    public function test_failed_compute_streams_become_exact_private_artifacts_without_changing_tool_failure(string $kind, bool $unknown): void
    {
        $bytes = str_repeat("  Complete José\r\n\0\xff", 5000).' exact tail ';
        $execution = ['instance_id' => 'fixture-compute', 'success' => false, 'exit_code' => 7,
            'error' => 'process_exit_7', 'stdin_base64' => base64_encode($bytes),
            'stdout_base64' => base64_encode($bytes), 'stderr_base64' => base64_encode($bytes)];
        $streams = ['stdin', 'stdout', 'stderr'];
        if ($kind === 'codex') {
            $execution += ['last_message_base64' => base64_encode($bytes), 'timed_out' => true, 'last_message_available' => true];
            $streams[] = 'last_message';
        }
        $outcome = ['success' => false, 'result' => null, 'error' => 'Synthetic domain failure', $kind.'_executions' => [$execution]];
        if ($unknown) {
            $outcome['outcome_unknown'] = true;
        }
        $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
            'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1', 'result' => $outcome]))];
        $packetPath = $this->directory.'/compute-packet.json';
        file_put_contents($packetPath, json_encode($packet));
        $artifacts = new CliArtifactStore($this->directory.'/artifacts');
        $runtime = new CliFrameworkServices($this->store, $this->session['id'], base_path(), '/usr/bin/node', $artifacts,
            fn () => new Process([PHP_BINARY, '-r', 'fgets(STDIN); readfile($argv[1]);', $packetPath]));
        if ($unknown) {
            try {
                $runtime->call('fixture_echo', ['text' => 'complete'], $runtime->context('fixture_echo', $this->definition), $this->definition);
                $this->fail('Unknown execution outcome became a normal retryable result.');
            } catch (CliToolOutcomeUnknown) {
                $events = array_values(array_filter($this->store->events($this->session['id']), fn ($event) => $event['type'] === 'framework_'.$kind.'_io'));
                $this->assertCount(1, $events);
                foreach ($streams as $stream) {
                    $this->assertSame($bytes, $artifacts->read($events[0]['document']['event'][$stream]['sha256']));
                }
            }

            return;
        }
        $result = $runtime->call('fixture_echo', ['text' => 'complete'], $runtime->context('fixture_echo', $this->definition), $this->definition);
        $this->assertFalse($result['success']);
        $this->assertSame('Synthetic domain failure', $result['error']);
        $this->assertArrayNotHasKey($kind.'_executions', $result);
        $this->assertCount(1, $result[$kind.'_artifacts']);
        $this->assertFalse($result[$kind.'_artifacts'][0]['tool_success']);
        foreach ($streams as $stream) {
            $this->assertSame($bytes, $artifacts->read($result[$kind.'_artifacts'][0][$stream]['sha256']));
        }
        $events = array_values(array_filter($this->store->events($this->session['id']), fn ($event) => $event['type'] === 'framework_'.$kind.'_io'));
        $this->assertCount(1, $events);
        $this->assertStringNotContainsString(base64_encode($bytes), json_encode($events));
    }

    public function test_worker_retains_codex_output_when_the_domain_tool_fails_and_restores_observer(): void
    {
        $this->definition['method'] = 'codexFailure';
        $result = $this->worker()->execute(['schema' => 'plos.cli.framework-request.v1', 'tool' => 'fixture_echo',
            'arguments' => ['text' => 'complete original'], 'skill' => 'fixture',
            'definition_sha256' => CliFrameworkServicePolicy::fingerprint($this->definition),
            'rule_sha256' => hash('sha256', json_encode($this->policy['tools']['fixture_echo'])),
            'root' => realpath(base_path()), 'production' => null, 'target' => 'local', 'approved' => false]);
        $this->assertFalse($result['success']);
        $this->assertCount(1, $result['codex_executions']);
        $this->assertTrue($result['outcome_unknown']);
        $this->assertSame('complete original', base64_decode($result['codex_executions'][0]['last_message_base64']));
        $this->assertNull((new \ReflectionProperty(CodexExecConnectorService::class, 'outputObserver'))->getValue());
    }

    public function test_session_and_saved_parameter_overrides_reach_worker_without_changing_target_tables(): void
    {
        $this->definition['method'] = 'option';
        $this->definition['parameters'] = ['enabled' => ['type' => 'boolean', 'default' => true, 'x_context_only' => true]];
        $this->seedToolDefinition();
        $source = $this->store->snapshot($this->session['generation']);
        $configuration = new CliConfiguration($this->store);
        $key = 'registry.agent_tool_registry.fixture_echo.parameters.enabled.default';
        $this->session = $configuration->set($this->session, $key, false);
        $requests = [];
        $call = function () use (&$requests, $configuration): array {
            $runtime = $this->client(function ($launch) use (&$requests) {
                $request = json_decode($launch['input'], true, flags: JSON_THROW_ON_ERROR);
                $requests[] = $request;
                $result = $this->worker()->execute($request);
                $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                    'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1', 'result' => $result]))];

                return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo '.var_export(json_encode($packet), true).';']);
            });
            $definition = (new CliToolCatalog($configuration))->definitions($this->session)['fixture_echo'];

            return $runtime->call('fixture_echo', [], $runtime->context('fixture_echo', $definition), $definition);
        };
        $this->assertFalse($call()['result']);
        $this->assertSame([], $this->store->defaults());
        $this->assertTrue($this->definition['parameters']['enabled']['default']);
        $this->assertSame([], $requests[0]['arguments']);
        $this->assertFalse($requests[0]['parameter_override']['parameters']['enabled']['default']);
        $this->session = $this->store->createSession(['generation' => $this->session['generation'],
            'overrides' => ['cli.skill' => 'fixture', 'cli.target' => 'local']]);
        $this->assertTrue($call()['result'], 'An unsaved override does not leak into another session.');
        $this->assertArrayNotHasKey('parameter_override', $requests[1]);
        $this->session = $configuration->set($this->session, $key, false, save: true);
        $this->session = $this->store->createSession(['generation' => $this->session['generation'],
            'overrides' => ['cli.skill' => 'fixture', 'cli.target' => 'local']]);
        $this->assertFalse($call()['result'], 'An explicitly saved override applies to a new session.');
        $this->assertSame($source, $this->store->snapshot($this->session['generation']));
        $this->assertSame(3, CliFrameworkFixture::$calls);
    }

    public function test_parameter_override_cannot_mask_target_changes_or_model_authority(): void
    {
        $this->definition['method'] = 'option';
        $this->definition['parameters'] = ['enabled' => ['type' => 'boolean', 'default' => true, 'x_context_only' => true]];
        $baseline = $this->definition;
        $effective = $baseline;
        $effective['parameters']['enabled']['default'] = false;
        $request = $this->request([]);
        $request['parameter_override'] = ['base_sha256' => CliFrameworkServicePolicy::fingerprint($baseline), 'parameters' => $effective['parameters']];
        $request['definition_sha256'] = CliFrameworkServicePolicy::fingerprint($effective);
        foreach (['stale_baseline', 'changed_parameters', 'changed_rule', 'disabled', 'missing_skill', 'extra_control', 'model_control'] as $case) {
            $attempt = $request;
            $worker = $this->worker();
            switch ($case) {
                case 'stale_baseline': $this->definition['requires_confirmation'] = true;
                    break;
                case 'changed_parameters': $attempt['parameter_override']['parameters']['enabled']['default'] = true;
                    break;
                case 'changed_rule': $attempt['rule_sha256'] = str_repeat('0', 64);
                    break;
                case 'disabled': $this->definition['availability_status'] = 'disabled';
                    break;
                case 'missing_skill': $worker = $this->worker(contract: fn () => ['skill_found' => false, 'tools' => []]);
                    break;
                case 'extra_control': $attempt['parameter_override']['approved'] = true;
                    break;
                case 'model_control': $attempt['arguments']['parameter_override'] = $attempt['parameter_override'];
                    break;
            }
            try {
                $worker->execute($attempt);
                $this->fail('Invalid override reached service: '.$case);
            } catch (RuntimeException) {
                $this->assertSame(0, CliFrameworkFixture::$calls, $case);
            }
            $this->definition = $baseline;
        }
        $this->definition['requires_confirmation'] = true;
        $effective['requires_confirmation'] = true;
        $request['parameter_override']['base_sha256'] = CliFrameworkServicePolicy::fingerprint($this->definition);
        $request['definition_sha256'] = CliFrameworkServicePolicy::fingerprint($effective);
        $this->assertTrue($this->worker()->execute($request)['confirmation_pending']);
        $request['approved'] = true;
        $this->assertFalse($this->worker()->execute($request)['result']);
        $this->rules = [['id' => 1, 'operation_pattern' => 'tool:fixture_echo', 'action' => 'block', 'reason' => 'target revocation']];
        $this->assertTrue($this->worker()->execute($request)['guardrail_blocked']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_parameter_override_changed_after_preparation_prevents_process_start(): void
    {
        $this->definition['parameters']['text']['default'] = 'original';
        $this->seedToolDefinition();
        $configuration = new CliConfiguration($this->store);
        $runtime = $this->client(fn () => $this->replyProcess());
        $definition = (new CliToolCatalog($configuration))->definitions($this->session)['fixture_echo'];
        $context = $runtime->context('fixture_echo', $definition);
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $context, $definition, function () use ($configuration) {
                $this->session = $configuration->set($this->session,
                    'registry.agent_tool_registry.fixture_echo.parameters.text.default', 'changed');
            });
            $this->fail('Changed operator settings reached a process.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('changed before dispatch', $error->getMessage());
            $this->assertSame([], $this->store->events($this->session['id']));
        }
    }

    public function test_operator_disabling_a_tool_before_dispatch_prevents_process_start(): void
    {
        $this->seedToolDefinition();
        $configuration = new CliConfiguration($this->store);
        $runtime = $this->client(fn () => $this->replyProcess());
        $definition = (new CliToolCatalog($configuration))->definitions($this->session)['fixture_echo'];
        $context = $runtime->context('fixture_echo', $definition);
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $context, $definition, function () use ($configuration) {
                $this->session = $configuration->set($this->session, 'registry.agent_tool_registry.fixture_echo.enabled', 0);
            });
            $this->fail('An operator-disabled tool reached a process.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('no longer available', $error->getMessage());
            $this->assertSame([], $this->store->events($this->session['id']));
        }
    }

    public function test_tree_scope_checks_actual_defaults_and_bundled_references(): void
    {
        $this->definition['method'] = 'person';
        $this->definition['parameters'] = ['person_id' => ['type' => 'integer', 'default' => 17, 'x_context_only' => true]];
        $this->policy['tools']['fixture_echo'] = ['target' => 'workflow', 'tree_scoped' => true,
            'references' => ['person_id' => ['table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
        $worker = $this->worker(reference: fn ($rule, $id, $tree) => $id === 17 && $tree === 4);
        $this->assertSame(17, $worker->execute($this->request([]))['result']);
        $baseline = $this->definition;
        $effective = $baseline;
        $effective['parameters']['person_id']['default'] = 99;
        $request = $this->request([]);
        $request['parameter_override'] = ['base_sha256' => CliFrameworkServicePolicy::fingerprint($baseline), 'parameters' => $effective['parameters']];
        $request['definition_sha256'] = CliFrameworkServicePolicy::fingerprint($effective);
        try {
            $worker->execute($request);
            $this->fail('Foreign-tree default reached service.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('absent from the selected tree', $error->getMessage());
        }
        $this->definition['parameters']['filters'] = ['type' => 'object', 'x_context_only' => true, 'x_parameter_bundle' => ['person_id']];
        $this->policy['tools']['fixture_echo']['references'] = ['filters.person_id' => $this->policy['tools']['fixture_echo']['references']['person_id']];
        $this->assertSame(17, $worker->execute($this->request([]))['result']);
        $this->definition['parameters']['person_id']['default'] = 99;
        try {
            $worker->execute($this->request([]));
            $this->fail('Foreign-tree bundled default reached service.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('absent from the selected tree', $error->getMessage());
        }
        $this->assertSame(2, CliFrameworkFixture::$calls);
    }

    private function seedToolDefinition(): void
    {
        $snapshot = $this->store->snapshot($this->session['generation'])['snapshot'];
        $snapshot['tables']['agent_tool_registry'] = [['id' => 1, 'name' => 'fixture_echo', 'enabled' => 1,
            'service_class' => $this->definition['service'], 'method' => $this->definition['method'], 'description' => 'Fixture',
            'parameters' => json_encode($this->definition['parameters']), 'permissions' => '[]', 'risk_level' => 'read',
            'requires_confirmation' => false, 'category' => 'fixture', 'availability_status' => 'available', 'mcp_server' => null, 'mcp_tool' => null]];
        $generation = $this->store->activate($snapshot);
        $this->session['generation'] = $generation;
        $this->session['overrides']['cli.target'] = 'local';
        $this->session = $this->store->saveSession($this->session);
    }

    public function test_worker_rechecks_target_permissions_definition_and_authority_before_dispatch(): void
    {
        foreach (['root', 'production', 'definition', 'rule', 'extra_authority', 'argument_authority', 'disabled', 'skill'] as $case) {
            $request = $this->request(['text' => 'original']);
            $worker = $this->worker();
            switch ($case) {
                case 'root': $request['root'] = '/foreign';
                    break;
                case 'production': $request['target'] = 'prod';
                    $request['production'] = ! app()->isProduction();
                    break;
                case 'definition': $request['definition_sha256'] = str_repeat('a', 64);
                    break;
                case 'rule': $request['rule_sha256'] = str_repeat('a', 64);
                    break;
                case 'extra_authority': $request['simulate'] = true;
                    break;
                case 'argument_authority': $request['arguments']['operator_confirmed'] = true;
                    break;
                case 'disabled': $this->definition['availability_status'] = 'disabled';
                    break;
                case 'skill': $worker = $this->worker(contract: fn () => ['skill_found' => false, 'tools' => []]);
                    break;
            }
            try {
                $worker->execute($request);
                $this->fail('Invalid '.$case.' request reached a service.');
            } catch (RuntimeException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
            unset($this->definition['availability_status']);
            $this->assertSame(0, CliFrameworkFixture::$calls, $case);
        }
    }

    public function test_worker_requires_exact_approval_and_retains_target_guardrail_authority(): void
    {
        $this->definition['requires_confirmation'] = true;
        $request = $this->request(['text' => 'approved output']);
        $result = $this->worker()->execute($request);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['confirmation_pending']);
        $this->assertSame(0, CliFrameworkFixture::$calls);
        $request['approved'] = true;
        $this->assertTrue($this->worker()->execute($request)['success']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
        $this->rules = [['id' => 1, 'operation_pattern' => 'tool:fixture_echo', 'action' => 'block', 'reason' => 'target revocation']];
        $result = $this->worker()->execute($request);
        $this->assertFalse($result['success']);
        $this->assertTrue($result['guardrail_blocked']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_tree_record_rules_refuse_foreign_or_missing_records_before_service_execution(): void
    {
        $this->definition['method'] = 'person';
        $this->definition['parameters'] = ['person_id' => ['type' => 'integer', 'required' => true]];
        $this->policy['tools']['fixture_echo'] = ['target' => 'workflow', 'tree_scoped' => true,
            'references' => ['person_id' => ['table' => 'genealogy_persons', 'id_column' => 'id', 'tree_column' => 'tree_id']]];
        $lookups = [];
        $worker = $this->worker(reference: function ($reference, $id, $tree) use (&$lookups) {
            $lookups[] = [$reference, $id, $tree];

            return $tree === 4 && $id === 17;
        });
        $result = $worker->execute($this->request(['person_id' => 17]));
        $this->assertSame(17, $result['result']);
        $this->assertSame('genealogy_persons', $lookups[0][0]['table']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
        foreach ([['person_id' => 99], ['person_id' => -1], []] as $arguments) {
            try {
                $worker->execute($this->request($arguments));
                $this->fail('Unscoped person reached service.');
            } catch (RuntimeException) {
                $this->assertSame(1, CliFrameworkFixture::$calls);
            }
        }
    }

    public function test_tree_argument_cannot_change_selected_tree_or_bind_an_undeclared_parameter(): void
    {
        $this->definition['method'] = 'tree';
        $this->definition['parameters'] = ['tree_id' => ['type' => 'integer', 'required' => true]];
        $this->policy['tools']['fixture_echo'] = ['target' => 'workflow', 'tree_scoped' => true, 'tree_argument' => 'tree_id', 'references' => []];
        $this->assertSame(4, $this->worker()->execute($this->request(['tree_id' => 4]))['result']);
        foreach (['foreign', 'missing_tree', 'undeclared'] as $case) {
            $request = $this->request(['tree_id' => $case === 'foreign' ? 8 : 4]);
            if ($case === 'missing_tree') {
                $request['tree_id'] = null;
            } elseif ($case === 'undeclared') {
                $this->policy['tools']['fixture_echo']['tree_argument'] = 'other_tree';
                $request = $this->request(['tree_id' => 4]);
            }
            try {
                $this->worker()->execute($request);
                $this->fail('Invalid tree argument reached service.');
            } catch (RuntimeException) {
                $this->assertSame(1, CliFrameworkFixture::$calls);
            }
        }
    }

    public function test_installation_default_uses_its_local_worker_without_remote_inference_changing_it(): void
    {
        unset($this->session['overrides']['cli.workflow_target']);
        $this->session['overrides']['cli.model'] = 'jetson::fixture';
        $this->store->saveSession($this->session);
        $runtime = $this->client(fn () => $this->fail('Inspection must not launch a process.'));
        $context = $runtime->context('fixture_echo', $this->definition);
        $this->assertSame('local', $context['operation_target']);
        $this->assertNotSame('operator@10.23.45.10', $context['execution_host']);
    }

    public function test_client_preserves_explicit_prod_supports_saved_local_and_keeps_all_io_without_trusting_approval_flags(): void
    {
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });
        $original = str_repeat("  Full José\r\n  ", 9000);
        $context = $runtime->context('fixture_echo', $this->definition);
        $this->assertSame('prod', $context['operation_target']);
        $this->assertSame('operator@10.23.45.10', $context['execution_host']);
        $result = $runtime->call('fixture_echo', ['text' => $original], $context + ['operator_confirmed' => true], $this->definition);
        $this->assertSame($original, $result['result_text']);
        $request = json_decode($launches[0]['input'], true);
        $this->assertFalse($request['approved']);
        $this->assertSame(['mode' => 'lan', 'host_addresses' => []], $request['network']);
        $this->assertSame($original, $request['arguments']['text']);
        $this->assertSame('/usr/bin/ssh', $launches[0]['command']);
        $this->assertContains('Hostname=10.23.45.10', $launches[0]['args']);
        $this->assertStringNotContainsString($original, json_encode($launches[0]['args']));
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.target', 'local', save: true);
        $context = $runtime->context('fixture_echo', $this->definition);
        $this->assertSame('local', $context['operation_target']);
        $this->assertTrue($runtime->call('fixture_echo', ['text' => 'local'], $context, $this->definition)['success']);
        $this->assertSame(PHP_BINARY, $launches[1]['command']);
        $this->assertSame(base_path().'/scripts/plos-cli/framework-service.php', $launches[1]['args'][2]);
        $this->assertSame('local', $this->store->defaults()['cli.target']);
        $events = $this->store->events($this->session['id']);
        $event = array_values(array_filter($events, fn ($event) => $event['type'] === 'framework_service_io'))[0]['document']['event'];
        $raw = (new CliArtifactStore($this->directory))->read($event['stdout']['sha256']);
        $packet = json_decode(base64_decode(json_decode($raw, true)['stdout_base64']), true);
        $this->assertSame($original, $packet['result']['result_text']);
        $this->assertSame(0, CliFrameworkFixture::$calls, 'Client fixtures never call domain services.');
    }

    public function test_client_does_not_switch_targets_or_replay_an_incomplete_process_result(): void
    {
        $starts = 0;
        $runtime = $this->client(function () use (&$starts) {
            $starts++;

            return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo "  partial output  "; fwrite(STDERR,"diagnostic"); exit(9);']);
        });
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $runtime->context('fixture_echo', $this->definition), $this->definition);
            $this->fail('An incomplete process was accepted.');
        } catch (CliToolOutcomeUnknown $error) {
            $this->assertStringContainsString('no alternate target or replay', $error->getMessage());
        }
        $this->assertSame(1, $starts);
        $event = $this->store->events($this->session['id'])[0]['document']['event'];
        $this->assertSame('  partial output  ', (new CliArtifactStore($this->directory))->read($event['stdout']['sha256']));
        $this->assertSame('diagnostic', (new CliArtifactStore($this->directory))->read($event['stderr']['sha256']));
    }

    public function test_framework_deadline_defaults_and_reviewed_rule_override_reach_owned_worker(): void
    {
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });
        $call = fn () => $runtime->call('fixture_echo', ['text' => 'original'], $runtime->context('fixture_echo', $this->definition), $this->definition);
        $call();
        $this->assertSame(30000, $launches[0]['timeout_ms']);
        $this->session['overrides']['cli.mcp_timeout_ms'] = 45000;
        $this->session = $this->store->saveSession($this->session);
        $call();
        $this->assertSame(45000, $launches[1]['timeout_ms']);
        $oldContext = $runtime->context('fixture_echo', $this->definition);
        $this->policy['tools']['fixture_echo']['timeout_ms'] = 120000;
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $this->session = $this->store->saveSession($this->session);
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $oldContext, $this->definition);
            $this->fail('Changed deadline must invalidate prepared authority.');
        } catch (RuntimeException) {
            $this->assertCount(2, $launches);
        }
        $call();
        $this->assertSame(120000, $launches[2]['timeout_ms']);
        $request = json_decode($launches[2]['input'], true);
        $this->assertSame(['text' => 'original'], $request['arguments']);
    }

    public function test_native_face_preview_execute_uses_its_selected_framework_timeout(): void
    {
        $tool = 'file_face_coverage_preview_execute';
        $this->policy['tools'][$tool] = ['target' => 'local', 'tree_scoped' => false, 'references' => [], 'timeout_ms' => 600000];
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $this->session = $this->store->saveSession($this->session);
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });

        $context = $runtime->context($tool, $this->definition);
        $runtime->call($tool, ['text' => 'exact tool arguments'], $context, $this->definition);
        $this->assertSame(600000, $launches[0]['timeout_ms']);

        $this->policy['tools'][$tool]['timeout_ms'] = 240000;
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $this->session = $this->store->saveSession($this->session);
        $runtime->call($tool, ['text' => 'exact tool arguments'], $runtime->context($tool, $this->definition), $this->definition);
        $this->assertSame(240000, $launches[1]['timeout_ms']);
    }

    public function test_reviewed_deadline_still_kills_owned_worker_and_retains_unknown_without_replay(): void
    {
        $this->policy['tools']['fixture_echo']['timeout_ms'] = 500;
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $this->session = $this->store->saveSession($this->session);
        $starts = 0;
        $runtime = $this->client(function () use (&$starts) {
            $starts++;

            return $this->managedFixture(hold: true);
        });
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $runtime->context('fixture_echo', $this->definition), $this->definition);
            $this->fail('Timed out service must remain unknown.');
        } catch (CliToolOutcomeUnknown) {
            $event = $this->store->events($this->session['id'])[0]['document']['event'];
            $packet = json_decode((new CliArtifactStore($this->directory))->read($event['stdout']['sha256']), true);
            $this->assertFalse($packet['completed']);
            $this->assertTrue($packet['cancelled']);
            $this->assertSame('SIGKILL', $packet['exit']['signal']);
            $this->assertSame('  partial service output  ', base64_decode($packet['stdout_base64']));
        }
        $this->assertSame(1, $starts);
    }

    public function test_real_managed_worker_control_pipe_stays_open_without_a_model_lease(): void
    {
        $runtime = $this->client(fn () => $this->managedFixture());
        $result = $runtime->call('fixture_echo', ['text' => '  original output  '],
            $runtime->context('fixture_echo', $this->definition), $this->definition);
        $this->assertSame('  original output  ', $result['result_text']);
        $event = $this->store->events($this->session['id'])[0]['document']['event'];
        $packet = json_decode((new CliArtifactStore($this->directory))->read($event['stdout']['sha256']), true);
        $this->assertTrue($packet['completed']);
        $this->assertFalse($packet['cancelled']);
    }

    public function test_real_managed_worker_retains_partial_output_when_custody_is_lost(): void
    {
        $runtime = $this->client(fn () => $this->managedFixture(hold: true));
        $deadline = microtime(true) + 10;
        try {
            $runtime->call('fixture_echo', ['text' => 'original'], $runtime->context('fixture_echo', $this->definition),
                $this->definition, function () use ($deadline) {
                    if (is_file($this->directory.'/managed-ready')) {
                        throw new RuntimeException('Fixture custody lost.');
                    }
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Fixture did not publish its partial output before the readiness deadline.');
                    }
                });
            $this->fail('Interrupted service was accepted as complete.');
        } catch (CliToolOutcomeUnknown) {
            $event = $this->store->events($this->session['id'])[0]['document']['event'];
            $packet = json_decode((new CliArtifactStore($this->directory))->read($event['stdout']['sha256']), true);
            $this->assertFalse($packet['completed']);
            $this->assertTrue($packet['cancelled']);
            $this->assertSame('  partial service output  ', base64_decode($packet['stdout_base64']));
            $this->assertSame('SIGKILL', $packet['exit']['signal']);
        }
    }

    private function managedFixture(bool $hold = false): Process
    {
        $wrapper = json_encode(base_path().'/scripts/plos-cli/internal-operation.mjs', JSON_THROW_ON_ERROR);
        $input = json_encode(base_path().'/scripts/plos-cli/managed-input.mjs', JSON_THROW_ON_ERROR);
        $ready = json_encode($this->directory.'/managed-ready', JSON_THROW_ON_ERROR);
        $child = json_encode($hold ? 'process.stdout.write("  partial service output  ", () => require("node:fs").writeFileSync('.$ready.', "ready")); setTimeout(() => {}, 10000);' : <<<'JS'
            let input = '';
            process.stdin.setEncoding('utf8');
            process.stdin.on('data', data => { input += data; });
            process.stdin.on('end', () => setTimeout(() => {
                const request = JSON.parse(input);
                process.stdout.write(JSON.stringify({schema: 'plos.cli.framework-result.v1', result: {success: true, result_text: request.arguments.text}}));
            }, 250));
            JS, JSON_THROW_ON_ERROR);

        return new Process(['/usr/bin/node', '--input-type=module', '-e',
            "import { runInternalOperation } from $wrapper; import { runManagedInput } from $input; await runManagedInput((request, options) => runInternalOperation({...request, command: process.execPath, args: ['-e', $child]}, options));"]);
    }

    public function test_client_refuses_changed_target_network_or_lost_custody_before_start(): void
    {
        $runtime = $this->client(fn () => $this->replyProcess());
        foreach (['target', 'network', 'custody'] as $case) {
            $context = $runtime->context('fixture_echo', $this->definition);
            if ($case === 'target') {
                $context['operation_target'] = 'local';
            } elseif ($case === 'network') {
                $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.network', 'offline');
            } else {
                $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.network', 'lan');
            }
            try {
                $runtime->call('fixture_echo', ['text' => 'original'], $context, $this->definition,
                    $case === 'custody' ? fn () => throw new RuntimeException('fixture custody loss') : null);
                $this->fail('Invalid '.$case.' reached process execution.');
            } catch (RuntimeException) {
                $this->assertSame([], array_filter($this->store->events($this->session['id']), fn ($event) => $event['type'] === 'framework_service_io'));
            }
        }
    }

    public function test_parent_framework_derives_caller_binding_from_exact_existing_approval(): void
    {
        $arguments = ['text' => '  approved original  '];
        $action = ['id' => 'caller-action-1', 'name' => 'fixture_echo', 'arguments' => $arguments];
        $turn = 'caller-turn-1';
        $scope = ['configuration_sha256' => str_repeat('a', 64), 'instance_id' => 'fixture', 'model' => 'no-model'];
        $session = $this->store->session($this->session['id']);
        $session['turns'] = [['id' => $turn, 'status' => 'inProgress']];
        $session['messages'] = [['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => $action['id'], 'type' => 'function', 'function' => ['name' => $action['name'], 'arguments' => json_encode($arguments)]],
        ]]];
        $session['message_turn_ids'] = [$turn];
        $this->session = $this->store->saveSession($session);
        $approval = $this->store->requestApproval($session['id'], $turn, $action, $scope);
        $this->assertFalse($this->store->hasApproval($session['id'], $turn, $action, $scope));
        $this->store->decideApproval($session['id'], $approval['id'], true);
        $this->assertTrue($this->store->hasApproval($session['id'], $turn, $action, $scope));
        $requests = [];
        $runtime = $this->client(function (array $launch) use (&$requests): Process {
            $requests[] = json_decode($launch['input'], true, flags: JSON_THROW_ON_ERROR);

            return $this->replyProcess();
        });
        $context = $runtime->context('fixture_echo', $this->definition) + [
            '_cli_action_id' => $action['id'], '_cli_action_turn' => $turn, '_cli_approval_scope' => $scope,
        ];
        $this->assertTrue($runtime->call('fixture_echo', $arguments, $context, $this->definition)['success']);
        $this->assertCount(1, $requests);
        $this->assertTrue($requests[0]['approved']);
        $this->assertSame($arguments, $requests[0]['arguments']);
        $this->assertSame(['session_id' => $session['id'], 'turn_id' => $turn, 'action_id' => $action['id'],
            'arguments_sha256' => hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR)),
            'approval_scope_sha256' => hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)),
        ], $requests[0]['caller_binding']);
        $this->assertArrayNotHasKey('caller_binding', $requests[0]['arguments']);
        $this->assertSame(0, CliFrameworkFixture::$calls, 'Parent process fixture never invokes a domain service.');
    }

    public function test_parent_framework_override_requires_exact_human_command_and_approval(): void
    {
        $arguments = ['text' => 'outside selected skill'];
        $action = ['id' => '51fdf413-6070-43d2-9ee4-e62446236741', 'name' => 'fixture_echo',
            'arguments' => $arguments, 'operator_override' => true];
        $turn = 'operator-turn';
        $scope = ['configuration_sha256' => str_repeat('a', 64), 'instance_id' => 'fixture', 'model' => 'no-model'];
        $session = $this->store->session($this->session['id']);
        $session['turns'] = [['id' => $turn, 'status' => 'inProgress', 'items' => [[
            'content' => [['type' => 'text', 'text' => '/tool override fixture_echo '.json_encode($arguments)]],
        ]]]];
        $this->session = $this->store->saveSession($session);
        $this->store->registerOperatorAction($session['id'], $action);
        $approval = $this->store->requestApproval($session['id'], $turn, $action, $scope);
        $this->assertFalse($this->store->hasApproval($session['id'], $turn, $action, $scope));
        $requests = [];
        $runtime = $this->client(function (array $launch) use (&$requests): Process {
            $requests[] = json_decode($launch['input'], true, flags: JSON_THROW_ON_ERROR);

            return $this->replyProcess();
        });
        $prepared = $runtime->context('fixture_echo', $this->definition);
        $this->assertNotNull($prepared);
        $context = $prepared + [
            '_cli_action_id' => $action['id'], '_cli_action_turn' => $turn,
            '_cli_approval_scope' => $scope, '_cli_operator_override' => true,
        ];
        $this->assertTrue($context['_cli_operator_override']);
        try {
            $runtime->call('fixture_echo', $arguments, $context, $this->definition);
            $this->fail('Unapproved override was dispatched.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('exact approved operator command', $error->getMessage());
        }
        $this->store->decideApproval($session['id'], $approval['id'], true);
        $this->assertTrue($runtime->call('fixture_echo', $arguments, $context, $this->definition)['success']);
        $this->assertCount(1, $requests);
        $this->assertTrue($requests[0]['operator_override']);
        $this->assertTrue($requests[0]['approved']);
        $this->assertSame($action['id'], $requests[0]['caller_binding']['action_id']);

        $forged = $context;
        $forged['_cli_action_id'] = 'd8fd3eba-10f4-4b36-ac3c-dd0544543d1a';
        $this->expectExceptionMessage('exact approved operator command');
        $runtime->call('fixture_echo', $arguments, $forged, $this->definition);
    }

    public function test_worker_override_uses_current_enabled_registry_entry_only_with_approved_caller(): void
    {
        $request = $this->request(['text' => 'global registry']);
        $contract = fn (string $skill, bool $override = false) => $override
            ? ['skill_found' => true, 'tools' => ['fixture_echo' => $this->definition]]
            : ['skill_found' => true, 'tools' => []];
        try {
            $this->worker($contract)->execute($request);
            $this->fail('Ordinary skill call used a global registry entry.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('does not permit', $error->getMessage());
        }
        $request['operator_override'] = true;
        foreach ([['approved' => false, 'caller_binding' => $this->callerBinding($request['arguments'])],
            ['approved' => true]] as $invalid) {
            try {
                $this->worker($contract)->execute(array_replace($request, $invalid));
                $this->fail('Unbound framework override executed.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('approved operator caller', $error->getMessage());
            }
        }
        $request['approved'] = true;
        $request['caller_binding'] = $this->callerBinding($request['arguments']);
        $this->assertTrue($this->worker($contract)->execute($request)['success']);
        $this->assertSame(1, CliFrameworkFixture::$calls);

        $revoked = fn () => ['skill_found' => true, 'tools' => []];
        $this->expectExceptionMessage('target registry does not permit');
        $this->worker($revoked)->execute($request);
    }

    public function test_worker_caller_context_is_scoped_restored_and_absent_for_legacy_calls(): void
    {
        $this->definition['method'] = 'inspectCaller';
        $outer = ['sentinel' => 'prior trusted context'];
        Context::addHidden(CliFrameworkServiceWorker::CALLER_CONTEXT, $outer);
        $request = $this->request(['text' => 'complete']);
        $request['caller_binding'] = $this->callerBinding($request['arguments']);
        try {
            $this->assertTrue($this->worker()->execute($request)['success']);
            $this->assertSame($request['caller_binding'], CliFrameworkFixture::$observedCaller);
            $this->assertSame($outer, Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));

            $request['arguments']['text'] = 'fail after caller inspection';
            $request['caller_binding'] = $this->callerBinding($request['arguments']);
            try {
                $this->worker()->execute($request);
                $this->fail('Fixture exception was accepted as complete.');
            } catch (CliToolOutcomeUnknown $error) {
                $this->assertStringContainsString('failed after dispatch', $error->getMessage());
            }
            $this->assertSame($request['caller_binding'], CliFrameworkFixture::$observedCaller);
            $this->assertSame($outer, Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));

            $legacy = $this->request(['text' => 'legacy complete']);
            $this->assertTrue($this->worker()->execute($legacy)['success']);
            $this->assertNull(CliFrameworkFixture::$observedCaller, 'A legacy request must not inherit another caller.');
            $this->assertSame($outer, Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));
            $this->assertSame(3, CliFrameworkFixture::$calls);
        } finally {
            Context::forgetHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        }
    }

    public function test_registered_face_comparison_execute_requires_confirmation_and_disabled_registry_denies_dispatch(): void
    {
        $tool = 'file_local_face_comparison_execute';
        $this->definition = ['service' => CliFrameworkFixture::class, 'method' => 'boundedFaceComparison',
            'parameters' => ['source_embedding_id' => ['type' => 'integer', 'required' => true],
                'candidate_embedding_id' => ['type' => 'integer', 'required' => true],
                'expected_input_digest' => ['type' => 'string', 'required' => true]],
            'permissions' => ['file:write'], 'risk_level' => 'write', 'requires_confirmation' => true,
            'mcp_server' => null, 'mcp_tool' => null];
        $this->policy['tools'][$tool] = ['target' => 'local', 'tree_scoped' => false, 'references' => []];
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $arguments = ['source_embedding_id' => 101, 'candidate_embedding_id' => 202, 'expected_input_digest' => str_repeat('a', 64)];
        $request = $this->request($arguments);
        $request['tool'] = $tool;
        $request['definition_sha256'] = CliFrameworkServicePolicy::fingerprint($this->definition);
        $request['rule_sha256'] = hash('sha256', json_encode($this->policy['tools'][$tool], JSON_THROW_ON_ERROR));
        $request['caller_binding'] = $this->callerBinding($arguments);
        $request['approved'] = false;
        $contract = fn () => ['skill_found' => true, 'tools' => [$tool => $this->definition]];

        $pending = $this->worker($contract)->execute($request);
        $this->assertTrue($pending['confirmation_pending']);
        $this->assertSame(0, CliFrameworkFixture::$calls);

        $request['approved'] = true;
        $result = $this->worker($contract)->execute($request);
        $this->assertTrue($result['success']);
        $this->assertSame($request['caller_binding'], CliFrameworkFixture::$observedCaller);
        $this->assertSame(CliWorkflowExecutionScope::SCHEMA, CliFrameworkFixture::$observedWorkflowScope['settings']['schema']);
        $this->assertSame(1, CliFrameworkFixture::$calls);

        try {
            $this->worker(fn () => ['skill_found' => true, 'tools' => []])->execute($request);
            $this->fail('Disabled face comparison registration reached its service.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('target registry does not permit', $error->getMessage());
        }
        $this->assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_registered_face_comparison_deadline_reaches_local_framework_worker_without_approving_it(): void
    {
        $tool = 'file_local_face_comparison_execute';
        $this->definition = ['service' => CliFrameworkFixture::class, 'method' => 'boundedFaceComparison',
            'parameters' => ['source_embedding_id' => ['type' => 'integer', 'required' => true],
                'candidate_embedding_id' => ['type' => 'integer', 'required' => true],
                'expected_input_digest' => ['type' => 'string', 'required' => true]],
            'permissions' => ['file:write'], 'risk_level' => 'write', 'requires_confirmation' => true,
            'mcp_server' => null, 'mcp_tool' => null];
        $this->policy['tools'][$tool] = ['target' => 'local', 'tree_scoped' => false, 'references' => [], 'timeout_ms' => 300000];
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $launches = [];
        $runtime = $this->client(function (array $launch) use (&$launches): Process {
            $launches[] = $launch;
            $result = ['schema' => 'plos.cli.framework-result.v1', 'result' => ['success' => false, 'confirmation_pending' => true]];
            $packet = ['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                'stdout_base64' => base64_encode(json_encode($result, JSON_THROW_ON_ERROR))];

            return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo '.var_export(json_encode($packet, JSON_THROW_ON_ERROR), true).';']);
        });
        $arguments = ['source_embedding_id' => 101, 'candidate_embedding_id' => 202, 'expected_input_digest' => str_repeat('a', 64)];

        $context = $runtime->context($tool, $this->definition);
        $result = $runtime->call($tool, $arguments, $context, $this->definition);

        $this->assertTrue($result['confirmation_pending']);
        $this->assertCount(1, $launches);
        $this->assertSame(300000, $launches[0]['timeout_ms']);
        $request = json_decode($launches[0]['input'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse($request['approved']);
        $this->assertSame($arguments, $request['arguments']);
        $this->assertSame(0, CliFrameworkFixture::$calls, 'The worker fixture did not invoke the comparison service.');
    }

    public function test_worker_rejects_invalid_caller_identity_hash_and_changed_arguments_before_dispatch(): void
    {
        $this->definition['method'] = 'inspectCaller';
        $request = $this->request(['text' => 'exact']);
        $request['caller_binding'] = $this->callerBinding($request['arguments']);
        $variants = [];
        foreach (['session_id' => '', 'turn_id' => "bad\nturn", 'action_id' => str_repeat('x', 256),
            'arguments_sha256' => str_repeat('0', 64), 'approval_scope_sha256' => 'not-a-sha256'] as $field => $value) {
            $variant = $request;
            $variant['caller_binding'][$field] = $value;
            $variants[] = $variant;
        }
        $variant = $request;
        unset($variant['caller_binding']['session_id']);
        $variants[] = $variant;
        $variant = $request;
        $variant['caller_binding']['approved'] = true;
        $variants[] = $variant;
        $variant = $request;
        $variant['arguments']['text'] = 'changed after binding';
        $variants[] = $variant;
        foreach ($variants as $variant) {
            try {
                $this->worker()->execute($variant);
                $this->fail('Invalid caller binding reached the service.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('caller', $error->getMessage());
            }
            $this->assertSame(0, CliFrameworkFixture::$calls);
            $this->assertNull(Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));
        }
    }

    public function test_trusted_caller_envelope_does_not_replace_existing_confirmation(): void
    {
        $this->definition['requires_confirmation'] = true;
        $request = $this->request(['text' => 'exact']);
        $request['caller_binding'] = $this->callerBinding($request['arguments']);
        $result = $this->worker()->execute($request);
        $this->assertFalse($result['success']);
        $this->assertSame(0, CliFrameworkFixture::$calls);
        $this->assertNull(Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));
        $request['approved'] = true;
        $this->assertTrue($this->worker()->execute($request)['success']);
        $this->assertSame(1, CliFrameworkFixture::$calls);
        $this->assertNull(Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));
    }

    private function callerBinding(array $arguments): array
    {
        return ['session_id' => 'caller-session', 'turn_id' => 'caller-turn', 'action_id' => 'caller-action',
            'arguments_sha256' => hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR)),
            'approval_scope_sha256' => str_repeat('a', 64)];
    }

    private function request(array $arguments): array
    {
        return ['schema' => 'plos.cli.framework-request.v1', 'tool' => 'fixture_echo', 'arguments' => $arguments,
            'skill' => 'fixture', 'definition_sha256' => CliFrameworkServicePolicy::fingerprint($this->definition),
            'rule_sha256' => hash('sha256', json_encode($this->policy['tools']['fixture_echo'], JSON_THROW_ON_ERROR)),
            'root' => realpath(base_path()), 'production' => null, 'target' => 'local', 'tree_id' => 4, 'approved' => false];
    }

    public function test_worker_applies_requested_network_scope_and_retains_stricter_target_policy(): void
    {
        $this->definition['method'] = 'fetchUrl';
        $this->definition['parameters'] = ['url' => ['type' => 'string', 'required' => true]];
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response("  complete workflow response José\r\n");
        });
        $request = $this->request(['url' => 'https://outside.fixture.invalid/read']);
        $request['network'] = ['mode' => 'online', 'host_addresses' => []];
        $refused = function (array $request): void {
            try {
                $this->worker()->execute($request);
                $this->fail('A restricted HTTP request completed.');
            } catch (CliToolOutcomeUnknown $error) {
                // The service may have done earlier work before reaching HTTP;
                // refusal must not classify the whole operation as safe to retry.
                $this->assertStringContainsString('failed after dispatch', $error->getMessage());
            }
        };
        $refused($request);
        $this->assertSame(0, $sent);
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.network', 'online');
        $request['network']['mode'] = 'lan';
        $refused($request);
        $this->assertSame(0, $sent);
        $request['arguments']['url'] = 'http://10.23.45.11/read';
        $this->assertSame("  complete workflow response José\r\n", $this->worker()->execute($request)['result']);
        $this->assertSame(1, $sent);
        $request['network']['mode'] = 'offline';
        $refused($request);
        $this->assertSame(1, $sent);
        $request['network']['mode'] = 'online';
        $request['arguments']['url'] = 'https://outside.fixture.invalid/read';
        $this->assertTrue($this->worker()->execute($request)['success']);
        $this->assertSame(2, $sent);
        $request['network']['mode'] = 'invented';
        try {
            $this->worker()->execute($request);
            $this->fail('Invalid workflow network mode reached execution.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('network settings', $error->getMessage());
            $this->assertSame(2, $sent);
        }
    }

    public function test_windows_ssh_target_encodes_only_bound_paths_and_keeps_payload_in_stdin(): void
    {
        $this->policy['targets']['prod'] = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11',
            'root' => "C:\\PLOS\\Operator's app", 'production' => false, 'platform' => 'windows', 'php' => 'C:\\tools\\php85\\php.exe'];
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });
        $text = 'original input $(not-a-command) & never shell text';
        $result = $runtime->call('fixture_echo', ['text' => $text], $runtime->context('fixture_echo', $this->definition), $this->definition);
        $this->assertSame($text, $result['result_text']);
        $this->assertCount(1, $launches);
        $args = $launches[0]['args'];
        $this->assertContains('StrictHostKeyChecking=yes', $args);
        $this->assertContains('ProxyCommand=none', $args);
        $this->assertContains('ControlPath=none', $args);
        $remote = $args[array_key_last($args)];
        $prefix = 'powershell.exe -NoProfile -NonInteractive -EncodedCommand ';
        $this->assertStringStartsWith($prefix, $remote);
        $program = mb_convert_encoding(base64_decode(substr($remote, strlen($prefix)), true), 'UTF-8', 'UTF-16LE');
        $this->assertSame('$ErrorActionPreference="Stop"; & \'C:\\tools\\php85\\php.exe\' -d display_errors=stderr \'C:\\PLOS\\Operator\'\'s app\\scripts\\plos-cli\\framework-service.php\'; exit $LASTEXITCODE', $program);
        $this->assertStringNotContainsString($text, $program);
        $request = json_decode($launches[0]['input'], true);
        $this->assertSame($text, $request['arguments']['text']);
        $this->assertFalse($request['approved']);
        $this->assertSame($this->policy['targets']['prod']['root'], $request['root']);
    }

    public function test_windows_target_requires_explicit_safe_paths_and_retires_changed_executable_context(): void
    {
        $target = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11', 'root' => 'C:\\PLOS\\application',
            'production' => false, 'platform' => 'windows', 'php' => 'C:\\tools\\php85\\php.exe'];
        $this->policy['targets']['prod'] = $target;
        foreach ([['php' => null], ['php' => 'php'], ['root' => '\\\\server\\share'], ['root' => 'C:\\PLOS\\..\\elsewhere'],
            ['root' => 'C:\\PLOS\\file:stream'], ['root' => 'C:\\PLOS\\trailing.'], ['platform' => 'linux']] as $change) {
            $bad = $this->policy;
            $bad['targets']['prod'] = array_replace($target, $change);
            try {
                CliFrameworkServicePolicy::validate($bad);
                $this->fail('Invalid Windows binding accepted.');
            } catch (RuntimeException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $starts = 0;
        $runtime = $this->client(function () use (&$starts) {
            $starts++;

            return $this->replyProcess();
        });
        $prepared = $runtime->context('fixture_echo', $this->definition);
        $this->policy['targets']['prod']['php'] = 'C:\\tools\\php84\\php.exe';
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $this->assertNotSame($prepared, $runtime->context('fixture_echo', $this->definition));
        try {
            $runtime->call('fixture_echo', ['text' => 'fixture'], $prepared, $this->definition);
            $this->fail('Stale binding ran.');
        } catch (RuntimeException) {
            $this->assertSame(0, $starts);
        }
    }

    public function test_private_windows_ini_and_dependency_pins_bind_the_existing_action_context(): void
    {
        $php = 'C:\\tools\\php84\\php.exe';
        $ini = "C:\\PLOS\\Operator's worker\\private.ini";
        $dll = 'C:\\tools\\php84\\ext\\php_pdo_sqlite.dll';
        $this->policy['targets']['prod'] = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11',
            'root' => "C:\\PLOS\\Operator's worker", 'production' => false, 'platform' => 'windows', 'php' => $php,
            'php_ini' => $ini, 'runtime_files' => [$php => str_repeat('a', 64), $ini => str_repeat('b', 64), $dll => str_repeat('c', 64)]];
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });
        $context = $runtime->context('fixture_echo', $this->definition);
        $result = $runtime->call('fixture_echo', ['text' => 'private ini'], $context, $this->definition);
        $this->assertSame('private ini', $result['result_text']);
        $remote = $launches[0]['args'][array_key_last($launches[0]['args'])];
        $program = mb_convert_encoding(base64_decode(substr($remote, strlen('powershell.exe -NoProfile -NonInteractive -EncodedCommand ')), true), 'UTF-8', 'UTF-16LE');
        $this->assertStringContainsString(" -c 'C:\\PLOS\\Operator''s worker\\private.ini' -d display_errors=stderr", $program);
        $this->assertStringContainsString('Get-FileHash -LiteralPath $pin.Name', $program);
        $this->assertLessThan(strpos($program, "& 'C:"), strpos($program, 'Get-FileHash'));
        $this->assertStringContainsString('$'."env:PHP_INI_SCAN_DIR='C:\\PLOS\\Operator''s worker\\private.ini'", $program);
        foreach ([$php, $ini, $dll] as $path) {
            $changed = $this->policy;
            $changed['targets']['prod']['runtime_files'][$path] = str_repeat('f', 64);
            $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $changed);
            $this->assertNotSame($context, $runtime->context('fixture_echo', $this->definition));
            try {
                $runtime->call('fixture_echo', ['text' => 'private ini'], $context, $this->definition);
                $this->fail('Stale runtime content pins ran.');
            } catch (RuntimeException) {
                $this->assertCount(1, $launches);
            }
        }
    }

    public function test_private_windows_ini_requires_all_exact_runtime_pins_and_refuses_non_windows_use(): void
    {
        $php = 'C:\\tools\\php84\\php.exe';
        $ini = 'C:\\PLOS\\worker\\private.ini';
        $dll = 'C:\\tools\\php84\\ext\\php_pdo_sqlite.dll';
        $target = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11', 'root' => 'C:\\PLOS\\worker', 'production' => false,
            'platform' => 'windows', 'php' => $php, 'php_ini' => $ini,
            'runtime_files' => [$php => str_repeat('a', 64), $ini => str_repeat('b', 64), $dll => str_repeat('c', 64)]];
        foreach ([['php_ini' => 'relative.ini'], ['php_ini' => 'C:\\worker.ini:stream'], ['php_ini' => 'C:\\worker.txt'],
            ['runtime_files' => [$php => str_repeat('a', 64)]], ['runtime_files' => [$php => str_repeat('a', 64), $ini => str_repeat('b', 64), $dll => 'invalid']],
            ['platform' => null]] as $change) {
            $policy = $this->policy;
            $policy['targets']['prod'] = array_replace($target, $change);
            try {
                CliFrameworkServicePolicy::validate($policy);
                $this->fail('Unsafe private PHP config admitted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_full_seventeen_file_windows_pin_set_survives_compression_below_shell_limit(): void
    {
        $paths = ['C:\\tools\\php84\\php.exe', 'C:\\tools\\php84\\ext\\php_pdo_sqlite.dll',
            'C:\\tools\\php84\\ext\\php_mbstring.dll', 'C:\\tools\\php84\\ext\\php_openssl.dll',
            'C:\\tools\\php84\\ext\\php_fileinfo.dll', 'C:\\tools\\php84\\php8.dll',
            'C:\\tools\\php84\\libsqlite3.dll', 'C:\\tools\\php84\\libcrypto-3-x64.dll', 'C:\\tools\\php84\\libssl-3-x64.dll'];
        $root = 'C:\\PLOS\\CLI\\releases\\worker-20260915\\runtime';
        foreach (['scripts/plos-cli/framework-service.php', 'vendor/autoload.php', 'integrity.php', 'worker-manifest.json',
            'bootstrap/app.php', 'verify-all-acl.ps1', 'scripts/plos-cli/windows-execution-environment.ps1'] as $relative) {
            $paths[] = $root.'\\'.str_replace('/', '\\', $relative);
        }
        $ini = 'C:\\PLOS\\CLI\\releases\\worker-20260915\\configuration\\private.ini';
        $paths[] = $ini;
        $pins = array_combine($paths, array_map(fn ($path) => hash('sha256', $path), $paths));
        $this->policy['targets']['prod'] = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11', 'root' => $root,
            'production' => false, 'platform' => 'windows', 'php' => $paths[0], 'php_ini' => $ini, 'runtime_files' => $pins];
        $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
        $launches = [];
        $runtime = $this->client(function ($launch) use (&$launches) {
            $launches[] = $launch;

            return $this->replyProcess();
        });
        $context = $runtime->context('fixture_echo', $this->definition);
        $result = $runtime->call('fixture_echo', ['text' => 'full pin set'], $context, $this->definition);
        $this->assertSame('full pin set', $result['result_text']);
        $remote = $launches[0]['args'][array_key_last($launches[0]['args'])];
        $this->assertLessThanOrEqual(7500, strlen($remote));
        $program = mb_convert_encoding(base64_decode(substr($remote, strlen('powershell.exe -NoProfile -NonInteractive -EncodedCommand ')), true), 'UTF-8', 'UTF-16LE');
        $this->assertSame(1, preg_match("/FromBase64String\\('([A-Za-z0-9+\\/=]+)'\\)/", $program, $match));
        $decoded = gzdecode(base64_decode($match[1], true));
        $this->assertSame($pins, json_decode($decoded, true, flags: JSON_THROW_ON_ERROR));
        $this->assertCount(17, json_decode($decoded, true));
        $this->assertStringContainsString('[byte[]]::new(32769)', $program);
        $this->assertStringContainsString('$n -gt 32768', $program);
        $this->assertStringContainsString('[Text.UTF8Encoding]::new(0,1)', $program);
        $this->assertStringContainsString('@($pins.PSObject.Properties).Count -ne 17', $program);
        $this->assertStringNotContainsString('full pin set', $program);
    }

    public function test_oversized_raw_or_encoded_windows_manifest_refuses_before_any_ssh_launch(): void
    {
        foreach (['decoded', 'encoded'] as $limit) {
            $php = 'C:\\tools\\php84\\php.exe';
            $ini = 'C:\\PLOS\\worker\\private.ini';
            $pins = [$php => hash('sha256', $php), $ini => hash('sha256', $ini)];
            for ($i = 0; $i < 60; $i++) {
                // Deterministic high-entropy fixture paths make the encoded
                // command exceed cmd.exe's limit without exceeding raw32KiB.
                $part = $limit === 'decoded' ? str_repeat('x', 600)
                    : hash('sha256', 'a'.$i).hash('sha256', 'b'.$i).hash('sha256', 'c'.$i);
                $pins['C:\\runtime\\'.$i.$part.'.dll'] = hash('sha256', (string) $i);
            }
            $this->policy['targets']['prod'] = ['transport' => 'ssh', 'host' => 'operator@10.23.45.11', 'root' => 'C:\\PLOS\\worker',
                'production' => false, 'platform' => 'windows', 'php' => $php, 'php_ini' => $ini, 'runtime_files' => $pins];
            $this->session = (new CliConfiguration($this->store))->set($this->session, 'cli.framework_services', $this->policy);
            $starts = 0;
            $runtime = $this->client(function () use (&$starts) {
                $starts++;

                return $this->replyProcess();
            });
            try {
                $runtime->call('fixture_echo', ['text' => 'fixture'], $runtime->context('fixture_echo', $this->definition), $this->definition);
                $this->fail('Oversized Windows launch ran.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString($limit === 'decoded' ? 'bounded launch format' : 'bounded command length', $error->getMessage());
                $this->assertSame(0, $starts);
            }
        }
    }

    public function test_parent_durable_control_retains_caller_and_pins_journal_before_launch(): void
    {
        $request = $this->durableRequest();
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $this->session = $this->store->saveSession($this->session);
        $sent = [];
        $runtime = $this->client(function (array $launch) use (&$sent): Process {
            $sent[] = json_decode($launch['input'], true, flags: JSON_THROW_ON_ERROR);

            return $this->replyProcess();
        });
        $context = $runtime->context('fixture_echo', $this->definition) + [
            '_cli_action_id' => 'original-action', '_cli_action_turn' => 'original-turn',
            '_cli_approval_scope' => ['configuration_sha256' => str_repeat('a', 64)],
        ];
        $runtime->call('fixture_echo', $request['arguments'], $context, $this->definition, remoteOperation: 'status');
        self::assertCount(1, $sent);
        self::assertSame('status', $sent[0]['remote_operation']);
        self::assertSame($request['worker_journal_sha256'], $sent[0]['worker_journal_sha256']);
        self::assertSame($this->session['id'], $sent[0]['caller_binding']['session_id']);
        self::assertSame('original-action', $sent[0]['caller_binding']['action_id']);
        $this->session['overrides']['cli.framework_services']['worker_receipts']['worker_id'] = 'changed';
        $this->session = $this->store->saveSession($this->session);
        try {
            $runtime->call('fixture_echo', $request['arguments'], $context, $this->definition, remoteOperation: 'status');
            self::fail('Changed journal dispatched with stale context.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('prepared execution target', $error->getMessage());
        }
        self::assertCount(1, $sent);
    }

    public function test_worker_journal_policy_accepts_real_identifiers_and_rejects_control_bytes(): void
    {
        $this->durableRequest();
        foreach (['C:\\PLOS-Worker-20260915-02\\state\\worker.sqlite', '/tmp/private worker/uuid-x00.sqlite'] as $path) {
            $policy = $this->policy;
            $policy['worker_receipts']['path'] = $path;
            $policy['worker_receipts']['session_id'] = '8b896e19-f746-4c0b-95a3-b37b2d50ee62';
            CliFrameworkServicePolicy::validate($policy);
            self::assertTrue(true);
        }
        foreach ([0, 1, 9, 10, 13, 31, 127] as $byte) {
            foreach (['path', 'session_id', 'turn_id', 'worker_id'] as $field) {
                $policy = $this->policy;
                $policy['worker_receipts'][$field] .= chr($byte).'tail';
                try {
                    CliFrameworkServicePolicy::validate($policy);
                    self::fail('A literal control byte was accepted in journal identity.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('journal identity', $error->getMessage());
                }
            }
        }
    }

    public function test_durable_remote_readback_accepts_live_wal_writer_and_rejects_shared_sidecar(): void
    {
        $request = $this->durableRequest();
        $this->worker()->execute($request);
        $path = $this->policy['worker_receipts']['path'];
        $writer = new \PDO('sqlite:'.$path);
        self::assertSame('wal', $writer->query('PRAGMA journal_mode=WAL')->fetchColumn());
        $writer->exec('BEGIN IMMEDIATE');
        $writer->exec('UPDATE cli_sessions SET revision=revision+1');
        try {
            $request['remote_operation'] = 'status';
            self::assertSame('result_recorded', $this->worker()->execute($request)['state']);
            self::assertSame(1, CliFrameworkFixture::$calls);
        } finally {
            $writer->exec('ROLLBACK');
        }
        self::assertFileExists($path.'-wal');
        chmod($path.'-wal', 0644);
        try {
            $this->worker()->execute($request);
            self::fail('A shared SQLite sidecar was admitted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('sidecars', $error->getMessage());
        } finally {
            chmod($path.'-wal', 0600);
        }
        self::assertSame('result_recorded', $this->worker()->execute($request)['state']);
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_durable_remote_two_real_processes_claim_once_without_duplicate_effects(): void
    {
        $request = $this->durableRequest();
        $input = $this->directory.'/race.json';
        file_put_contents($input, json_encode(['request' => $request, 'journal' => $this->policy['worker_receipts']], JSON_THROW_ON_ERROR));
        chmod($input, 0600);
        $script = $this->directory.'/race.php';
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
$data = json_decode(file_get_contents($argv[5]), true, flags: JSON_THROW_ON_ERROR);
$journal = $data['journal'];
$remote = new App\Services\UnifiedCli\CliFrameworkRemoteAction(new App\Services\UnifiedCli\CliStateStore($journal['path']), $journal);
echo "ready\n"; flush();
$deadline = microtime(true) + 5;
while (!is_file($argv[6])) {
    if (microtime(true) > $deadline) { exit(3); }
    usleep(1000);
}
try {
    $result = $remote->dispatch($data['request'], function () use ($argv): array {
        file_put_contents($argv[7], "one effect\n", FILE_APPEND | LOCK_EX);
        usleep(200000);
        return ['success' => true, 'result' => 'one synthetic effect'];
    });
    echo "completed\n";
} catch (App\Services\UnifiedCli\CliToolOutcomeUnknown) {
    echo "held\n";
}
PHP);
        $autoload = dirname((new \ReflectionClass(ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $source = fn (string $class): string => (new \ReflectionClass($class))->getFileName();
        $command = [PHP_BINARY, $script, $autoload, $source(CliStateStore::class), $source(CliFrameworkRemoteAction::class),
            $source(CliToolOutcomeUnknown::class), $input, $this->directory.'/go', $this->directory.'/effects'];
        $processes = [new Process($command, timeout: 10), new Process($command, timeout: 10)];
        try {
            foreach ($processes as $process) {
                $process->start();
                self::assertTrue($process->waitUntil(fn (string $type, string $bytes): bool => str_contains($bytes, "ready\n")));
            }
            touch($this->directory.'/go');
            $outputs = [];
            foreach ($processes as $process) {
                self::assertSame(0, $process->wait(), $process->getErrorOutput());
                $outputs[] = trim(str_replace("ready\n", '', $process->getOutput()));
            }
            sort($outputs);
            self::assertSame(['completed', 'held'], $outputs);
            self::assertSame("one effect\n", file_get_contents($this->directory.'/effects'));
            $request['remote_operation'] = 'status';
            $receipt = $this->worker()->execute($request);
            self::assertSame('result_recorded', $receipt['state']);
            self::assertFalse($receipt['automatic_replay_allowed']);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0, 9);
                }
            }
        }
    }

    public function test_private_mutable_journal_refuses_writable_ancestor_and_accepts_trusted_sticky_parent(): void
    {
        $ancestor = $this->directory.'/writable';
        $parent = $ancestor.'/private';
        mkdir($parent, 0700, true);
        chmod($ancestor, 0777);
        $path = $parent.'/journal.sqlite';
        touch($path);
        chmod($path, 0600);
        try {
            CliLocalBindings::assertPrivateSqlite($path);
            self::fail('Non-sticky writable ancestor permitted private directory replacement.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('untrusted replacement', $error->getMessage());
        }
        chmod($ancestor, 01777);
        CliLocalBindings::assertPrivateSqlite($path);
        self::assertTrue(true);
        chmod($parent, 0755);
        try {
            CliLocalBindings::assertPrivateSqlite($path);
            self::fail('Public journal parent was accepted.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('private parent', $error->getMessage());
        }
    }

    public function test_existing_control_survives_tool_revocation_and_cancelled_journal_without_dispatch(): void
    {
        $request = $this->durableRequest();
        $done = $this->worker()->execute($request);
        $journal = $this->policy['worker_receipts'];
        (new CliStateStore($journal['path']))->requestTurnCancellation($journal['session_id'], $journal['turn_id']);
        $this->policy['tools'] = [];
        $request['control_existing'] = true;
        $request['remote_operation'] = 'cancel';
        $request['approved'] = false;
        $worker = $this->worker(contract: fn () => throw new RuntimeException('Revoked registry must not be consulted.'));
        $saved = $worker->execute($request);
        self::assertSame($done['framework_remote_receipt']['result_sha256'], $saved['result_sha256']);
        self::assertSame('result_recorded', $saved['state']);
        self::assertFalse($saved['automatic_replay_allowed']);
        self::assertSame(1, CliFrameworkFixture::$calls);
        unset($request['control_existing']);
        $request['remote_operation'] = 'execute';
        $request['approved'] = true;
        $this->expectException(RuntimeException::class);
        $worker->execute($request);
    }

    public function test_existing_control_never_claims_absent_action_or_executes(): void
    {
        $request = $this->durableRequest();
        $request['control_existing'] = true;
        $request['approved'] = false;
        foreach (['status', 'cancel'] as $operation) {
            $request['remote_operation'] = $operation;
            self::assertSame('not_found', $this->worker()->execute($request)['state']);
        }
        $journal = $this->policy['worker_receipts'];
        self::assertSame([], (new CliStateStore($journal['path']))->actionReceipts($journal['session_id'], $journal['turn_id']));
        self::assertSame(0, CliFrameworkFixture::$calls);
        $request['remote_operation'] = 'execute';
        $request['approved'] = true;
        $this->expectException(RuntimeException::class);
        $this->worker()->execute($request);
    }

    public function test_durable_preclaim_cancel_survives_reconnect_without_tool_execution(): void
    {
        $request = $this->durableRequest();
        $control = $request;
        $control['control_existing'] = true;
        $control['approved'] = false;
        $control['remote_operation'] = 'cancel';
        $reply = $this->worker()->execute($control);
        self::assertSame('not_found', $reply['state']);
        self::assertTrue($reply['cancel_requested']);
        self::assertFalse($reply['process_tree_termination_verified']);
        CliFrameworkServices::validateReadback($reply, $control, 'synthetic_worker');
        $journal = $this->policy['worker_receipts'];
        self::assertSame([], (new CliStateStore($journal['path']))->actionReceipts($journal['session_id'], $journal['turn_id']));
        // Reopened real journal: the original approved dispatch sees prior intent.
        $result = $this->worker()->execute($request);
        self::assertFalse($result['success']);
        self::assertTrue($result['cancelled_before_dispatch']);
        self::assertSame(0, CliFrameworkFixture::$calls);
        $control['remote_operation'] = 'status';
        $reply = $this->worker()->execute($control);
        self::assertSame('cancelled_before_dispatch', $reply['state']);
        self::assertNull($reply['result_json']);
        CliFrameworkServices::validateReadback($reply, $control, 'synthetic_worker');
        self::assertSame(0, CliFrameworkFixture::$calls);
        $this->expectException(CliToolOutcomeUnknown::class);
        $this->worker()->execute($request);
    }

    public function test_existing_control_keeps_original_request_and_journal_identity(): void
    {
        $request = $this->durableRequest();
        $this->worker()->execute($request);
        $request['control_existing'] = true;
        $request['remote_operation'] = 'cancel';
        $request['approved'] = false;
        foreach (['scope', 'journal', 'arguments', 'root'] as $field) {
            $changed = $request;
            if ($field === 'scope') {
                $changed['caller_binding']['approval_scope_sha256'] = str_repeat('f', 64);
            }
            if ($field === 'journal') {
                $changed['worker_journal_sha256'] = str_repeat('e', 64);
            }
            if ($field === 'arguments') {
                $changed['arguments']['text'] = 'different';
                $changed['caller_binding']['arguments_sha256'] = hash('sha256', json_encode($changed['arguments']));
            }
            if ($field === 'root') {
                $changed['root'] = '/another-installation';
            }
            try {
                $this->worker()->execute($changed);
                self::fail('Changed identity controlled original action.');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_parent_reconnect_uses_saved_dispatch_after_revocation_and_refuses_host_drift(): void
    {
        $request = $this->durableRequest();
        $this->session['overrides']['cli.framework_services'] = $this->policy;
        $action = ['id' => 'original-action', 'name' => 'fixture_echo', 'arguments' => $request['arguments']];
        $turn = 'original-turn';
        $scope = ['configuration_sha256' => str_repeat('a', 64), 'instance_id' => 'fixture', 'model' => 'no-model'];
        $this->session['turns'] = [['id' => $turn, 'status' => 'inProgress']];
        $this->session['messages'] = [['role' => 'assistant', 'content' => null, 'tool_calls' => [
            ['id' => $action['id'], 'type' => 'function', 'function' => ['name' => $action['name'], 'arguments' => json_encode($action['arguments'])]],
        ]]];
        $this->session['message_turn_ids'] = [$turn];
        $this->session = $this->store->saveSession($this->session);
        $approval = $this->store->requestApproval($this->session['id'], $turn, $action, $scope);
        $this->store->decideApproval($this->session['id'], $approval['id'], true);
        $this->store->startAction($this->session['id'], $turn, $action, ['instance_id' => 'fixture', 'model' => 'none']);
        $sent = [];
        $runtime = $this->client(function ($launch) use (&$sent): Process {
            $request = json_decode($launch['input'], true);
            $sent[] = $request;
            if (($request['control_existing'] ?? false) === true) {
                $caller = $request['caller_binding'];
                $result = ['schema' => 'plos.cli.framework-remote-receipt.v1', 'success' => true,
                    'worker_id' => $this->policy['worker_receipts']['worker_id'],
                    'action_id' => hash('sha256', json_encode([$caller['session_id'], $caller['turn_id'], $caller['action_id']])),
                    'request_sha256' => hash('sha256', json_encode(array_diff_key($request, ['remote_operation' => true, 'approved' => true, 'control_existing' => true]))),
                    'state' => 'running_or_outcome_unknown', 'cancel_requested' => true, 'process_tree_termination_verified' => false,
                    'automatic_replay_allowed' => false, 'result_json' => null, 'result_sha256' => null];
                $packet = json_encode(['schema' => 'plos.cli.internal-operation.v1', 'completed' => true,
                    'stdout_base64' => base64_encode(json_encode(['schema' => 'plos.cli.framework-result.v1', 'result' => $result]))]);

                return new Process([PHP_BINARY, '-r', 'fgets(STDIN); echo base64_decode($argv[1]);', base64_encode($packet)]);
            }

            return $this->replyProcess();
        });
        $context = $runtime->context('fixture_echo', $this->definition) + [
            '_cli_action_id' => $action['id'], '_cli_action_turn' => $turn, '_cli_approval_scope' => $scope,
        ];
        $runtime->call('fixture_echo', $action['arguments'], $context, $this->definition);
        $this->session = $this->store->session($this->session['id']);
        $this->session['overrides']['cli.framework_services']['tools'] = [];
        $this->session['turns'][0]['status'] = 'interrupted';
        $this->session = $this->store->saveSession($this->session);
        $runtime->control($turn, $action['id'], 'cancel');
        self::assertCount(2, $sent);
        self::assertTrue($sent[1]['control_existing']);
        self::assertFalse($sent[1]['approved']);
        self::assertSame($sent[0]['caller_binding'], $sent[1]['caller_binding']);
        self::assertSame($sent[0]['arguments'], $sent[1]['arguments']);
        self::assertSame('started', $this->store->actionReceipts($this->session['id'], $turn)[$action['id']]['state']);
        $this->session['overrides']['cli.framework_services']['targets']['prod']['host'] = 'another-host';
        $this->store->saveSession($this->session);
        try {
            $runtime->control($turn, $action['id'], 'status');
            self::fail('Changed host used.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('binding changed', $error->getMessage());
        }
        self::assertCount(2, $sent);
        try {
            $runtime->control($turn, 'unknown-action', 'cancel');
            self::fail('Unknown original action used.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('No original', $error->getMessage());
        }
        self::assertCount(2, $sent);
    }

    public static function terminalWorkerFailures(): array
    {
        return ['direct' => ['direct'], 'wrapped' => ['wrapped'], 'ordinary lossless' => ['ordinary']];
    }

    #[DataProvider('terminalWorkerFailures')]
    public function test_actual_worker_preserves_dispatched_original_chain_and_started_durable_journal(string $kind): void
    {
        $this->definition['method'] = 'throwTerminalFailure';
        $request = $this->durableRequest();
        $stop = new InferenceAdmissionException('Synthetic native terminal UNKNOWN');
        $failure = match ($kind) {
            'direct' => $stop,
            'wrapped' => new RuntimeException('Synthetic wrapper', 0, $stop),
            default => new RuntimeException('Synthetic ordinary lossless failure'),
        };
        CliFrameworkFixture::$terminalFailure = $failure;
        $observed = null;
        try {
            $this->worker()->execute($request);
        } catch (CliToolOutcomeUnknown $error) {
            $observed = $error;
        } finally {
            CliFrameworkFixture::$terminalFailure = null;
        }
        self::assertNotNull($observed);
        self::assertInstanceOf(CliToolOutcomeUnknown::class, $observed->getPrevious());
        self::assertSame($failure, $observed->getPrevious()->getPrevious());
        self::assertSame("Tool 'fixture_echo' failed after dispatch; inspect its outcome before resuming.", $observed->getPrevious()->getMessage());
        self::assertSame($kind !== 'ordinary', InferenceAdmissionException::isAdmissionFailure($observed));
        $journal = $this->policy['worker_receipts'];
        $receipts = (new CliStateStore($journal['path']))->actionReceipts($journal['session_id'], $journal['turn_id']);
        self::assertCount(1, $receipts);
        self::assertSame('started', array_values($receipts)[0]['state']);
        self::assertSame(1, CliFrameworkFixture::$calls);
        $request['remote_operation'] = 'status';
        $saved = $this->worker()->execute($request);
        self::assertSame('running_or_outcome_unknown', $saved['state']);
        self::assertNull($saved['result_json']);
        self::assertFalse($saved['automatic_replay_allowed']);
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    private function durableRequest(string $text = 'exact durable result'): array
    {
        $journal = new CliStateStore($this->directory.'/worker.sqlite');
        $session = $journal->createSession(['framework_worker' => ['worker_id' => 'synthetic_worker', 'root' => realpath(base_path())],
            'turns' => [['id' => 'worker-turn', 'status' => 'inProgress']]]);
        $this->policy['worker_receipts'] = ['path' => $this->directory.'/worker.sqlite',
            'session_id' => $session['id'], 'turn_id' => 'worker-turn', 'worker_id' => 'synthetic_worker'];
        $this->policy['tools']['fixture_echo']['durable_receipts'] = true;
        $request = $this->request(['text' => $text]);
        $request['approved'] = true;
        $request['caller_binding'] = $this->callerBinding($request['arguments']);
        $request['remote_operation'] = 'execute';
        $request['worker_journal_sha256'] = hash('sha256', json_encode($this->policy['worker_receipts']));

        return $request;
    }

    public function test_worker_supervisor_does_not_change_unselected_durable_tools(): void
    {
        $request = $this->durableRequest();
        $pins = [];
        foreach (['plos-windows-sandbox.exe', 'codex-command-runner.exe', 'codex-windows-sandbox-setup.exe'] as $name) {
            $pins['C:\\Private\\bin\\'.$name] = hash('sha256', $name);
        }
        $this->policy['worker_receipts']['supervisor'] = ['helper' => 'C:\\Private\\bin\\plos-windows-sandbox.exe', 'runtime_files' => $pins];
        $request['worker_journal_sha256'] = hash('sha256', json_encode($this->policy['worker_receipts']));
        foreach (['unreviewed request', 'missing request', 'wrong type'] as $case) {
            $variant = $request;
            if ($case === 'unreviewed request') {
                $variant['supervised_process'] = true;
            } elseif ($case === 'missing request') {
                $this->policy['tools']['fixture_echo']['supervised_process'] = true;
            } else {
                $variant['supervised_process'] = 'true';
            }
            $variant['rule_sha256'] = hash('sha256', json_encode($this->policy['tools']['fixture_echo']));
            try {
                $this->worker()->execute($variant);
                self::fail('Mismatched supervisor selection executed.');
            } catch (RuntimeException) {
                self::assertSame(0, CliFrameworkFixture::$calls);
            }
            unset($this->policy['tools']['fixture_echo']['supervised_process']);
        }
        // These Windows binary pins must never be loaded by an unselected tool on Linux.
        $result = $this->worker()->execute($request);
        self::assertTrue($result['success']);
        self::assertFalse($result['framework_remote_receipt']['process_tree_termination_verified']);
        self::assertSame(1, CliFrameworkFixture::$calls);
        self::assertNull(Context::getHidden(CliWindowsActionProcess::CONTEXT));
        $request['remote_operation'] = 'status';
        $request['approved'] = false;
        $request['control_existing'] = true;
        $this->policy['tools'] = [];
        self::assertSame($result['framework_remote_receipt'], $this->worker()->execute($request));
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_durable_remote_result_reopens_without_registry_replay(): void
    {
        $request = $this->durableRequest("  exact José\r\n");
        $result = $this->worker()->execute($request);
        self::assertSame("  exact José\r\n", $result['result']);
        self::assertSame('result_recorded', $result['framework_remote_receipt']['state']);
        self::assertFalse($result['framework_remote_receipt']['process_tree_termination_verified']);
        $read = $request;
        $read['remote_operation'] = 'status';
        $read['approved'] = false; // Reading an existing claim does not create new execution authority.
        $saved = $this->worker()->execute($read);
        self::assertSame($result['framework_remote_receipt'], $saved);
        self::assertSame(hash('sha256', $saved['result_json']), $saved['result_sha256']);
        self::assertSame(1, CliFrameworkFixture::$calls);
        $this->expectException(CliToolOutcomeUnknown::class);
        $this->worker()->execute($request);
    }

    public function test_durable_remote_identity_rejects_changed_request_and_scope(): void
    {
        $request = $this->durableRequest();
        $this->worker()->execute($request);
        foreach (['arguments', 'scope'] as $change) {
            $read = $request;
            $read['remote_operation'] = 'status';
            if ($change === 'arguments') {
                $read['arguments']['text'] = 'changed';
                $read['caller_binding']['arguments_sha256'] = hash('sha256', json_encode($read['arguments']));
            } else {
                $read['caller_binding']['approval_scope_sha256'] = str_repeat('b', 64);
            }
            try {
                $this->worker()->execute($read);
                self::fail('Changed original authority returned a receipt.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('different request', $error->getMessage());
            }
        }
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    public function test_durable_remote_failure_retains_claim_and_cancel_is_only_intent(): void
    {
        $this->definition['method'] = 'inspectCaller';
        $request = $this->durableRequest('fail after caller inspection');
        try {
            $this->worker()->execute($request);
            self::fail('Unknown execution was completed.');
        } catch (CliToolOutcomeUnknown) {
            self::assertSame(1, CliFrameworkFixture::$calls);
        }
        $read = $request;
        $read['remote_operation'] = 'cancel';
        $cancel = $this->worker()->execute($read);
        self::assertSame('running_or_outcome_unknown', $cancel['state']);
        self::assertTrue($cancel['cancel_requested']);
        self::assertFalse($cancel['process_tree_termination_verified']);
        self::assertNull($cancel['result_json']);
        $read['remote_operation'] = 'status';
        self::assertSame($cancel, $this->worker()->execute($read));
        self::assertSame(1, CliFrameworkFixture::$calls);
        $this->expectException(CliToolOutcomeUnknown::class);
        $this->worker()->execute($request);
    }

    public function test_durable_remote_control_refuses_unapproved_and_legacy_rules_without_dispatch(): void
    {
        $request = $this->durableRequest();
        $request['approved'] = false;
        try {
            $this->worker()->execute($request);
            self::fail('Unapproved durable action ran.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('approved caller', $error->getMessage());
        }
        unset($this->policy['tools']['fixture_echo']['durable_receipts']);
        $request['rule_sha256'] = hash('sha256', json_encode($this->policy['tools']['fixture_echo']));
        try {
            $this->worker()->execute($request);
            self::fail('Legacy service accepted a remote control operation.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('explicitly enabled', $error->getMessage());
        }
        self::assertSame(0, CliFrameworkFixture::$calls);
    }

    public function test_durable_remote_terminal_commit_failure_stays_unknown_without_replay(): void
    {
        $request = $this->durableRequest();
        $db = new \PDO('sqlite:'.$this->policy['worker_receipts']['path']);
        $db->exec("CREATE TRIGGER fail_completion BEFORE UPDATE ON cli_action_receipts BEGIN SELECT RAISE(ABORT, 'synthetic terminal disk failure'); END");
        try {
            $this->worker()->execute($request);
            self::fail('An uncommitted terminal result was returned.');
        } catch (CliToolOutcomeUnknown) {
            self::assertSame(1, CliFrameworkFixture::$calls);
        }
        $read = $request;
        $read['remote_operation'] = 'status';
        $receipt = $this->worker()->execute($read);
        self::assertSame('running_or_outcome_unknown', $receipt['state']);
        self::assertNull($receipt['result_sha256']);
        $this->expectException(CliToolOutcomeUnknown::class);
        $this->worker()->execute($request);
    }

    public function test_durable_remote_claim_excludes_a_second_dispatch_before_first_result(): void
    {
        $request = $this->durableRequest();
        $journal = $this->policy['worker_receipts'];
        $first = new CliFrameworkRemoteAction(new CliStateStore($journal['path']), $journal);
        $second = new CliFrameworkRemoteAction(new CliStateStore($journal['path']), $journal);
        $calls = 0;
        $first->dispatch($request, function () use ($second, $request, &$calls): array {
            $calls++;
            try {
                $second->dispatch($request, function () use (&$calls): array {
                    $calls++;

                    return ['success' => true];
                });
                self::fail('A second connection dispatched an already claimed action.');
            } catch (CliToolOutcomeUnknown) {
                self::assertSame(1, $calls);
            }

            return ['success' => true, 'result' => 'first only'];
        });
        self::assertSame(1, $calls);
        $request['remote_operation'] = 'status';
        self::assertSame('result_recorded', $second->dispatch($request, static function (): array {
            throw new RuntimeException('Readback executed a tool.');
        })['state']);
    }

    public function test_durable_remote_cancel_before_dispatch_records_no_effect_and_blocks_later_execute(): void
    {
        $request = $this->durableRequest();
        $request['remote_operation'] = 'cancel';
        $receipt = $this->worker()->execute($request);
        self::assertSame('cancelled_before_dispatch', $receipt['state']);
        self::assertTrue($receipt['cancel_requested']);
        self::assertSame(0, CliFrameworkFixture::$calls);
        $request['remote_operation'] = 'status';
        self::assertSame($receipt, $this->worker()->execute($request));
        $request['remote_operation'] = 'execute';
        $this->expectException(CliToolOutcomeUnknown::class);
        $this->worker()->execute($request);
    }

    public function test_durable_remote_journal_change_refuses_before_claim_or_dispatch(): void
    {
        $request = $this->durableRequest();
        $this->policy['worker_receipts']['worker_id'] = 'changed_worker';
        try {
            $this->worker()->execute($request);
            self::fail('Changed journal used stale approval.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('journal differs', $error->getMessage());
        }
        self::assertSame(0, CliFrameworkFixture::$calls);
        $journal = new CliStateStore($this->policy['worker_receipts']['path']);
        self::assertSame([], $journal->actionReceipts($this->policy['worker_receipts']['session_id'], 'worker-turn'));
    }

    public function test_durable_remote_service_cannot_spoof_its_custody_receipt(): void
    {
        $request = $this->durableRequest();
        $journal = $this->policy['worker_receipts'];
        $remote = new CliFrameworkRemoteAction(new CliStateStore($journal['path']), $journal);
        try {
            $remote->dispatch($request, fn () => ['success' => true, 'framework_remote_receipt' => ['state' => 'invented']]);
            self::fail('Service result supplied authoritative custody evidence.');
        } catch (CliToolOutcomeUnknown) {
            $request['remote_operation'] = 'status';
            $receipt = $remote->dispatch($request, static function (): array {
                throw new RuntimeException('Readback executed.');
            });
            self::assertSame('running_or_outcome_unknown', $receipt['state']);
            self::assertNull($receipt['result_json']);
        }
    }

    public function test_durable_remote_status_does_not_claim_an_absent_action(): void
    {
        $request = $this->durableRequest();
        $request['remote_operation'] = 'status';
        self::assertSame('not_found', $this->worker()->execute($request)['state']);
        self::assertSame(0, CliFrameworkFixture::$calls);
        $request['remote_operation'] = 'execute';
        self::assertTrue($this->worker()->execute($request)['success']);
        self::assertSame(1, CliFrameworkFixture::$calls);
    }

    private function worker(?\Closure $contract = null, ?\Closure $reference = null): CliFrameworkServiceWorker
    {
        return new CliFrameworkServiceWorker(fn () => $this->policy,
            $contract ?? fn () => ['skill_found' => true, 'tools' => ['fixture_echo' => $this->definition]],
            fn ($provider, $confirmation) => new AgentToolRegistryService(toolProvider: $provider, callRecorder: fn () => null,
                losslessResults: true, confirmationVerifier: $confirmation, localServiceFactory: fn () => new CliFrameworkFixture),
            $reference);
    }

    private function client(\Closure $factory): CliFrameworkServices
    {
        return new CliFrameworkServices($this->store, $this->session['id'], base_path(), '/usr/bin/node', new CliArtifactStore($this->directory), $factory);
    }

    private function replyProcess(): Process
    {
        return new Process([PHP_BINARY, '-r', <<<'PHP'
            $launch = json_decode(fgets(STDIN), true);
            $request = json_decode($launch['input'], true);
            $result = ['schema' => 'plos.cli.framework-result.v1', 'result' => ['success' => true, 'result_text' => $request['arguments']['text']]];
            echo json_encode(['schema' => 'plos.cli.internal-operation.v1', 'completed' => true, 'stdout_base64' => base64_encode(json_encode($result))]);
            PHP]);
    }
}

class CliFrameworkFixture
{
    public static ?\Throwable $terminalFailure = null;

    public function throwTerminalFailure(string $text): string
    {
        self::$calls++;
        throw self::$terminalFailure ?? new RuntimeException('Synthetic missing terminal failure');
    }

    public static int $calls = 0;

    public static ?array $observedCaller = null;

    public static ?array $observedWorkflowScope = null;

    public function boundedFaceComparison(int $source_embedding_id, int $candidate_embedding_id, string $expected_input_digest): array
    {
        self::$calls++;
        self::$observedCaller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        self::$observedWorkflowScope = CliWorkflowExecutionScope::current();

        return ['success' => true, 'source_embedding_id' => $source_embedding_id,
            'candidate_embedding_id' => $candidate_embedding_id, 'expected_input_digest' => $expected_input_digest];
    }

    public function inspectCaller(string $text): string
    {
        self::$calls++;
        self::$observedCaller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        if ($text === 'fail after caller inspection') {
            throw new RuntimeException('Synthetic caller scope failure.');
        }

        return $text;
    }

    public function codexFailure(string $text): void
    {
        $observer = (new \ReflectionProperty(CodexExecConnectorService::class, 'outputObserver'))->getValue();
        $observer(['instance_id' => 'codex_exec', 'success' => false, 'timed_out' => true, 'exit_code' => 143,
            'error' => 'timeout', 'stdin_base64' => base64_encode($text), 'stdout_base64' => base64_encode('full transport'),
            'stderr_base64' => base64_encode('full diagnostic'), 'last_message_available' => true,
            'last_message_base64' => base64_encode($text)]);
        throw new RuntimeException('Synthetic domain failure after receiving process output');
    }

    public function fetchUrl(string $url): string
    {
        self::$calls++;

        return Http::get($url)->body();
    }

    public function option(bool $enabled = true): bool
    {
        self::$calls++;

        return $enabled;
    }

    public function echoOriginal(string $text): string
    {
        self::$calls++;

        return $text;
    }

    public function person(int $personId): int
    {
        self::$calls++;

        return $personId;
    }

    public function tree(int $treeId): int
    {
        self::$calls++;

        return $treeId;
    }
}
