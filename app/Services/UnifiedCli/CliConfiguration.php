<?php

namespace App\Services\UnifiedCli;

use App\Services\LlmRoutePolicyService;
use InvalidArgumentException;

/** Effective session settings; this service never writes production configuration. */
final class CliConfiguration
{
    public function __construct(private readonly CliStateStore $store) {}

    public function effective(array $session): array
    {
        $snapshot = isset($session['generation']) ? $this->store->snapshot($session['generation']) : null;
        // No extra CLI connectivity gate by default. Synced policy and explicit
        // local/session restrictions retain their normal precedence below.
        $values = ['cli.network' => 'online', 'cli.workflow_target' => 'local', 'cli.operator_mode' => 'operator'];
        $origins = array_fill_keys(array_keys($values), 'default');
        foreach ($this->definitions($session, 'system_configs') as $row) {
            $key = $row['section'].'.'.$row['config_key'];
            $values[$key] = CliSettingValue::decode($row['config_value'] ?? null, $row['data_type'] ?? 'string');
            $origins[$key] = 'synced';
        }
        if (isset($values['cli.policy_profile'])) {
            $values['routing.active_profile'] = $values['cli.policy_profile'];
            $origins['routing.active_profile'] = 'synced:cli.policy_profile';
        }
        foreach (['local' => $this->store->defaults(), 'session' => $session['overrides'] ?? []] as $origin => $overlay) {
            if (array_key_exists('cli.policy_profile', $overlay) && ! array_key_exists('routing.active_profile', $overlay)) {
                $overlay['routing.active_profile'] = $overlay['cli.policy_profile'];
            }
            foreach ($overlay as $key => $value) {
                $values[$key] = $value;
                $origins[$key] = $origin;
            }
        }

        return ['generation' => $snapshot['generation'] ?? null, 'values' => $values, 'origins' => $origins,
            'input_integrity' => ['compaction' => false, 'manual_compaction' => 'explicit_confirmation_required',
                'optimization' => false, 'rewriting' => false, 'truncation' => false]];
    }

