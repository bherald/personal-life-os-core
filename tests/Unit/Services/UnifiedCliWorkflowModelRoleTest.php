<?php

namespace Tests\Unit\Services;

use App\Services\AIService;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\RegistrySnapshotValidator;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class UnifiedCliWorkflowModelRoleTest extends TestCase
{
    public function test_unavailable_role_reports_one_stream_error_without_model_io_and_restores_scope(): void
    {
        $events = AIService::withWorkflowModelRole('deep_offline',
            fn () => iterator_to_array((new WorkflowRolePipelineProbe)->processWithToolsStreaming('  Original source José  ')),
            static fn () => throw new RuntimeException('Synthetic workflow role unavailable'));
        $this->assertCount(1, $events);
        $this->assertSame(['type' => 'error', 'content' => 'Synthetic workflow role unavailable'], json_decode($events[0], true));
        $this->assertSame('standard', WorkflowRolePipelineProbe::capture('Original', 'standard')['model_role']);
    }

    public function test_scoped_role_reaches_real_pipeline_selection_and_restores_nested_and_failed_calls(): void
    {
        $text = str_repeat("  Original José workflow evidence\r\n\t", 3000).' exact tail  ';
        $probe = fn () => WorkflowRolePipelineProbe::capture($text, 'standard');
        $this->assertSame('standard', $probe()['model_role']);
        AIService::withWorkflowModelRole('deep_offline', function () use ($probe, $text): void {
            $result = $probe();
            $this->assertSame('deep_offline', $result['model_role']);
            $this->assertSame($text, $result['captured_prompt']);
            $this->assertTrue($result['sensitive_data']);
            $this->assertSame(2048, $result['max_tokens']);
            $this->assertSame('vision', WorkflowRolePipelineProbe::capture($text, 'vision')['model_role']);
            $this->assertSame('embedding', WorkflowRolePipelineProbe::capture($text, 'embedding')['model_role']);
            $this->assertSame('fast', AIService::withWorkflowModelRole('fast', $probe)['model_role']);
            $this->assertSame('deep_offline', $probe()['model_role']);
            $this->assertSame('standard', AIService::withWorkflowModelRole(null, $probe)['model_role']);
            try {
                AIService::withWorkflowModelRole('quality', static fn () => throw new RuntimeException('Synthetic workflow failure'));
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic workflow failure', $error->getMessage());
            }
            $this->assertSame('deep_offline', $probe()['model_role']);
        });
        $this->assertSame('standard', $probe()['model_role']);
    }

    public function test_role_override_can_be_saved_and_survives_sync_without_changing_registry_rows(): void
    {
        $tables = array_fill_keys(RegistrySnapshotValidator::REQUIRED_TABLES, []);
        $tables['system_configs'] = [['section' => 'cli', 'config_key' => 'workflow_model_role', 'config_value' => 'auto', 'data_type' => 'string']];
        $tables['llm_instances'] = [['id' => 1, 'instance_id' => 'fixture', 'config' => json_encode(['models' => ['deep_offline' => 'synthetic-model']])]];
        $snapshot = ['schema' => RegistrySnapshotValidator::SCHEMA, 'source' => 'fixture', 'exported_at' => gmdate('c'), 'tables' => $tables];
        $store = new CliStateStore(':memory:');
        $generation = $store->activate($snapshot);
        $session = $store->createSession(['generation' => $generation]);
        $configuration = new CliConfiguration($store);
        $session = $configuration->set($session, 'cli.workflow_model_role', 'deep_offline', save: true);
        $this->assertSame('deep_offline', $configuration->effective($session)['values']['cli.workflow_model_role']);
        $this->assertSame('deep_offline', $store->defaults()['cli.workflow_model_role']);
        $snapshot['exported_at'] = '2026-09-10T00:00:00Z';
        $store->activate($snapshot);
        $this->assertSame('deep_offline', $configuration->effective($store->session($session['id']))['values']['cli.workflow_model_role']);
        $this->assertSame('auto', $store->snapshot()['snapshot']['tables']['system_configs'][0]['config_value']);
        $session = $configuration->set($store->session($session['id']), 'cli.workflow_model_role', 'auto');
        $this->assertSame('auto', $configuration->effective($session)['values']['cli.workflow_model_role']);
        $this->expectException(InvalidArgumentException::class);
        $configuration->set($session, 'cli.workflow_model_role', 'unregistered_role');
    }
}

/** Stop at the real AIService model-selection boundary, before any model I/O. */
class WorkflowRolePipelineProbe extends AIService
{
    public function __construct() {}

    public function autoSelectModel(string $prompt, array $config = []): string
    {
        throw new WorkflowRoleCaptured($config + ['captured_prompt' => $prompt]);
    }

    public static function capture(string $prompt, string $role): array
    {
        try {
            return (new self)->process($prompt, ['auto_select_model' => true, 'model_role' => $role,
                'sensitive_data' => true, 'max_tokens' => 2048]);
        } catch (WorkflowRoleCaptured $capture) {
            return $capture->configuration;
        }
    }
}

class WorkflowRoleCaptured extends RuntimeException
{
    public function __construct(public readonly array $configuration)
    {
        parent::__construct('Captured pipeline model selection before inference.');
    }
}
