<?php

namespace Tests\Unit\Services;

use App\Services\AgentToolRegistryService;
use App\Services\RepoDevMCPService;
use App\Services\RepoEditRecoveryService;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliFrameworkRemoteAction;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UpstreamPrivatePreparationAdmissionService as Admission;
use App\Services\UpstreamPrivatePreparationWorkerService as Worker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/** Dedicated per-test SQLite and temp files; model/tool callbacks are deterministic. */
class UpstreamPrivatePreparationWorkerServiceTest extends TestCase
{
    private string $connection;

    private string $base;

    private string $root;

    private string $db;

    private string $key;

    private array $grant;

    private array $plan;

    private array $runtime;

    private array $trace = [];

    private array $journalDirectories = [];

    private Admission $admission;

    private RepoDevMCPService $repo;

    private RepoEditRecoveryService $recovery;

    private CliArtifactStore $artifacts;

    private int $tokens = 5;

    private bool $toolAllowed = true;

    private bool $failTransport = false;

    private bool $tamperTerminal = false;

    private bool $badRollback = false;

    private bool $verificationPass = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = DB::getDefaultConnection();
        $this->base = base_path();
        $this->root = sys_get_temp_dir().'/plos-upstream-worker-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/app', 0700, true);
        mkdir($this->root.'/tests', 0700);
        file_put_contents($this->root.'/tests/Verify.php', '<?php // fixed protected fixture');
        file_put_contents($this->root.'/app/Prepared.php', '<?php // original');
        app()->setBasePath($this->root);
        $this->db = $this->root.'/test.sqlite';
        touch($this->db);
        config()->set('database.connections.upstream_worker_test', ['driver' => 'sqlite', 'database' => $this->db, 'prefix' => '']);
        DB::setDefaultConnection('upstream_worker_test');
        Queue::fake();
        Http::preventStrayRequests();
        Schema::create('system_configs', function (Blueprint $t): void {
            $t->id();
            $t->string('section');
            $t->string('config_key');
            $t->text('config_value');
            $t->string('data_type')->nullable();
            $t->text('description')->nullable();
            $t->timestamps();
            $t->unique(['section', 'config_key']);
        });
        Schema::create('distributed_agents', function (Blueprint $t): void {
            $t->id();
            $t->string('agent_id');
            $t->string('node_name');
            $t->string('status');
        });
        Schema::create('distributed_tasks', function (Blueprint $t): void {
            $t->id();
            $t->string('task_id');
            $t->string('task_type');
            $t->string('status');
            $t->integer('retry_count');
            $t->integer('assignment_generation');
            $t->integer('assigned_agent_id');
            $t->text('payload');
            $t->text('result')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('task_custody_records', function (Blueprint $t): void {
            $t->id();
            $t->string('surface');
            $t->string('surface_ref');
            $t->string('owner_token');
            $t->timestamp('expires_at');
            $t->timestamp('released_at')->nullable();
            $t->string('outcome')->nullable();
            $t->text('result_envelope')->nullable();
            $t->string('notification_state')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('agent_review_queue', function (Blueprint $t): void {
            $t->id();
            $t->string('agent_id');
            $t->string('review_type');
            $t->string('status');
            $t->integer('priority');
            $t->text('title');
            $t->text('summary');
            $t->text('details');
            $t->string('token');
            $t->timestamps();
        });
        $this->key = 'upstream_source_work_'.str_repeat('a', 64);
        $this->artifacts = new CliArtifactStore($this->root.'/artifacts');
        $original = str_repeat('Complete unchanged UTF8 source é. ', 150).'CRITICAL FULL TAIL';
        $source = $this->artifacts->captureBytes($original);
        $messages = [['role' => 'user', 'content' => $original]];
        $this->plan = ['artifact_directory' => $this->root.'/artifacts', 'schema' => 'plos.upstream_worker_plan.v1', 'messages' => $messages,
            'messages_sha256' => $this->hash($messages), 'source_inputs' => [$source + ['message_index' => 0]],
            'skill' => 'fixture', 'actions' => [['name' => 'patch', 'arguments' => ['patch' => 'exact fixture patch']]],
            'verification' => ['runner' => 'phpunit-target', 'target' => 'tests/Verify.php', 'timeoutSeconds' => 10],
            'verification_sha256' => hash_file('sha256', $this->root.'/tests/Verify.php'), 'schema_isolation_qualified' => true];
        $work = ['status' => 'preparation_pending', 'check_key' => 'ops.fixture_check', 'original_source' => $original,
            'owner' => 'fixture-owner', 'public_approval' => ['status' => 'not_requested']];
        $this->save($this->key, $work);
        $this->save('fixture_check', ['source' => 'exact']);
        $this->save(Admission::POLICY_KEY, ['enabled' => true, 'worker_execution_enabled' => true]);
        $this->grant = ['work_key' => $this->key, 'owner_session' => 'fixture-session', 'task_id' => 'fixture-task',
            'agent_id' => 'fixture-agent', 'node_name' => 'fixture-node', 'attempt' => 0, 'assignment_generation' => 1,
            'session_custody_id' => 1, 'session_owner_token' => 'fixture-owner',
            'workspace' => ['worktree_root' => $this->root], 'work_sha256' => hash('sha256', $this->raw($this->key)),
            'check_sha256' => hash('sha256', $this->raw('fixture_check')),
            'budgets' => ['wall_seconds' => 120, 'input_tokens' => 4096, 'output_tokens' => 128, 'changed_files' => 2, 'artifact_bytes' => 65536],
            'worker_plan' => $this->plan];
        $this->saveGrant();
        DB::table('distributed_agents')->insert(['id' => 1, 'agent_id' => 'fixture-agent', 'node_name' => 'fixture-node', 'status' => 'busy']);
        DB::table('distributed_tasks')->insert(['task_id' => 'fixture-task', 'task_type' => Admission::TASK_TYPE, 'status' => 'running',
            'retry_count' => 0, 'assignment_generation' => 1, 'assigned_agent_id' => 1, 'payload' => $this->payload()]);
        DB::table('task_custody_records')->insert(['id' => 1, 'surface' => 'agent_session', 'surface_ref' => 'fixture-session',
            'owner_token' => 'fixture-owner', 'expires_at' => '2050-01-01']);
        Context::addHidden(CliFrameworkServiceWorker::CALLER_CONTEXT, ['session_id' => 'fixture-session']);
        $this->admission = Mockery::mock(Admission::class);
        $this->admission->shouldReceive('previewWorkerAdmission')->andReturn(['binding_verified' => true]);
        $this->admission->shouldReceive('begin')->andReturnUsing(function (): array {
            $this->trace[] = 'begin';
            $reservation = ['work_key' => $this->key, 'owner_session' => 'fixture-session', 'outcome' => 'unknown'];
            $this->save($this->reservationKey(), $reservation);
            $receipt = ['phase' => 'dispatch_intent', 'outcome' => 'unknown', 'owner_session' => 'fixture-session',
                'admission_sha256' => $this->hash($this->grant), 'workspace_reservation_sha256' => hash('sha256', $this->raw($this->reservationKey()))];
            $this->save($this->receiptKey(), $receipt);

            return ['status' => 'intent_recorded', 'receipt_sha256' => hash('sha256', $this->raw($this->receiptKey())), 'admission_sha256' => $this->hash($this->grant)];
        });
        $this->admission->shouldReceive('markUnknown')->andReturn(['status' => 'hold']);
        $this->repo = Mockery::mock(RepoDevMCPService::class);
        $this->repo->shouldReceive('applyRepoPatch')->andReturn(['paths' => ['app/Prepared.php'], 'check' => ['success' => true]]);
        $this->repo->shouldReceive('runVerification')->andReturnUsing(fn () => ['result' => ['success' => $this->verificationPass]]);
        $this->recovery = Mockery::mock(RepoEditRecoveryService::class);
        $this->recovery->shouldReceive('load')->andReturnUsing(fn ($token) => ['token' => $token, 'files' => [['path' => 'app/Prepared.php',
            'after' => ['sha256' => $this->badRollback ? str_repeat('f', 64) : hash_file('sha256', $this->root.'/app/Prepared.php')]]]]);
        $round = 0;
        $route = ['agent_admitted' => true, 'instance_id' => 'fixture-instance', 'model' => 'fixture-model',
            'context_window' => 8192, 'max_output_tokens' => 64, 'max_iterations' => 2, 'max_tool_calls' => 1];
        $this->runtime = ['privacy_admitted' => true, 'artifacts' => $this->artifacts, 'route' => $route, 'methods' => ['patch' => 'applyRepoPatch'],
            'tools' => [['type' => 'function', 'function' => ['name' => 'patch', 'parameters' => ['type' => 'object',
                'properties' => ['patch' => ['type' => 'string']], 'required' => ['patch'], 'additionalProperties' => false]]]],
            'counter' => function ($route, $messages): int {
                $this->trace[] = 'count';
                $this->assertStringContainsString('CRITICAL FULL TAIL', $messages[0]['content']);

                return $this->tokens;
            },
            'admit' => function ($kind): bool {
                $this->trace[] = 'admit_'.$kind;

                return $kind === 'model' || $this->toolAllowed;
            },
            'transport' => function () use (&$round, $route): array {
                $this->trace[] = 'model';
                if ($this->failTransport) {
                    throw new \RuntimeException('Private provider failure should not leak.');
                }
                $round++;
                if ($round === 2 && $this->tamperTerminal) {
                    DB::table('task_custody_records')->update(['owner_token' => 'foreign']);
                }

                return ['instance_id' => $route['instance_id'], 'model' => $route['model'], 'completed' => true,
                    'content' => $round === 1 ? null : 'Prepared; verify independently.',
                    'tool_calls' => $round === 1 ? [['id' => 'action-1', 'type' => 'function', 'function' => [
                        'name' => 'patch', 'arguments' => json_encode(['patch' => 'exact fixture patch'])]]] : []];
            },
            'execute' => function (): array {
                $this->trace[] = 'execute';
                file_put_contents($this->root.'/app/Prepared.php', '<?php // prepared');

                return ['success' => true, 'output' => 'Exact private result'];
            },
            'journal' => function ($phase): bool {
                $this->trace[] = 'journal_'.$phase;

                return true;
            },
            'checkpoint' => fn ($messages) => $this->artifacts->captureBytes(json_encode($messages, JSON_THROW_ON_ERROR)),
            'model_readback' => fn () => [['document' => ['event' => ['completed' => true, 'instance_id' => 'fixture-instance', 'model' => 'fixture-model',
                'evidence' => ['request' => $this->artifacts->captureBytes('complete request'), 'response' => $this->artifacts->captureBytes('complete response')]]]]],
            'readback' => fn () => [['success' => true, 'result' => ['applied' => true, 'recovery' => ['token' => 'fixture-recovery']]]]];
    }

    protected function tearDown(): void
    {
        Context::forgetHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        app()->setBasePath($this->base);
        DB::setDefaultConnection($this->connection);
        DB::purge('upstream_worker_test');
        foreach ($this->journalDirectories as $directory) {
            foreach (new \FilesystemIterator($directory) as $file) {
                unlink($file->getPathname());
            }
            rmdir($directory);
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_all_full_source_model_and_tool_admission_precedes_intent_and_real_lossless_loop(): void
    {
        $original = json_decode($this->raw($this->key), true)['original_source'];
        $result = $this->runWorker();
        $this->assertSame('private_prepared', $result['status']);
        $this->assertLessThan(array_search('begin', $this->trace), array_search('admit_tool', $this->trace));
        $this->assertLessThan(array_search('model', $this->trace), array_search('begin', $this->trace));
        $this->assertLessThan(array_search('execute', $this->trace), array_search('journal_start', $this->trace));
        $this->assertSame('prepared', json_decode($this->raw($this->receiptKey()), true)['outcome']);
        $this->assertSame('prepared', json_decode($this->raw($this->reservationKey()), true)['outcome']);
        $this->assertSame($original, json_decode($this->raw($this->key), true)['original_source']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
        $this->assertNull(DB::table('task_custody_records')->value('released_at'));
        $this->assertSame('running', DB::table('distributed_tasks')->value('status'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_disabled_worker_never_prepares_runtime_or_intent(): void
    {
        $this->save(Admission::POLICY_KEY, ['enabled' => true, 'worker_execution_enabled' => false]);
        $this->assertSame('worker_execution_disabled', $this->runWorker()['reason_code']);
        $this->assertSame([], $this->trace);
    }

    public function test_production_runtime_accepts_only_exact_authenticated_nested_launch_journal_lineage(): void
    {
        $observed = $this->probeNestedRuntime();
        // Exercise the production journal boundary through real nested native
        // claims. Host permission and model/tokenizer admission remain separate.
        $this->assertSame('lineage_verified', $observed);
        $this->assertSame([], $this->trace);
        $this->assertFalse(DB::table('system_configs')->where('config_key', $this->receiptKey())->exists());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_production_runtime_refuses_unrelated_or_changed_nested_journal_before_runtime_bindings(): void
    {
        foreach (['unrelated_action', 'operator_messages', 'changed_parent_request', 'changed_parent_owner', 'changed_inner_request', 'changed_grant'] as $variant) {
            $this->assertSame('Preparation requires an exact pristine launch and worker journal lineage.', $this->probeNestedRuntime($variant));
        }
        $this->assertSame([], $this->trace);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function probeNestedRuntime(string $variant = 'exact'): string
    {
        // The host's /tmp ancestors need not pass production journal ownership
        // guards. Use only this test's private, isolated directory in the existing
        // application private storage; no shared journal or application DB.
        $directory = dirname(realpath($this->base.'/vendor')).'/storage/app/private/upstream-lineage-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $this->journalDirectories[] = $directory;
        $path = $directory.'/journal.sqlite';
        $store = new CliStateStore($path);
        $session = $store->createSession(['messages' => [], 'turns' => [['id' => 'nested-turn', 'status' => 'inProgress']],
            'framework_worker' => ['worker_id' => 'nested-worker', 'root' => realpath(base_path())]]);
        $journal = ['path' => $path, 'session_id' => $session['id'], 'turn_id' => 'nested-turn', 'worker_id' => 'nested-worker'];
        $caller = ['session_id' => $session['id'], 'turn_id' => 'nested-turn', 'action_id' => 'inner-worker',
            'approval_scope_sha256' => str_repeat('d', 64), 'arguments_sha256' => str_repeat('e', 64)];
        $parent = ['session_id' => 'parent-dispatch-session', 'turn_id' => 'parent-turn', 'action_id' => 'outer-launch',
            'approval_scope_sha256' => str_repeat('a', 64), 'arguments_sha256' => hash('sha256', json_encode(['workKey' => $this->key], JSON_THROW_ON_ERROR))];
        $request = ['root' => realpath(base_path()), 'tool' => 'upstream_prepare_worker', 'approved' => true,
            'remote_operation' => 'execute', 'caller_binding' => $caller];
        $outer = ['root' => realpath(base_path()), 'tool' => 'upstream_launch_worker', 'approved' => true,
            'remote_operation' => 'execute', 'caller_binding' => $parent];
        $requestHash = fn ($r) => hash('sha256', json_encode(array_diff_key($r, ['remote_operation' => true, 'approved' => true, 'control_existing' => true]), JSON_THROW_ON_ERROR));
        $grant = $this->grant;
        $grant['owner_session'] = $session['id'];
        $grant['worker_preview_caller'] = $caller;
        $grant['worker_launch'] = ['caller' => $parent, 'parent_framework_request_sha256' => $requestHash($outer)];
        $plan = $this->plan + ['journal_path' => $path, 'journal_worker_id' => 'nested-worker', 'bindings_file' => $this->root.'/deliberately-absent-bindings.json'];
        $grant['worker_plan'] = $plan;
        $launch = ['schema' => 'plos.upstream_worker_launch.v1', 'work_key' => $this->key,
            'admission_sha256' => $this->hash($grant), 'framework_request_sha256' => $requestHash($request),
            'outcome' => 'unknown', 'physical_binding' => ['schema' => 'plos.compute_dispatch_binding.v1']];
        DB::table('system_configs')->updateOrInsert(['section' => 'cli', 'config_key' => 'framework_services'],
            ['config_value' => json_encode(['worker_receipts' => $journal], JSON_THROW_ON_ERROR)]);
        $this->save('upstream_source_launch_'.substr($this->key, -64), $launch);
        $observed = '';
        $remote = new CliFrameworkRemoteAction($store, $journal);
        $remote->dispatch($outer, function () use ($remote, $request, $store, $session, $grant, $plan, $variant, &$observed): array {
            $remote->dispatch($request, function () use ($store, $session, $grant, $plan, $variant, &$observed): array {
                if ($variant === 'unrelated_action') {
                    $store->startAction($session['id'], 'nested-turn', ['id' => 'unrelated', 'name' => 'operator-tool', 'arguments' => []],
                        ['instance_id' => 'nested-worker', 'model' => 'framework-no-model']);
                } elseif ($variant === 'operator_messages') {
                    $current = $store->session($session['id']);
                    $current['messages'] = [['role' => 'user', 'content' => 'preserve original operator history']];
                    $store->saveSession($current);
                } elseif ($variant === 'changed_parent_request') {
                    $grant['worker_launch']['parent_framework_request_sha256'] = str_repeat('b', 64);
                } elseif ($variant === 'changed_parent_owner') {
                    $grant['worker_launch']['caller']['session_id'] = 'different-parent';
                } elseif ($variant === 'changed_inner_request') {
                    $launchKey = 'upstream_source_launch_'.substr($this->key, -64);
                    $launch = json_decode($this->raw($launchKey), true);
                    $launch['framework_request_sha256'] = str_repeat('c', 64);
                    $this->save($launchKey, $launch);
                } elseif ($variant === 'changed_grant') {
                    $grant['budgets']['wall_seconds']++;
                }
                Context::addHidden(CliFrameworkServiceWorker::CALLER_CONTEXT, $grant['worker_preview_caller']);
                try {
                    (new \ReflectionMethod(Worker::class, 'assertPristineLaunchJournal'))
                        ->invoke($this->worker(), $store, $store->session($session['id']), $grant, $plan, $grant['worker_preview_caller']);
                    $observed = 'lineage_verified';
                } catch (\RuntimeException $e) {
                    $observed = $e->getMessage();
                }

                return ['success' => true];
            });

            return ['success' => true];
        });

        return $observed;
    }

    public function test_disabled_recurring_cycle_does_not_touch_cursor_or_invoke_framework(): void
    {
        $before = $this->snapshot();
        $worker = $this->cycleWorker();
        $this->assertSame('disabled', $worker->consumeScheduled()['status']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->trace);
    }

    public function test_recurring_cycle_uses_pinned_framework_and_observes_original_launch_without_replay(): void
    {
        $this->enableCycle('launch', 'upstream_launch_worker');
        $worker = $this->cycleWorker();
        $first = $worker->consumeScheduled();
        $this->assertSame(1, $first['selected']);
        $this->assertSame(1, $first['invoked']);
        $this->assertSame(['upstream_launch_worker'], $this->trace);
        $launchKey = 'upstream_source_launch_'.substr($this->key, -64);
        $this->save($launchKey, ['outcome' => 'unknown', 'original' => 'unchanged physical owner']);
        $this->save($this->receiptKey(), ['outcome' => 'unknown']);
        $this->assertSame(0, $worker->consumeScheduled()['invoked']);
        $this->assertSame(['upstream_launch_worker'], $this->trace);
        $this->save($this->receiptKey(), ['outcome' => 'prepared']);
        $this->enableCycle('reconcile', 'upstream_reconcile_worker');
        $hash = hash('sha256', $this->raw($launchKey));
        $observed = $this->cycleWorker(function (array $request) use ($hash): array {
            $this->assertSame(['workKey' => $this->key, 'expectedLaunchHash' => $hash, 'dryRun' => false], $request['arguments']);
            $this->assertSame(hash('sha256', json_encode($request['arguments'], JSON_THROW_ON_ERROR)), $request['caller_binding']['arguments_sha256']);
            $this->trace[] = $request['tool'];

            return ['success' => true];
        })->consumeScheduled();
        $this->assertSame(1, $observed['invoked']);
        $this->assertSame(['upstream_launch_worker', 'upstream_reconcile_worker'], $this->trace);
        $this->assertSame($hash, hash('sha256', $this->raw($launchKey)));
    }

    public function test_cycle_cursor_advances_past_unqualified_work_and_does_not_change_metadata_retry_cursor(): void
    {
        $this->enableCycle('launch', 'upstream_launch_worker');
        $bad = 'upstream_source_work_'.str_repeat('0', 64);
        $this->save($bad, ['status' => 'preparation_pending']);
        $this->save('upstream_source_cursor', ['retry_at' => '2050-01-01', 'original' => 'preserve']);
        $retry = $this->raw('upstream_source_cursor');
        $worker = $this->cycleWorker();
        $this->assertSame(0, $worker->consumeScheduled()['invoked']);
        $this->assertSame(1, $worker->consumeScheduled()['invoked']);
        $this->assertSame($retry, $this->raw('upstream_source_cursor'));
        $this->assertSame(['upstream_launch_worker'], $this->trace);
    }

    public function test_cycle_refuses_changed_private_template_or_caller_before_framework_invocation(): void
    {
        $this->enableCycle('launch', 'upstream_launch_worker');
        file_put_contents($this->grant['cycle_requests']['launch']['file'], 'changed private content');
        $this->assertSame(0, $this->cycleWorker()->consumeScheduled()['invoked']);
        $this->enableCycle('launch', 'upstream_launch_worker');
        $this->grant['worker_launch']['caller']['approval_scope_sha256'] = str_repeat('e', 64);
        $this->saveGrant();
        $this->assertSame(0, $this->cycleWorker()->consumeScheduled()['invoked']);
        $this->assertSame([], $this->trace);
    }

    public function test_cycle_budget_and_ready_review_missing_proof_never_invoke_framework(): void
    {
        $this->enableCycle('launch', 'upstream_launch_worker');
        $this->save(Worker::CYCLE_POLICY_KEY, ['enabled' => true, 'max_items' => 2, 'max_wall_seconds' => 600,
            'budget_caps' => ['max_items' => 1, 'max_wall_seconds' => 600]]);
        $this->assertSame('cycle_budget_unqualified', $this->cycleWorker()->consumeScheduled()['reason_code']);
        $this->enableCycle('ready_review', 'upstream_ready_review');
        $this->save('upstream_source_launch_'.substr($this->key, -64), ['outcome' => 'prepared']);
        $this->save($this->receiptKey(), ['outcome' => 'prepared', 'physical_reconciliation' => ['physical_cleanup_verified' => true]]);
        $this->assertSame(0, $this->cycleWorker()->consumeScheduled()['invoked']);
        $this->assertSame([], $this->trace);
    }

    public function test_private_provider_admission_is_required_before_intent_or_model_io(): void
    {
        $this->runtime['privacy_admitted'] = false;
        $this->assertSame('worker_private_provider_unqualified', $this->runWorker()['reason_code']);
        $this->assertSame([], $this->trace);
    }

    public function test_full_tail_change_is_rejected_before_intent(): void
    {
        $this->grant['worker_plan']['messages'][0]['content'] .= 'CHANGED';
        $this->saveGrant();
        $this->assertSame('worker_plan_unqualified', $this->runWorker()['reason_code']);
        $this->assertNotContains('begin', $this->trace);
    }

    public function test_input_and_output_budget_refusals_never_dispatch(): void
    {
        $this->tokens = 4097;
        $this->assertSame('worker_model_budget_unqualified', $this->runWorker()['reason_code']);
        $this->assertNotContains('begin', $this->trace);
        $this->tokens = 5;
        $this->runtime['route']['max_output_tokens'] = 65;
        $this->assertSame('worker_model_budget_unqualified', $this->runWorker()['reason_code']);
        $this->assertNotContains('model', $this->trace);
    }

    public function test_tool_and_protected_test_refusals_never_publish_intent(): void
    {
        $this->toolAllowed = false;
        $this->assertSame('worker_tool_admission_refused', $this->runWorker()['reason_code']);
        $this->assertNotContains('begin', $this->trace);
        $this->toolAllowed = true;
        $this->grant['worker_plan']['verification_sha256'] = str_repeat('b', 64);
        $this->saveGrant();
        $this->assertSame('worker_verification_unqualified', $this->runWorker()['reason_code']);
        $this->assertNotContains('begin', $this->trace);
    }

    public function test_incomplete_model_outcome_retains_original_unknown_reservation_without_leaking_message(): void
    {
        $this->failTransport = true;
        $result = $this->runWorker();
        $this->assertSame('worker_outcome_unknown', $result['reason_code']);
        $this->assertStringNotContainsString('Private provider', json_encode($result));
        $this->assertSame('unknown', json_decode($this->raw($this->receiptKey()), true)['outcome']);
        $this->assertSame('unknown', json_decode($this->raw($this->reservationKey()), true)['outcome']);
        $this->assertSame('preparation_pending', json_decode($this->raw($this->key), true)['status']);
        $this->assertNull(DB::table('task_custody_records')->value('released_at'));
    }

    public function test_changed_custody_or_rollback_and_test_failure_cannot_publish_terminal_completion(): void
    {
        $this->tamperTerminal = true;
        $this->assertSame('worker_outcome_unknown', $this->runWorker()['reason_code']);
        $this->assertSame('unknown', json_decode($this->raw($this->receiptKey()), true)['outcome']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_independent_rollback_hash_mismatch_holds_unknown(): void
    {
        $this->badRollback = true;
        $this->assertSame('worker_outcome_unknown', $this->runWorker()['reason_code']);
        $this->assertSame('unknown', json_decode($this->raw($this->receiptKey()), true)['outcome']);
    }

    public function test_independent_verification_failure_holds_unknown(): void
    {
        $this->verificationPass = false;
        $this->assertSame('worker_outcome_unknown', $this->runWorker()['reason_code']);
        $this->assertSame('unknown', json_decode($this->raw($this->receiptKey()), true)['outcome']);
    }

    public function test_provider_rejection_without_exact_tokenizer_cannot_admit_before_intent(): void
    {
        $this->runtime['route']['context_handling'] = 'provider_reject';
        $this->assertSame('worker_exact_tokenizer_unavailable', $this->runWorker()['reason_code']);
        $this->assertNotContains('begin', $this->trace);
    }

    public function test_ready_review_requires_independent_evidence_and_preserves_rejection(): void
    {
        $this->assertSame('ready_review_evidence_unqualified', $this->worker()->deliverReadyReview($this->key)['reason_code']);
        $this->assertSame('private_prepared', $this->runWorker()['status']);
        $receipt = json_decode($this->raw($this->receiptKey()), true);
        $receipt['physical_reconciliation'] = ['physical_cleanup_verified' => true];
        $this->save($this->receiptKey(), $receipt);
        $ready = ['schema' => 'plos.upstream_ready_review.v1', 'worker_caller' => ['session_id' => 'fixture-session'],
            'physical_terminal_qualified' => true, 'completion_sha256' => $receipt['completion_artifact']['sha256'],
            'title' => 'Bounded prepared source', 'summary' => 'Evidence independently qualified.', 'qualified_evidence' => []];
        foreach (['benefit', 'license', 'hardware_fit', 'source', 'tests', 'rollback'] as $gate) {
            $evidence = ['schema' => 'plos.upstream_qualified_evidence.v1', 'gate' => $gate, 'work_key' => $this->key,
                'completion_sha256' => $ready['completion_sha256'], 'qualified' => true, 'owning_record' => 'fixture/record'];
            $descriptor = $this->artifacts->captureBytes(json_encode($evidence, JSON_THROW_ON_ERROR));
            $ready['qualified_evidence'][$gate] = ['qualified' => true, 'owning_record' => 'fixture/record', 'sha256' => $descriptor['sha256']];
        }
        $readyKey = 'upstream_source_ready_'.substr($this->key, -64);
        $this->save($readyKey, $ready);
        $first = $this->worker()->deliverReadyReview($this->key);
        $this->assertSame('created', $first['status']);
        DB::table('agent_review_queue')->where('id', $first['review_id'])->update(['status' => 'rejected']);
        $second = $this->worker()->deliverReadyReview($this->key);
        $this->assertSame('unchanged', $second['status']);
        $this->assertSame('rejected', $second['disposition']);
        $this->assertSame(1, DB::table('agent_review_queue')->count());
        $this->assertFalse($second['public_writes']);
    }

    public function test_independent_outer_observer_closes_exact_custody_and_frees_archived_workspace(): void
    {
        $launchHash = $this->outerFixture();
        $before = $this->snapshot();
        $journalHash = hash_file('sha256', $this->root.'/journal.sqlite');
        $result = $this->worker()->reconcileWorkerOutcome($this->key, $launchHash);
        $this->assertSame('dry_run_verified', $result['status']);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($journalHash, hash_file('sha256', $this->root.'/journal.sqlite'));
        $this->observerContext($launchHash, false);
        $result = $this->worker()->reconcileWorkerOutcome($this->key, $launchHash, false);
        $this->assertSame('reconciled', $result['status']);
        $this->assertFalse($result['automatic_replay_allowed']);
        $this->assertSame('completed', DB::table('distributed_tasks')->value('status'));
        $session = DB::table('task_custody_records')->where('id', 1)->first();
        $this->assertNotNull($session->released_at);
        $this->assertSame('success', $session->outcome);
        $this->assertFalse(DB::table('system_configs')->where('config_key', $this->reservationKey())->exists());
        $receipt = json_decode($this->raw($this->receiptKey()), true);
        $originals = json_decode($this->artifacts->read($receipt['physical_reconciliation']['originals']['sha256']), true);
        $this->assertSame('fixture-owner', $originals['session']['owner_token']);
        $this->assertSame('running', $originals['task']['status']);
        $this->assertSame('prepared', json_decode($originals['reservation']['config_value'], true)['outcome']);
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    public function test_bare_pid_and_expired_session_cannot_replace_outer_terminal_receipt(): void
    {
        $hash = $this->outerFixture();
        DB::table('task_custody_records')->where('id', 1)->update(['expires_at' => '2000-01-01']);
        DB::table('task_custody_records')->where('id', 2)->update(['result_envelope' => json_encode(['pid' => 99, 'exited' => true])]);
        $before = $this->snapshot();
        $this->assertSame('observer_physical_terminal_unqualified', $this->worker()->reconcileWorkerOutcome($this->key, $hash)['reason_code']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_launch_owner_or_generation_cannot_close_another_domain_owner(): void
    {
        $hash = $this->outerFixture();
        $before = $this->snapshot();
        $this->observerContext(str_repeat('d', 64), true);
        $this->assertSame('observer_original_binding_changed', $this->worker()->reconcileWorkerOutcome($this->key, str_repeat('d', 64))['reason_code']);
        $this->assertSame($before, $this->snapshot());
        $this->observerContext($hash, true);
        DB::table('distributed_tasks')->update(['assignment_generation' => 2]);
        $this->assertSame('observer_domain_owner_changed', $this->worker()->reconcileWorkerOutcome($this->key, $hash)['reason_code']);
        $this->assertNull(DB::table('task_custody_records')->where('id', 1)->value('released_at'));
    }

    public function test_large_original_input_reference_is_lossless_without_table_truncation(): void
    {
        $descriptor = $this->artifacts->captureBytes(json_encode($this->grant['worker_plan']['messages'], JSON_THROW_ON_ERROR));
        $this->grant['worker_plan']['messages_artifact'] = $descriptor;
        unset($this->grant['worker_plan']['messages']);
        $this->saveGrant();
        $this->assertSame('private_prepared', $this->runWorker()['status']);
        $this->assertStringContainsString('CRITICAL FULL TAIL', $this->artifacts->read($descriptor['sha256']));
    }

    private function outerFixture(): string
    {
        $identity = ['session_id' => 'observer-session', 'turn_id' => 'observer-turn', 'action_id' => 'observer-action', 'approval_scope_sha256' => str_repeat('c', 64)];
        $this->grant['observer_caller'] = $identity;
        $this->grant['worker_preview_caller'] = ['session_id' => 'fixture-session', 'turn_id' => 'fixture-turn', 'action_id' => 'fixture-outer'];
        $this->grant['worker_plan']['journal_path'] = $this->root.'/journal.sqlite';
        $this->grant['worker_plan']['journal_worker_id'] = 'fixture-journal-worker';
        $this->saveGrant();
        $this->assertSame('private_prepared', $this->runWorker()['status']);
        $receipt = json_decode($this->raw($this->receiptKey()), true);
        $commandHash = str_repeat('e', 64);
        $binding = ['schema' => 'plos.compute_dispatch_binding.v1', 'custody_id' => 2, 'owner_token' => 'fixture-compute-owner',
            'instance_id' => 'fixture-compute', 'surface_ref' => hash('sha256', 'fixture-compute').':0', 'command_sha256' => $commandHash];
        $launch = ['schema' => 'plos.upstream_worker_launch.v1', 'admission_sha256' => $this->hash($this->grant), 'outcome' => 'unknown',
            'physical_binding' => $binding, 'command_sha256' => $commandHash, 'compute_instance_id' => 'fixture-compute', 'framework_request_sha256' => str_repeat('f', 64)];
        $launchKey = 'upstream_source_launch_'.substr($this->key, -64);
        $this->save($launchKey, $launch);
        $terminal = ['schema' => 'plos.compute_terminal.v1', 'owner_token' => 'fixture-compute-owner', 'command_sha256' => $commandHash,
            'cleanup_verified' => true, 'dispatched' => true, 'exit_code' => 0];
        DB::table('task_custody_records')->insert(['id' => 2, 'surface' => 'compute_execution', 'surface_ref' => $binding['surface_ref'],
            'owner_token' => 'fixture-compute-owner', 'expires_at' => '2050-01-01', 'released_at' => '2026-10-14 12:00:00',
            'result_envelope' => json_encode(['instance_id' => 'fixture-compute', 'terminal_receipt' => $terminal])]);
        $store = new CliStateStore($this->root.'/journal.sqlite');
        $fixtureJournal = new \PDO('sqlite:'.$this->root.'/journal.sqlite');
        $insert = $fixtureJournal->prepare('INSERT INTO cli_sessions(id,revision,document) VALUES (?,0,?)');
        $insert->execute(['fixture-session', json_encode(['id' => 'fixture-session', 'turns' => [['id' => 'fixture-turn', 'status' => 'inProgress']]])]);
        $id = hash('sha256', json_encode(['fixture-session', 'fixture-turn', 'fixture-outer'], JSON_THROW_ON_ERROR));
        $store->startAction('fixture-session', 'fixture-turn', ['id' => $id, 'name' => 'upstream_prepare_worker',
            'arguments' => ['request_sha256' => str_repeat('f', 64)]], ['instance_id' => 'fixture-journal-worker', 'model' => 'framework-no-model']);
        $store->completeAction('fixture-session', 'fixture-turn', $id, ['success' => true, 'output' => json_encode(['result' => [
            'status' => 'private_prepared', 'completion_artifact_sha256' => $receipt['completion_artifact']['sha256']]])]);
        $hash = hash('sha256', $this->raw($launchKey));
        $this->observerContext($hash, true);

        return $hash;
    }

    private function observerContext(string $hash, bool $dryRun): void
    {
        Context::addHidden(CliFrameworkServiceWorker::CALLER_CONTEXT, $this->grant['observer_caller'] + [
            'arguments_sha256' => hash('sha256', json_encode(['workKey' => $this->key, 'expectedLaunchHash' => $hash, 'dryRun' => $dryRun], JSON_THROW_ON_ERROR))]);
    }

    private function snapshot(): array
    {
        return ['configs' => DB::table('system_configs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), 'custody' => DB::table('task_custody_records')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'task' => DB::table('distributed_tasks')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function test_registration_stays_disabled_preserves_overrides_and_does_not_make_a_worker_target(): void
    {
        Schema::create('agent_tool_registry', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            foreach (['service_class', 'method', 'description', 'parameters', 'permissions', 'risk_level', 'category',
                'availability_status', 'privacy_class', 'source', 'notes'] as $field) {
                $t->text($field)->nullable();
            }
            $t->boolean('requires_confirmation');
            $t->boolean('enabled');
            $t->boolean('allows_private_data');
            $t->timestamps();
        });
        $this->save(Admission::POLICY_KEY, ['schema' => Admission::POLICY_SCHEMA, 'enabled' => false, 'budget_caps' => ['wall_seconds' => 10]]);
        DB::table('system_configs')->insert(['section' => 'cli', 'config_key' => 'framework_services', 'config_value' => json_encode([
            'targets' => ['local' => ['transport' => 'local']], 'tools' => ['existing' => ['target' => 'local', 'tree_scoped' => false, 'references' => []]]])]);
        $migration = require $this->base.'/database/migrations/2026_10_14_130000_register_disabled_upstream_preparation_worker.php';
        $migration->up();
        $this->assertSame(4, DB::table('agent_tool_registry')->count());
        $this->assertSame(0, DB::table('agent_tool_registry')->where('enabled', true)->count());
        $policy = json_decode($this->raw(Admission::POLICY_KEY), true);
        $this->assertFalse($policy['enabled']);
        $this->assertFalse($policy['worker_execution_enabled']);
        $this->assertFalse(json_decode($this->raw(Worker::CYCLE_POLICY_KEY), true)['enabled']);
        $framework = json_decode(DB::table('system_configs')->where('section', 'cli')->value('config_value'), true);
        $this->assertSame(['local'], array_keys($framework['targets']));
        $this->assertArrayNotHasKey('worker_receipts', $framework);
        $this->assertTrue($framework['tools']['upstream_prepare_worker']['durable_receipts']);
        $policy['worker_execution_enabled'] = true;
        $this->save(Admission::POLICY_KEY, $policy);
        DB::table('agent_tool_registry')->where('name', 'upstream_prepare_worker')->update(['notes' => 'Operator override retained']);
        $migration->up();
        $this->assertTrue(json_decode($this->raw(Admission::POLICY_KEY), true)['worker_execution_enabled']);
        $this->assertSame('Operator override retained', DB::table('agent_tool_registry')->where('name', 'upstream_prepare_worker')->value('notes'));
        $this->assertSame(0, DB::table('agent_review_queue')->count());
    }

    private function worker(): Worker
    {
        return new Worker($this->admission, $this->repo, $this->recovery, Mockery::mock(AgentToolRegistryService::class), fn () => $this->runtime);
    }

    private function enableCycle(string $phase, string $tool): void
    {
        $this->save(Worker::CYCLE_POLICY_KEY, ['enabled' => true, 'max_items' => 1, 'max_wall_seconds' => 600,
            'budget_caps' => ['max_items' => 1, 'max_wall_seconds' => 600]]);
        $identity = ['session_id' => 'fixture-cycle', 'turn_id' => 'fixture-cycle-turn', 'action_id' => $phase,
            'approval_scope_sha256' => str_repeat('d', 64)];
        $arguments = ['workKey' => $this->key];
        $request = ['root' => realpath(base_path()), 'tool' => $tool, 'approved' => true, 'arguments' => $arguments,
            'caller_binding' => $identity + ['arguments_sha256' => hash('sha256', json_encode($arguments, JSON_THROW_ON_ERROR))]];
        $file = $this->root.'/'.$phase.'.json';
        file_put_contents($file, json_encode($request, JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $this->grant['cycle_requests'][$phase] = ['file' => $file, 'sha256' => hash_file('sha256', $file)];
        $this->grant['worker_launch']['caller'] = $request['caller_binding'];
        $this->grant['observer_caller'] = $identity;
        $this->saveGrant();
    }

    private function cycleWorker(?\Closure $invoker = null): Worker
    {
        return new Worker($this->admission, $this->repo, $this->recovery, Mockery::mock(AgentToolRegistryService::class),
            frameworkInvoker: $invoker ?? function (array $request): array {
                $this->trace[] = $request['tool'];

                return ['success' => true];
            });
    }

    private function runWorker(): array
    {
        return $this->worker()->runWorker($this->key, 'fixture-task', 0, 1);
    }

    private function receiptKey(): string
    {
        return 'upstream_source_attempt_'.substr($this->key, -64);
    }

    private function reservationKey(): string
    {
        return 'upstream_workspace_reservation_'.hash('sha256', $this->root);
    }

    private function save(string $key, array $value): void
    {
        DB::table('system_configs')->updateOrInsert(['section' => 'ops', 'config_key' => $key], ['config_value' => json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    private function raw(string $key): string
    {
        return DB::table('system_configs')->where('config_key', $key)->value('config_value');
    }

    private function saveGrant(): void
    {
        $this->save('upstream_source_admission_'.substr($this->key, -64), $this->grant);
        if (Schema::hasTable('distributed_tasks')) {
            DB::table('distributed_tasks')->update(['payload' => $this->payload()]);
        }
    }

    private function payload(): string
    {
        return json_encode(['upstream_preparation' => ['work_key' => $this->key, 'work_sha256' => $this->grant['work_sha256'], 'owner_session' => $this->grant['owner_session'], 'admission_sha256' => $this->hash($this->grant)]], JSON_THROW_ON_ERROR);
    }

    private function hash(array $value): string
    {
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            } foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
