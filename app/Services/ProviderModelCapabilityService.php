<?php

namespace App\Services;

class ProviderModelCapabilityService
{
    /**
     * Resolve an exact provider/model capability without inferring from model names.
     *
     * @param  array<string,mixed>  $provider
     * @return array{supported:bool,capability:string,model:string,source:string,reason:string,transport:?string}
     */
    public function resolve(array $provider, string $model, string $capability): array
    {
        $model = trim($model);
        $policy = config("provider_model_capabilities.{$capability}", []);
        $providerConfig = is_array($provider['config'] ?? null) ? $provider['config'] : [];
        $providerCapability = is_array($providerConfig[$capability] ?? null)
            ? $providerConfig[$capability]
            : [];
        $modelRecord = $providerCapability['models'][$model] ?? null;
        $modelCapabilities = $providerConfig['model_capabilities'][$model] ?? null;

        $result = [
            'supported' => false,
            'capability' => $capability,
            'model' => $model,
            'source' => 'llm_instances.config',
            'reason' => 'exact_model_capability_not_admitted',
            'transport' => null,
        ];

        if ($model === '') {
            $result['reason'] = 'model_missing';

            return $result;
        }

        if (($providerCapability['enabled'] ?? null) === false
            || $modelRecord === false
            || (is_array($modelRecord) && ($modelRecord['supported'] ?? null) === false)
            || (is_array($modelCapabilities) && ($modelCapabilities[$capability] ?? null) === false)) {
            $result['reason'] = 'explicit_capability_denial';

            return $result;
        }

        $aliases = array_values(array_filter(
            is_array($policy['model_capability_aliases'] ?? null) ? $policy['model_capability_aliases'] : [],
            'is_string'
        ));
        $admitted = $modelRecord === true
            || (is_array($modelRecord) && ($modelRecord['supported'] ?? null) === true)
            || $this->capabilityListAdmits($modelRecord, $aliases)
            || $this->capabilityListAdmits($modelCapabilities, $aliases);

        if (! $admitted) {
            return $result;
        }

        $transport = is_array($modelRecord) && is_string($modelRecord['transport'] ?? null)
            ? $modelRecord['transport']
            : ($providerCapability['transport'] ?? null);
        $allowedTransports = is_array($policy['allowed_transports'] ?? null)
            ? $policy['allowed_transports']
            : [];
        if (! is_string($transport) || ! in_array($transport, $allowedTransports, true)) {
            $result['reason'] = 'unsupported_structured_output_transport';

            return $result;
        }
        $result['transport'] = $transport;

        $localValidation = is_array($modelRecord) && array_key_exists('local_validation', $modelRecord)
            ? $modelRecord['local_validation']
            : ($providerCapability['local_validation'] ?? null);
        if (($policy['require_local_validation'] ?? true) && $localValidation !== true) {
            $result['reason'] = 'local_validation_not_admitted';

            return $result;
        }

        $result['supported'] = true;
        $result['reason'] = 'exact_model_capability_admitted';

        return $result;
    }

    /**
     * @param  list<string>  $aliases
     */
    private function capabilityListAdmits(mixed $value, array $aliases): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($aliases as $alias) {
            if (($value[$alias] ?? null) === true || in_array($alias, $value, true)) {
                return true;
            }
        }

        return false;
    }
}
