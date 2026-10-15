<?php

namespace Tests\Unit\Services;

use App\Services\ModelHostControllerPolicyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class OperatorAdoptionPacketApplicabilityTest extends TestCase
{
    private string $previous;

    private string $sourceRoot;

    private string $packetRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = DB::getDefaultConnection();
        $this->sourceRoot = base_path();
        $this->packetRoot = sys_get_temp_dir().'/plos-operator-packets-'.bin2hex(random_bytes(6));
        mkdir($this->packetRoot.'/config/local-engine-candidates', 0700, true);
        config(['database.connections.operator_packet_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'model_host_controller.operator_adoption_packet_paths' => []]);
        DB::setDefaultConnection('operator_packet_test');
        Schema::create('system_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('section');
            $table->string('config_key');
            $table->text('config_value');
        });
        app()->setBasePath($this->packetRoot);
        app()->instance('env', 'production');
    }

    protected function tearDown(): void
    {
        app()->setBasePath($this->sourceRoot);
        foreach (glob($this->packetRoot.'/config/local-engine-candidates/*') as $path) {
            unlink($path);
        }
        rmdir($this->packetRoot.'/config/local-engine-candidates');
        rmdir($this->packetRoot.'/config');
        rmdir($this->packetRoot);
        DB::purge('operator_packet_test');
        DB::setDefaultConnection($this->previous);
        parent::tearDown();
    }

    public function test_production_legacy_migrations_without_operator_packets_do_not_adopt_or_change_bindings(): void
    {
        $untouched = ['host_id' => 'fixture-other-host', 'targets' => ['fixture' => ['activation_allowed' => true]]];
        DB::table('system_configs')->insert(['section' => 'ai', 'config_key' => 'model_host_controller_policy',
            'config_value' => json_encode($untouched)]);
        $count = 0;
        foreach (glob($this->sourceRoot.'/database/migrations/*.php') as $path) {
            if (! str_contains(file_get_contents($path), 'operatorPacketsApplicable([')) {
                continue;
            }
            (require $path)->up();
            $count++;
        }
        $this->assertSame(17, $count);
        $this->assertSame($untouched, json_decode(DB::table('system_configs')->value('config_value'), true));
        $this->assertSame(1, DB::table('system_configs')->count());
    }

    public function test_partial_packet_set_refuses_before_adoption(): void
    {
        file_put_contents(base_path('config/local-engine-candidates/fixture-present.json'), '{}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing config/local-engine-candidates/fixture-missing.json');
        (new ModelHostControllerPolicyService)->operatorPacketsApplicable([
            'config/local-engine-candidates/fixture-present.json', 'config/local-engine-candidates/fixture-missing.json',
        ]);
    }

    public function test_explicit_configuration_missing_packet_refuses(): void
    {
        config(['model_host_controller.operator_adoption_packet_paths' => ['config/local-engine-candidates/fixture-missing.json']]);
        $this->expectException(RuntimeException::class);
        (new ModelHostControllerPolicyService)->operatorPacketsApplicable(['config/local-engine-candidates/fixture-missing.json']);
    }

    public function test_table_bound_admission_with_missing_packet_refuses_and_preserves_binding(): void
    {
        $binding = ['targets' => ['fixture' => ['activation_allowed' => true,
            'operator_adoption_packet_paths' => ['config/local-engine-candidates/fixture-missing.json']]]];
        DB::table('system_configs')->insert(['section' => 'ai', 'config_key' => 'model_host_controller_policy',
            'config_value' => json_encode($binding)]);
        try {
            (new ModelHostControllerPolicyService)->operatorPacketsApplicable(['config/local-engine-candidates/fixture-missing.json']);
            $this->fail('An admitted declaration cannot silently lose its packet.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing', $exception->getMessage());
        }
        $this->assertSame($binding, json_decode(DB::table('system_configs')->value('config_value'), true));
    }

    public function test_complete_packet_set_only_enables_existing_migration_guards(): void
    {
        $path = 'config/local-engine-candidates/fixture-present.json';
        file_put_contents(base_path($path), '{}');
        $this->assertTrue((new ModelHostControllerPolicyService)->operatorPacketsApplicable([$path]));
        $this->assertSame(0, DB::table('system_configs')->count());
    }
}
