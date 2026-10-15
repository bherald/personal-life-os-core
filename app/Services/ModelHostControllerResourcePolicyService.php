<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Table-owned capacity assessment; never grants lifecycle or model-role admission. */
class ModelHostControllerResourcePolicyService
{
    public const CONFIG_KEY = 'model_host_controller_resource_policy';

    private const METRICS = [
        'free_physical_bytes', 'commit_headroom_bytes', 'gpu_free_bytes',
        'drive_c_free_bytes', 'drive_d_free_bytes', 'pagefile_current_bytes', 'pagefile_growth_bytes',
    ];

    /** Only a complete, exact pre-dispatch Plan may authorize a capacity wait. */
    public static function temporaryShortage(array $plan, string $target, string $model, string $engine): ?array
    {
        return self::planRequirements($plan, $target, $model, $engine, false);
    }

    public static function availableCapacity(array $plan, string $target, string $model, string $engine): bool
    {
        return self::planRequirements($plan, $target, $model, $engine, true) !== null;
    }

    private static function planRequirements(array $plan, string $target, string $model, string $engine, bool $eligible): ?array
    {
        if (($plan['schema'] ?? null) !== 'plos.model_host_controller.plan.v1'
            || ($plan['target'] ?? null) !== $target || ($plan['model'] ?? null) !== $model
            || ($plan['engine'] ?? null) !== $engine || ($plan['result'] ?? null) !== ($eligible ? 'ready' : 'rejected')
            || ($plan['contains_prompt_or_response_content'] ?? null) !== false
            || ($plan['exact_identity'] ?? null) !== true || ($plan['activation_allowed'] ?? null) !== true
            || ($plan['task_present'] ?? null) !== true
            || ($plan['basic_resource_gate_passed'] ?? null) !== $eligible
            || ($plan['lifecycle_resource_gate_passed'] ?? null) !== $eligible
            || ($plan['candidate_eligible_after_future_gate'] ?? null) !== $eligible) {
            return null;
        }
        $assessment = $plan['resource_assessment'] ?? null;
        if (! is_array($assessment) || ($assessment['table_owned'] ?? null) !== true
            || ($assessment['eligible'] ?? null) !== $eligible
            || ($assessment['result'] ?? null) !== ($eligible ? 'capacity_available' : 'temporarily_unavailable')
            || ! in_array($assessment['phase'] ?? null, ['cold', 'loaded'], true)
            || ! is_string($assessment['profile'] ?? null) || $assessment['profile'] === ''
            || ! is_string($assessment['policy_revision'] ?? null) || $assessment['policy_revision'] === ''
            || ! is_array($assessment['requirements'] ?? null)
            || ! isset($assessment['requirements']['free_physical_bytes']['minimum'], $assessment['requirements']['commit_headroom_bytes']['minimum'])) {
            return null;
        }
        $shortages = [];
        foreach ($assessment['requirements'] as $metric => $rule) {
            if (! in_array($metric, self::METRICS, true) || ! is_array($rule)
                || array_diff(array_keys($rule), ['minimum', 'maximum', 'available', 'passed']) !== []
                || ! is_int($rule['available'] ?? null) || $rule['available'] < 0 || ! is_bool($rule['passed'] ?? null)
                || (! array_key_exists('minimum', $rule) && ! array_key_exists('maximum', $rule))) {
                return null;
            }
            foreach (['minimum', 'maximum'] as $bound) {
                if (array_key_exists($bound, $rule) && (! is_int($rule[$bound]) || $rule[$bound] < 0)) {
                    return null;
                }
            }
            if (isset($rule['minimum'], $rule['maximum']) && $rule['minimum'] > $rule['maximum']) {
                return null;
            }
            $passed = (! isset($rule['minimum']) || $rule['available'] >= $rule['minimum'])
                && (! isset($rule['maximum']) || $rule['available'] <= $rule['maximum']);
            if ($passed !== $rule['passed']) {
                return null;
            }
            if (! $passed) {
                $shortages[$metric] = $rule;
            }
        }

        return $eligible ? ($shortages === [] ? [] : null) : ($shortages === [] ? null : $shortages);
    }

    public function __construct(private readonly ?Closure $localPolicy = null) {}

