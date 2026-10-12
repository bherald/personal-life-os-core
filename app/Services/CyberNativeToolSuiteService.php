<?php

namespace App\Services;

use App\Services\UnifiedCli\CliQueuedFrameworkAction;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliToolOutcomeUnknown;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Fixed private qualification composition; generic queued framework authority stays in PLOS. */
final class CyberNativeToolSuiteService
{
    public function __construct(private readonly ?CliQueuedFrameworkAction $bridge = null) {}

    public function validatePlan(array $plan): void
    {
        $action = $plan['action'] ?? [];
        $args = $action['arguments'] ?? [];
        if (($action['name'] ?? null) !== 'os_tools_php_syntax_fixture'
            || array_diff(array_keys($args), ['fixture', 'expected_environment', 'expected_evidence']) !== []
            || ! in_array($args['fixture'] ?? null, ['clean', 'invalid'], true)
            || ($plan['approval_scope']['model'] ?? null) !== 'no-model-operator-fixture') {
            throw new RuntimeException('Only the exact reviewed fixed Windows syntax fixture profile is supported.');
        }
        foreach (['expected_environment', 'expected_evidence'] as $key) {
            if (! is_string($args[$key] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $args[$key])) {
                throw new RuntimeException('Current reviewed Windows evidence fingerprints required.');
            }
        }
    }

    public function assertSubmission(array $binding, string $owner, string $handle, string $scope): void
    {
        $plan = $binding['native_tool'] ?? [];
        $this->validatePlan($plan);
        $prepared = ($this->bridge ?? new CliQueuedFrameworkAction)->qualify($plan, ['owner' => $owner, 'handle' => $handle, 'scope_sha256' => $scope]);
        $this->assertWorkerArtifact($binding, $prepared['target']);
        $store = new CliStateStore($plan['state_path']);
        if (isset($store->actionReceipts($plan['session_id'], $plan['turn_id'])[$plan['action']['id']])) {
            throw new RuntimeException('Original reviewed native action is already claimed; no new job.');
        }
    }

