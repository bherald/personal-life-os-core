<?php

namespace App\Services\UnifiedCli;

use Closure;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

/** Current table contract, exact controller ownership and authenticated gateway identity. */
final class CliAgentAdmission
{
    private readonly CliAgentRuntime $runtime;

    public function __construct(
        private readonly CliStateStore $store,
        CliLocalBindings $bindings,
        CliArtifactStore $artifacts,
        ClientInterface $http,
        Closure $controllerStatus,
    ) {
        $this->runtime = new CliAgentRuntime($store, $bindings, $artifacts, $http, $controllerStatus);
    }

    public function admit(array $session, array $candidate, array $action = []): array
    {
        $qualification = $this->qualification($candidate, $session);

        return $qualification['allowed'] ? $this->runtime->admit($session, $candidate, $action) : $qualification;
    }

    /** Historical entrypoint name: validate table configuration before activation. */
    public function qualification(array $candidate, ?array $session = null): array
    {
        try {
            $values = $session === null ? [] : (new CliConfiguration($this->store))->effective($session)['values'];
            if (($candidate['instance']['instance_type'] ?? null) === 'ollama') {
                if ($session === null) {
                    throw new RuntimeException('Ollama runtime settings require the current CLI session.');
                }
                $contract = (new CliOllamaRuntimePolicy)->resolve($candidate, $values);
            } elseif (! CliAgentRuntime::isColibriEngine($candidate['engine'] ?? null)
                && CliProviderRuntimePolicy::supports($candidate['instance'])) {
                if ($session === null) {
                    throw new RuntimeException('Provider runtime settings require the current CLI session.');
                }
                $contract = (new CliProviderRuntimePolicy)->resolve($candidate, $values);
            } else {
                $contract = CliAgentRuntime::validateContract($candidate);
            }
            // Callers supplying no session receive only static contract validation;
            // they cannot activate or dispatch through the CLI runtime boundary.
            $capacity = $session === null ? null : CliAgentRuntime::assertCapacityContract($this->store, $session, $contract);

            return ['allowed' => true, 'contract' => $contract, 'capacity_binding' => $capacity];
        } catch (Throwable $error) {
            return ['allowed' => false, 'reason' => $error instanceof RuntimeException ? $error->getMessage()
                : 'Current native CLI runtime verification failed.'];
        }
    }
}
