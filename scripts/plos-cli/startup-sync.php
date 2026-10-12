<?php

use App\Services\UnifiedCli\CliDevelopmentDatabaseSync;
use App\Services\UnifiedCli\CliRegistrySynchronizer;
use App\Services\UnifiedCli\CliStateStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if (! in_array($argc, [4, 5], true)) {
        throw new InvalidArgumentException('Missing startup sync parameters.');
    }
    $store = new CliStateStore($argv[1].'/state.sqlite');
    $network = $argv[3] !== '' ? $argv[3] : ($store->defaults()['cli.network'] ?? 'lan');
    if ($network === 'offline') {
        echo "PLOS: using saved registry; network mode is offline.\n";
        exit(0);
    }
    (new CliRegistrySynchronizer($store, $argv[1], $argv[2],
        developmentDatabaseSync: CliDevelopmentDatabaseSync::forApplication(dirname(__DIR__, 2))))->sync($network, [], $argv[4] ?? 'registry');
    echo ($argv[4] ?? 'registry') === 'development'
        ? "PLOS: development schema/config and registry refreshed.\n"
        : "PLOS: registry refreshed; application databases unchanged.\n";
} catch (Throwable $error) {
    echo 'PLOS: '.CliRegistrySynchronizer::publicFailure($error->getMessage())." Using saved registry.\n";
    exit(1);
}