    public function assertWorkerArtifact(array $binding, array $target): void
    {
        $manifestPath = ($binding['runtime_root'] ?? '').'/worker-manifest.json';
        if (! is_file($manifestPath) || hash_file('sha256', $manifestPath) !== ($binding['manifest_sha256'] ?? null)) {
            throw new RuntimeException('Exact qualified Windows artifact manifest required.');
        }
        $manifest = json_decode(file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $normalize = static fn (string $path): string => strtolower(preg_replace('~[\\\\/]+~', '/', $path));
        $pins = [];
        foreach ($target['runtime_files'] ?? [] as $path => $hash) {
            $canonical = $normalize($path);
            if (isset($pins[$canonical])) {
                throw new RuntimeException('Ambiguous Windows source pin aliases.');
            }
            $pins[$canonical] = $hash;
        }
        foreach (['worker-manifest.json', 'scripts/plos-cli/framework-service.php', 'vendor/autoload.php', 'integrity.php', 'bootstrap/app.php', 'verify-all-acl.ps1', 'scripts/plos-cli/windows-execution-environment.ps1'] as $file) {
            $expected = $file === 'worker-manifest.json' ? $binding['manifest_sha256'] : ($manifest['files'][$file] ?? null);
            if (! is_string($expected) || ($pins[$normalize(rtrim($target['root'], '\\/').'/'.$file)] ?? null) !== $expected) {
                throw new RuntimeException('Selected worker source pins differ from the qualified artifact.');
            }
        }
    }

    public function execute(array $input): array
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $input['task_id'] ?? '')->first();
        if ($row === null || $row->session_id !== ($input['session_id'] ?? null)) {
            throw new RuntimeException('Exact durable native task/session required.');
        }
        $binding = (new CyberSuiteArtifactService)->assertJobBinding($row);
        $plan = $binding['native_tool'] ?? [];
        $this->validatePlan($plan);
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        $store = new CliStateStore($policy['state_path']);
        $job = ['owner' => $row->owner, 'handle' => $row->handle, 'scope_sha256' => $row->scope_sha256];
        $bridge = $this->bridge ?? new CliQueuedFrameworkAction;
        $dispatching = false;
        $cancelForwarded = false;
        $assertActive = function () use ($store, $row, $binding, $bridge, &$dispatching, &$cancelForwarded): void {
            $cancelled = ($store->session($row->session_id)['turns'][0]['cancel_requested'] ?? false) === true;
            if (! $dispatching) {
                $store->assertTurnNotCancelled($row->session_id, $row->handle);
            } elseif ($cancelled && ! $cancelForwarded) {
                // One bounded cooperative stop request. Keep the original channel
                // alive for its native terminal/deadline instead of killing SSH.
                $cancelForwarded = true;
                $jobs = new CyberWorkflowJobService($store, $row->owner, $bridge);
                $prior = $jobs->status($row->handle)['native_control'];
                $control = ($prior['operation'] ?? null) === 'cancel'
                    ? $prior : $jobs->cancel($row->handle, 'cancel_live')['native_control'];
                if (($control['cancel_requested'] ?? false) !== true) {
                    throw new RuntimeException('Original remote cancellation was not verified; retain unknown custody.');
                }
            }
            $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
            $currentBinding = $policy['artifact'] ?? [];
            $currentBinding['native_tool'] = $policy['native_tool'] ?? null;
            if (($policy['enabled'] ?? false) !== true || ($policy['workflow_name'] ?? null) !== 'cyber_qualified_native_tools'
                || $currentBinding !== $binding || (new CyberSuiteArtifactService)->workflowFingerprint() !== $row->workflow_sha256) {
                throw new RuntimeException('Current server native profile or workflow was revoked or changed.');
            }
            $current = DB::table('cyber_workflow_jobs')->where('handle', $row->handle)->where('owner', $row->owner)->first();
            if ($current === null || $current->session_id !== $row->session_id || $current->scope_sha256 !== $row->scope_sha256
                || json_decode($current->artifact_binding, true, flags: JSON_THROW_ON_ERROR) !== $binding) {
                throw new RuntimeException('Durable native action ownership or binding changed.');
            }
        };
        $assertActive();
        $old = $store->actionReceipts($row->session_id, $row->handle)['native-framework-action'] ?? null;
        if ($old !== null) {
            throw new RuntimeException('Native job already has an action receipt; reconnect, never replay.');
        }
        $prepared = $bridge->qualify($plan, $job);
        $this->assertWorkerArtifact($binding, $prepared['target']);
        $store->startAction($row->session_id, $row->handle,
            ['id' => 'native-framework-action', 'tool' => 'cyber_qualified_native_tools', 'arguments' => $job + ['plan_sha256' => CliQueuedFrameworkAction::digest($plan)]],
            ['instance_id' => $plan['worker_id'], 'model' => 'no-model-operator-fixture']);
        $progress = function (string $phase) use ($store, $row, &$dispatching): void {
            $store->recordEvent($row->session_id, 'cyber_native_tool_progress', ['phase' => $phase]);
            if ($phase === 'dispatching') {
                $dispatching = true;
            }
        };
        $progress('validating');
        try {
            $result = $bridge->execute($plan, $job, $assertActive, $progress);
        } catch (CliToolOutcomeUnknown $error) {
            $progress('remote_outcome_unknown');
            throw $error;
        } catch (RuntimeException $error) {
            // Bridge RuntimeException occurs before remote claim/effect; after claim it uses the typed unknown exception.
            $store->completeAction($row->session_id, $row->handle, 'native-framework-action', ['success' => false, 'output' => 'Native action refused before remote dispatch.']);
            throw $error;
        }
        $probe = $result['result'] ?? [];
        $passed = ($result['success'] ?? false) === true && ($probe['fixture_probe_passed'] ?? false) === true
            && ($probe['runtime_started'] ?? false) === true && ($probe['fixture_source_executed'] ?? null) === false
            && ($probe['syntax_valid'] ?? null) === ($plan['action']['arguments']['fixture'] === 'clean');
        $store->completeAction($row->session_id, $row->handle, 'native-framework-action', ['success' => $passed, 'output' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $progress($passed ? 'completed' : 'verified_refusal');
        if (! $passed) {
            throw new RuntimeException('Verified native response did not pass the fixed fixture; no automatic replay.');
        }

        return $input + ['native_fixture_verified' => true, 'operator_review_required' => true];
    }
}
