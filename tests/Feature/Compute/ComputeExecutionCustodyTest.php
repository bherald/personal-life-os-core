<?php

namespace Tests\Feature\Compute;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Compute\ComputeExecutionCustodyService;
use App\Services\ComputeRouterRuntimePolicyService;
use App\Services\ComputeRouterService;
use App\Services\Custody\TaskCustodyService;
use App\Services\SystemConfigService;
use Illuminate\Foundation\MaintenanceModeManager;
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\FakesComputeExecutionCustody;
use Tests\TestCase;

class ComputeExecutionCustodyTest extends TestCase
{
    use FakesComputeExecutionCustody;

    private string $database;

    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installComputeProcessFactory();
        $this->database = tempnam(sys_get_temp_dir(), 'plos-compute-custody-');
        $this->previousConnection = DB::getDefaultConnection();
        config()->set('database.connections.compute_custody_test', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '']);
        DB::setDefaultConnection('compute_custody_test');
        DB::statement('CREATE TABLE compute_instances (instance_id TEXT PRIMARY KEY)');
        DB::statement('CREATE TABLE task_custody_records (id INTEGER PRIMARY KEY AUTOINCREMENT, surface TEXT, surface_ref TEXT, owner_token TEXT, acquired_at TEXT, expires_at TEXT, released_at TEXT, outcome TEXT, result_envelope TEXT, notification_state TEXT, progress_note TEXT, created_at TEXT, updated_at TEXT)');
        DB::statement('CREATE UNIQUE INDEX custody_active ON task_custody_records(surface,surface_ref) WHERE released_at IS NULL');
        DB::table('compute_instances')->insert(['instance_id' => 'fixture']);
        Cache::setDefaultDriver('array');
        config()->set('lock_ttls.compute_cpu', 1);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->previousConnection);
        DB::purge('compute_custody_test');
        unlink($this->database);
        parent::tearDown();
    }

    private function fixtureInstance(int $slots = 1): object
    {
        return (object) ['instance_id' => 'fixture', 'is_local' => true, 'max_concurrent' => $slots,
            'python_path' => '/usr/bin/python3', 'host' => '127.0.0.1'];
    }

    private function router(?ComputeExecutionCustodyService $custody = null): ComputeRouterService
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn(['cleanup_grace_seconds' => 1, 'output_max_bytes' => 1048576]);
        $router = Mockery::mock(ComputeRouterService::class, [new ComputeRouterRuntimePolicyService($config), $custody])->makePartial();
        $router->shouldReceive('recordSuccess', 'recordFailure')->andReturnNull();

        return $router;
    }

    private function command(string $source): string
    {
        return escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($source);
    }

    public function test_real_success_preserves_binary_streams_and_releases_exact_custody(): void
    {
        $input = str_repeat("  Full José\r\n\0\xff", 5000).' exact tail ';
        $observed = [];
        $router = $this->router();
        $command = $this->command('$data=stream_get_contents(STDIN);fwrite(STDOUT,$data);fwrite(STDERR,$data);');
        $result = ComputeRouterService::withRequiredInstance('fixture',
            fn () => (new ReflectionMethod(ComputeRouterService::class, 'executeWithInput'))->invoke($router, $this->fixtureInstance(), $command, $input, 2),
            function ($execution) use (&$observed) {
                $observed[] = $execution;
            });
        $this->assertTrue($result['success']);
        $this->assertSame($input, $result['output']);
        foreach (['stdin', 'stdout', 'stderr'] as $stream) {
            $this->assertSame($input, base64_decode($observed[0][$stream.'_base64'], true));
        }
        $row = DB::table('task_custody_records')->first();
        $this->assertNotNull($row->released_at);
        $receipt = json_decode($row->result_envelope, true)['terminal_receipt'];
        $this->assertTrue($receipt['cleanup_verified']);
        $this->assertArrayNotHasKey('stdout_base64', $receipt);
        $this->assertStringNotContainsString('Full José', $row->result_envelope);
        $this->assertFalse($router->isInstanceBusy($this->fixtureInstance()));
    }

    public function test_dispatch_binding_is_durably_observed_before_any_child_launch(): void
    {
        $router = $this->router();
        $command = $this->command('echo "complete";');
        $seen = null;
        $result = ComputeRouterService::withRequiredInstance('fixture',
            fn () => $router->execute($this->fixtureInstance(), $command, 2),
            dispatchObserver: function (array $binding) use (&$seen, $command): void {
                $row = DB::table('task_custody_records')->where('id', $binding['custody_id'])->first();
                $this->assertNotNull($row);
                $this->assertNull($row->released_at);
                $this->assertSame($row->owner_token, $binding['owner_token']);
                $this->assertSame($row->surface_ref, $binding['surface_ref']);
                $this->assertSame(hash('sha256', $command), $binding['command_sha256']);
                $this->assertArrayNotHasKey('terminal_receipt', json_decode($row->result_envelope, true));
                $seen = $binding;
            });
        $this->assertTrue($result['success']);
        $this->assertSame('complete', $result['output']);
        $this->assertSame('plos.compute_dispatch_binding.v1', $seen['schema']);
        $terminal = json_decode(DB::table('task_custody_records')->where('id', $seen['custody_id'])->value('result_envelope'), true)['terminal_receipt'];
        $this->assertSame($seen['command_sha256'], $terminal['command_sha256']);
        $this->assertSame($seen['owner_token'], $terminal['owner_token']);
    }

    public function test_failed_dispatch_observer_proves_no_child_and_preserves_typed_no_dispatch(): void
    {
        $marker = sys_get_temp_dir().'/plos-observer-no-child-'.bin2hex(random_bytes(8));
        $router = $this->router();
        $command = $this->command('file_put_contents('.var_export($marker, true).', "unexpected");');
        try {
            ComputeRouterService::withRequiredInstance('fixture', fn () => $router->execute($this->fixtureInstance(), $command, 2),
                dispatchObserver: static fn () => throw new \RuntimeException('Observer storage rejected'));
            $this->fail('Dispatch observer refusal must stop the runner.');
        } catch (InferenceAdmissionException) {
            $this->assertFileDoesNotExist($marker);
        }
        $row = DB::table('task_custody_records')->first();
        $terminal = json_decode($row->result_envelope, true)['terminal_receipt'];
        $this->assertSame('plos.compute_no_dispatch.v1', $terminal['schema']);
        $this->assertFalse($terminal['dispatched']);
        $this->assertNotNull($row->released_at);
        $this->assertSame('failure', $row->outcome);
    }

    public function test_real_timeout_has_terminal_proof_and_exact_partial_streams(): void
    {
        $input = "  partial\0\xff\r\n";
        $observed = [];
        $router = $this->router();
        $command = $this->command('$data=stream_get_contents(STDIN);fwrite(STDOUT,$data);fwrite(STDERR,$data);sleep(20);');
        $result = ComputeRouterService::withRequiredInstance('fixture',
            fn () => (new ReflectionMethod(ComputeRouterService::class, 'executeWithInput'))->invoke($router, $this->fixtureInstance(), $command, $input, 1),
            function ($execution) use (&$observed) {
                $observed[] = $execution;
            });
        $this->assertFalse($result['success']);
        $this->assertSame('process_exit_124', $result['error']);
        $this->assertSame($input, base64_decode($observed[0]['stdout_base64'], true));
        $this->assertSame($input, base64_decode($observed[0]['stderr_base64'], true));
        $this->assertNotNull(DB::table('task_custody_records')->first()->released_at);
    }

    public function test_unknown_transport_retains_slot_after_cache_expiry_and_does_not_erase_owner(): void
    {
        $router = $this->router();
        Process::fake(fn () => Process::result(output: 'partial', exitCode: 255));
        try {
            $router->execute($this->fixtureInstance(), 'echo fixture');
            $this->fail('Transport uncertainty was hidden.');
        } catch (InferenceAdmissionException $error) {
            $this->assertStringContainsString('UNKNOWN', $error->getMessage());
        }
        $row = DB::table('task_custody_records')->first();
        $this->assertNull($row->released_at);
        $this->assertNotEmpty($row->owner_token);
        $this->travel(120)->seconds();
        $this->assertTrue($router->isInstanceBusy($this->fixtureInstance()));
        $this->assertFalse($router->execute($this->fixtureInstance(), 'echo fixture')['success']);
        Process::assertRanTimes(fn () => true, 1);
        $this->assertTrue((new TaskCustodyService)->hasLiveOwner($row));
        $this->travelBack();
    }

    public function test_failed_unknown_publication_still_counts_exact_slot_and_preserves_other_cpu_slots(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('unknown')->andThrow(new \RuntimeException('synthetic DB outage'));
        $router = $this->router($custody);
        Process::fake(fn () => Process::result(output: '{}'));
        try {
            $router->execute($this->fixtureInstance(2), 'echo fixture');
            $this->fail('Receipt uncertainty was hidden.');
        } catch (InferenceAdmissionException) {
        }
        $this->travel(120)->seconds();
        $this->assertTrue($custody->occupied('fixture', 0));
        $this->assertFalse($custody->occupied('fixture', 1));
        $this->assertFalse($router->isInstanceBusy($this->fixtureInstance(2)));
        $this->assertTrue($router->acquireGpuLock($this->fixtureInstance(2)));
        $router->releaseGpuLock($this->fixtureInstance(2));
        Process::swap(new Factory);
        $independent = $this->router()->execute($this->fixtureInstance(2), 'printf independent');
        $this->assertTrue($independent['success']);
        $this->assertSame('independent', $independent['output']);
        $this->assertSame(1, DB::table('task_custody_records')->whereNull('released_at')->count());
        $this->travelBack();
    }

    public function test_receipt_publication_error_retains_custody_and_binary_observer_output(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('terminal')->andThrow(new \RuntimeException('synthetic publication failure'));
        $router = $this->router($custody);
        $observed = [];
        try {
            ComputeRouterService::withRequiredInstance('fixture', fn () => $router->execute($this->fixtureInstance(), 'printf fixture'),
                function ($execution) use (&$observed) {
                    $observed[] = $execution;
                });
            $this->fail('Publication error was hidden.');
        } catch (InferenceAdmissionException) {
        }
        $this->assertSame('fixture', base64_decode($observed[0]['stdout_base64'], true));
        $this->assertNull(DB::table('task_custody_records')->first()->released_at);
    }

    public static function invalidReceipts(): array
    {
        return [['', 0], ['{}', 0], ['{"schema":', 0], ['{}', 124], ['{}', 255]];
    }

    #[DataProvider('invalidReceipts')]
    public function test_missing_malformed_truncated_or_transport_receipt_cannot_release(string $bytes, int $exit): void
    {
        Process::fake(fn () => Process::result(output: $bytes, exitCode: $exit));
        $router = $this->router();
        try {
            $router->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('Unproven terminal result was admitted.');
        } catch (InferenceAdmissionException) {
        }
        $this->assertNull(DB::table('task_custody_records')->first()->released_at);
        $this->travel(120)->seconds();
        $this->assertFalse($router->acquireGpuLock($this->fixtureInstance()));
        Process::assertRanTimes(fn () => true, 1);
        $this->travelBack();
    }

    public function test_observer_error_keeps_custody_and_typed_no_replay(): void
    {
        $router = $this->router();
        try {
            ComputeRouterService::withRequiredInstance('fixture', fn () => $router->execute($this->fixtureInstance(), 'printf fixture'),
                fn () => throw new \RuntimeException('synthetic observer publication failure'));
            $this->fail('Observer error was hidden.');
        } catch (InferenceAdmissionException) {
        }
        $this->assertNull(DB::table('task_custody_records')->first()->released_at);
    }

    public function test_metrics_error_keeps_custody_and_typed_no_replay(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn([]);
        $router = Mockery::mock(ComputeRouterService::class, [new ComputeRouterRuntimePolicyService($config)])->makePartial();
        $router->shouldReceive('recordSuccess')->andThrow(new \RuntimeException('synthetic accounting failure'));
        try {
            $router->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('Metrics error was hidden.');
        } catch (InferenceAdmissionException) {
        }
        $this->assertNull(DB::table('task_custody_records')->first()->released_at);
    }

    public function test_custody_lookup_failure_holds_capacity_and_never_launches(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('occupied')->andThrow(new \RuntimeException('synthetic custody lookup failure'));
        $router = $this->router($custody);
        Process::fake();
        $this->assertTrue($router->isInstanceBusy($this->fixtureInstance()));
        $this->assertFalse($router->execute($this->fixtureInstance(), 'printf fixture')['success']);
        Process::assertNothingRan();
    }

    public function test_nonce_mismatch_and_wrong_command_hash_retain_exact_custody(): void
    {
        foreach (['owner_token', 'command_sha256'] as $index => $field) {
            DB::table('task_custody_records')->delete();
            $this->travel(120 * ($index + 1))->seconds();
            Process::fake(function ($process) use ($field) {
                $receipt = json_decode($this->fakeComputeTerminal($process, 'fixture')->output(), true);
                $receipt[$field] = 'wrong-synthetic-binding';

                return Process::result(json_encode($receipt));
            });
            try {
                $this->router()->execute($this->fixtureInstance(), 'printf fixture');
                $this->fail('Foreign terminal receipt was admitted.');
            } catch (InferenceAdmissionException) {
            }
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
            $this->travelBack();
        }
    }

    public function test_unsupported_supervisor_is_proven_no_dispatch_without_compute_health_penalty(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn([]);
        $router = Mockery::mock(ComputeRouterService::class, [new ComputeRouterRuntimePolicyService($config)])->makePartial();
        $router->shouldNotReceive('recordFailure', 'recordSuccess');
        Process::fake(function ($process) {
            $receipt = json_decode($this->fakeComputeTerminal($process, '', '', 125)->output(), true);
            $receipt['dispatched'] = false;

            return Process::result(json_encode($receipt));
        });
        $this->assertSame('compute_supervisor_unavailable', $router->execute($this->fixtureInstance(), 'printf fixture')['error']);
        $this->assertNotNull(DB::table('task_custody_records')->first()->released_at);
    }

    public function test_maintenance_refuses_new_dispatch_and_persisted_admission(): void
    {
        $this->app->instance('maintenance', new MaintenanceModeManager($this->app));
        $this->app->maintenanceMode()->activate(['time' => time()]);
        try {
            Process::fake();
            $this->assertFalse($this->router()->execute($this->fixtureInstance(), 'printf fixture')['success']);
            $this->assertNull((new ComputeExecutionCustodyService)->acquire('fixture', 0, 'synthetic-owner', 'synthetic-cache', 60));
            $this->assertSame(0, DB::table('task_custody_records')->count());
            Process::assertNothingRan();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_policy_migration_preserves_existing_settings_and_rollback_holds(): void
    {
        DB::statement('CREATE TABLE system_configs (id INTEGER PRIMARY KEY AUTOINCREMENT, section TEXT, config_key TEXT, config_value TEXT, updated_at TEXT)');
        $original = ['execution_timeout_seconds' => 99, 'cleanup_grace_seconds' => 2, 'operator_setting' => 'preserved'];
        DB::table('system_configs')->insert(['section' => 'compute', 'config_key' => 'router_runtime_policy', 'config_value' => json_encode($original)]);
        $migration = require database_path('migrations/2026_10_11_230500_register_compute_terminal_custody_policy.php');
        $migration->up();
        $policy = json_decode(DB::table('system_configs')->first()->config_value, true);
        foreach ($original as $key => $value) {
            $this->assertSame($value, $policy[$key]);
        }
        $this->assertSame(67108864, $policy['output_max_bytes']);
        $migration->down();
        $this->assertSame($policy, json_decode(DB::table('system_configs')->first()->config_value, true));
    }

    public function test_maintenance_race_publishes_owned_no_dispatch_and_releases_without_runner(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('dispatching')->andReturnUsing(function ($row) {
            (new ComputeExecutionCustodyService)->dispatching($row);
            $this->app->maintenanceMode()->activate(['time' => time()]);
        });
        Process::fake();
        try {
            $this->router($custody)->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('Maintenance race was ignored.');
        } catch (InferenceAdmissionException $error) {
            $this->assertStringContainsString('no command started', $error->getMessage());
            $row = DB::table('task_custody_records')->first();
            $this->assertNotNull($row->released_at);
            $this->assertFalse(json_decode($row->result_envelope, true)['terminal_receipt']['dispatched']);
            Process::assertNothingRan();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
        $this->assertFalse($this->router()->isInstanceBusy($this->fixtureInstance()));
    }

    public function test_no_dispatch_publication_failure_retains_owned_row_without_runner(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('dispatching')->andReturnUsing(function ($row) {
            (new ComputeExecutionCustodyService)->dispatching($row);
            $this->app->maintenanceMode()->activate(['time' => time()]);
        });
        $custody->shouldReceive('noDispatch')->andThrow(new \RuntimeException('synthetic no-dispatch DB failure'));
        Process::fake();
        try {
            $this->router($custody)->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('No-dispatch publication failure was hidden.');
        } catch (InferenceAdmissionException) {
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
            Process::assertNothingRan();
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }
    }

    public function test_hostile_transport_is_bounded_before_symfony_and_keeps_exact_custody(): void
    {
        $executable = tempnam(sys_get_temp_dir(), 'plos-hostile-transport-');
        file_put_contents($executable, "#!/usr/bin/python3\nimport os\nwhile True:\n os.write(1,b'X'*8192)\n os.write(2,b'Y'*8192)\n");
        chmod($executable, 0700);
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn(['output_max_bytes' => 1024, 'capture_transport_overhead_bytes' => 512, 'cleanup_grace_seconds' => 1]);
        $router = Mockery::mock(ComputeRouterService::class, [new ComputeRouterRuntimePolicyService($config)])->makePartial();
        $router->shouldNotReceive('recordSuccess', 'recordFailure');
        $instance = $this->fixtureInstance();
        $instance->python_path = $executable;
        $observed = [];
        try {
            ComputeRouterService::withRequiredInstance('fixture', fn () => $router->execute($instance, 'printf fixture'),
                function ($value) use (&$observed) {
                    $observed[] = $value;
                });
            $this->fail('Hostile transport was admitted.');
        } catch (InferenceAdmissionException) {
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
            $bytes = strlen(base64_decode($observed[0]['stdout_base64'], true)) + strlen(base64_decode($observed[0]['stderr_base64'], true));
            $this->assertLessThanOrEqual(1884, $bytes);
            $this->assertGreaterThan(0, $bytes);
            $this->assertFalse($observed[0]['success']);
        } finally {
            unlink($executable);
        }
    }

    public function test_no_dispatch_cas_cannot_release_foreign_owner_or_unknown_state(): void
    {
        $service = new ComputeExecutionCustodyService;
        $row = $service->acquire('fixture', 0, 'synthetic-owner', 'synthetic-cache', 60);
        $foreign = clone $row;
        $foreign->owner_token = 'foreign-owner';
        try {
            $service->noDispatch($foreign);
            $this->fail('Foreign no-dispatch publication was admitted.');
        } catch (InferenceAdmissionException) {
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
        }
        $service->unknown($row, 'synthetic-unknown');
        try {
            $service->noDispatch($row);
            $this->fail('UNKNOWN custody became no-dispatch.');
        } catch (InferenceAdmissionException) {
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
        }
    }

    public function test_quiet_callback_independently_refuses_fake_transport_over_combined_cap(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn(['output_max_bytes' => 1024, 'capture_transport_overhead_bytes' => 512]);
        $router = new ComputeRouterService(new ComputeRouterRuntimePolicyService($config));
        Process::fake(fn () => Process::result(output: str_repeat('a', 1000), errorOutput: str_repeat('b', 1000)));
        try {
            $router->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('Combined transport cap was bypassed.');
        } catch (InferenceAdmissionException) {
            $this->assertNull(DB::table('task_custody_records')->first()->released_at);
        }
        Process::assertRan(fn ($process) => $process->quietly);
    }

    public function test_receipt_decoded_combined_cap_is_checked_before_allocating_streams(): void
    {
        $config = Mockery::mock(SystemConfigService::class);
        $config->shouldReceive('get')->andReturn(['output_max_bytes' => 1024, 'capture_transport_overhead_bytes' => 8192]);
        $router = new ComputeRouterService(new ComputeRouterRuntimePolicyService($config));
        $receipt = ['schema' => 'plos.compute_terminal.v1', 'owner_token' => 'synthetic-owner',
            'command_sha256' => hash('sha256', 'fixture'), 'cleanup_verified' => true, 'dispatched' => true, 'exit_code' => 0,
            'stdout_base64' => base64_encode(str_repeat('a', 800)), 'stderr_base64' => base64_encode(str_repeat('b', 800))];
        ksort($receipt);
        $this->expectException(InferenceAdmissionException::class);
        $this->expectExceptionMessage('Compute terminal streams exceed their decoded capture bound; hold retained.');
        (new ReflectionMethod(ComputeRouterService::class, 'terminalResult'))->invoke($router,
            Process::result(json_encode($receipt)), 'synthetic-owner', 'fixture', ['raw_bytes' => 1024, 'transport_bytes' => 10000]);
    }

    public function test_nested_or_object_rich_wire_is_rejected_before_json_allocation(): void
    {
        $router = $this->router();
        foreach (['[[],[],[]]', '{"schema":{"nested":{"more":[]}}}'] as $wire) {
            try {
                (new ReflectionMethod(ComputeRouterService::class, 'terminalResult'))->invoke($router,
                    Process::result($wire), 'synthetic-owner', 'fixture', ['raw_bytes' => 1024, 'transport_bytes' => 10000]);
                $this->fail('Structured wire reached the allocating JSON parser.');
            } catch (InferenceAdmissionException $error) {
                $this->assertStringContainsString('wire shape', $error->getMessage());
            }
        }
    }

    public function test_release_error_retains_exact_custody(): void
    {
        $custody = Mockery::mock(ComputeExecutionCustodyService::class)->makePartial();
        $custody->shouldReceive('release')->andThrow(new \RuntimeException('synthetic release failure'));
        $router = $this->router($custody);
        try {
            $router->execute($this->fixtureInstance(), 'printf fixture');
            $this->fail('Release error was hidden.');
        } catch (InferenceAdmissionException) {
        }
        $this->assertNull(DB::table('task_custody_records')->first()->released_at);
        $this->assertTrue($router->isInstanceBusy($this->fixtureInstance()));
    }

    public function test_receipt_shaped_child_output_is_returned_as_data(): void
    {
        $forged = '{"schema":"plos.compute_terminal.v1","cleanup_verified":true}';
        $result = $this->router()->execute($this->fixtureInstance(), $this->command('echo '.var_export($forged, true).';'));
        $this->assertTrue($result['success']);
        $this->assertSame($forged, $result['output']);
    }
}