    /** Registered controller endpoints must enter through their existing custody boundary. */
    public function assertUnmanagedTransportAllowed(string $endpoint): void
    {
        $row = DB::selectOne('SELECT config_value FROM system_configs WHERE section = ? AND config_key = ?', ['ai', self::CONFIG_KEY]);
        if ($row === null) {
            return;
        }
        $policy = $this->normalize(json_decode($row->config_value, true, flags: JSON_THROW_ON_ERROR));
        if (! $policy['enabled']) {
            return;
        }
        if ($this->managedHostForEndpoint($endpoint) !== null) {
            throw new RuntimeException('This registered endpoint requires managed controller custody before inference.');
        }
    }

    /** SQL-owned controller host identities also cover auxiliary ports on that host. */
    public function managedHostForEndpoint(string $endpoint): ?string
    {
        $host = parse_url($endpoint, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('Invalid inference endpoint host.');
        }
        $host = strtolower($host);
        $aliases = json_decode((string) DB::table('system_configs')->where('section', 'ai')
            ->where('config_key', 'controller_transport_host_aliases')->value('config_value'), true) ?? [];
        if (isset($aliases[$host])) {
            if (! is_string($aliases[$host]) || $aliases[$host] === '') {
                throw new RuntimeException('Controller host alias is invalid.');
            }

            return $aliases[$host];
        }
        $identities = [];
        foreach (DB::table('llm_instances')->whereNotNull('base_url')->get(['base_url', 'config']) as $provider) {
            if (strtolower((string) parse_url($provider->base_url, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $config = json_decode($provider->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            foreach ($config['controller_catalog']['targets'] ?? [] as $target) {
                if (is_string($target['controller_host_id'] ?? null)) {
                    $identities[$target['controller_host_id']] = true;
                }
            }
        }
        if (count($identities) > 1) {
            throw new RuntimeException('Inference host belongs to conflicting controller identities.');
        }

        return array_key_first($identities);
    }

    /** Match registered origins, including alternate API paths on the same managed engine. */
    public function managedProvider(string $endpoint): ?object
    {
        $origin = static function (string $url): ?array {
            $parts = parse_url($url);
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }

            return [strtolower($parts['scheme']), strtolower($parts['host']),
                $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)];
        };
        $wanted = $origin($endpoint);
        if ($wanted === null) {
            throw new RuntimeException('Invalid inference endpoint origin.');
        }
        $managedHost = $this->managedHostForEndpoint($endpoint);
        $match = null;
        foreach (DB::table('llm_instances')->whereNotNull('base_url')->get() as $provider) {
            $registeredOrigin = $origin($provider->base_url);
            $config = json_decode($provider->config ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            if (! array_key_exists('controller_catalog', $config)) {
                continue;
            }
            $sameController = $managedHost !== null && array_filter($config['controller_catalog']['targets'] ?? [],
                fn ($target) => ($target['controller_host_id'] ?? null) === $managedHost) !== [];
            if ($registeredOrigin !== $wanted
                && (! $sameController || $registeredOrigin[0] !== $wanted[0] || $registeredOrigin[2] !== $wanted[2])) {
                continue;
            }
            if ($match !== null) {
                throw new RuntimeException('Managed endpoint has ambiguous provider registration.');
            }
            $match = $provider;
        }

        return $match;
    }

    public function required(): array
    {
        if ($this->localPolicy !== null) {
            $policy = ($this->localPolicy)();
        } else {
            $row = DB::selectOne('SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1', ['ai', self::CONFIG_KEY]);
            $policy = json_decode((string) ($row->config_value ?? ''), true);
        }

        return $this->normalize(is_array($policy) ? $policy : []);
    }

    public function normalize(array $policy): array
    {
        if (array_diff(array_keys($policy), ['schema', 'enabled', 'temperature_mode', 'revision', 'maximum_sample_age_ms', 'maximum_future_skew_ms', 'profiles', 'bindings', 'host_reserves']) !== []
            || ($policy['schema'] ?? null) !== 'plos.model_host_controller.resource_policy.v1'
            || ! is_bool($policy['enabled'] ?? null)
            || ($policy['temperature_mode'] ?? null) !== 'observation_only'
            || ! is_string($policy['revision'] ?? null) || trim($policy['revision']) === ''
            || ! $this->nonnegativeInt($policy['maximum_sample_age_ms'] ?? null)
            || $policy['maximum_sample_age_ms'] === 0
            || ! $this->nonnegativeInt($policy['maximum_future_skew_ms'] ?? null)
            || ! is_array($policy['profiles'] ?? null)) {
            throw new RuntimeException('Resource policy is missing or invalid.');
        }
        $reserves = array_key_exists('host_reserves', $policy) ? $policy['host_reserves'] : [];
        if (! is_array($reserves)) {
            throw new RuntimeException('Host resource reserves are invalid.');
        }
        foreach ($reserves as $host => $rules) {
            if (! is_string($host) || trim($host) === '' || ! is_array($rules) || $rules === []) {
                throw new RuntimeException('Host resource reserve identity is invalid.');
            }
            foreach ($rules as $metric => $rule) {
                if (! in_array($metric, ['free_physical_bytes', 'commit_headroom_bytes', 'gpu_free_bytes', 'drive_c_free_bytes', 'drive_d_free_bytes'], true)
                    || ! is_array($rule) || array_keys($rule) !== ['minimum']
                    || ! $this->nonnegativeInt($rule['minimum'])) {
                    throw new RuntimeException('Host resource reserve must define supported minimum headroom.');
                }
            }
        }
        foreach ($policy['profiles'] as $id => $profile) {
            if (! is_string($id) || ! preg_match('/\A[a-z0-9][a-z0-9_.:-]*\z/', $id)
                || ! is_array($profile) || ! is_bool($profile['qualified'] ?? null)
                || array_diff(array_keys($profile), ['qualified', 'host_id', 'target', 'model_identity', 'runtime_identity', 'settings', 'requirements', 'request_contract_sha256', 'request_contracts']) !== []) {
                throw new RuntimeException('Resource profile identity or qualification is invalid.');
            }
            foreach (['host_id', 'target', 'model_identity', 'runtime_identity'] as $field) {
                if (! is_string($profile[$field] ?? null) || trim($profile[$field]) === '') {
                    throw new RuntimeException('Resource profile binding is incomplete.');
                }
            }
            if (array_key_exists('request_contract_sha256', $profile)
                && (! is_string($profile['request_contract_sha256'])
                    || preg_match('/\A[a-f0-9]{64}\z/', $profile['request_contract_sha256']) !== 1)) {
                throw new RuntimeException('Resource profile request contract binding is invalid.');
            }
            if (array_key_exists('request_contracts', $profile)) {
                $variants = $profile['request_contracts'];
                if (array_key_exists('request_contract_sha256', $profile)
                    || ! is_array($variants) || $variants === [] || array_is_list($variants)) {
                    throw new RuntimeException('Resource profile request variants require one explicit named map.');
                }
                foreach ($variants as $name => $digest) {
                    if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_.:-]*\z/', $name) !== 1
                        || ! is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                        throw new RuntimeException('Resource profile request variant is invalid.');
                    }
                }
                if (count(array_unique(array_values($variants))) !== count($variants)) {
                    throw new RuntimeException('Resource profile request variants must have distinct digests.');
                }
            }
            $settings = $profile['settings'] ?? null;
            if (! is_array($settings) || array_diff(array_keys($settings), ['context_tokens', 'parallel_requests', 'mtp_enabled']) !== []
                || ! $this->nonnegativeInt($settings['context_tokens'] ?? null) || $settings['context_tokens'] === 0
                || ! $this->nonnegativeInt($settings['parallel_requests'] ?? null) || $settings['parallel_requests'] === 0
                || ! is_bool($settings['mtp_enabled'] ?? null)) {
                throw new RuntimeException('Resource profile execution settings are invalid.');
            }
            if (! is_array($profile['requirements'] ?? null)
                || array_diff(array_keys($profile['requirements']), ['cold', 'loaded']) !== []
                || ! isset($profile['requirements']['cold'], $profile['requirements']['loaded'])) {
                throw new RuntimeException('Resource profile must define cold and loaded requirements.');
            }
            foreach ($profile['requirements'] as $phase => $rules) {
                if (! is_array($rules) || ! isset($rules['free_physical_bytes']['minimum'], $rules['commit_headroom_bytes']['minimum'])) {
                    throw new RuntimeException('Resource profile requires explicit physical and commit headroom.');
                }
                foreach ($rules as $metric => $rule) {
                    if (! in_array($metric, self::METRICS, true) || ! is_array($rule) || $rule === []
                        || array_diff(array_keys($rule), ['minimum', 'maximum']) !== []) {
                        throw new RuntimeException('Unsupported resource rule; temperature cannot govern admission.');
                    }
                    foreach ($rule as $value) {
                        if (! $this->nonnegativeInt($value)) {
                            throw new RuntimeException('Resource thresholds must be nonnegative integer bytes.');
                        }
                    }
                    if (isset($rule['minimum'], $rule['maximum']) && $rule['minimum'] > $rule['maximum']) {
                        throw new RuntimeException('Resource rule bounds are inconsistent.');
                    }
                }
                foreach ($reserves[$profile['host_id']] ?? [] as $metric => $reserve) {
                    $rule = $rules[$metric] ?? [];
                    $rule['minimum'] = max($rule['minimum'] ?? 0, $reserve['minimum']);
                    if (isset($rule['maximum']) && $rule['minimum'] > $rule['maximum']) {
                        throw new RuntimeException('Resource profile conflicts with its host reserve.');
                    }
                    $policy['profiles'][$id]['requirements'][$phase][$metric] = $rule;
                }
            }
        }
        if (array_key_exists('bindings', $policy) && ! is_array($policy['bindings'])) {
            throw new RuntimeException('Resource policy bindings are invalid.');
        }
        foreach ($policy['bindings'] ?? [] as $host => $targets) {
            if (! is_string($host) || ! is_array($targets)) {
                throw new RuntimeException('Resource policy host binding is invalid.');
            }
            foreach ($targets as $target => $id) {
                $profile = is_string($id) ? ($policy['profiles'][$id] ?? null) : null;
                if (! is_string($target) || $profile === null || $profile['host_id'] !== $host || $profile['target'] !== $target) {
                    throw new RuntimeException('Resource policy target binding is invalid.');
                }
            }
        }

        return $policy;
    }

    /** Stable identity of the resolved, content-free request settings, not a prompt or credential. */
    public static function requestContractIdentity(array $contract): string
    {
        $canonicalize = function (mixed $value) use (&$canonicalize): mixed {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }

                return array_map($canonicalize, $value);
            }
            if (! is_null($value) && ! is_scalar($value)) {
                throw new RuntimeException('Request contract must contain only JSON settings.');
            }

            return $value;
        };

