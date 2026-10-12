<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Run bound local adapters while the owning PHP process maintains its lease. */
final class CliInternalMcpWorker
{
    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly string $workspace,
        private readonly string $stateDirectory,
        private readonly string $plosRoot,
        private readonly string $nodeExecutable,
        private readonly CliLocalBindings $bindings,
        private readonly CliArtifactStore $artifacts,
    ) {}

    public function dispatch(string $server, string $tool, array $arguments, array $definition, array $context, Closure $keeper): array
    {
        $keeper();
        if (! is_file($this->stateDirectory.'/state.sqlite')) {
            throw new RuntimeException('Managed internal services require the existing persistent CLI state.');
        }
        $configuration = (new CliConfiguration($this->store))->effective($this->store->session($this->sessionId));
        $timeout = $configuration['values']['cli.mcp_timeout_ms'] ?? 30000;
        if (! is_int($timeout) || $timeout < 50 || $timeout > 3600000) {
            throw new RuntimeException('Internal MCP timeout requires an explicit valid millisecond value.');
        }
        $credentials = [];
        if (CliLocalMcpServices::requiresDataBinding($server, $tool)) {
            $data = new CliDataBindings($this->store, $this->sessionId, $this->stateDirectory.'/data-bindings.json', $this->bindings);
            foreach ($data->credentialReferences($context) as $reference) {
                $credentials[$reference] = $this->bindings->resolve($reference);
            }
        }
        if ($server === 'lemma-pod') {
            foreach ($definition['environment_bindings'] ?? [] as $binding) {
                $reference = $binding['value_ref'] ?? null;
                if (! is_string($reference) || ! str_starts_with($reference, 'mcp:lemma-pod:')) {
                    throw new RuntimeException('Lemma pod MCP binding is invalid.');
                }
                $credentials[$reference] = $this->bindings->resolveMcp($reference);
            }
        }
        $request = ['server' => $server, 'tool' => $tool, 'arguments' => $arguments, 'context' => $context,
            'definition' => $definition, 'credentials' => $credentials,
            'configuration_sha256' => hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR))];
        $args = [];
        if (is_string(php_ini_loaded_file())) {
            $args = ['-c', php_ini_loaded_file()];
        }
        $args = array_merge($args, ['-d', 'display_errors=stderr', '-d', 'log_errors=0',
            $this->plosRoot.'/scripts/plos-cli/internal-mcp.php', $this->workspace, $this->stateDirectory, $this->sessionId, $this->nodeExecutable]);
        $env = ['PATH' => dirname(PHP_BINARY).PATH_SEPARATOR.dirname($this->nodeExecutable)];
        // Preserve the explicitly selected PHP module configuration in the
        // managed child. Other inherited environment and credentials stay scrubbed.
        $scanDirectory = getenv('PHP_INI_SCAN_DIR');
        if (is_string($scanDirectory)) {
            $env['PHP_INI_SCAN_DIR'] = $scanDirectory;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $env['SystemRoot'] = getenv('SystemRoot') ?: 'C:\\Windows';
        }
        $environment = array_fill_keys(array_filter(array_unique(array_merge(array_keys($_SERVER), array_keys($_ENV), array_keys(getenv()))), is_string(...)), false);
        $environment = array_replace($environment, $env);
        $launch = ['command' => PHP_BINARY, 'args' => $args, 'cwd' => $this->plosRoot, 'env' => $env,
            'input' => json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'timeout_ms' => $timeout];
        $process = new Process([$this->nodeExecutable, $this->plosRoot.'/scripts/plos-cli/internal-operation.mjs'],
            $this->plosRoot, $environment, timeout: ($timeout / 1000) + 5);
        try {
            (new CliMcpWorkerProcess)->run($process, json_encode($launch, JSON_THROW_ON_ERROR), $keeper);
            $keeper();
        } catch (\Throwable) {
            $this->evidence($server, $tool, $process);
            throw new CliToolOutcomeUnknown('Internal worker interrupted; inspect its outcome before resuming.');
        }
        $this->evidence($server, $tool, $process);
        try {
            $receipt = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $original = base64_decode($receipt['stdout_base64'] ?? '', true);
            $response = is_string($original) ? json_decode($original, true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('Internal worker returned no complete result; automatic replay is blocked.');
        }
        if (! $process->isSuccessful() || ($receipt['schema'] ?? null) !== 'plos.cli.internal-operation.v1'
            || ($receipt['completed'] ?? false) !== true || ($response['schema'] ?? null) !== 'plos.cli.internal-result.v1'
            || ! is_array($response['result'] ?? null)) {
            throw new CliToolOutcomeUnknown('Internal worker completion is unverified; automatic replay is blocked.');
        }

        return $response['result'];
    }

    private function evidence(string $server, string $tool, Process $process): void
    {
        try {
            $this->store->recordEvent($this->sessionId, 'mcp_internal_worker', ['server' => $server, 'tool' => $tool,
                'stdout' => $this->artifacts->captureBytes($process->getOutput()),
                'stderr' => $this->artifacts->captureBytes($process->getErrorOutput())]);
        } catch (\Throwable) {
            throw new CliToolOutcomeUnknown('Internal worker evidence could not be saved; automatic replay is blocked.');
        }
    }
}
