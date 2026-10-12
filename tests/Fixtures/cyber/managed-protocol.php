<?php

// Test-only lifecycle protocol simulator. Real SuiteHarness/PrimaryAgentLoop/tools run; no host or LLM calls.
$dependency = getenv('CYBER_PROTOCOL_PLOS');
require $dependency.'/vendor/autoload.php';
use App\Services\CyberManagedModelJobContext;
use App\Services\UnifiedCli\CliStateStore;
use SecurityLab\SuiteHarness;
use SecurityLab\SuiteTools;

umask(0077);
$options = getopt('', ['state:', 'activate', 'run', 'release', 'job-context:', 'job-context-sha256:']);
$dir = $options['state'];
$record = json_decode(file_get_contents($dir.'/managed-session.json'), true);
$store = new CliStateStore($dir.'/state.sqlite');
$session = $store->session($record['session_id']);
$config = json_decode(file_get_contents($dir.'/settings.json'), true);
$scenario = $config['fixture_scenario'] ?? 'success';
if (isset($options['activate'])) {
    $session['host_lease'] = ['phase' => $scenario === 'activation_unknown' ? 'activate_pending' : 'owned', 'lease_id' => 'protocol-only-'.$record['session_id']];
    $store->saveSession($session);
    echo json_encode(['protocol_fixture' => true, 'phase' => $session['host_lease']['phase']]);
    exit(in_array($scenario, ['activation_unknown', 'activation_owned_error'], true) ? 17 : 0);
}
if (isset($options['release'])) {
    if (array_filter($session['turns'] ?? [], fn ($t) => $t['status'] === 'inProgress')) {
        exit(18);
    }
    $session['host_lease']['phase'] = $scenario === 'release_unknown' ? 'release_pending' : 'released';
    $store->saveSession($session);
    echo json_encode(['protocol_fixture' => true]);
    exit($scenario === 'release_unknown' ? 17 : 0);
}
$job = new CyberManagedModelJobContext($options['job-context'], $options['job-context-sha256']);
$session['turns'][] = ['id' => 'protocol-run', 'status' => 'inProgress'];
$store->saveSession($session);
if ($scenario === 'process_failure') {
    exit(19);
}
require dirname(__DIR__).'/src/SuiteTools.php';
require dirname(__DIR__).'/src/SuiteHarness.php';
$harness = new SuiteHarness(dirname(__DIR__), $dependency, $job->artifactDirectory());
$route = ['instance_id' => 'protocol-only', 'model' => 'protocol-only', 'agent_admitted' => true, 'context_window' => 8192, 'max_output_tokens' => 512, 'max_iterations' => 8, 'max_tool_calls' => 12, 'context_handling' => 'provider_reject', 'wire_api' => 'ollama_chat'];
$iteration = 0;
$report = $harness->run($route, function ($selected, $messages, $schemas) use (&$iteration, $scenario, $job, $options) {
    $iteration++;
    $job->progress('model_request');
    if ($scenario === 'cancel' && $iteration === 2) {
        $c = json_decode(file_get_contents($options['job-context']), true);
        (new CliStateStore($c['state_path']))->requestTurnCancellation($c['session_id'], $c['turn_id']);
    }
    $job->assertActive();
    $calls = [];
    if ($iteration < 4) {
        foreach (SuiteTools::OPERATIONS as $case => $operation) {
            $name = match ($iteration) {
                1 => 'suite_check',2 => 'suite_fix',3 => 'suite_retest'
            };
            $args = ['case_id' => $case];
            if ($iteration === 2) {
                $args['operation'] = $operation;
            }
            $calls[] = ['id' => 'protocol-'.$iteration.'-'.$case, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]];
        }
    }

    return ['instance_id' => 'protocol-only', 'model' => 'protocol-only', 'completed' => true, 'content' => 'Protocol fixture only', 'tool_calls' => $calls];
}, function () use ($job) {
    $job->assertActive();

    return ['allowed' => true];
}, 'mock_boundary_test');
$store->recordEvent($record['session_id'], 'model_io', ['completed' => $scenario !== 'native_unknown', 'fixture' => true]);
$session = $store->session($record['session_id']);
$session['turns'][0]['status'] = 'completed';
$store->saveSession($session);
file_put_contents($job->artifactDirectory().'/portable-report.json', json_encode($report, JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_THROW_ON_ERROR);
exit($report['passed'] ? 0 : 1);
