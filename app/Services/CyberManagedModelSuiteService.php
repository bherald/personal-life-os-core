<?php

namespace App\Services;

use App\Services\UnifiedCli\CliAgentAdmission;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliModelHostStatus;
use App\Services\UnifiedCli\CliRouteResolver;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/** Fixed managed CLI composition inside the existing durable workflow, not a replacement queue. */
final class CyberManagedModelSuiteService
{
    /** Existing captured routing and connection identity, read-only before a queued workflow starts. */
    public function capacityPlan(object $row, array $binding): array
    {
        $template = $binding['managed_model']['connection_directory'];
        $this->assertScope($row, $template);
        $record = json_decode(CliLocalBindings::readPrivateFile($template.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
        $store = new CliStateStore($template.'/state.sqlite');
        $session = $store->session($record['session_id']);
        if (isset($session['host_lease']) && ! in_array($session['host_lease']['phase'] ?? null, ['released', 'not_started'], true)
            || array_filter($session['turns'] ?? [], fn ($turn) => ($turn['status'] ?? null) === 'inProgress') !== []) {
            throw new RuntimeException('Managed template has active or unresolved work.');
        }
        $candidates = (new CliRouteResolver(new CliConfiguration($store)))->inspect($session)['candidates'];
        $matches = array_values(array_filter($candidates, fn ($candidate) => ($candidate['configuration_eligible'] ?? false) === true
            && $candidate['instance_id'] === $record['instance'] && $candidate['model'] === $record['model']));
        if (count($matches) !== 1) {
            throw new RuntimeException('Capacity waiting requires one exact admitted managed model.');
        }
        $bindings = CliLocalBindings::fromFile($template.'/bindings.json');
        $artifacts = new CliArtifactStore($template.'/artifacts');
        $http = new Client;
        $qualification = (new CliAgentAdmission($store, $bindings, $artifacts, $http,
            fn () => throw new RuntimeException('Runtime mutation is unavailable during capacity planning.')))->qualification($matches[0], $session);
        $target = $qualification['contract']['controller_target'] ?? null;
        if (($qualification['allowed'] ?? null) !== true || ! is_string($target)) {
            throw new RuntimeException('Managed model has no qualified controller target.');
        }
        $transport = json_decode(CliLocalBindings::readPrivateFile($template.'/controller-binding.json'), true, flags: JSON_THROW_ON_ERROR);
        $connection = new CliModelHostStatus($store, $session['id'], $bindings, $artifacts, $http, $transport);
        $spec = $connection->policy()['targets'][$target] ?? null;
        if (! is_array($spec) || $spec['model'] !== $record['model']) {
            throw new RuntimeException('Managed model and controller policy differ.');
        }
        $plan = $connection->client()->plan($target, 'capacity-plan-'.Str::uuid());
        ModelHostControllerResourcePolicyService::assertReceiptBinding($qualification['capacity_binding'] ?? null,
            $plan['resource_assessment']['request_binding'] ?? null);
        (new CyberSuiteArtifactService)->assertJobBinding($row);
        $this->assertScope($row, $template);

        return ['plan' => $plan, 'target' => $target, 'model' => $record['model'], 'engine' => $spec['engine']];
    }

    public static function connectionFingerprint(string $directory): string
    {
        if (realpath($directory) !== $directory || ! is_dir($directory) || is_link($directory)
            || (fileperms($directory) & 0077) !== 0 || fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException('Managed connection requires a canonical private directory.');
        }
        $hashes = [];
        foreach (['settings.json', 'controller-binding.json', 'bindings.json', 'managed-session.json'] as $file) {
            $hashes[$file] = hash('sha256', CliLocalBindings::readPrivateFile($directory.'/'.$file));
        }
        $record = json_decode(CliLocalBindings::readPrivateFile($directory.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
        CliLocalBindings::readPrivateFile($directory.'/state.sqlite');
        $store = new CliStateStore($directory.'/state.sqlite');
        $session = $store->session($record['session_id']);
        $hashes['session_configuration'] = hash('sha256', json_encode([$session['generation'], $session['overrides'] ?? [], $store->defaults()], JSON_THROW_ON_ERROR));

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    public function execute(array $input): array
    {
        $row = DB::table('cyber_workflow_jobs')->where('handle', $input['task_id'] ?? '')->first();
        if ($row === null || $row->session_id !== ($input['session_id'] ?? null) || $row->handle !== ($input['turn_id'] ?? null)) {
            throw new RuntimeException('Exact durable task/session/turn binding required.');
        }
        $artifacts = new CyberSuiteArtifactService;
        $binding = $artifacts->assertJobBinding($row);
        $expectedCoverage = $artifacts->suiteCoverage($binding);
        if (! is_array($binding['managed_model'] ?? null)) {
            throw new RuntimeException('Managed workflow binding is unavailable.');
        }
        $policy = app(SystemConfigService::class)->get('cyber.workflow_jobs', []);
        $store = new CliStateStore($policy['state_path']);
        $store->assertTurnNotCancelled($row->session_id, $row->handle);
        $old = $store->actionReceipts($row->session_id, $row->handle)['managed-model-suite'] ?? null;
        if ($old !== null) {
            if ($old['state'] === 'completed' && ($old['document']['result']['success'] ?? false) === true) {
                return $input;
            }
            throw new RuntimeException('Prior managed suite is failed or unknown; no automatic replay.');
        }
        $template = $binding['managed_model']['connection_directory'];
        $this->assertScope($row, $template);
        $lockPath = $template.'/background.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Connection lock must not be a symlink.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open private connection lock.');
        }
        chmod($lockPath, 0600);
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Managed connection is already in use.');
        }
        try {
            // Recheck after obtaining custody; no queued metadata or stale lease becomes authority.
            $artifacts->assertJobBinding($row);
            $this->assertScope($row, $template);
            $store->assertTurnNotCancelled($row->session_id, $row->handle);
            $arguments = ['turn_id' => $row->handle, 'owner' => $row->owner, 'scope_sha256' => $row->scope_sha256,
                'artifact_sha256' => $binding['archive_sha256'], 'connection_sha256' => $binding['managed_model']['connection_sha256']];
            $store->startAction($row->session_id, $row->handle, ['id' => 'managed-model-suite', 'tool' => 'cyber_managed_model_suite', 'arguments' => $arguments], ['instance_id' => 'managed-controller', 'model' => 'exact-approved-connection']);
            $work = $template.'/background-jobs/'.$row->handle;
            if (file_exists($work) || is_link($work) || ! mkdir($work, 0700, true)) {
                throw new RuntimeException('A managed job working directory is never reused.');
            }
            $connection = $this->forkConnection($template, $work.'/connection');
            $artifactParent = $binding['runtime_root'].'/lab/artifacts';
            if (! is_dir($artifactParent)) {
                mkdir($artifactParent, 0700);
            }
            $suiteDirectory = $artifactParent.'/background-'.$row->handle;
            $context = ['schema' => 'plos.cyber.managed-job-context.v1', 'state_path' => $policy['state_path'], 'session_id' => $row->session_id] + $arguments + ['artifact_directory' => $suiteDirectory];
            $contextFile = $work.'/job-context.json';
            file_put_contents($contextFile, json_encode($context, JSON_THROW_ON_ERROR));
            chmod($contextFile, 0600);
            $contextHash = hash_file('sha256', $contextFile);
            $report = null;
            $failure = null;
            $release = null;
            $activated = false;
            try {
                $store->assertTurnNotCancelled($row->session_id, $row->handle);
                $this->progress($store, $row, 'activating');
                $activation = $this->invoke($binding, $connection, 'activate', $work);
                $activated = $activation['exit_code'] === 0;
                if (! $activated) {
                    throw new RuntimeException('Activation did not finish with verified ownership.');
                }
                $store->assertTurnNotCancelled($row->session_id, $row->handle);
                $this->progress($store, $row, 'running');
                $run = $this->invoke($binding, $connection, 'run', $work, ['--job-context='.$contextFile, '--job-context-sha256='.$contextHash]);
                $report = $run['json'];
                if (! is_array($report) || ($report['artifact_directory'] ?? null) !== $suiteDirectory) {
                    throw new RuntimeException('Managed suite report is incomplete or bound to another job.');
                }
                $reportBytes = CliLocalBindings::readPrivateFile($suiteDirectory.'/portable-report.json');
                $receiptsBytes = CliLocalBindings::readPrivateFile($suiteDirectory.'/receipts.json');
                if (json_decode($reportBytes, true, flags: JSON_THROW_ON_ERROR) !== $report) {
                    throw new RuntimeException('Managed stdout/report identity differs.');
                }
                $receipts = json_decode($receiptsBytes, true, flags: JSON_THROW_ON_ERROR);
                $summary = (new CyberSuiteResultSummary)->summarize($report, $receipts, $expectedCoverage);
                if ($run['exit_code'] !== 0 || $summary['status'] !== 'recorded_synthetic_checks_passed' || ($report['operator_closed'] ?? null) !== false) {
                    throw new RuntimeException('Managed suite did not establish the required recorded checks.');
                }
            } catch (Throwable $error) {
                $failure = $error;
            } finally {
                // Graceful cancellation cannot skip cleanup; release still uses the exact owned lease.
                $leaseStore = new CliStateStore($connection.'/state.sqlite');
                $leaseRecord = json_decode(CliLocalBindings::readPrivateFile($connection.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
                $lease = $leaseStore->session($leaseRecord['session_id'])['host_lease'] ?? null;
                $verifiedOwned = is_array($lease) && ($lease['phase'] ?? null) === 'owned';
                if ($activated || $verifiedOwned) {
                    $this->progress($store, $row, 'releasing');
                    try {
                        $release = $this->invoke($binding, $connection, 'release', $work);
                    } catch (Throwable) {
                        $release = null;
                    }
                }
            }
            $state = new CliStateStore($connection.'/state.sqlite');
            $record = json_decode(CliLocalBindings::readPrivateFile($connection.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
            $managed = $state->session($record['session_id']);
            $released = ($activated || $verifiedOwned) && ($release['exit_code'] ?? null) === 0 && ($managed['host_lease']['phase'] ?? null) === 'released';
            $active = array_filter($managed['turns'] ?? [], fn ($turn) => ($turn['status'] ?? null) === 'inProgress');
            if (! $released || $active !== []) {
                $this->progress($store, $row, 'cleanup_or_outcome_unknown');
                throw new RuntimeException('Managed outcome or lease cleanup is unverified. Keep the original action held; no replay.');
            }
            $nativeUnknown = array_filter($state->events($record['session_id']), fn ($event) => $event['type'] === 'model_io' && ($event['document']['event']['completed'] ?? false) !== true);
            $inner = $suiteDirectory.'/receipts.json';
            $unknownActions = is_file($inner) ? array_filter(json_decode(CliLocalBindings::readPrivateFile($inner), true, flags: JSON_THROW_ON_ERROR), fn ($receipt) => $receipt['state'] !== 'completed') : [];
            if ($unknownActions !== [] || $nativeUnknown !== []) {
                throw new RuntimeException('An inner action outcome is unknown; outer job remains held.');
            }
            $result = ['success' => $failure === null, 'output' => json_encode(['status' => $failure === null ? 'recorded_synthetic_checks_passed' : 'stopped_with_verified_release',
                'lease_released' => true, 'operator_closed' => false, 'security_certification' => false, 'model_io_incomplete' => count($nativeUnknown),
                'report_sha256' => is_file($suiteDirectory.'/portable-report.json') ? hash_file('sha256', $suiteDirectory.'/portable-report.json') : null], JSON_THROW_ON_ERROR)];
            $store->completeAction($row->session_id, $row->handle, 'managed-model-suite', $result);
            $this->progress($store, $row, $failure === null ? 'completed' : 'stopped');
            if ($failure !== null) {
                throw new RuntimeException('Managed run stopped; verified release and retained evidence require review.');
            }

            return $input + ['managed_suite' => json_decode($result['output'], true)];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertScope(object $row, string $template): void
    {
        $context = CliWorkflowExecutionScope::current();
        $captured = json_decode($row->scope_json, true, flags: JSON_THROW_ON_ERROR);
        if ($context === null || $context !== $captured || ! hash_equals($row->scope_sha256, hash('sha256', json_encode($captured, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Managed subprocess requires its exact captured workflow scope.');
        }
        $record = json_decode(CliLocalBindings::readPrivateFile($template.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
        $store = new CliStateStore($template.'/state.sqlite');
        $session = $store->session($record['session_id']);
        $values = (new CliConfiguration($store))->effective($session)['values'];
        $settings = $captured['settings'];
        $rank = ['offline' => 0, 'lan' => 1, 'online' => 2];
        $mode = $values['cli.network'] ?? 'offline';
        $profile = $settings['policy_profile'] ?? 'offline_dev_assist';
        if (! isset($rank[$mode]) || $rank[$mode] > $rank[$settings['network']['mode']]
            || ! in_array($settings['model_role'], ['auto', $values['cli.role'] ?? 'standard'], true)
            || ! in_array($settings['model_selection'], ['auto', ($record['instance'] ?? '').'::'.($record['model'] ?? '')], true)
            || $settings['vision_model_selection'] !== 'auto' || $settings['embedding_model_selection'] !== 'auto'
            || $settings['compute_selection'] !== 'auto' || $profile !== 'offline_dev_assist') {
            throw new RuntimeException('Managed template cannot honor the captured network/model/compute/profile constraints.');
        }
        $transport = json_decode(CliLocalBindings::readPrivateFile($template.'/controller-binding.json'), true, flags: JSON_THROW_ON_ERROR);
        $instances = (new CliConfiguration($store))->definitions($session, 'llm_instances');
        $matches = array_values(array_filter($instances, fn ($instance) => ($instance['instance_id'] ?? null) === ($record['instance'] ?? null)));
        if (count($matches) !== 1) {
            throw new RuntimeException('Managed scope requires one exact model instance.');
        }
        $model = $matches[0];
        foreach ([$model['base_url'] ?? '', $transport['base_url'] ?? ''] as $endpoint) {
            $host = parse_url($endpoint, PHP_URL_HOST);
            if (! is_string($host) || $host === '' || ! in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)) {
                throw new RuntimeException('Managed scope requires exact valid model/controller endpoints.');
            }
            if (isset($settings['network']['host_addresses'][$host])
                && ($values['cli.host_addresses'][$host] ?? null) !== $settings['network']['host_addresses'][$host]) {
                throw new RuntimeException('Managed template differs from captured endpoint address pin.');
            }
        }
    }

    private function forkConnection(string $template, string $directory): string
    {
        $record = json_decode(CliLocalBindings::readPrivateFile($template.'/managed-session.json'), true, flags: JSON_THROW_ON_ERROR);
        $original = new CliStateStore($template.'/state.sqlite');
        $source = $original->session($record['session_id']);
        if (isset($source['host_lease']) && ! in_array($source['host_lease']['phase'] ?? null, ['released', 'not_started'], true)
            || array_filter($source['turns'] ?? [], fn ($t) => ($t['status'] ?? null) === 'inProgress') !== []) {
            throw new RuntimeException('Connection template has unresolved ownership; no job fork.');
        }
        mkdir($directory, 0700);
        foreach (['settings.json', 'controller-binding.json', 'bindings.json'] as $file) {
            file_put_contents($directory.'/'.$file, CliLocalBindings::readPrivateFile($template.'/'.$file));
            chmod($directory.'/'.$file, 0600);
        }
        $store = new CliStateStore($directory.'/state.sqlite');
        $generation = $store->activate($original->snapshot($source['generation'])['snapshot']);
        foreach ($original->defaults() as $key => $value) {
            $store->saveDefault($key, $value);
        }
        $session = $store->createSession(['generation' => $generation, 'overrides' => $source['overrides'] ?? [], 'turns' => [], 'messages' => []]);
        $record['session_id'] = $session['id'];
        file_put_contents($directory.'/managed-session.json', json_encode($record, JSON_THROW_ON_ERROR));
        chmod($directory.'/managed-session.json', 0600);

        return $directory;
    }

    private function invoke(array $binding, string $directory, string $phase, string $work, array $extra = []): array
    {
        $process = new Process([PHP_BINARY, $binding['runtime_root'].'/lab/bin/managed-model.php', '--state='.$directory, '--'.$phase, ...$extra], $binding['runtime_root'], ['SECURITY_LAB_RUNTIME' => $binding['runtime_root']], null, 900);
        $exit = $process->run();
        file_put_contents($work.'/'.$phase.'.stdout', $process->getOutput());
        file_put_contents($work.'/'.$phase.'.stderr', $process->getErrorOutput());

        return ['exit_code' => $exit, 'json' => json_decode($process->getOutput(), true)];
    }

    private function progress(CliStateStore $store, object $row, string $phase): void
    {
        $store->recordEvent($row->session_id, 'cyber_managed_model_progress', ['phase' => $phase]);
    }
}
