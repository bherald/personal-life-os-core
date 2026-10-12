<?php

use App\Services\UnifiedCli\CliNativeChannel;
use App\Services\UnifiedCli\CliNativeOperator;
use App\Services\UnifiedCli\CliStateStore;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 3 || realpath($argv[1]) !== $argv[1] || ! is_dir($argv[2])) {
    exit(2);
}
$root = dirname(__DIR__, 2);
$app = new Application($root);
$app->instance('config', new Repository(['offline_policy' => require $root.'/config/offline_policy.php']));
Facade::setFacadeApplication($app);
$store = new CliStateStore($argv[2].'/state.sqlite');
$channel = CliNativeChannel::open($store);
$operator = new CliNativeOperator($store, $argv[1], $channel);
while (($line = fgets(STDIN)) !== false) {
    $request = null;
    try {
        $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (($request['method'] ?? null) === 'turn/interrupt') {
            $store->requestTurnCancellation($request['params']['threadId'], $request['params']['turnId']);
            $result = (object) [];
        } else {
            $result = $operator->run($request['params']['threadId'], $request['params']['command'], $request['ownedIds']);
        }
        $response = ['id' => $request['id'], 'result' => $result];
    } catch (Throwable $error) {
        $response = ['id' => $request['id'] ?? null, 'error' => ['code' => -32000, 'message' => $error->getMessage()]];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR)."\n";
    flush();
}
