<?php

namespace Tests\Unit\Services;

use App\Services\ModelHostControllerPolicyService;
use App\Services\ModelHostControllerResourcePolicyService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ColibriTaskLogonContractTest extends TestCase
{
    private function spec(): array
    {
        return ['adapter_type' => 'colibri_gateway', 'resource_mode' => 'ram_commit_c',
            'control_roots' => [], 'required_process_arguments' => [], 'native_tools_enabled' => true,
            'control_sha256' => str_repeat('a', 64), 'executable_sha256' => str_repeat('b', 64),
            'task_arguments' => '-File fixture.ps1', 'gateway_sha256' => str_repeat('c', 64),
            'runtime_receipt_sha256' => str_repeat('d', 64)];
    }

    private function project(mixed $logon, bool $declared = true): array
    {
        $adapter = array_intersect_key($this->spec(), array_flip(['adapter_type', 'resource_mode', 'control_roots', 'required_process_arguments', 'native_tools_enabled']));
        if ($declared) {
            $adapter['task_logon_type'] = $logon;
        }
        $policy = ['schema' => 'plos.model_host_controller_policy.v1', 'host_id' => 'fixture-host',
            'execution_mode' => 'serial', 'concurrent_inference_enabled' => false, 'compatible_residency_enabled' => false,
            'lease_ttl_seconds' => 900, 'max_switches_per_hour' => 4, 'default_target' => 'fixture-target',
            'targets' => ['fixture-target' => ['model' => 'fixture-model', 'engine' => 'colibri', 'state' => 'production',
                'activation_allowed' => true, 'roles' => ['deep_offline'], 'resource_group' => 'fixture-group',
                'startup_timeout_seconds' => 300, 'switch_budget_exempt' => false, 'lifecycle_adapter' => $adapter]]];
        $manifest = ['host_id' => 'fixture-host', 'execution_mode' => 'serial', 'compatible_residency_enabled' => false,
            'concurrent_inference_enabled' => false, 'targets' => ['fixture-target' => $this->spec()]];

        return (new ModelHostControllerPolicyService(fn () => $policy, fn () => 'fixture-host'))->projectLifecycleControls($manifest)['targets']['fixture-target'];
    }

    public function test_exact_logon_type_projects_from_table_and_binds_capacity(): void
    {
        $s4u = $this->project('S4U');
        $this->assertSame('S4U', $s4u['task_logon_type']);
        $this->assertSame($this->spec()['task_arguments'], $s4u['task_arguments']);
        $this->assertNotSame(ModelHostControllerResourcePolicyService::runtimeIdentity($this->spec()),
            ModelHostControllerResourcePolicyService::runtimeIdentity($s4u));
    }

    public function test_explicit_legacy_interactive_preserves_previous_capacity_identity(): void
    {
        $this->assertSame(ModelHostControllerResourcePolicyService::runtimeIdentity($this->spec()),
            ModelHostControllerResourcePolicyService::runtimeIdentity($this->project('Interactive')));
        $this->assertArrayNotHasKey('task_logon_type', $this->project(null, false));
    }

    #[DataProvider('badTypes')]
    public function test_undeclared_logon_modes_refuse_projection(mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task logon binding');
        $this->project($value);
    }

    public static function badTypes(): array
    {
        return [[null], [false], ['s4u'], ['ServiceAccount'], ['Password']];
    }
}
