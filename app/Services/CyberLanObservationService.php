<?php

namespace App\Services;

use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliNetworkBoundary;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliWorkflowExecutionScope;
use Closure;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Context;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/** Fixed, body-free observations. Execution belongs to the existing confirmed tool path. */
final class CyberLanObservationService
{
    public const CASES = ['web_root', 'cyber_unauthenticated'];

    public function __construct(private readonly ?ClientInterface $client = null, private readonly ?Closure $clock = null) {}

    public function plan(): array
    {
        $profile = app(SystemConfigService::class)->get('cyber.lan_observation', []);
        if (! is_array($profile) || array_diff(array_keys($profile), ['enabled', 'target', 'hostname', 'reviewed_until', 'transport']) !== []
            || ($profile['target'] ?? null) !== (string) config('cyber_lan_observation.target_host', '')
            || ! is_bool($profile['enabled'] ?? null)
            || ! is_string($profile['hostname'] ?? null)
            || ! in_array($profile['transport'] ?? 'https', ['https', 'lan_http_8000'], true)
            || (($profile['transport'] ?? 'https') === 'lan_http_8000'
                ? $profile['hostname'] !== (string) config('cyber_lan_observation.target_host', '')
                : ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*$/D', $profile['hostname']))
            || ! is_int($profile['reviewed_until'] ?? null)) {
            throw new RuntimeException('Exact configured reviewed target profile and web hostname are required.');
        }
        $document = ['schema' => 'plos.cyber.lan-observation.v1', 'profile' => $profile,
            'cases' => self::CASES, 'method' => 'HEAD', 'connect_timeout_seconds' => 3, 'timeout_seconds' => 10,
            'max_header_bytes' => 16384, 'redirects' => false, 'retries' => 0, 'request_credentials' => false,
            'retained_response_body' => false, 'target_changes_allowed' => false];

        return $document + ['profile_sha256' => hash('sha256', json_encode($document, JSON_THROW_ON_ERROR)),
            'profile_executable' => $profile['enabled'] && $profile['reviewed_until'] > $this->now() && $profile['reviewed_until'] <= $this->now() + 86400,
            'ready' => false, 'qualification_status' => 'candidate_not_end_to_end_qualified',
            'findings_status' => 'operator_review_required'];
    }

    public function observe(string $case, string $expected_profile, string $execution_intent): array
    {
        $denied = ['success' => false, 'complete' => false, 'status' => 'not_started', 'request_started' => false,
            'findings_status' => 'operator_review_required', 'automatic_retry_allowed' => false];
        try {
            $plan = $this->plan();
            if (! in_array($case, self::CASES, true) || $execution_intent !== 'observe_live'
                || ! $plan['profile_executable'] || ! hash_equals($plan['profile_sha256'], $expected_profile)) {
                throw new RuntimeException('Exact current enabled profile and observation intent required.');
            }
            $scope = CliWorkflowExecutionScope::current();
            if ($scope === null || ($scope['settings']['network']['mode'] ?? null) !== 'lan') {
                throw new RuntimeException('An existing explicit LAN workflow scope is required.');
            }
            $host = $plan['profile']['hostname'];
            $httpObservation = ($plan['profile']['transport'] ?? 'https') === 'lan_http_8000';
            $addresses = $scope['settings']['network']['host_addresses'];
            if (! $httpObservation && ($addresses[$host] ?? null) !== (string) config('cyber_lan_observation.target_host', '')) {
                throw new RuntimeException('Captured workflow must pin the reviewed hostname to the configured target.');
            }
            $path = $case === 'cyber_unauthenticated' ? '/api/v1/cyber/workflow-jobs/00000000-0000-4000-8000-000000000000' : '/';
            $url = $httpObservation ? 'http://'.(string) config('cyber_lan_observation.target_host', '').':8000'.$path : 'https://'.$host.$path;
            $settings = app(SystemConfigService::class);
            $admission = $this->admit($case, $expected_profile, $scope);
            // Operator-approved anonymous HTTP observation of this one literal origin only.
            // The shared model/controller TLS policy is never changed.
            $tls = $httpObservation ? [] : $settings->get('cli.tls_policy', []);
            $options = (new CliNetworkBoundary($addresses, $tls))->requestOptions($url, 'lan', true);
            $options += ['connect_timeout' => 3, 'timeout' => 10, 'http_errors' => false,
                'headers' => ['Accept' => 'application/json', 'User-Agent' => 'PLOS-CYBER-bounded-observation/1'],
                'on_headers' => static function (ResponseInterface $response): void {
                    if (strlen(json_encode($response->getHeaders(), JSON_THROW_ON_ERROR)) > 16384) {
                        throw new RuntimeException('Observation header budget exceeded.');
                    }
                }];
        } catch (\Throwable) {
            return $denied + ['reason' => 'profile_scope_or_transport_policy_refused'];
        }
        $started = microtime(true);
        try {
            // Atomic existing receipt claim happens only after every no-effect guard.
            $admission['store']->assertTurnNotCancelled($admission['session'], $admission['execution_turn']);
            $admission['store']->startAction($admission['session'], $admission['turn'],
                ['id' => 'lan-'.$case, 'tool' => 'cyber_lan_observe', 'arguments' => ['case' => $case, 'profile_sha256' => $expected_profile]],
                ['instance_id' => 'qualified-lan-observation', 'model' => 'no-llm'], $admission['continuation_turn']);
        } catch (\Throwable) {
            return $denied + ['reason' => 'cancelled_or_existing_action_receipt'];
        }
        try {
            $response = ($this->client ?? PipelineHttpClientFactory::make())->request('HEAD', $url, $options);
            ($options['on_headers'])($response);
            $status = $response->getStatusCode();
            // No raw headers, cookies, body, server banner, location query or exception text reaches the model.
            $headers = [];
            foreach (['strict-transport-security', 'content-security-policy', 'x-content-type-options', 'referrer-policy'] as $name) {
                $headers[$name] = $response->hasHeader($name);
            }
            $location = $response->getHeaderLine('location');
            $parts = $location === '' ? false : parse_url($location);
            $redirect = in_array($status, [301, 302, 303, 307, 308], true);
            $observation = $case === 'cyber_unauthenticated'
                ? (in_array($status, [401, 403], true) ? 'unauthenticated_request_denied' : ($status === 404 ? 'route_unconfirmed' : 'unexpected_auth_status'))
                : ($status >= 500 ? 'server_error_observed' : 'response_observed');
            $receipt = ['schema' => 'plos.cyber.lan-observation.receipt.v1', 'success' => true, 'complete' => true,
                'case' => $case, 'profile_sha256' => $expected_profile, 'status' => 'observed', 'request_started' => true,
                'http_status' => $status, 'observation' => $observation, 'security_headers_present' => $headers,
                'redirect_observed' => $redirect, 'redirect_followed' => false,
                'redirect_same_host_https' => $redirect && is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === $host,
                'tls_verification_requested' => ! $httpObservation, 'transport' => $httpObservation ? 'operator_approved_anonymous_lan_http' : 'https', 'response_body_retained' => false,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'findings_status' => 'operator_review_required', 'automatic_retry_allowed' => false];
        } catch (\Throwable) {
            $receipt = array_replace($denied, ['request_started' => true, 'status' => 'incomplete',
                'reason' => 'transport_or_header_budget_failure', 'case' => $case, 'profile_sha256' => $expected_profile]);
        }

        try {
            $mayContinue = $receipt['complete'] && ($receipt['http_status'] ?? 500) < 500
                && ! in_array($receipt['observation'] ?? '', ['unexpected_auth_status', 'route_unconfirmed'], true);
            $admission['store']->completeAction($admission['session'], $admission['turn'], 'lan-'.$case,
                ['success' => $mayContinue, 'output' => json_encode($receipt + ['finished_at' => $this->now()], JSON_THROW_ON_ERROR)]);
        } catch (\Throwable) {
            return array_replace($denied, ['request_started' => true, 'status' => 'incomplete', 'reason' => 'receipt_persistence_failed_unknown_effect']);
        }

        return $receipt;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /** Uses the existing session/action ledger; no caller-selected run IDs or new ledger. */
    private function admit(string $case, string $profile, array $scope): array
    {
        $binding = app(SystemConfigService::class)->get('cyber.lan_observation_run', []);
        if (! is_array($binding) || array_diff(array_keys($binding), ['path', 'sha256']) !== []
            || ! is_string($binding['path'] ?? null) || ! is_string($binding['sha256'] ?? null)) {
            throw new RuntimeException('Operator-owned assessment run binding required.');
        }
        $bytes = CliLocalBindings::readPrivateFile($binding['path']);
        if (! hash_equals($binding['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('Assessment binding changed.');
        }
        $run = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (($run['schema'] ?? '') !== 'plos.cyber.lan-run.v1'
            || array_diff(array_keys($run), ['schema', 'state_path', 'session_id', 'turn_id', 'scope_sha256', 'maintenance_path', 'maintenance_sha256', 'caller']) !== []
            || ! hash_equals($run['scope_sha256'] ?? '', hash('sha256', json_encode($scope, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('Assessment scope differs from its operator binding.');
        }
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $expectedCaller = $run['caller'] ?? [];
        $action = $expectedCaller['actions'][$case] ?? [];
        if (! is_array($caller) || count($caller) !== 5
            || ($caller['session_id'] ?? null) !== ($expectedCaller['session_id'] ?? null)
            || ($caller['turn_id'] ?? null) !== ($expectedCaller['turn_id'] ?? null)
            || ($caller['action_id'] ?? null) !== ($action['action_id'] ?? null)
            || ($caller['approval_scope_sha256'] ?? null) !== ($action['approval_scope_sha256'] ?? null)
            || ! is_string($action['arguments_sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $action['arguments_sha256'])
            || ($caller['arguments_sha256'] ?? null) !== $action['arguments_sha256']) {
            throw new RuntimeException('Exact initiating CLI caller/action binding is required.');
        }
        // Read existing owner-private database only; never silently create another budget.
        CliLocalBindings::readPrivateFile($run['state_path']);
        $store = new CliStateStore($run['state_path']);
        $session = $store->session($run['session_id']);
        if (($session['cyber_lan_profile_sha256'] ?? null) !== $profile
            || ($session['cyber_lan_turn'] ?? null) !== $run['turn_id']) {
            throw new RuntimeException('Assessment session is not bound to the reviewed profile.');
        }
        // /approve and /continue have their own turns. The parent journal owns
        // continuation identity; neither model arguments nor the run file can grant it.
        $executionTurn = $run['turn_id'];
        $continuationTurn = null;
        $latestTurn = $session['turns'][array_key_last($session['turns'] ?? [])]['id'] ?? null;
        if ($latestTurn !== $run['turn_id']) {
            $parent = $store->actionReceipts($session['id'], $run['turn_id'])[$caller['action_id']] ?? [];
            $document = $parent['document'] ?? [];
            $continuationTurn = $document['continuation_turn_id'] ?? null;
            if (($parent['state'] ?? null) !== 'started'
                || ($session['active_agent_turn'] ?? null) !== $run['turn_id']
                || ! is_string($continuationTurn) || $continuationTurn !== $latestTurn
                || ($document['action']['name'] ?? null) !== 'cyber_lan_observe'
                || ! hash_equals($caller['arguments_sha256'], hash('sha256', json_encode($document['action']['arguments'] ?? null, JSON_THROW_ON_ERROR)))) {
                throw new RuntimeException('Exact active parent action continuation is required.');
            }
            $executionTurn = $continuationTurn;
        }
        $store->assertTurnNotCancelled($session['id'], $executionTurn);
        $raw = CliLocalBindings::readPrivateFile($run['maintenance_path']);
        $health = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($run['maintenance_sha256'], hash('sha256', $raw))
            || ($health['schema'] ?? '') !== 'plos.cyber.operator-maintenance-admission.v1'
            || ($health['target'] ?? '') !== (string) config('cyber_lan_observation.target_host', '')
            || ! is_int($health['observed_at'] ?? null) || $health['observed_at'] > $this->now()
            || $this->now() - $health['observed_at'] > 60
            || ($health['source'] ?? '') !== 'operator_verified_existing_admin_route'
            || ! preg_match('/^[a-f0-9]{64}$/D', $health['source_receipt_sha256'] ?? '')
            || ($health['checks'] ?? null) !== ['backup_inactive' => true, 'soft_stop_inactive' => true, 'health_ready' => true]) {
            throw new RuntimeException('Fresh exact trusted maintenance admission is required.');
        }
        $sourceBytes = CliLocalBindings::readPrivateFile($health['source_receipt_path'] ?? '');
        $source = json_decode($sourceBytes, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($health['source_receipt_sha256'], hash('sha256', $sourceBytes))
            || ($source['schema'] ?? '') !== 'plos.cyber.prod-readonly-posture.v1'
            || ($source['target'] ?? '') !== (string) config('cyber_lan_observation.target_host', '') || ($source['observed_at'] ?? null) !== $health['observed_at']
            || ($source['backup_processes'] ?? null) !== [] || ($source['maintenance_markers'] ?? null) !== []
            || ($source['horizon'] ?? '') !== 'running' || ($source['health_exit_code'] ?? null) !== 0) {
            throw new RuntimeException('Admission does not match the exact healthy admin-route source receipt.');
        }
        $receipts = $store->actionReceipts($session['id'], $run['turn_id']);
        $index = array_search($case, self::CASES, true);
        foreach ($receipts as $id => $old) {
            if (str_starts_with($id, 'lan-') && ! in_array(substr($id, 4), array_slice(self::CASES, 0, $index), true)) {
                throw new RuntimeException('Assessment case already claimed or sequence differs.');
            }
        }
        foreach (array_slice(self::CASES, 0, $index) as $previous) {
            $old = $receipts['lan-'.$previous] ?? null;
            $result = $old['document']['result'] ?? [];
            $output = json_decode($result['output'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (($old['state'] ?? null) !== 'completed' || ($result['success'] ?? null) !== true
                || ($output['profile_sha256'] ?? null) !== $profile || ($output['complete'] ?? null) !== true
                || ! is_int($output['finished_at'] ?? null) || $this->now() - $output['finished_at'] < 2) {
                throw new RuntimeException('Prior case must be complete and spacing satisfied; no automatic retry.');
            }
        }

        return ['store' => $store, 'session' => $session['id'], 'turn' => $run['turn_id'],
            'execution_turn' => $executionTurn, 'continuation_turn' => $continuationTurn];
    }
}
