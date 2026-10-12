<?php

namespace Tests\Feature\Console;

use App\Services\WorkflowPortableDefinitionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowPortableDefinitionCommandTest extends TestCase
{
    private string $originalDefaultConnection;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = (string) config('database.default');
        config([
            'database.default' => 'workflow_portable_test',
            'database.connections.workflow_portable_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);
        DB::purge('workflow_portable_test');
        DB::setDefaultConnection('workflow_portable_test');
        $this->createSchema();
        $this->seedWorkflow();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        DB::purge('workflow_portable_test');
        config(['database.default' => $this->originalDefaultConnection]);
        DB::setDefaultConnection($this->originalDefaultConnection);

        parent::tearDown();
    }

    public function test_export_is_versioned_integrity_checked_and_omits_secrets(): void
    {
        $manifest = app(WorkflowPortableDefinitionService::class)->export('Portable Test');

        $this->assertSame('plos.portable_workflow.v2', $manifest['schema']);
        $this->assertSame('2.0', $manifest['dsl_version']);
        $this->assertTrue($manifest['posture']['secrets_included'] === false);
        $this->assertArrayNotHasKey('id', $manifest['workflow']);
        $this->assertArrayNotHasKey('workflow_id', $manifest['workflow']['nodes'][0]);
        $this->assertNull($this->entry($manifest['workflow']['defaults'], 'api_token')['value']);
        $this->assertTrue($this->entry($manifest['workflow']['defaults'], 'api_token')['requires_runtime_value']);
        $this->assertNull($this->entry($manifest['workflow']['nodes'][0]['configs'], 'api_key')['value']);
        $this->assertNull($manifest['workflow']['nodes'][0]['compensation_config']);
        $this->assertSame('hello', $this->entry($manifest['workflow']['nodes'][0]['configs'], 'prompt')['value']);

        $validation = app(WorkflowPortableDefinitionService::class)->validate($manifest);
        $this->assertSame('ready_with_warnings', $validation['status']);
        $this->assertSame('verified', $validation['integrity']['status']);
        $this->assertContains('active_state_not_imported', array_column($validation['warnings'], 'code'));
        $this->assertContains('secret_value_omitted', array_column($validation['warnings'], 'code'));
        $this->assertContains('runtime_value_required', array_column($validation['warnings'], 'code'));
    }

    public function test_export_command_previews_before_writing_private_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'plos-workflow-export-');
        $this->assertNotFalse($path);
        unlink($path);
        $this->temporaryFiles[] = $path;

        $exit = Artisan::call('workflow:export', [
            'name' => 'Portable Test',
            '--path' => $path,
            '--json' => true,
        ]);
        $preview = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('preview', $preview['status']);
        $this->assertTrue($preview['read_only']);
        $this->assertSame('plos.portable_workflow.v2', $preview['manifest']['schema']);
        $this->assertFileDoesNotExist($path);

        $exit = Artisan::call('workflow:export', [
            'name' => 'Portable Test',
            '--path' => $path,
            '--write' => true,
            '--json' => true,
        ]);
        $written = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('exported', $written['status']);
        $this->assertFalse($written['read_only']);
        $this->assertSame($path, $written['path']);
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777);

        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('plos.portable_workflow.v2', $manifest['schema']);
    }

    public function test_tampered_v2_definition_is_blocked(): void
    {
        $manifest = app(WorkflowPortableDefinitionService::class)->export('Portable Test');
        $manifest['workflow']['nodes'][0]['configs'][0]['value'] = 'tampered';

        $validation = app(WorkflowPortableDefinitionService::class)->validate($manifest);

        $this->assertSame('blocked', $validation['status']);
        $this->assertSame('mismatch', $validation['integrity']['status']);
        $this->assertContains('integrity_mismatch', array_column($validation['errors'], 'code'));
    }

    public function test_import_is_preview_first_confirmed_and_inactive(): void
    {
        $definitions = app(WorkflowPortableDefinitionService::class);
        $manifest = $definitions->export('Portable Test');
        $path = $this->writeManifest($manifest);
        $this->deleteSeedWorkflow();

        $exit = Artisan::call('workflow:import', ['file' => $path, '--json' => true]);
        $preview = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('planned', $preview['status']);
        $this->assertTrue($preview['read_only']);
        $this->assertSame(0, DB::table('workflows')->count());

        $exit = Artisan::call('workflow:import', [
            'file' => $path,
            '--execute' => true,
            '--json' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertSame(0, DB::table('workflows')->count());

        $exit = Artisan::call('workflow:import', [
            'file' => $path,
            '--execute' => true,
            '--confirm' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $exit);
        $this->assertSame('imported_inactive', $payload['status']);
        $this->assertFalse($payload['result']['active']);
        $workflow = DB::table('workflows')->where('name', 'Portable Test')->first();
        $this->assertNotNull($workflow);
        $this->assertSame(0, (int) $workflow->active);
        $this->assertSame(1, DB::table('workflow_nodes')->where('workflow_id', $workflow->id)->count());
        $this->assertSame(
            null,
            DB::table('workflow_defaults')
                ->where('workflow_id', $workflow->id)
                ->where('config_key', 'api_token')
                ->value('config_value')
        );
    }

    public function test_overwrite_is_blocked_when_execution_history_exists(): void
    {
        $definitions = app(WorkflowPortableDefinitionService::class);
        $manifest = $definitions->export('Portable Test');
        $workflowId = (int) DB::table('workflows')->where('name', 'Portable Test')->value('id');
        DB::table('workflow_runs')->insert([
            'workflow_id' => $workflowId,
            'status' => 'completed',
            'started_at' => now(),
        ]);

        $plan = $definitions->planImport($manifest, true);

        $this->assertSame('blocked', $plan['status']);
        $this->assertSame(1, $plan['existing']['execution_history_count']);
        $this->assertContains('overwrite_history_blocked', array_column($plan['errors'], 'code'));
    }

    public function test_legacy_v1_definition_is_normalized_with_warning_and_secret_omission(): void
    {
        $legacy = [
            'version' => '1.0',
            'workflow' => [
                'name' => 'Legacy Portable',
                'description' => null,
                'schedule' => null,
                'active' => true,
                'error_handling' => 'stop',
            ],
            'retry_config' => null,
            'defaults' => [
                ['config_key' => 'password', 'config_value' => 'private-value'],
            ],
            'nodes' => [[
                'node_type' => 'ResearchNode',
                'node_order' => 0,
                'configs' => [['config_key' => 'query', 'config_value' => 'public value']],
            ]],
        ];

        $validation = app(WorkflowPortableDefinitionService::class)->validate($legacy);

        $this->assertSame('ready_with_warnings', $validation['status']);
        $this->assertSame('unverified_legacy', $validation['integrity']['status']);
        $this->assertSame('plos.portable_workflow.v2', $validation['manifest']['schema']);
        $this->assertNull($validation['manifest']['workflow']['defaults'][0]['value']);
        $this->assertContains('legacy_v1_normalized', array_column($validation['warnings'], 'code'));
        $this->assertContains('secret_value_omitted', array_column($validation['warnings'], 'code'));
    }

    /** @param list<array<string,mixed>> $entries */
    private function entry(array $entries, string $key): array
    {
        foreach ($entries as $entry) {
            if (($entry['key'] ?? null) === $key) {
                return $entry;
            }
        }

        $this->fail("Missing portable entry: {$key}");
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(array $manifest): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plos-workflow-portable-');
        $this->assertNotFalse($path);
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function deleteSeedWorkflow(): void
    {
        DB::table('retry_backoff_intervals')->delete();
        DB::table('retry_configs')->delete();
        DB::table('workflow_node_configs')->delete();
        DB::table('workflow_nodes')->delete();
        DB::table('workflow_defaults')->delete();
        DB::table('workflows')->delete();
    }

    private function seedWorkflow(): void
    {
        DB::table('workflows')->insert([
            'name' => 'Portable Test',
            'description' => 'Portable workflow fixture',
            'schedule' => '0 5 * * *',
            'active' => true,
            'current_version' => 3,
            'error_handling' => 'stop',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $workflowId = (int) DB::getPdo()->lastInsertId();
        DB::table('workflow_defaults')->insert([
            ['workflow_id' => $workflowId, 'config_key' => 'region', 'config_value' => 'local'],
            ['workflow_id' => $workflowId, 'config_key' => 'api_token', 'config_value' => 'secret-token-value'],
        ]);
        DB::table('retry_configs')->insert([
            'workflow_id' => $workflowId,
            'max_attempts' => 3,
            'notify_on_failure' => 'pushover',
            'backoff_strategy' => 'exponential',
        ]);
        $retryId = (int) DB::getPdo()->lastInsertId();
        DB::table('retry_backoff_intervals')->insert([
            'retry_config_id' => $retryId,
            'attempt_number' => 1,
            'backoff_seconds' => 30,
        ]);
        DB::table('workflow_nodes')->insert([
            'workflow_id' => $workflowId,
            'node_type' => 'ResearchNode',
            'node_order' => 0,
            'timeout_seconds' => 120,
            'compensation_handler' => 'restore',
            'compensation_config' => json_encode(['password' => 'test-compensation-secret']),
            'created_at' => now(),
        ]);
        $nodeId = (int) DB::getPdo()->lastInsertId();
        DB::table('workflow_node_configs')->insert([
            ['workflow_node_id' => $nodeId, 'config_key' => 'prompt', 'config_value' => 'hello'],
            ['workflow_node_id' => $nodeId, 'config_key' => 'api_key', 'config_value' => 'node-secret'],
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('schedule')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('current_version')->default(1);
            $table->string('error_handling')->default('stop');
            $table->timestamps();
        });
        Schema::create('workflow_defaults', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('workflow_id');
            $table->string('config_key');
            $table->text('config_value')->nullable();
        });
        Schema::create('workflow_nodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('workflow_id');
            $table->string('node_type');
            $table->unsignedInteger('node_order');
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->string('compensation_handler')->nullable();
            $table->text('compensation_config')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('workflow_node_configs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('workflow_node_id');
            $table->string('config_key');
            $table->text('config_value')->nullable();
        });
        Schema::create('retry_configs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('workflow_id');
            $table->integer('max_attempts');
            $table->string('notify_on_failure');
            $table->string('backoff_strategy');
        });
        Schema::create('retry_backoff_intervals', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('retry_config_id');
            $table->unsignedInteger('attempt_number');
            $table->unsignedInteger('backoff_seconds');
        });
        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('workflow_id');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
        });
    }
}
