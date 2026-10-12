<?php

use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliConfiguration;
use App\Services\UnifiedCli\CliDataBindings;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliLocalMcpServices;
use App\Services\UnifiedCli\CliMcpToolRuntime;
use App\Services\UnifiedCli\CliStateStore;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    if ($argc !== 5 || ! is_file($argv[2].'/state.sqlite')) {
        throw new RuntimeException('Existing internal worker state is required.');
    }
    $root = dirname(__DIR__, 2);
    $request = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $app = new Application($root);
    $app->instance('config', new Repository(['offline_policy' => require $root.'/config/offline_policy.php']));
    Facade::setFacadeApplication($app);
    $store = new CliStateStore($argv[2].'/state.sqlite');
    $configuration = (new CliConfiguration($store))->effective($store->session($argv[3]));
    if (hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR)) !== ($request['configuration_sha256'] ?? null)) {
        throw new RuntimeException('Selected configuration changed before internal dispatch.');
    }
    $metadata = $values = [];
    foreach ($request['credentials'] as $reference => $value) {
        if (! is_string($reference) || (! str_starts_with($reference, 'host:')
            && ! str_starts_with($reference, 'mcp:lemma-pod:')) || ! is_string($value)) {
            throw new RuntimeException('Invalid internal credential binding.');
        }
        $metadata[$reference] = ['source' => 'env', 'name' => 'PLOS_WORKER_'.hash('sha256', $reference)];
        $values[$metadata[$reference]['name']] = $value;
    }
    $bindings = new CliLocalBindings($metadata, fn ($name) => $values[$name] ?? null);
    unset($request['credentials']);
    $artifacts = new CliArtifactStore($argv[2].'/artifacts');
    $services = new CliLocalMcpServices($store, $argv[3], $argv[1], $artifacts,
        new CliDataBindings($store, $argv[3], $argv[2].'/data-bindings.json', $bindings), $argv[4]);
    $runtime = new CliMcpToolRuntime($store, $argv[3], $root, $argv[4], $artifacts,
        $bindings->resolveMcp(...),
        $services->allows(...), internalToolDispatcher: $services->dispatch(...), internalOnly: true);
    $result = $runtime->call($request['server'], $request['tool'], $request['arguments'], $request['context'], $request['definition']);
    fwrite(STDOUT, json_encode(['schema' => 'plos.cli.internal-result.v1', 'result' => $result],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
} catch (Throwable) {
    // Credentials and service exception text never enter transport diagnostics.
    fwrite(STDERR, "Internal PLOS adapter did not complete.\n");
    exit(1);
}
