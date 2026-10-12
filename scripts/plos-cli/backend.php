<?php

use App\Services\UnifiedCli\CliAgentActivation;
use App\Services\UnifiedCli\CliAgentAdmission;
use App\Services\UnifiedCli\CliAgentBackend;
use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliDataBindings;
use App\Services\UnifiedCli\CliDevelopmentDatabaseSync;
use App\Services\UnifiedCli\CliGenealogyMediaInput;
use App\Services\UnifiedCli\CliHostLifecycle;
use App\Services\UnifiedCli\CliImageGeneration;
use App\Services\UnifiedCli\CliLocalBindings;
use App\Services\UnifiedCli\CliMcpToolRuntime;
use App\Services\UnifiedCli\CliModelHostStatus;
use App\Services\UnifiedCli\CliNativeChannel;
use App\Services\UnifiedCli\CliNativeOperator;
use App\Services\UnifiedCli\CliOperatorTool;
use App\Services\UnifiedCli\CliRegistrySynchronizer;
use App\Services\UnifiedCli\CliStateStore;
use App\Services\UnifiedCli\CliWorkflowMediaProfile;
use App\Services\UnifiedCli\CliWorkflowServices;
use App\Services\UnifiedCli\CodexProtocolService;
use GuzzleHttp\Client;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if ($argc !== 3 || ! is_dir($argv[1]) || ! is_dir($argv[2])) {
    fwrite(STDERR, "Usage: backend.php CANONICAL_WORKSPACE PRIVATE_STATE_DIRECTORY\n");
    exit(2);
}

$store = new CliStateStore($argv[2].'/state.sqlite');
$nativeChannel = null;
if (getenv('PLOS_CLI_NATIVE_CHANNEL') === 'pipes-v1') {
    $nativeChannel = CliNativeChannel::open($store);
}
$plosRoot = dirname(__DIR__, 2);
// Only framework configuration needed by local PLOS policy. No application
// kernel/providers, shared database bootstrap or production environment file.
$app = new Application($plosRoot);
$app->instance('config', new Repository(['offline_policy' => require $plosRoot.'/config/offline_policy.php']));
Facade::setFacadeApplication($app);
$source = getenv('PLOS_CLI_SYNC_SOURCE');
$synchronizer = is_string($source) && $source !== '' ? new CliRegistrySynchronizer($store, $argv[2], $source,
    developmentDatabaseSync: CliDevelopmentDatabaseSync::forApplication($plosRoot)) : null;
