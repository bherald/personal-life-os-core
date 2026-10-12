<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;
use Throwable;

/** Local composition of existing PLOS qualification; never host readiness or permission. */
final class CliManagedReviewAdmission
{
    private readonly CliConfiguration $configuration;

    private readonly string $configurationHash;

    public function __construct(
        private readonly CliStateStore $store,
        private readonly string $sessionId,
        private readonly array $route,
        private readonly CliAgentAdmission $admission,
        private readonly ?Closure $assertActive = null,
    ) {
        $this->configuration = new CliConfiguration($store);
        $this->configurationHash = $this->hash($this->configuration->effective($store->session($sessionId)));
    }

    public function __invoke(string $kind, array $selected, array $action = []): array
    {
        try {
            ($this->assertActive ?? static function (): void {})();
            $session = $this->store->session($this->sessionId);
            $turn = $session['turns'][array_key_last($session['turns'] ?? [])] ?? [];
            if (! in_array($kind, ['tool', 'model'], true) || $selected !== $this->route
                || ($turn['cancel_requested'] ?? false) === true
                || $this->hash($this->configuration->effective($session)) !== $this->configurationHash) {
                throw new RuntimeException('Current managed selection or operator context changed.');
            }
            $values = $this->configuration->effective($session)['values'];
            $candidates = array_values(array_filter((new CliRouteResolver($this->configuration))->inspect($session)['candidates'],
                fn ($candidate) => $candidate['instance_id'] === ($selected['instance_id'] ?? null)
                    && $candidate['model'] === ($selected['model'] ?? null)));
            if (count($candidates) !== 1 || ($candidates[0]['configuration_eligible'] ?? false) !== true
                || ($candidates[0]['native_tools_configured'] ?? false) !== true
                || ($candidates[0]['instance']['instance_type'] ?? null) !== 'ollama'
                || ($candidates[0]['instance']['base_url'] ?? null) !== ($selected['base_url'] ?? null)
                || ($values['cli.network'] ?? null) !== ($selected['network_mode'] ?? null)) {
                throw new RuntimeException('Exact local managed Ollama definition is no longer eligible.');
            }
            $qualification = $this->admission->qualification($candidates[0], $session);
            $contract = $qualification['contract'] ?? [];
            $lease = $session['host_lease'] ?? [];
            if (($qualification['allowed'] ?? false) !== true
                || ($contract['runtime_management'] ?? null) === 'provider'
                || ($contract['controller_target'] ?? null) !== ($selected['controller_target'] ?? null)
                || $this->hash($contract) !== ($selected['runtime_contract_sha256'] ?? null)
                || ($lease['phase'] ?? null) !== 'owned'
                || ($lease['target'] ?? null) !== ($contract['controller_target'] ?? null)
                || ($lease['host_id'] ?? null) !== ($contract['controller_host_id'] ?? null)
                || ($lease['model'] ?? null) !== $selected['model']
                || ! is_string($lease['lease_id'] ?? null) || $lease['lease_id'] === ''
                || ! is_string($lease['controller_identity'] ?? null) || $lease['controller_identity'] === ''
                || strtotime($lease['expires_at'] ?? '') <= time()
                || $this->hash([$lease['controller_identity'], $lease['lease_id']]) !== ($selected['lease_binding_sha256'] ?? null)) {
                throw new RuntimeException('Local runtime contract or saved owned lease changed.');
            }

            return ['allowed' => true, 'runtime_admission_checked' => false];
        } catch (Throwable) {
            return ['allowed' => false, 'reason' => 'Current local managed review binding failed; no host readiness established.'];
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
