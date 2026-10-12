<?php

namespace App\Services\UnifiedCli;

use App\Services\OfflinePolicyService;
use Closure;
use RuntimeException;

/** Session providers for existing internal MCP mappings; never a global binding. */
final class CliMcpRouterContext
{
    private bool $serviceBound = false;

    public function __construct(
        public readonly CliSystemConfigService $settings,
        public readonly OfflinePolicyService $policy,
        private readonly Closure $serverDefinitions,
        private readonly Closure $admitTool,
        private readonly Closure $serviceFactory,
        private readonly ?Closure $failureRecorder = null,
        private readonly ?Closure $toolDispatcher = null,
    ) {}

    public function servers(): array
    {
        $servers = ($this->serverDefinitions)();
        if (! is_array($servers)) {
            throw new RuntimeException('Current session MCP definitions are unavailable.');
        }

        return $servers;
    }

    public function allows(string $server, string $tool): bool
    {
        return ($this->admitTool)($server, $tool) === true;
    }

    public function service(string $server, string $class): object
    {
        $definition = $this->servers()[$server] ?? [];
        if (($definition['enabled'] ?? false) !== true || ($definition['type'] ?? null) !== 'internal'
            || ($definition['service'] ?? null) !== $class) {
            throw new RuntimeException('Internal MCP service differs from the current session definition.');
        }
        $service = ($this->serviceFactory)($server, $class);
        if (! $service instanceof $class) {
            throw new RuntimeException('Internal MCP service is not bound to this session and operation target.');
        }
        $this->serviceBound = true;

        return $service;
    }

    public function invoke(string $server, string $tool, Closure $dispatch, array $arguments = []): mixed
    {
        $previous = $this->serviceBound;
        $this->serviceBound = false;
        try {
            if ($this->toolDispatcher !== null) {
                $this->serviceBound = true;

                return ($this->toolDispatcher)($server, $tool, $arguments);
            }

            return $dispatch();
        } catch (\Throwable $error) {
            if (! $this->serviceBound) {
                throw $error;
            }
            try {
                if ($this->failureRecorder !== null) {
                    ($this->failureRecorder)($server, $tool, $error);
                }
            } finally {
                throw new CliToolOutcomeUnknown('Internal MCP service failed after dispatch; inspect its outcome before resuming.');
            }
        } finally {
            $this->serviceBound = $previous;
        }
    }
}