    /** Every instance/model row is discoverable, including unavailable candidates. */
    public function models(?string $generation = null, array $overrides = []): array
    {
        $snapshot = $this->store->snapshot($generation)['snapshot'] ?? null;
        $tables = $snapshot === null ? [] : (new CliRegistryOverlay)->apply($snapshot, array_replace($this->store->defaults(), $overrides))['tables'];
        $instances = [];
        foreach ($tables['llm_instances'] ?? [] as $row) {
            $instances[(string) $row['id']] = $row;
        }
        $rows = $tables['ollama_models'] ?? [];
        $registered = [];
        foreach ($rows as $row) {
            $registered[($row['instance_id'] ?? 'catalog').'::'.$row['model_name']] = true;
        }
        // Some engines advertise models before a model-detail row is synced.
        // Show those names without inventing availability or capabilities.
        foreach ($instances as $instance) {
            foreach (CliSettingValue::decode($instance['supported_models'] ?? [], 'json') as $name) {
                if (is_string($name) && ! isset($registered[$instance['id'].'::'.$name])) {
                    $rows[] = ['instance_id' => $instance['id'], 'model_name' => $name,
                        'status' => 'details_not_registered', 'is_available' => false,
                        'catalog_source' => 'llm_instances.supported_models'];
                    $registered[$instance['id'].'::'.$name] = true;
                }
            }
        }
        $result = [];
        foreach ($rows as $row) {
            $instance = $row['instance_id'] === null ? null : $instances[(string) $row['instance_id']];
            $host = $instance === null ? null : parse_url($instance['base_url'] ?? '', PHP_URL_HOST);
            $host = is_string($host) && $host !== '' ? trim($host, '[]') : null;
            $shortHost = $host;
            if ($host === '::1' || ($host !== null && str_starts_with($host, '127.'))) {
                $shortHost = 'localhost';
            } elseif ($host !== null && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $shortHost = '.'.substr($host, strrpos($host, '.') + 1);
            }
            $requireCachedHealth = (new LlmRoutePolicyService)->requiresCachedHealth(
                CliSettingValue::decode($instance['config'] ?? [], 'json'));
            $result[] = [
                'id' => ($instance['instance_id'] ?? 'catalog').'::'.$row['model_name'],
                'instance' => $instance['instance_id'] ?? null,
                'engine' => $instance['compat_runtime_family'] ?? $instance['instance_type'] ?? null,
                'model' => $row['model_name'],
                'display_name' => $row['display_name'] ?? $row['model_name'],
                'description' => $row['description'] ?? null,
                'host' => $host, 'short_host' => $shortHost,
                'catalog_source' => $row['catalog_source'] ?? 'ollama_models',
                'use_cases' => CliSettingValue::decode($row['use_cases'] ?? [], 'json'),
                'status' => $row['status'] ?? 'unknown',
                'available' => $instance !== null && $this->bool($row['is_available'] ?? false)
                    && $this->bool($instance['is_active'] ?? false) && (! $requireCachedHealth || $this->bool($instance['is_healthy'] ?? false))
                    && ($instance['routability'] ?? '') === 'allowed',
                'registry_available' => $this->bool($row['is_available'] ?? false),
                'is_censored' => $instance === null ? null : $this->bool($instance['is_censored'] ?? true),
                'allows_private_data' => $instance !== null && $this->bool($instance['allows_private_data'] ?? false),
                'context_length' => $row['context_length'] ?? $instance['context_length'] ?? null,
                'size_gb' => $row['size_gb'] ?? null,
                'vram_required_mb' => $row['vram_required_mb'] ?? null,
                'quality_rating' => $row['quality_rating'] ?? null,
                'vetted_at' => $row['vetted_at'] ?? null,
                'capabilities' => CliSettingValue::decode($row['capabilities'] ?? [], 'json'),
                'profiles' => array_values(array_filter($tables['llm_model_profiles'] ?? [], fn ($profile) => ($profile['model_name'] ?? null) === $row['model_name'])),
            ];
        }

        return $result;
    }

    public function browseModels(array $session, string $query = ''): array
    {
        $models = $this->modelsForSession($session);
        if ($query === '') {
            return $models;
        }

        return array_values(array_filter($models, function ($model) use ($query) {
            $searchable = array_intersect_key($model, array_flip(['id', 'model', 'display_name', 'description',
                'engine', 'instance', 'host', 'short_host', 'capabilities', 'status', 'use_cases']));

            return mb_stripos(json_encode($searchable, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $query) !== false;
        }));
    }

    public function selectModel(array $session, string $id): array
    {
        foreach ($this->modelsForSession($session) as $model) {
            if ($model['id'] === $id) {
                $session['overrides']['cli.model'] = $id;

                return $this->store->saveSession($session);
            }
        }
        throw new InvalidArgumentException('Unknown exact instance/model binding. Use /model to inspect the synced registry.');
    }

    public function modelsForSession(array $session): array
    {
        return isset($session['generation']) ? $this->models($session['generation'], $session['overrides'] ?? []) : [];
    }

    public function profiles(array $session): array
    {
        return $this->definitions($session, 'llm_model_profiles');
    }

    /** Explicit selections take precedence over the table-owned startup skill. */
    public function selectedSkillName(array $session): ?string
    {
        $values = $this->effective($session)['values'];

        return $values['cli.skill'] ?? $this->agentForSession($session)['agent_id'] ?? $values['cli.default_skill'] ?? null;
    }

    public function agentForSession(array $session): ?array
    {
        $id = $this->effective($session)['values']['cli.agent'] ?? null;
        if ($id === null) {
            return null;
        }
        $matches = array_values(array_filter($this->definitions($session, 'agent_handoff_agents'), fn ($row) => ($row['agent_id'] ?? null) === $id));
        if (count($matches) !== 1 || ! $this->bool($matches[0]['is_active'] ?? false)) {
            throw new InvalidArgumentException('Selected agent is inactive, missing or ambiguous in the current registry.');
        }

        return $matches[0];
    }

    /** Operator selection alone never binds a database or grants tool authority. */
    public function operationSelection(array $session, bool $workflow = false): array
    {
        $values = $this->effective($session)['values'];
        $target = array_key_exists('cli.target', $values) ? $values['cli.target']
            : ($workflow ? ($values['cli.workflow_target'] ?? 'local') : null);
        $tree = $values['cli.tree_id'] ?? null;
        $this->validateOperationSelection('cli.target', $target);
        $this->validateOperationSelection('cli.tree_id', $tree);

        return ['target' => $target, 'tree_id' => $tree];
    }

    /** A bound service must agree with every explicitly selected target field. */
    public function requireOperationContext(array $session, array $context): void
    {
        $values = $this->effective($session)['values'];
        $selection = $this->operationSelection($session);
        foreach (['cli.target' => ['target', 'operation_target'], 'cli.tree_id' => ['tree_id', 'tree_id']] as $key => [$selected, $bound]) {
            if (array_key_exists($key, $values) && ($context[$bound] ?? null) !== $selection[$selected]) {
                throw new InvalidArgumentException('Bound service context differs from the selected '.$selected.'. Select its matching data binding before executing tools.');
            }
        }
    }

    private function validateOperationSelection(string $key, mixed $value): void
    {
        if ($key === 'cli.target' && $value !== null
            && (! is_string($value) || ! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value))) {
            throw new InvalidArgumentException('Data target must be a lowercase local binding name, or null to clear the selection.');
        }
        if ($key === 'cli.tree_id' && $value !== null && (! is_int($value) || $value < 1)) {
            throw new InvalidArgumentException('Tree selection must be a positive integer, or null to clear it.');
        }
    }

