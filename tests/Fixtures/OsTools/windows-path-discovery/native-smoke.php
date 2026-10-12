<?php

use App\Services\OsToolEnvironmentDiscovery;

// Isolated native observation test. Observed .cmd/.exe files are never executed.
if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('This proof requires native Windows PHP.');
}
require __DIR__.'/OsToolEnvironmentDiscovery.php';
$root = __DIR__.'/fixture';
if (file_exists($root)) {
    throw new RuntimeException('Preserve any previous fixture; never replay this proof.');
}
mkdir($root);
mkdir($root.'/first');
mkdir($root.'/second');
$checks = [];
$assert = static function (string $name, bool $value) use (&$checks): void {
    if (! $value) {
        throw new RuntimeException('Observation proof failed: '.$name);
    }
    $checks[$name] = true;
};
file_put_contents($root.'/first/Tool.CMD', '@echo unexpected>"'.__DIR__.'/executed-marker"');
file_put_contents($root.'/second/tool.cmd', '@echo unexpected>"'.__DIR__.'/executed-marker"');
file_put_contents($root.'/first/not-a-binary.exe', 'not an executable image');
file_put_contents($root.'/first/readme.txt', 'not executable');
copy(PHP_BINARY, $root.'/first/native.exe');
$environment = static fn (): array => ['os' => PHP_OS_FAMILY,
    'path' => '"'.$root.'/first";'.$root.'/second;'.$root.'/first;.;C:relative;\\\\invalid-server\\never-open;\\\\?\\C:\\never-open'];
$service = new OsToolEnvironmentDiscovery(environment: $environment);
$result = $service->discover();
$rows = $result['executable_observations'];
$names = array_merge(...array_column($rows, 'names'));
sort($names);
$assert('binary_and_batch_candidates_observed_without_format_claim', $names === ['Tool.CMD', 'native.exe', 'not-a-binary.exe', 'tool.cmd']);
$assert('distinct_windows_file_identities', count($rows) === 4 && count(array_unique(array_column($rows, 'id'))) === 4);
$assert('case_shadow_preserved', in_array('tool.cmd', array_merge(...array_column($rows, 'shadowed_names')), true));
$assert('duplicate_directory_skipped', $result['sources']['path']['directories_observed'] === 2);
$assert('unsafe_roots_refused', in_array('unsafe_path_component_skipped', $result['errors'], true));
$assert('no_raw_paths', ! str_contains(json_encode($result), str_replace('\\', '\\\\', __DIR__)) && $result['raw_paths_included'] === false);
$assert('format_remains_unverified', count(array_filter($rows, static fn ($row) => $row['qualification'] === 'unverified' && $row['execution_authorized'] === false)) === 4);
$assert('no_authority_or_probe', $result['execution_authorized'] === false && $result['runtime_probes_executed'] === false);
$assert('no_windows_package_claim', $result['installed_packages'] === [] && $result['supported_sources']['packages'] === 'unsupported_platform');
$assert('no_execution_marker', ! file_exists(__DIR__.'/executed-marker'));
file_put_contents($root.'/first/installed.cmd', '@echo unexpected>"'.__DIR__.'/executed-marker"');
$new = $service->discover();
$assert('rediscovery_finds_new_tool', count($new['executable_observations']) === 5);
unlink($root.'/first/installed.cmd');
unlink($root.'/first/native.exe');
$after = $service->discover();
$assert('rediscovery_removes_tool', count($after['executable_observations']) === 3);
$assert('private_observations_reset', count($service->privateExecutablePaths()) === 3);
$limited = (new OsToolEnvironmentDiscovery(environment: $environment, limits: ['entries' => 1]))->discover();
$assert('bounds_reported', $limited['truncated'] && in_array('entries', $limited['truncation_reasons'], true));
$assert('still_no_execution_marker', ! file_exists(__DIR__.'/executed-marker'));
$receipt = ['schema' => 'plos.windows.path-discovery.native-proof.v1', 'passed' => true,
    'platform' => PHP_OS_FAMILY, 'php_version' => PHP_VERSION, 'checks' => $checks,
    'service_sha256' => hash_file('sha256', __DIR__.'/OsToolEnvironmentDiscovery.php'),
    'test_sha256' => hash_file('sha256', __FILE__), 'php_sha256' => hash_file('sha256', PHP_BINARY),
    'runtime_probes_executed' => 0, 'execution_authorized' => false, 'scope' => 'isolated_synthetic_path_fixture_not_full_host_inventory'];
file_put_contents(__DIR__.'/RESULT.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode($receipt, JSON_THROW_ON_ERROR)."\n";
