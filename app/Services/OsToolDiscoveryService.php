<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Observations only: discovering an executable never admits its execution. */
class OsToolDiscoveryService
{
    public const REVISION = 'environment-discovery-20260913-v2';

    private string $definitionSource = 'not_loaded';

    private ?array $environmentCache = null;

    private array $privateExecutablePaths = [];

    public function __construct(
        private readonly ?OsToolEnvironmentDiscovery $environmentDiscovery = null,
        private readonly ?OsToolDocumentationService $documentationService = null,
    ) {}

    public function catalog(): array
    {
        $seed = json_decode(file_get_contents(__DIR__.'/../../resources/os-tools/definitions.json'), true, flags: JSON_THROW_ON_ERROR);
        $definitions = null;
        try {
            $definitions = app(SystemConfigService::class)->get('os_tools.definitions');
        } catch (\Throwable) {
            // Discovery remains usable before DB setup; this seed grants no execution.
        }
        $this->definitionSource = $definitions === null ? 'seed_file_fallback' : 'system_configs:os_tools.definitions';
        $definitions ??= $seed;
        if (! is_array($definitions) || count($definitions) > 500) {
            throw new RuntimeException('Invalid OS-tool definition configuration.');
        }
        foreach ($definitions as $name => &$entry) {
            if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.+-]{0,127}$/D', (string) $name)
                || ! is_array($entry) || ! isset($entry['category'], $entry['paths'], $entry['dependencies'])
                || ! is_array($entry['paths']) || ! is_array($entry['dependencies'])
                || count($entry['paths']) > 16 || count($entry['dependencies']) > 32) {
                throw new RuntimeException('Invalid OS-tool definition entry.');
            }
            $entry += ['package' => null, 'adapter' => null, 'references' => [], 'purpose' => 'Review documentation.'];
            if (! is_string($entry['category']) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $entry['category'])
                || ($entry['package'] !== null && (! is_string($entry['package']) || ! preg_match('/^[a-z0-9][a-z0-9+.-]{0,127}$/D', $entry['package'])))
                || ($entry['adapter'] !== null && (! is_string($entry['adapter']) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $entry['adapter'])))
                || ! is_string($entry['purpose']) || strlen($entry['purpose']) > 1500
                || ! is_array($entry['references']) || count($entry['references']) > 10) {
                throw new RuntimeException('Invalid OS-tool enrichment metadata.');
            }
            foreach ($entry['dependencies'] as $dependency) {
                if (! is_string($dependency) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.+-]{0,127}$/D', $dependency)) {
                    throw new RuntimeException('Invalid dependency name.');
                }
            }
            foreach ($entry['references'] as $reference) {
                if (! is_array($reference) || ! is_string($reference['url'] ?? null) || strlen($reference['url']) > 2048
                    || count($reference) > 4 || array_diff(array_keys($reference), ['url', 'kind', 'verification', 'checked_at'])) {
                    throw new RuntimeException('Invalid reference metadata.');
                }
                foreach ($reference as $field => $value) {
                    if (! is_string($value) || ($field !== 'url' && strlen($value) > 128)) {
                        throw new RuntimeException('Invalid reference field.');
                    }
                }
            }
            $entry['paths'] = array_map(function ($path) {
                if (! is_string($path) || strlen($path) > 4096 || str_contains($path, "\0")) {
                    throw new RuntimeException('Invalid observation path.');
                }

                return str_replace('{home}', rtrim(getenv('HOME') ?: '', '/'), $path);
            }, $entry['paths']);
        }
        unset($entry);

        return $definitions;
    }

    public function environment(): array
    {
        if ($this->environmentCache === null) {
            $discovery = $this->environmentDiscovery ?? new OsToolEnvironmentDiscovery;
            $this->environmentCache = $discovery->discover();
            $this->privateExecutablePaths = $discovery->privateExecutablePaths();
        }

        return $this->environmentCache;
    }

    public function discover(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 500 || $offset < 0 || $offset > 100000) {
            throw new RuntimeException('Use limit 1–500 and nonnegative offset up to 100000.');
        }
        $this->environmentCache = null;
        $this->privateExecutablePaths = [];
        $environment = $this->environment();
        $catalog = $this->catalog();
        $rows = $environment['executable_observations'];
        foreach ($rows as &$row) {
            $matches = array_intersect($row['names'], array_keys($catalog));
            $name = $matches ? reset($matches) : null;
            $row['category'] = $name === null ? 'uncategorized' : $catalog[$name]['category'];
            $row['definition_match'] = $name;
            $row['category_basis'] = $name === null ? 'unclassified' : 'name_match_not_identity_qualification';
            $row['next_step'] = 'documentation_review';
        }
        unset($row);
        $packages = $environment['installed_packages'];
        unset($environment['executable_observations'], $environment['installed_packages']);

        return $environment + ['success' => true, 'status' => ($environment['truncated'] || ($environment['errors'] ?? [])) ? 'partial_observation' : 'bounded_observation_complete', 'definition_source' => $this->definitionSource,
            'known_definitions' => count($catalog), 'executables_total' => count($rows),
            'installed_packages_total' => count($packages), 'offset' => $offset, 'limit' => $limit,
            'next_offset' => $offset + $limit < count($rows) ? $offset + $limit : null,
            'tools' => array_slice($rows, $offset, $limit),
            'package_metadata_note' => 'Packages and executables are separate observations; package ownership is not inferred.',
            'execution_authorized' => false];
    }

    public function review(string $tool, ?string $previous_evidence = null): array
    {
        if (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.+-]{0,127}$/D', $tool)) {
            throw new RuntimeException('Use an observed executable or known definition name, not a path or command.');
        }
        if ($previous_evidence !== null && ! preg_match('/^[a-f0-9]{64}$/D', $previous_evidence)) {
            throw new RuntimeException('Previous review evidence must be a SHA-256 fingerprint.');
        }
        if ($this->windowsPlatform()) {
            return $this->reviewWindowsSelected($tool, $previous_evidence);
        }
        $this->environmentCache = null;
        $this->privateExecutablePaths = [];
        $catalog = $this->catalog();
        $definition = $catalog[$tool] ?? null;
        $selected = $this->linuxPlatform() && $definition !== null;
        $environment = $selected ? $this->linuxSelectedEnvironment($tool, $definition) : $this->environment();
        $observed = array_values(array_filter($environment['executable_observations'], fn ($row) => in_array($tool, $row['names'], true)));
        $packageName = $definition['package'] ?? $tool;
        $package = array_values(array_filter($environment['installed_packages'], fn ($row) => explode(':', $row['name'], 2)[0] === $packageName));
        if ($definition === null && ! $observed && ! $package) {
            throw new RuntimeException('Tool is not observed or defined; add a sourced candidate definition before review.');
        }
        $observedNames = array_merge([], ...array_column($environment['executable_observations'], 'names'));
        $dependencies = array_map(fn ($name) => ['name' => $name,
            'availability' => $selected ? 'declared_catalog_paths_bound_below_not_runtime_qualified'
                : (in_array($name, $observedNames, true) ? 'observed_in_path' : 'not_observed_in_scanned_scope'),
            'qualified' => false], $definition['dependencies'] ?? []);
        $paths = [];
        foreach ($observed as $row) {
            foreach ($this->privateExecutablePaths[$row['id']]['paths'] ?? [] as $path) {
                if ($selected || basename($path) === $tool) {
                    $paths[] = $path;
                }
            }
        }
        $documentationPaths = $selected ? array_values(array_filter($paths, $this->validSelectedPath(...))) : $paths;
        $docs = ($this->documentationService ?? new OsToolDocumentationService)->evidence($tool, $packageName, $documentationPaths);
        if ($selected && ($docs['package_ownership']['status'] ?? null) === 'verified_installed_owner') {
            $owner = $docs['package_ownership'];
            $package = [['name' => $owner['package'], 'version' => $owner['version']]];
        }
        $installedNames = array_map(fn ($row) => explode(':', $row['name'], 2)[0], $environment['installed_packages']);
        foreach ($docs['package_dependencies']['groups'] ?? [] as $index => $group) {
            $docs['package_dependencies']['groups'][$index]['installed_name_matches'] = array_values(array_intersect($group['package_alternatives'], $installedNames));
            $docs['package_dependencies']['groups'][$index]['installed_package_observations'] = array_values(array_map(
                fn ($row) => array_intersect_key($row, array_flip(['name', 'version'])),
                array_filter($environment['installed_packages'], fn ($row) => in_array(explode(':', $row['name'], 2)[0], $group['package_alternatives'], true))));
            $docs['package_dependencies']['groups'][$index]['availability'] = $docs['package_dependencies']['groups'][$index]['installed_name_matches']
                ? 'installed_name_observed_constraints_unverified' : 'not_observed_or_virtual_provider_unresolved';
        }
        if ($selected) {
            $docs['package_dependencies']['runtime_linkage'] = 'not_verified';
        }
        $references = array_values(array_filter($definition['references'] ?? [], fn ($ref) => is_array($ref)
            && is_string($ref['url'] ?? null) && filter_var($ref['url'], FILTER_VALIDATE_URL)
            && parse_url($ref['url'], PHP_URL_SCHEME) === 'https'
            && ! parse_url($ref['url'], PHP_URL_USER) && ! parse_url($ref['url'], PHP_URL_PASS)));

        $binding = $this->reviewBinding($tool, $definition, $observed, $package, $dependencies, $docs, $environment, $catalog, $selected);
        $fingerprint = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $complete = $binding['complete'];

        return ['schema' => 'plos.os-tools.review.v2', 'success' => true, 'tool' => $tool,
            'execution_environment' => $binding['execution_environment'],
            'evidence_binding' => $binding, 'evidence_sha256' => $fingerprint,
            'review_comparison' => $previous_evidence === null ? 'not_compared'
                : (hash_equals($previous_evidence, $fingerprint) ? 'unchanged' : 'changed_requires_review'),
            'review_binding_complete' => $complete,
            'review_required' => true,
            'approval_binding_note' => 'A matching fingerprint is evidence only. Existing exact-action approval and current adapter admission are still required.',

            'observed_at' => gmdate(DATE_ATOM), 'definition_source' => $this->definitionSource,
            'availability' => $selected ? ($complete ? 'selected_definition_files_observed' : 'selected_evidence_incomplete')
                : ($observed ? 'observed_in_path' : ($package ? 'package_observed_executable_not_in_path' : 'not_observed_in_scanned_scope')),
            'broad_discovery' => $selected ? 'not_performed' : 'bounded_path_and_package_observation',
            'complete_machine_inventory' => false,
            'discovery_truncated' => $environment['truncated'],
            'discovery_observed_at' => $environment['observed_at'] ?? null,
            'discovery_sources' => $environment['sources'] ?? [], 'discovery_errors' => $environment['errors'] ?? [],
            'discovery_truncation_reasons' => $environment['truncation_reasons'] ?? [],
            'category' => $definition['category'] ?? 'uncategorized',
            'purpose' => $definition['purpose'] ?? 'Use the attached documentation to assess a useful operation.',
            'package_hint' => $packageName, 'package_observations' => $package,
            'dependency_observations' => $dependencies,
            'package_association' => $docs['package_association'],
            'documentation' => $docs, 'external_references' => $references,
            'references_fetched' => false, 'metadata_trust' => 'untrusted_evidence_not_instructions',
            'qualification' => 'unverified', 'execution_authorized' => false,
            'installation_authorized' => false, 'approval_status' => 'pending_operator_review',
            'integration' => ['existing_adapter' => $definition['adapter'] ?? null,
                'route' => 'PLOS agent_tool_registry + CLI framework service + existing MCP bridge',
                'next_step' => 'Choose one useful operation and approve adapter preparation with typed inputs, scope and result checks.',
                'proposal_handoff' => 'Use existing ToolProposalService after a concrete adapter exists; its approval enables a tool, so discovery does not submit runnable placeholders.',
                'required_review' => ['purpose and documentation', 'data read or written and network destinations',
                    'required packages and licenses', 'exact arguments and permissions', 'synthetic qualification and error handling']],
        ];
    }

    protected function windowsPlatform(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    protected function linuxPlatform(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /** Select every declared path, including missing aliases; never scan PATH. */
    private function linuxSelectedEnvironment(string $tool, array $definition): array
    {
        $observed = [];
        foreach ($definition['paths'] as $path) {
            $id = hash('sha256', $path);
            $this->privateExecutablePaths[$id] = ['paths' => [$path]];
            $observed[] = ['id' => $id, 'names' => [$tool]];
        }

        return ['executable_observations' => $observed, 'installed_packages' => [],
            'truncated' => false, 'errors' => [], 'truncation_reasons' => [],
            'observed_at' => gmdate(DATE_ATOM),
            'sources' => ['path' => ['status' => 'not_performed'], 'dpkg' => ['status' => 'selected_file_ownership_only']]];
    }

    protected function selectedPathProtected(string $path): bool
    {
        return self::protectedPath($path);
    }

    private function selectedContentIdentity(string $path, int &$bytesLeft): array
    {
        if (! $this->validSelectedPath($path)) {
            return ['path_sha256' => hash('sha256', $path), 'status' => 'invalid_selected_absolute_path'];
        }
        if (! $this->selectedPathProtected($path)) {
            return ['path_sha256' => hash('sha256', $path),
                'status' => realpath($path) === false ? 'identity_size_or_type_limit' : 'unprotected_selected_path'];
        }
        $identity = $this->contentIdentity($path, $bytesLeft);
        if (($identity['status'] ?? null) === 'observed' && ! $this->selectedPathProtected($path)) {
            return ['path_sha256' => hash('sha256', $path), 'status' => 'unprotected_selected_path'];
        }

        return $identity;
    }

    private function validSelectedPath(string $path): bool
    {
        return str_starts_with($path, '/') && ! preg_match('/[\x00-\x1f\x7f*?\[\]\\\\]/', $path)
            && ! preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', $path);
    }

    protected function windowsSelection(array $files): array
    {
        return OsToolWindowsEnvironment::observeSelected((string) realpath(base_path()), $files);
    }

    /** Windows selects explicit table-owned paths; it does not claim PATH/dpkg discovery. */
    private function reviewWindowsSelected(string $tool, ?string $previous): array
    {
        $definition = $this->catalog()[$tool] ?? null;
        $files = $definition['windows_review_files'] ?? null;
        if (! is_array($definition) || ! is_array($files) || ! array_is_list($files) || count($files) < 1 || count($files) > 32) {
            throw new RuntimeException('This Windows tool needs reviewed explicit executable, dependency and documentation paths in its existing definition.');
        }
        $executables = array_values(array_filter($files, fn ($file) => ($file['kind'] ?? null) === 'executable'));
        if (count($executables) !== 1 || ! in_array($executables[0]['path'] ?? null, $definition['paths'], true)) {
            throw new RuntimeException('Windows selected review must bind one executable already present in the tool definition.');
        }
        $evidence = $this->windowsSelection($files);
        $binding = ['schema' => 'plos.os-tools.windows-selected-binding.v1', 'tool' => $tool,
            'definition_sha256' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'execution_environment' => $evidence['execution_environment'], 'selected_files' => $evidence['selected_files'],
            'scope' => 'explicit_windows_definition_paths_only',
            'complete' => ($evidence['complete'] ?? false) === true, 'execution_authorized' => false];
        $fingerprint = hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return ['schema' => 'plos.os-tools.review.v2', 'success' => true, 'tool' => $tool,
            'execution_environment' => $binding['execution_environment'], 'evidence_binding' => $binding,
            'evidence_sha256' => $fingerprint, 'review_binding_complete' => $binding['complete'],
            'review_comparison' => $previous === null ? 'not_compared' : (hash_equals($previous, $fingerprint) ? 'unchanged' : 'changed_requires_review'),
            'review_required' => true, 'definition_source' => $this->definitionSource,
            'availability' => $binding['complete'] ? 'selected_definition_files_observed' : 'selected_evidence_incomplete',
            'documentation' => $evidence['documentation'] ?? [],
            'package_association' => 'unknown_windows_package_ownership_not_verified',
            'runtime_version' => 'not_probed_binary_content_only', 'broad_discovery' => 'unsupported_use_selected_definition_paths',
            'qualification' => 'unverified', 'execution_authorized' => false, 'installation_authorized' => false,
            'approval_status' => 'pending_operator_review', 'references_fetched' => false,
            'approval_binding_note' => 'Matching evidence does not authorize execution; the existing exact-action approval is still required.'];
    }

    /** Called by reviewed adapters on the actual job host, before any process. */
    public function assertReviewed(string $tool, string $expected_environment, string $expected_evidence): array
    {
        foreach ([$expected_environment, $expected_evidence] as $pin) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $pin)) {
                throw new RuntimeException('Review the tool on its execution host and supply both exact evidence fingerprints.');
            }
        }
        $review = $this->review($tool, $expected_evidence);
        if (! ($review['review_binding_complete'] ?? false)
            || ! hash_equals($expected_environment, $review['execution_environment']['fingerprint'] ?? '')
            || $review['review_comparison'] !== 'unchanged') {
            throw new RuntimeException('Tool or execution environment evidence changed or is incomplete. Rediscover, re-vet and approve the new exact action before use.');
        }

        return $review;
    }

    /** Local observations, never identity values supplied in model arguments. */
    protected function executionEnvironment(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return OsToolWindowsEnvironment::observe((string) realpath(base_path()));
        }

        $machine = PHP_OS_FAMILY === 'Linux' ? @file_get_contents('/etc/machine-id', false, null, 0, 129) : false;
        $root = realpath('/');
        $stat = $root === false ? false : @stat($root);
        $mountNamespace = PHP_OS_FAMILY === 'Linux' ? @readlink('/proc/self/ns/mnt') : false;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $available = is_string($machine) && preg_match('/^[a-f0-9]{32}\s*$/D', $machine)
            && $stat !== false && is_string($mountNamespace) && is_int($uid);
        $host = ['platform' => PHP_OS_FAMILY, 'architecture' => php_uname('m'),
            'hostname_sha256' => hash('sha256', gethostname() ?: ''),
            'machine_id_sha256' => $available ? hash('sha256', $machine) : null];
        $identity = ['host' => $host, 'root' => $root, 'device' => $stat['dev'] ?? null,
            'inode' => $stat['ino'] ?? null, 'mount_namespace' => $mountNamespace, 'effective_uid' => $uid];

        return ['schema' => 'plos.os-tools.execution-environment.v1', 'platform' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'), 'status' => $available ? 'observed' : 'unsupported_or_unavailable',
            'host_fingerprint' => $available ? hash('sha256', json_encode($host, JSON_THROW_ON_ERROR)) : null,
            'fingerprint' => $available ? hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)) : null,
            'basis' => 'local_hashed_machine_identity_platform_root_mount_namespace_and_account',
            'remote_authentication' => 'provided_by_existing_execution_route_not_this_observation'];
    }

    private function reviewBinding(string $tool, ?array $definition, array $observed, array $packages, array $dependencies, array $docs, array $environment, array $catalog, bool $selected = false): array
    {
        $executables = [];
        $bytesLeft = 67108864;
        foreach ($observed as $row) {
            foreach ($this->privateExecutablePaths[$row['id']]['paths'] ?? [] as $path) {
                if (! $selected && basename($path) !== $tool) {
                    continue;
                }
                if (count($executables) >= 16) {
                    $executables[] = ['status' => 'identity_count_limit'];
                    break 2;
                }
                $executables[] = $selected ? $this->selectedContentIdentity($path, $bytesLeft) : $this->contentIdentity($path, $bytesLeft);
            }
        }
        usort($executables, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        $documents = array_map(fn ($doc) => array_intersect_key($doc, array_flip([
            'source_id', 'kind', 'sha256', 'decoded_sha256', 'source_bytes', 'decoded_bytes',
        ])), $docs['documents'] ?? []);
        usort($documents, fn ($a, $b) => strcmp($a['source_id'], $b['source_id']));
        $packages = array_map(fn ($package) => array_intersect_key($package, array_flip(['name', 'version', 'section'])), $packages);
        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $dependencyIdentities = [];
        $dependencyCount = 0;
        foreach ($definition['dependencies'] ?? [] as $dependency) {
            $depDefinition = $catalog[$dependency] ?? null;
            $identities = [];
            foreach ($depDefinition['paths'] ?? [] as $path) {
                if (! $selected && ! is_file($path) && ! is_link($path)) {
                    continue;
                }
                if ($dependencyCount++ >= 16) {
                    $identities[] = ['status' => 'identity_count_limit'];
                    break;
                }
                $identities[] = $selected ? $this->selectedContentIdentity($path, $bytesLeft) : $this->contentIdentity($path, $bytesLeft);
            }
            $depPackage = $depDefinition['package'] ?? null;
            $versions = array_values(array_map(fn ($entry) => array_intersect_key($entry, array_flip(['name', 'version'])),
                array_filter($environment['installed_packages'] ?? [], fn ($entry) => $depPackage !== null
                    && explode(':', $entry['name'], 2)[0] === $depPackage)));
            $dependencyIdentities[$dependency] = ['identities' => $identities, 'package_observations' => $versions,
                'scope' => 'declared_catalog_paths_only_not_dynamic_dependency_analysis'];
        }
        $local = $this->executionEnvironment();
        $complete = ($local['status'] ?? null) === 'observed' && $executables !== []
            && ! array_filter($executables, fn ($entry) => $entry['status'] !== 'observed')
            && ! ($environment['truncated'] ?? true) && ($environment['errors'] ?? []) === []
            && ($docs['warnings'] ?? []) === [];
        if ($selected) {
            $complete = $complete && ($docs['package_ownership']['status'] ?? null) === 'verified_installed_owner'
                && ($docs['bounded_documentation_complete'] ?? false) === true;
        }
        foreach ($dependencyIdentities as $dependency) {
            if ($dependency['identities'] === [] || array_filter($dependency['identities'], fn ($entry) => $entry['status'] !== 'observed')) {
                $complete = false;
            }
        }

        $selection = $selected ? ['scope' => 'exact_definition_paths_only',
            'catalog_sha256' => hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'broad_discovery' => 'not_performed', 'complete_machine_inventory' => false] : [];

        return $selection + ['schema' => $selected ? 'plos.os-tools.linux-selected-binding.v1' : 'plos.os-tools.evidence-binding.v1', 'tool' => $tool,
            'execution_environment' => $local, 'executables' => $executables,
            'definition_sha256' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'package_observations' => $packages,
            'dependency_observations' => $dependencies, 'declared_dependency_identities' => $dependencyIdentities,
            'package_ownership' => $docs['package_ownership'] ?? ['status' => 'unknown'],
            'package_dependencies' => $docs['package_dependencies'] ?? ['status' => 'unknown'],
            'license_evidence' => $docs['license_evidence'] ?? ['status' => 'missing'],
            'documentation' => ($selected ? [
                'bounded_documentation_complete' => $docs['bounded_documentation_complete'] ?? false,
                'complete_documentation' => false] : []) + ['status' => $docs['status'] ?? 'missing', 'documents' => $documents,
                    'warnings' => $docs['warnings'] ?? [], 'scope' => $docs['scope'] ?? null],
            'discovery_scope' => $selected ? ['scope' => 'exact_definition_paths_only', 'broad_discovery' => 'not_performed',
                'complete_machine_inventory' => false] : ['path_sha256' => hash('sha256', getenv('PATH') ?: ''),
                    'truncated' => $environment['truncated'] ?? true, 'errors' => $environment['errors'] ?? [],
                    'truncation_reasons' => $environment['truncation_reasons'] ?? [],
                    'package_status' => $environment['sources']['dpkg']['status'] ?? 'unavailable'],
            'runtime_version' => 'not_probed_use_package_metadata_and_binary_content',
            'complete' => (bool) $complete, 'execution_authorized' => false];
    }

    /** Read selected executable bytes with a shared bound, never invoke --version. */
    private function contentIdentity(string $path, int &$bytesLeft): array
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        $pathId = hash('sha256', $path);
        $initial = $resolved === false ? false : @stat($resolved);
        if ($initial === false || ($initial['mode'] & 0170000) !== 0100000
            || $initial['size'] > min(33554432, $bytesLeft)) {
            return ['path_sha256' => $pathId, 'status' => 'identity_size_or_type_limit'];
        }
        $handle = @fopen($resolved, 'rb');
        if ($handle === false) {
            return ['path_sha256' => $pathId, 'status' => 'unreadable'];
        }
        try {
            $before = fstat($handle);
            if ($before === false || ($before['mode'] & 0170000) !== 0100000 || $before['size'] > min(33554432, $bytesLeft)) {
                return ['path_sha256' => $pathId, 'status' => 'identity_size_or_type_limit'];
            }
            $bytes = stream_get_contents($handle, min(33554432, $bytesLeft) + 1);
            $after = fstat($handle);
            if (! is_string($bytes) || strlen($bytes) > min(33554432, $bytesLeft)) {
                return ['path_sha256' => $pathId, 'status' => 'identity_size_limit'];
            }
            $bytesLeft -= strlen($bytes);
            clearstatcache(true, $path);
            $current = @stat($path);
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                if ($before[$field] !== ($after[$field] ?? null) || $before[$field] !== ($current[$field] ?? null)) {
                    return ['path_sha256' => $pathId, 'status' => 'identity_changed_during_read'];
                }
            }
            if (realpath($path) !== $resolved || ! is_executable($path)) {
                return ['path_sha256' => $pathId, 'status' => 'identity_changed_during_read'];
            }

            return ['status' => 'observed', 'path_sha256' => $pathId, 'canonical_path_sha256' => hash('sha256', $resolved),
                'content_sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes),
                'device' => $before['dev'], 'inode' => $before['ino'], 'mode' => $before['mode'],
                'uid' => $before['uid'], 'gid' => $before['gid'],
                'symlink_target_sha256' => is_link($path) ? hash('sha256', readlink($path)) : null];
        } finally {
            fclose($handle);
        }
    }

    public function inventory(?string $category = null): array
    {
        $catalog = $this->catalog();
        if ($category !== null && ! in_array($category, array_column($catalog, 'category'), true)) {
            throw new RuntimeException('Unknown tool category.');
        }
        $observations = [];
        foreach ($catalog as $id => $entry) {
            $path = null;
            foreach ($entry['paths'] as $candidate) {
                if (is_file($candidate) || is_link($candidate)) {
                    $path = $candidate;
                    break;
                }
            }
            $identity = $path === null ? null : self::identity($path);
            $package = $entry['package'] === null ? null : $this->package($entry['package']);
            $observations[$id] = [
                'id' => $id, 'category' => $entry['category'], 'package' => $entry['package'],
                'package_installed' => $package['installed'] ?? null, 'package_version' => $package['version'] ?? null,
                'package_lookup' => $entry['package'] === null ? 'unknown_distribution' : ($package['status'] ?? 'unavailable'),
                'binary_present' => $identity !== null, 'executable' => $path !== null && is_executable($path),
                'identity_sha256' => $identity === null ? null : hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
                'protected_identity' => $path !== null && self::protectedPath($path),
                'version_probe_passed' => null, 'runtime_version' => null, 'fixture_probe_passed' => null,
                'qualification' => 'unverified', 'adapter_id' => $entry['adapter'],
                'permission_required' => $entry['adapter'] !== null, 'execution_authorized' => false,
                'requirements' => $entry['dependencies'], 'dependency_missing' => [],
                'capability_requirements' => match ($id) {
                    'shellcheck' => ['Linux x86_64 runtime libraries', 'working unprivileged namespaces', 'reviewed fixture only'],
                    'semgrep' => ['compatible runtime', 'reviewed local rules'],
                    'zap' => ['compatible Java', 'reviewed add-ons and target plan'],
                    'tesseract' => ['selected language data'],
                    'nmap' => ['reviewed network scope; raw modes may require privileges'],
                    default => ['capability-specific fixture not qualified'],
                },
            ];
        }
        foreach ($observations as &$observation) {
            $observation['dependency_missing'] = array_values(array_filter($observation['requirements'],
                fn ($id) => ! ($observations[$id]['executable'] ?? false)));
        }
        unset($observation);

        return ['schema' => 'plos.os-tools.inventory.v1', 'success' => true, 'catalog_revision' => self::REVISION,
            'definition_source' => $this->definitionSource, 'discovery_command' => 'os-tools:discover',
            'observed_at' => gmdate(DATE_ATOM), 'expires_at' => gmdate(DATE_ATOM, time() + 600),
            'os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'scope' => 'curated_paths_in_current_process_account',
            'complete_machine_inventory' => false, 'raw_paths_included' => false,
            'runtime_probes_executed' => false, 'registration_health' => 'not_checked',
            'tools' => array_values(array_filter($observations, fn ($row) => $category === null || $row['category'] === $category))];
    }

    public static function identity(string $path): ?array
    {
        clearstatcache(true, $path);
        $resolved = realpath($path);
        if ($resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }
        $stat = stat($resolved);

        return ['path' => $path, 'resolved' => $resolved, 'sha256' => hash_file('sha256', $resolved),
            'uid' => $stat['uid'], 'mode' => $stat['mode'], 'device' => $stat['dev'], 'inode' => $stat['ino'],
            'symlink' => is_link($path) ? readlink($path) : null];
    }

    public static function protectedPath(string $path): bool
    {
        $resolved = realpath($path);
        if ($resolved === false) {
            return false;
        }
        foreach (array_unique([$path, $resolved]) as $entry) {
            do {
                $stat = lstat($entry);
                if ($stat === false || $stat['uid'] !== 0 || (! is_link($entry) && ($stat['mode'] & 0022) !== 0)) {
                    return false;
                }
                $parent = dirname($entry);
                if ($entry === $parent) {
                    break;
                }
                $entry = $parent;
            } while (true);
        }

        return true;
    }

    private function package(string $name): array
    {
        if (! is_executable('/usr/bin/dpkg-query') || ! self::protectedPath('/usr/bin/dpkg-query')) {
            return ['status' => 'unavailable'];
        }
        try {
            $environment = array_fill_keys(array_keys(getenv()), false);
            $environment['LC_ALL'] = 'C';
            $process = new Process(['/usr/bin/dpkg-query', '-W', '-f=${db:Status-Status}\t${Version}', $name], '/', $environment);
            $process->setTimeout(2)->run();
            if ($process->getExitCode() === 1) {
                return ['status' => 'checked_known_package', 'installed' => false, 'version' => null];
            }
            if (! $process->isSuccessful() || strlen($process->getOutput()) > 1024) {
                return ['status' => 'unavailable'];
            }
            $parts = explode("\t", trim($process->getOutput()), 2);

            return ['status' => 'checked_known_package', 'installed' => $parts[0] === 'installed', 'version' => $parts[1] ?? null];
        } catch (\Throwable) {
            return ['status' => 'unavailable'];
        }
    }
}