    public function definitions(array $session, string $table): array
    {
        return $this->snapshotForSession($session)['tables'][$table] ?? [];
    }

    private function snapshotForSession(array $session): array
    {
        if (! isset($session['generation'])) {
            return [];
        }

        return (new CliRegistryOverlay)->apply($this->store->snapshot($session['generation'])['snapshot'],
            array_replace($this->store->defaults(), $session['overrides'] ?? []));
    }

    public function skills(array $session, bool $includeDisabled = false): array
    {
        $snapshot = $this->snapshotForSession($session);
        $bundles = array_column($snapshot['skill_bundles'] ?? [], null, 'skill_name');
        $sourceActiveIds = [];
        if ($includeDisabled && isset($session['generation'])) {
            foreach ($this->store->snapshot($session['generation'])['snapshot']['tables']['skill_versions'] ?? [] as $source) {
                if (isset($source['id']) && $this->bool($source['is_active'] ?? false)) {
                    $sourceActiveIds[(string) $source['id']] = true;
                }
            }
        }
        $result = [];
        foreach ($snapshot['tables']['skill_versions'] ?? [] as $skill) {
            $active = $this->bool($skill['is_active'] ?? false);
            if (! $active && (! $includeDisabled || ! isset($sourceActiveIds[(string) ($skill['id'] ?? '')]))) {
                continue;
            }
            $bundle = $bundles[$skill['skill_name']] ?? null;
            $result[] = ['name' => $skill['skill_name'], 'version' => $skill['version'],
                'content_hash' => $skill['content_hash'], 'bundle_present' => $bundle !== null,
                'version_matches' => $bundle['version_matches'] ?? false,
                'asset_count' => count($bundle['files'] ?? []), 'permissions' => $skill['permissions']]
                + ($includeDisabled ? ['active' => $active, 'registry_id' => $skill['id'] ?? null, 'active_value' => $skill['is_active']] : []);
        }

        return $result;
    }

    public function skillBundle(array $session, string $name): array
    {
        foreach ($this->snapshotForSession($session)['skill_bundles'] ?? [] as $bundle) {
            if ($bundle['skill_name'] === $name && $bundle['version_matches'] === true) {
                return $bundle;
            }
        }
        throw new InvalidArgumentException('A complete skill bundle matching its table version is required.');
    }