$controller = function ($session) use ($store, $argv): CliModelHostStatus {
    $path = $argv[2].'/controller-binding.json';
    $transport = file_exists($path) ? json_decode(CliLocalBindings::readPrivateFile($path), true, flags: JSON_THROW_ON_ERROR) : [];
    if (! is_array($transport)) {
        throw new RuntimeException('A local controller binding is required.');
    }
    $path = $argv[2].'/bindings.json';
    $bindings = file_exists($path) ? CliLocalBindings::fromFile($path) : new CliLocalBindings;

    return new CliModelHostStatus($store, $session['id'], $bindings,
        new CliArtifactStore($argv[2].'/artifacts'), new Client, $transport);
};
$agentBackend = function () use ($store, $argv, $plosRoot, $controller, $nativeChannel): CliAgentBackend {
    $node = getenv('PLOS_CLI_NODE');
    if (! is_string($node) || ! is_executable($node)) {
        throw new RuntimeException('The CLI Node runtime is unavailable.');
    }
    $path = $argv[2].'/bindings.json';
    $bindings = file_exists($path) ? CliLocalBindings::fromFile($path) : new CliLocalBindings;
    $http = new Client;
    $admission = new CliAgentAdmission($store, $bindings, new CliArtifactStore($argv[2].'/artifacts'), $http,
        function ($session, ?string $target = null) use ($controller) {
            $connection = $controller($session);

            return $connection->status($target) + ['controller_identity' => $connection->identity()];
        });

    return new CliAgentBackend($store, $argv[1], $argv[2], $plosRoot, $node, $bindings, $http,
        $admission->admit(...), null,
        function ($session, $route, $definition = null) use ($store, $argv, $bindings): array {
            return (new CliDataBindings($store, $session['id'], $argv[2].'/data-bindings.json', $bindings))->operationContext($definition);
        },
        leaseKeeperFactory: fn ($session, $route) => (new CliHostLifecycle($store, $session['id'], $controller($session)))
            ->keeper($route['controller_target'], $route['model']),
        runtimePreparation: (new CliAgentActivation($store, $admission->qualification(...), $controller))->prepare(...),
        nativeChannel: $nativeChannel);
};
$service = new CodexProtocolService(
    $store,
    $argv[1],
    $argv[2],
    agent: fn ($session, $input) => $agentBackend()->run($session, $input),
    continueAgent: fn ($session) => $agentBackend()->resume($session),
    operatorTool: fn ($session, $command) => (new CliOperatorTool($store, $argv[1], $argv[2], $plosRoot,
        getenv('PLOS_CLI_NODE') ?: ''))->run($session['id'], $command),
    nativeReadOnly: $nativeChannel === null ? null : fn ($session, $staged) => (new CliNativeOperator($store, $argv[1], $nativeChannel))
        ->diff($session['id'], $staged, new CliArtifactStore($argv[2].'/artifacts')),
    compactAgent: fn ($session, $confirmation) => $agentBackend()->compact($session, $confirmation),
    registrySync: $synchronizer === null ? null : $synchronizer->sync(...),
    registryPreview: $synchronizer === null ? null : $synchronizer->preview(...),
    networkMode: getenv('PLOS_CLI_NETWORK') ?: null,
    dataTargetStatus: function ($session) use ($store, $argv) {
        $api = new CliWorkflowServices($store, $session['id'], new CliArtifactStore($argv[2].'/artifacts'), new CliLocalBindings, new Client);

        if (($target = $api->inspectTarget()) !== null) {
            return $target;
        }

        return (new CliDataBindings($store, $session['id'], $argv[2].'/data-bindings.json', new CliLocalBindings))->inspect();
    },
    mediaInput: function ($session, $mediaId) use ($store, $argv, $plosRoot, $controller) {
        $path = $argv[2].'/bindings.json';
        $bindings = file_exists($path) ? CliLocalBindings::fromFile($path) : new CliLocalBindings;
        $data = new CliDataBindings($store, $session['id'], $argv[2].'/data-bindings.json', $bindings);
        $artifacts = new CliArtifactStore($argv[2].'/artifacts');
        $services = new CliWorkflowServices($store, $session['id'], $artifacts, $bindings, new Client);
        $mcp = new CliMcpToolRuntime($store, $session['id'], $plosRoot, getenv('PLOS_CLI_NODE') ?: '',
            $artifacts, $bindings->resolveMcp(...), workflowServices: $services);
        $keeper = ($session['host_lease']['phase'] ?? null) === 'owned'
            ? (new CliHostLifecycle($store, $session['id'], $controller($session)))
                ->keeper($session['host_lease']['target'], $session['host_lease']['model']) : null;
        $workflow = new CliWorkflowMediaProfile($store, $session['id'], $argv[1], $services, $mcp, $keeper);

        return (new CliGenealogyMediaInput($store, $session['id'], $data, $artifacts, $workflow))->capture($mediaId);
    },
    mediaKeeperFactory: fn ($session) => (new CliHostLifecycle($store, $session['id'], $controller($session)))
        ->keeper($session['host_lease']['target'], $session['host_lease']['model']),
    imageGenerate: fn ($session, $model, $prompt) => (new CliImageGeneration(
        $store, $session['id'], $plosRoot, new CliArtifactStore($argv[2].'/artifacts')))->generate($model, $prompt),
    hostStatus: fn ($session) => $controller($session)->status(),
    hostLifecycle: function ($session, $operation, $target, $override) use ($store, $controller) {
        $connection = $controller($session);
        $lifecycle = new CliHostLifecycle($store, $session['id'], $connection);

        return match ($operation) {
            'lease' => $lifecycle->inspect(),
            'activate' => $lifecycle->activate($target, $override),
            'heartbeat' => $lifecycle->heartbeat(),
            'release' => $lifecycle->release(),
            'reconcile' => $lifecycle->reconcile(),
            'plan' => ['plan' => $connection->client()->plan($target, 'cli-plan-'.Str::uuid()), 'runtime_admission_checked' => false],
        };
    },
    mcpDiscover: function ($session, $server, $context) use ($store, $plosRoot, $argv) {
        $node = getenv('PLOS_CLI_NODE');
        if (! is_string($node) || ! is_executable($node)) {
            throw new RuntimeException('The CLI Node runtime is unavailable.');
        }
        $bindingFile = $argv[2].'/bindings.json';
        $bindings = file_exists($bindingFile) ? CliLocalBindings::fromFile($bindingFile) : new CliLocalBindings;
        $runtime = new CliMcpToolRuntime($store, $session['id'], $plosRoot, $node,
            new CliArtifactStore($argv[2].'/artifacts'), $bindings->resolveMcp(...));

        return $runtime->discover($server, $context);
    },
);

while (($line = fgets(STDIN)) !== false) {
    try {
        $request = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($request)) {
            throw new InvalidArgumentException('Expected an RPC object.');
        }
        foreach ($service->handle($request) as $message) {
            fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            fflush(STDOUT);
        }
    } catch (Throwable) {
        // No request bodies, credentials or PHP exception trace in transport logs.
        fwrite(STDOUT, "{\"id\":null,\"error\":{\"code\":-32700,\"message\":\"Invalid protocol request.\"}}\n");
        fflush(STDOUT);
    }
}
