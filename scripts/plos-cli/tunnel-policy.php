<?php

use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliNetworkBoundary;
use App\Services\UnifiedCli\CliStateStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $store = new CliStateStore($argv[1].'/state.sqlite');
    $resume = $argv[3] ?? '';
    $session = $resume === 'last' ? ($store->sessions()[0] ?? []) : ($resume !== '' ? $store->session($resume) : []);
    $session += ['generation' => $store->snapshot()['generation'] ?? null, 'overrides' => []];
    $values = (new CliConfiguration($store))->effective($session)['values'];
    $network = ($argv[2] ?? '') !== '' ? $argv[2] : $values['cli.network'];
    if (! in_array($network, ['offline', 'lan', 'online'], true)) {
        throw new RuntimeException('Invalid network mode.');
    }
    $forwards = json_decode(getenv('PLOS_CLI_REMOTE_FORWARDS') ?: '[]', true, flags: JSON_THROW_ON_ERROR);
    // Validate transport intent, without marking the actual backend forwards active.
    $validation = array_map(fn ($forward) => array_replace($forward, ['active' => true]), $forwards);
    $boundary = new CliNetworkBoundary($values['cli.host_addresses'] ?? [], [], $validation);
    if ($network !== 'offline') {
        foreach ($validation as $forward) {
            $boundary->requestOptions('http://127.0.0.1:'.$forward['localPort'], $network);
        }
    }
    $host = strtolower($validation[0]['remoteHost'] ?? '');
    $sshAddress = $values['cli.host_addresses'][$host] ?? null;
    echo json_encode(['network' => $network, 'start' => $network !== 'offline', 'ssh_address' => $sshAddress], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "The configured SSH host does not meet the selected network boundary or its provenance is invalid. Use a literal LAN address or explicit hostname pin for LAN mode.\n");
    exit(1);
}