    public function roles(array $session): array
    {
        $roles = [];
        foreach ($this->definitions($session, 'llm_instances') as $instance) {
            $config = CliSettingValue::decode($instance['config'] ?? [], 'json');
            foreach (array_keys($config['models'] ?? []) as $role) {
                $roles[$role] = true;
            }
        }

        return array_keys($roles);
    }

    public function reset(array $session, string $key, bool $saved = false): array
    {
        unset($session['overrides'][$key]);
        $session = $this->store->saveSession($session);
        if ($saved) {
            $this->store->resetDefault($key);
        }

        return $session;
    }

    public function set(array $session, string $key, mixed $value, bool $save = false): array
    {
        if ($key === 'cli.compaction_policy') {
            CliCompaction::validatePolicy($value);
        } elseif (in_array($key, ['cli.workflow_model', 'cli.workflow_vision_model', 'cli.workflow_embedding_model'], true)) {
            CliWorkflowModelSelection::validate($value);
            if ($value !== 'auto' && ! in_array($value, array_column($this->modelsForSession($session), 'id'), true)) {
                throw new InvalidArgumentException('Choose an exact supporting model from the synced /models catalog.');
            }
        } elseif ($key === 'cli.workflow_model_role') {
            if (! is_string($value) || ($value !== 'auto' && ! in_array($value, $this->roles($session), true))) {
                throw new InvalidArgumentException('Choose auto or a model role advertised by the synced PLOS tables.');
            }
        } elseif ($key === 'cli.framework_services') {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Framework services must be a JSON object.');
            }
            CliFrameworkServicePolicy::validate($value);
        } elseif ($key === 'cli.workflow_services') {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Workflow services must be a JSON object.');
            }
            CliWorkflowServices::validate($value);
        } elseif (preg_match('/compact|compress|optim|summari|rewrit|truncat/i', $key)) {
            throw new InvalidArgumentException('Input integrity is fixed: no prompt transformation can be enabled.');
        }
        if ($key === 'cli.operator_mode') {
            CliOperatorModes::validate($value);
        } elseif (str_starts_with($key, 'registry.')) {
            if (! isset($session['generation'])) {
                throw new InvalidArgumentException('Sync the registry before overriding its fields.');
            }
            (new CliRegistryOverlay)->apply($this->store->snapshot($session['generation'])['snapshot'],
                array_replace($this->store->defaults(), $session['overrides'] ?? [], [$key => $value]));
        } elseif (in_array($key, ['cli.target', 'cli.tree_id', 'cli.workflow_target'], true)) {
            $this->validateOperationSelection($key === 'cli.workflow_target' ? 'cli.target' : $key, $value);
        } elseif (in_array($key, ['routing.internet_access', 'routing.offline_mode'], true)) {
            if (! in_array($value, ['enabled', 'disabled'], true)) {
                throw new InvalidArgumentException('Routing connectivity switches require enabled or disabled.');
            }
        } elseif ($key === 'cli.network') {
            if (! in_array($value, ['offline', 'lan', 'online'], true)) {
                throw new InvalidArgumentException('Network mode must be offline, lan or online.');
            }
        } elseif ($key === 'cli.media_roots') {
            CliGenealogyMediaInput::validateRoots($value);
        } elseif ($key === 'cli.tls_policy') {
            if (! is_array($value)) {
                throw new InvalidArgumentException('TLS policy must be a JSON object.');
            }
            new CliTlsPolicy($value);
        } elseif ($key === 'cli.host_addresses') {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Host addresses must map lowercase hostnames to literal IP addresses.');
            }
            foreach ($value as $host => $address) {
                if (! is_string($host) || $host !== strtolower($host)
                    || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $host)
                    || @inet_pton($host) !== false || ! is_string($address) || @inet_pton($address) === false) {
                    throw new InvalidArgumentException('Host addresses must map lowercase hostnames to literal IP addresses.');
                }
                (new CliNetworkBoundary($value))->requestOptions('https://'.$host, 'online');
            }
        } elseif ($key === 'cli.controller_url') {
            if (! is_string($value)) {
                throw new InvalidArgumentException('Controller endpoint must be a URL string.');
            }
            // Validate syntax without contacting the endpoint. The active
            // network mode is enforced again when status is actually requested.
            (new CliNetworkBoundary)->requestOptions($value, 'online');
        } elseif ($key === 'cli.engine_activation') {
            if (! in_array($value, ['auto', 'manual'], true)) {
                throw new InvalidArgumentException('Engine activation mode must be auto or manual.');
            }
        } elseif ($key === 'cli.mcp_timeout_ms') {
            if (! is_int($value) || $value < 50 || $value > 3600000) {
                throw new InvalidArgumentException('MCP timeout must be 50 through 3600000 milliseconds.');
            }
        } elseif ($key === 'cli.compute_instance') {
            $matches = array_values(array_filter($this->definitions($session, 'compute_instances'),
                fn ($row) => ($row['instance_id'] ?? null) === $value));
            if (! is_string($value) || ($value !== 'auto' && count($matches) !== 1)) {
                throw new InvalidArgumentException('Unknown or ambiguous compute instance. Use /compute to inspect the pinned registry.');
            }
        } elseif ($key === 'cli.role') {
            if (! is_string($value) || ($value !== 'auto' && ! in_array($value, $this->roles($session), true))) {
                throw new InvalidArgumentException('Unknown model role in the pinned registry.');
            }
        } elseif ($key === 'cli.agent') {
            if (! is_string($value) || ! in_array($value, array_column($this->definitions($session, 'agent_handoff_agents'), 'agent_id'), true)) {
                throw new InvalidArgumentException('Unknown agent in the pinned registry.');
            }
        } elseif ($key === 'cli.skill') {
            $skills = array_column($this->skills($session), null, 'name');
            if (! is_string($value) || ! isset($skills[$value]) || ! $skills[$value]['version_matches']) {
                throw new InvalidArgumentException('Skill requires its complete matching source bundle. Inspect /skills for version disagreements.');
            }
        } elseif ($key === 'cli.model') {
            if (! is_string($value) || ($value !== 'auto' && ! in_array($value, array_column($this->modelsForSession($session), 'id'), true))) {
                throw new InvalidArgumentException('Unknown exact instance/model binding.');
            }
        } elseif ($key === 'cli.engine') {
            if (! is_string($value) || ($value !== 'auto' && ! in_array($value, array_column($this->modelsForSession($session), 'engine'), true))) {
                throw new InvalidArgumentException('Unknown engine in the pinned registry.');
            }
        } elseif ($key === 'cli.profile') {
            if (! is_string($value) || ($value !== 'auto' && ! in_array($value, array_column($this->profiles($session), 'profile_name'), true))) {
                throw new InvalidArgumentException('Unknown profile in the pinned registry.');
            }
        } else {
            $definitions = $this->settingDefinitions($session);
            if (! isset($definitions[$key])) {
                throw new InvalidArgumentException('Unknown setting; sync its table definition before overriding it.');
            }
            CliSettingValue::validate($value, $definitions[$key]);
        }
        $session['overrides'][$key] = $value;
        $saved = $this->store->saveSession($session);
        if ($save) {
            $this->store->saveDefault($key, $value);
        }

        return $saved;
    }

    public function selectedModel(array $session): ?array
    {
        $id = $this->effective($session)['values']['cli.model'] ?? null;
        foreach ($this->modelsForSession($session) as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }

        return null;
    }

    private function settingDefinitions(array $session): array
    {
        $definitions = [];
        $tables = isset($session['generation']) ? ($this->store->snapshot($session['generation'])['snapshot']['tables'] ?? []) : [];
        foreach ($tables['system_configs'] ?? [] as $row) {
            $definitions[$row['section'].'.'.$row['config_key']] = $row['data_type'] ?? 'string';
        }

        return $definitions;
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }
}