        return hash('sha256', json_encode($canonicalize($contract), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Check actual resolved request settings before activation and again before dispatch. */
    public function assertRequestContract(string $host, string $target, array $contract): ?array
    {
        $policy = $this->required();
        if (! $policy['enabled']) {
            return null;
        }
        $id = $policy['bindings'][$host][$target] ?? null;
        $profile = is_string($id) ? ($policy['profiles'][$id] ?? null) : null;
        if (! is_array($profile) || ! $profile['qualified']
            || ($contract['controller_target'] ?? null) !== $target
            || ($contract['context_window'] ?? null) !== $profile['settings']['context_tokens']
            || ! in_array(self::requestContractIdentity($contract),
                isset($profile['request_contracts']) ? array_values($profile['request_contracts']) : [$profile['request_contract_sha256'] ?? null], true)) {
            throw new RuntimeException('Selected request settings do not match a qualified capacity profile. No model request is admitted.');
        }

        return ['profile' => $id, 'policy_revision' => $policy['revision'],
            'host_id' => $host, 'target' => $target, 'model_identity' => $profile['model_identity'],
            'runtime_identity' => $profile['runtime_identity'],
            // The host attests the complete qualified set; each caller still proves exact membership.
            ...(isset($profile['request_contracts']) ? ['request_contracts' => $profile['request_contracts']]
                : ['request_contract_sha256' => $profile['request_contract_sha256']]),
            'settings' => $profile['settings']];
    }

    public static function assertReceiptBinding(?array $expected, mixed $actual): void
    {
        if (($expected === null && $actual !== null)
            || ($expected !== null && (! is_array($actual)
                || self::requestContractIdentity($expected) !== self::requestContractIdentity($actual)))) {
            throw new RuntimeException('Controller capacity binding differs from the current qualified request profile.');
        }
    }

    /** Bind a reviewed launch contract, including external configuration receipts where present. */
    public static function runtimeIdentity(array $target): string
    {
        $parts = [isset($target['adapter_type']) ? 'plos.model_host_controller.runtime_binding.v2' : 'plos.model_host_controller.runtime_binding.v1'];
        foreach (['control_sha256', 'executable_sha256', 'task_arguments', 'gateway_sha256', 'runtime_receipt_sha256'] as $field) {
            $value = $target[$field] ?? '';
            if (! is_string($value) || str_contains($value, "\n") || str_contains($value, "\r")
                || (in_array($field, ['control_sha256', 'executable_sha256', 'task_arguments'], true) && $value === '')
                || ($field !== 'task_arguments' && $value !== '' && preg_match('/\A[a-f0-9]{64}\z/', $value) !== 1)) {
                throw new RuntimeException('Runtime binding is incomplete.');
            }
            $parts[] = $value;
        }
        if (isset($target['adapter_type'])) {
            foreach (['adapter_type', 'resource_mode'] as $field) {
                if (! is_string($target[$field] ?? null) || preg_match('/[\r\n]/', $target[$field])) {
                    throw new RuntimeException('Runtime adapter binding is incomplete.');
                }
                $parts[] = $field.':'.$target[$field];
            }
            foreach (['control_roots', 'required_process_arguments'] as $field) {
                if (! is_array($target[$field] ?? null) && ! is_object($target[$field] ?? null)) {
                    throw new RuntimeException('Runtime adapter parameter binding is incomplete.');
                }
                $parameters = (array) $target[$field];
                ksort($parameters, SORT_STRING);
                foreach ($parameters as $key => $value) {
                    if (! is_string($key) || ! is_string($value) || preg_match('/[\r\n]/', $key.$value)) {
                        throw new RuntimeException('Runtime adapter parameter binding is invalid.');
                    }
                    $parts[] = $field.':'.$key.'='.$value;
                }
            }
        }

        if (($target['adapter_type'] ?? null) === 'colibri_gateway' && array_key_exists('native_tools_enabled', $target)) {
            if (! is_bool($target['native_tools_enabled'])) {
                throw new RuntimeException('Colibri tool capability binding must be boolean.');
            }
            $parts[] = 'native_tools_enabled:'.($target['native_tools_enabled'] ? 'true' : 'false');
        }
        if (($target['adapter_type'] ?? null) === 'colibri_gateway' && array_key_exists('task_logon_type', $target)) {
            if (! in_array($target['task_logon_type'], ['Interactive', 'S4U'], true)) {
                throw new RuntimeException('Colibri task logon binding is invalid.');
            }
            // Explicit legacy Interactive retains its prior capacity identity.
            if ($target['task_logon_type'] !== 'Interactive') {
                $parts[] = 'task_logon_type:'.$target['task_logon_type'];
            }
        }
        if (in_array($target['adapter_type'] ?? null, ['authenticated_image', 'authenticated_reranker'], true)) {
            foreach (['interpreter_path', 'interpreter_sha256'] as $field) {
                $value = $target[$field] ?? null;
                if (! is_string($value) || $value === '' || preg_match('/[\r\n]/', $value)) {
                    throw new RuntimeException('Image interpreter binding is missing.');
                }
                $parts[] = $field.':'.$value;
            }
        }

        return 'sha256:'.hash('sha256', implode("\n", $parts));
    }

    /** Project table-owned capacity data into a reviewed lifecycle manifest; never install it. */
    public function projectLifecycle(array $manifest): array
    {
        $policy = $this->required();
        $host = $manifest['host_id'] ?? null;
        if (! is_string($host) || ! is_array($manifest['targets'] ?? null) || $manifest['targets'] === []) {
            throw new RuntimeException('Lifecycle projection requires a host and targets.');
        }
        $bindings = $policy['bindings'][$host] ?? [];
        $profiles = [];
        foreach ($manifest['targets'] as $target => $spec) {
            if (! is_array($spec)) {
                throw new RuntimeException('Lifecycle target specification is invalid.');
            }
            // Preserve targets awaiting measurement. The projected policy remains
            // present, so the host refuses unbound targets without a legacy fallback.
            if (! array_key_exists($target, $bindings)) {
                continue;
            }
            $id = $bindings[$target] ?? null;
            $profile = is_string($id) ? ($policy['profiles'][$id] ?? null) : null;
            if ($profile === null || ($spec['model_identity'] ?? null) !== $profile['model_identity']
                || self::runtimeIdentity($spec) !== $profile['runtime_identity']) {
                throw new RuntimeException('Registered lifecycle capacity bindings must match the exact launch contract.');
            }
            $profiles[$id] = $profile;
        }
        if (array_diff(array_keys($bindings), array_keys($manifest['targets'])) !== []) {
            throw new RuntimeException('Capacity binding names an absent lifecycle target.');
        }
        $policy['profiles'] = $profiles;
        $policy['bindings'] = [$host => $bindings];
        $manifest['resource_policy'] = $policy;

        return $manifest;
    }

    /** Evaluate a timestamped snapshot against an exact registered profile; no writes. */
    public function assess(string $profileId, array $snapshot, int $nowMs): array
    {
        $policy = $this->required();
        $profile = $policy['profiles'][$profileId] ?? null;
        $receipt = [
            'schema' => 'plos.model_host_controller.resource_assessment.v1',
            'policy_revision' => $policy['revision'], 'profile' => $profileId,
            'eligible' => false, 'mutation_available' => false,
            'lifecycle_admission_checked' => false, 'model_role_admission_checked' => false,
            'temperature_mode' => 'observation_only', 'contains_prompt_or_response_content' => false,
            'requirements' => [], 'observations' => [],
        ];
        if (! $policy['enabled']) {
            return $receipt + ['result' => 'policy_disabled'];
        }
        if ($profile === null || ! $profile['qualified']) {
            return $receipt + ['result' => 'profile_unqualified'];
        }
        foreach (['host_id', 'target', 'model_identity', 'runtime_identity'] as $field) {
            if (($snapshot[$field] ?? null) !== $profile[$field]) {
                return $receipt + ['result' => 'profile_mismatch'];
            }
        }
        $settings = $snapshot['settings'] ?? null;
        if (! is_array($settings) || count($settings) !== count($profile['settings'])) {
            return $receipt + ['result' => 'profile_mismatch'];
        }
        foreach ($profile['settings'] as $key => $value) {
            if (($settings[$key] ?? null) !== $value) {
                return $receipt + ['result' => 'profile_mismatch'];
            }
        }
        $sampledAt = $snapshot['sampled_at_ms'] ?? null;
        if (! $this->nonnegativeInt($sampledAt) || $nowMs < 0
            || $sampledAt - $nowMs > $policy['maximum_future_skew_ms']
            || $nowMs - $sampledAt > $policy['maximum_sample_age_ms']) {
            return $receipt + ['result' => 'telemetry_stale'];
        }
        $phase = $snapshot['phase'] ?? null;
        if (! is_string($phase) || ! isset($profile['requirements'][$phase])) {
            return $receipt + ['result' => 'profile_mismatch'];
        }
        $receipt['phase'] = $phase;
        $receipt['sampled_at_ms'] = $sampledAt;
        // Temperatures are optional observations; neither missing nor hot sensors affect capacity.
        foreach (['acpi_thermal_celsius', 'gpu_thermal_celsius', 'disk_maximum_thermal_celsius'] as $field) {
            $value = $snapshot['observations'][$field] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $receipt['observations'][$field] = $value;
            }
        }
        $missing = false;
        $shortage = false;
        foreach ($profile['requirements'][$phase] as $metric => $rule) {
            $available = $snapshot['metrics'][$metric] ?? null;
            $valid = $this->nonnegativeInt($available);
            $passed = $valid && (! isset($rule['minimum']) || $available >= $rule['minimum'])
                && (! isset($rule['maximum']) || $available <= $rule['maximum']);
            $receipt['requirements'][$metric] = $rule + ['available' => $valid ? $available : null, 'passed' => $passed];
            $missing = $missing || ! $valid;
            $shortage = $shortage || ! $passed;
        }
        $receipt['eligible'] = ! $shortage;

        return $receipt + ['result' => $missing ? 'telemetry_unavailable' : ($shortage ? 'temporarily_unavailable' : 'capacity_available')];
    }

    private function nonnegativeInt(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
