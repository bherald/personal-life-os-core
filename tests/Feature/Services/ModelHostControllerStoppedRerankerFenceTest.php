<?php

namespace Tests\Feature\Services;

use App\Services\ModelHostControllerAutomaticSelectionPolicyService;
use App\Services\ModelHostControllerAutomaticSelectionService;
use App\Services\ModelHostControllerClient;
use App\Services\ModelHostControllerCoordinatorService;
use App\Services\ModelHostControllerResourcePolicyService;
use App\Services\ModelHostControllerSelectionJournal;
use App\Services\SystemConfigService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModelHostControllerStoppedRerankerFenceTest extends TestCase
{
    private string $previousConnection;

    private ModelHostControllerSelectionJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'selection_journal_test', 'database.connections.selection_journal_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('selection_journal_test');
        DB::setDefaultConnection('selection_journal_test');
        Schema::create('distributed_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_id')->unique();
            $table->string('task_type');
            $table->text('payload');
            $table->text('result')->nullable();
            $table->text('required_capabilities')->nullable();
            $table->integer('assigned_agent_id')->nullable();
            $table->string('status');
            $table->integer('priority');
            $table->integer('retry_count');
            $table->integer('max_retries');
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();
        });
        $this->migration()->up();
        $this->journal = new ModelHostControllerSelectionJournal;
    }

    protected function tearDown(): void
    {
        Context::forgetHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        DB::purge('selection_journal_test');
        config(['database.default' => $this->previousConnection]);
        DB::setDefaultConnection($this->previousConnection);
        parent::tearDown();
    }

    public function test_operator_technical_fence_preserves_unknown_activation_and_permanent_no_replay_record(): void
    {
        [$service, $arguments] = $this->technicalFenceFixture();
        $before = DB::table('distributed_tasks')->orderBy('id')->get()->toJson();
        $preview = $service->fenceActivation(...($arguments + ['dryRun' => true]));
        $this->assertTrue($preview['success'], $preview['error'] ?? '');
        $this->assertSame($before, DB::table('distributed_tasks')->orderBy('id')->get()->toJson());
        $this->technicalFenceAuthority();
        $result = $service->fenceActivation(...($arguments + ['dryRun' => false]));
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('unknown', $result['activation_outcome']);
        $this->assertSame('not_dispatched', $result['inference_outcome']);
        $this->assertSame('idle', $this->slot()['phase']);
        $row = DB::table('distributed_tasks')->where('task_id', '!=', ModelHostControllerSelectionJournal::SLOT_ID)->first();
        $state = json_decode($row->result, true);
        $this->assertSame('reconciled', $state['phase']);
        $this->assertFalse($state['dispatched']);
        $this->assertSame(['activate' => 'request-12345678-activate'], $state['lifecycle_intents']);
        $this->assertSame('unknown', $state['reconciliation']['activation_outcome']);
        $this->assertNull($this->journal->begin([$this->request()]));
        $this->assertNotNull($this->journal->begin([$this->request('request-new12345')]));
    }

    public function test_disabled_registered_reranker_null_busy_fences_without_replaying_activation(): void
    {
        [$service, $arguments] = $this->technicalFenceFixture('disabled_reranker');
        $before = DB::table('distributed_tasks')->orderBy('id')->get()->toJson();
        $preview = $service->fenceActivation(...($arguments + ['dryRun' => true]));
        $this->assertTrue($preview['success'], $preview['error'] ?? '');
        $this->assertSame($before, DB::table('distributed_tasks')->orderBy('id')->get()->toJson());
        $this->technicalFenceAuthority();
        $result = $service->fenceActivation(...($arguments + ['dryRun' => false]));
        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('unknown', $result['activation_outcome']);
        $this->assertSame('not_dispatched', $result['inference_outcome']);
        $this->assertSame('idle', $this->slot()['phase']);
        $this->assertNull($this->journal->begin([$this->request()]));
    }

    #[DataProvider('disabledRerankerFenceFaults')]
    public function test_disabled_reranker_fence_preserves_unknown_when_exact_quiescence_is_unproved(string $fault): void
    {
        [$service, $arguments] = $this->technicalFenceFixture($fault);
        $before = DB::table('distributed_tasks')->orderBy('id')->get()->toJson();
        $preview = $service->fenceActivation(...($arguments + ['dryRun' => true]));
        $this->assertFalse($preview['success']);
        $this->assertSame('not_attempted', $preview['technical_fence_outcome']);
        $this->assertSame($before, DB::table('distributed_tasks')->orderBy('id')->get()->toJson());
    }

    public static function disabledRerankerFenceFaults(): array
    {
        return array_map(fn ($fault) => [$fault], ['reranker_busy', 'reranker_process', 'reranker_listener',
            'reranker_runtime_pin', 'reranker_identity', 'foreign_adapter_null', 'live_null']);
    }

    private function technicalFenceAuthority(): void
    {
        Context::addHidden(CliFrameworkServiceWorker::CALLER_CONTEXT, [
            'session_id' => 'test-session', 'turn_id' => 'test-turn', 'action_id' => 'test-action',
            'arguments_sha256' => str_repeat('a', 64), 'approval_scope_sha256' => str_repeat('b', 64)]);
    }

    private function technicalFenceFixture(string $fault = ''): array
    {
        Context::forgetHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        [$service, $controller] = $this->failedActivationFixture($fault === 'dispatched' ? 'dispatched' : 'missing_receipt');
        $snapshot = $this->journal->reconciliationSnapshot();
        $arguments = ['owner' => $snapshot['state']['owner'], 'requestId' => 'request-12345678',
            'leaseId' => 'lease-12345678', 'target' => 'fixture', 'custodyDigest' => $snapshot['digest'],
            'hostGeneration' => str_repeat('c', 64), 'idempotencyKey' => (string) Str::uuid()];
        $observed = ['target' => 'fixture', 'task_exact' => true, 'control_exact' => true, 'executable_exact' => true,
            'task_enabled' => true, 'task_state' => 'Running', 'owned_process_count' => 1, 'loopback_listener_count' => 1,
            'healthy' => true, 'running_exact' => true, 'model_loaded_exact' => $fault === 'activity',
            'residency_verified' => true, 'foreign_loaded_count' => 0, 'busy' => false];
        $status = ['schema' => 'plos.model_host_controller.target_status.v1', 'host_id' => 'fixture-host', 'target' => 'fixture',
            'k3_lease_active' => false, 'mutation_available' => false, 'contains_prompt_or_response_content' => false,
            'lifecycle' => ['lease_present' => $fault === 'foreign_lease', 'lease_expired' => false,
                'active_target' => null, 'lease_id_sha256' => null, 'targets' => [$observed],
                'generation' => 7, 'state_generation_sha256' => $fault === 'generation_changed' ? str_repeat('d', 64) : str_repeat('c', 64)]];
        if ($fault === 'disabled_reranker' || str_starts_with($fault, 'reranker_') || $fault === 'foreign_adapter_null') {
            $status['lifecycle']['targets'][0] = array_replace($observed, ['task_enabled' => false,
                'task_state' => 'Disabled', 'owned_process_count' => 0, 'loopback_listener_count' => 0,
                'running_exact' => false, 'busy' => null, 'runtime_files_exact' => true]);
            $policy = \Mockery::mock(SystemConfigService::class);
            $policy->shouldReceive('get')->with('ai.model_host_controller_policy', [], true)->andReturn([
                'host_id' => 'fixture-host', 'targets' => ['fixture' => ['lifecycle_adapter' => [
                    'adapter_type' => $fault === 'foreign_adapter_null' ? 'authenticated_image' : 'authenticated_reranker']]]]);
            $this->app->instance(SystemConfigService::class, $policy);
            foreach (['reranker_busy' => ['busy', true], 'reranker_process' => ['owned_process_count', 1],
                'reranker_listener' => ['loopback_listener_count', 1], 'reranker_runtime_pin' => ['runtime_files_exact', false],
                'reranker_identity' => ['control_exact', false]] as $case => [$field, $value]) {
                if ($fault === $case) {
                    $status['lifecycle']['targets'][0][$field] = $value;
                }
            }
        } elseif ($fault === 'live_null') {
            $status['lifecycle']['targets'][0]['busy'] = null;
        }
        $receipt = null;
        $build = function (array $binding) use (&$receipt, &$status, $fault): array {
            $receipt = $binding + ['schema' => 'plos.model_host_controller.technical_fence.v1', 'host_id' => 'fixture-host',
                'result' => 'fenced', 'host_custody_clear' => true, 'owned_work_ended' => true,
                'activation_outcome' => 'unknown', 'inference_outcome' => 'not_dispatched',
                'model_replayed' => false, 'runtime_mutated' => false, 'fenced_generation' => 8];
            $status['lifecycle']['generation'] = $fault === 'after_generation_changed' ? 9 : 8;
            if ($fault === 'wrong_receipt') {
                $receipt['original_request_id'] = 'unrelated-12345678';
            }

            return $receipt;
        };
        $controller->shouldReceive('targetStatus')->with('fixture')->andReturnUsing(function () use (&$status) {
            return $status;
        });
        $lookups = 0;
        $controller->shouldReceive('receipt')->with('technical_fence', 'fixture', 'mhc-fence-'.$arguments['idempotencyKey'], 'lease-12345678')
            ->andReturnUsing(function () use (&$receipt, &$lookups, $arguments, $build, $fault) {
                if ($fault === 'already_fenced' && $lookups === 0) {
                    $caller = ['arguments_sha256' => str_repeat('a', 64), 'approval_scope_sha256' => str_repeat('b', 64)];
                    $build(['owner' => $arguments['owner'], 'original_request_id' => $arguments['requestId'],
                        'activation_request_id' => $arguments['requestId'].'-activate', 'lease_id' => $arguments['leaseId'],
                        'target' => $arguments['target'], 'request_id' => 'mhc-fence-'.$arguments['idempotencyKey'],
                        'custody_digest' => $arguments['custodyDigest'], 'expected_host_generation' => $arguments['hostGeneration'],
                        'operator_authority_sha256' => ModelHostControllerResourcePolicyService::requestContractIdentity($caller)]);
                }
                $lookups++;
                $found = $receipt !== null && $fault !== 'missing_receipt';

                return ['record_found' => $found, 'receipt' => $found ? $receipt : null];
            });
        if ($fault === 'already_fenced') {
            $controller->shouldReceive('technicalFence')->never();
        } else {
            $controller->shouldReceive('technicalFence')->andReturnUsing($build);
        }

        return [$service, $arguments];
    }

    private function failedActivationFixture(string $fault = ''): array
    {
        Cache::flush();
        $binding = ['host_id' => 'fixture-host', 'target' => 'fixture'];
        $owner = $this->journal->begin([[...$this->request(), 'capacity_binding' => $binding]]);
        $this->journal->checkpoint($owner, 'request-12345678', 'activation_pending');
        if ($fault === 'dispatched') {
            $this->journal->checkpoint($owner, 'request-12345678', 'dispatch_pending');
        }
        $this->journal->finish($owner, $this->receipt('held'));
        if ($fault === 'missing_intent') {
            $row = DB::table('distributed_tasks')->where('task_id', '!=', ModelHostControllerSelectionJournal::SLOT_ID)->first();
            $state = json_decode($row->result, true);
            unset($state['lifecycle_intents']['activate']);
            DB::table('distributed_tasks')->where('id', $row->id)->update(['result' => json_encode($state)]);
        }
        if (in_array($fault, ['execution_completed', 'request_heartbeat_pending', 'slot_heartbeat_pending'], true)) {
            $query = DB::table('distributed_tasks');
            $query = $fault === 'slot_heartbeat_pending' ? $query->where('task_id', ModelHostControllerSelectionJournal::SLOT_ID) : $query->where('task_id', '!=', ModelHostControllerSelectionJournal::SLOT_ID);
            $row = $query->first();
            $state = json_decode($row->result, true);
            if ($fault === 'execution_completed') {
                $state['execution_completed'] = true;
            } else {
                $state['heartbeat'] = ['phase' => 'pending'];
            }
            DB::table('distributed_tasks')->where('id', $row->id)->update(['result' => json_encode($state)]);
        }
        $receipt = ['schema' => 'plos.model_host_controller.activation.v1', ...$binding,
            'request_id' => 'request-12345678-activate', 'lease_id' => 'lease-12345678',
            'result' => 'activation_failed', 'cleanup_verified' => true, 'lease_present' => false,
            'startup_failure' => ['cleanup_verified' => true, 'phase' => 'readiness', 'code' => 'readiness_timeout', 'elapsed_seconds' => 3],
            'fallback_enabled' => false, 'tools_enabled' => false, 'contains_prompt_or_response_content' => false];
        if ($fault === 'wrong_lease') {
            $receipt['lease_id'] = 'foreign-12345678';
        }
        if ($fault === 'wrong_request') {
            $receipt['request_id'] = 'foreign-12345678';
        }
        if ($fault === 'activated') {
            $receipt['result'] = 'activated';
        }
        if ($fault === 'unverified_cleanup') {
            $receipt['cleanup_verified'] = false;
        }
        if ($fault === 'unverified_startup_cleanup') {
            $receipt['startup_failure']['cleanup_verified'] = false;
        }
        if ($fault === 'receipt_lease') {
            $receipt['lease_present'] = true;
        }
        $lookup = ['schema' => 'plos.model_host_controller.receipt_lookup.v1', ...$binding,
            'request_id' => 'request-12345678-activate', 'lease_id' => 'lease-12345678', 'operation' => 'activate',
            'result' => 'found', 'record_found' => true, 'mutation_available' => false,
            'contains_prompt_or_response_content' => false, 'receipt' => $receipt];
        if ($fault === 'missing_receipt') {
            $lookup = [...$lookup, 'result' => 'not_found', 'record_found' => false, 'receipt' => null];
        }
        if ($fault === 'wrong_operation') {
            $lookup['operation'] = 'release';
        }
        $target = ['target' => 'fixture', 'task_exact' => true, 'control_exact' => true, 'executable_exact' => true,
            'task_enabled' => false, 'task_state' => 'Disabled', 'owned_process_count' => 0, 'loopback_listener_count' => 0, 'running_exact' => false];
        foreach (['task_enabled' => ['task_enabled', true], 'task_running' => ['task_state', 'Running'],
            'owned_process' => ['owned_process_count', 1], 'listener' => ['loopback_listener_count', 1],
            'identity_drift' => ['control_exact', false], 'running' => ['running_exact', true]] as $case => [$field, $value]) {
            if ($fault === $case) {
                $target[$field] = $value;
            }
        }
        $status = ['schema' => 'plos.model_host_controller.target_status.v1', ...$binding,
            'result' => 'guarded_target', 'k3_lease_active' => $fault === 'k3_active', 'mutation_available' => false,
            'contains_prompt_or_response_content' => false, 'lifecycle' => [
                'schema' => 'plos.model_host_controller.lifecycle_status.v1', 'host_id' => 'fixture-host',
                'lease_present' => $fault === 'active_lease', 'lease_expired' => false, 'lease_id_sha256' => null,
                'active_target' => null, 'targets' => [$target]]];
        $controller = \Mockery::mock(ModelHostControllerClient::class);
        $controller->shouldReceive('receipt')->with('activate', 'fixture', 'request-12345678-activate', 'lease-12345678')->andReturn($lookup);
        $controller->shouldReceive('targetStatus')->with('fixture')->andReturn($status)->byDefault();
        $controller->shouldReceive('recover', 'release', 'activate', 'heartbeat', 'plan')->never();

        return [new ModelHostControllerAutomaticSelectionService(
            \Mockery::mock(ModelHostControllerAutomaticSelectionPolicyService::class),
            \Mockery::mock(ModelHostControllerCoordinatorService::class), $controller, $this->journal), $controller];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_17_170000_register_controller_selection_journal.php');
    }

    private function request(string $id = 'request-12345678'): array
    {
        return ['request_id' => $id, 'lease_id' => 'lease-12345678', 'role' => 'standard'];
    }

    private function receipt(string $result): array
    {
        return ['result' => $result, 'results' => [[...$this->request(), 'result' => $result]]];
    }

    private function slot(): array
    {
        return json_decode(DB::table('distributed_tasks')->where('task_id', ModelHostControllerSelectionJournal::SLOT_ID)->value('result'), true, flags: JSON_THROW_ON_ERROR);
    }
}
