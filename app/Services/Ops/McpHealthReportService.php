<?php

namespace App\Services\Ops;

use App\Services\OfflinePolicyService;
use Symfony\Component\Process\Process;
use Throwable;

class McpHealthReportService
{
    private const POLICY_PROFILES = [
        'default',
        'offline_review',
        'offline_dev_assist',
        'offline_genealogy_assist',
        'hybrid_review',
        'hybrid_dev_assist',
        'cloud_escalation_only',
    ];

    private const TRUST_BOUNDARY_LABELS = [
        'external_process',
        'internet',
        'internal',
        'local_host',
        'local_lan',
        'local_user',
        'plos_local',
        'unknown',
    ];

    private const WRITE_SCOPE_LABELS = [
        'email_outbox',
        'filesystem_scoped',
        'local_context_cache',
        'local_memory_graph',
        'nextcloud_calendar',
        'nextcloud_contacts',
        'nextcloud_data',
        'none',
        'plos_data',
        'read',
        'read_only',
        'repo_worktree',
        'unknown',
        'workspace',
    ];

    private const NETWORK_REQUIRED_LABELS = [
        'internet',
        'lan_only',
        'localhost',
        'no',
        'none',
        'optional',
        'unknown',
        'yes',
    ];

    private const SECRET_SURFACE_RISK_LABELS = [
        'high',
        'low',
        'medium',
        'unknown',
    ];

    private const TRANSPORT_LABELS = [
        'external_process',
        'internal_service',
        'unknown',
    ];

    public function __construct(private readonly OfflinePolicyService $offlinePolicy) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(?string $processList = null): array
    {
        $servers = (array) config('mcp.servers', []);
        $process = $this->processLines($processList);
        $reports = [];

        foreach ($servers as $name => $server) {
            if (! is_array($server)) {
                continue;
            }

            $reports[] = $this->serverReport((string) $name, $server, $process);
        }

        return [
            'generated_at' => now('UTC')->toIso8601String(),
            'status' => $this->overallStatus($reports, $process['available']),
            'process_check' => [
                'available' => $process['available'],
                'source' => $process['source'],
            ],
            'summary' => $this->summary($reports),
            'servers' => $reports,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactPayload(array $payload): array
    {
        $servers = collect((array) ($payload['servers'] ?? []));

        return [
            'generated_at' => $payload['generated_at'] ?? null,
            'compact' => true,
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'process_check' => $payload['process_check'] ?? ['available' => false, 'source' => 'unknown'],
            'summary' => $payload['summary'] ?? [],
            'config_posture' => $this->configPostureSummary($servers->filter(fn (mixed $server): bool => is_array($server))->values()->all()),
            'policy_posture' => $this->policyPostureSummary($servers->filter(fn (mixed $server): bool => is_array($server))->values()->all()),
            'attention' => $servers
                ->filter(fn (mixed $server): bool => is_array($server)
                    && ! in_array(($server['status'] ?? null), ['ok', 'disabled'], true))
                ->map(fn (array $server): array => [
                    'name' => $this->safeServerName((string) ($server['name'] ?? '')),
                    'status' => (string) ($server['status'] ?? 'unknown'),
                    'enabled' => (bool) ($server['enabled'] ?? false),
                    'transport' => $this->normalizeLabel($server['transport'] ?? null, self::TRANSPORT_LABELS),
                    'process_matchable' => (bool) data_get($server, 'process.matchable', false),
                    'process_running' => (bool) data_get($server, 'process.running', false),
                    'process_marker_count' => (int) data_get($server, 'process.marker_count', 0),
                    'missing_entries' => (int) ($server['missing_entries'] ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array{available:bool,source:string,lines:list<string>}  $process
     * @return array<string, mixed>
     */
    private function serverReport(string $name, array $server, array $process): array
    {
        $enabled = (bool) ($server['enabled'] ?? false);
        $transport = (string) ($server['transport'] ?? ($server['type'] ?? 'unknown'));
        $type = (string) ($server['type'] ?? 'unknown');
        $command = isset($server['command']) ? (string) $server['command'] : null;
        $args = array_values(array_map('strval', (array) ($server['args'] ?? [])));
        $entries = $this->entryChecks($args);
        $missingEntries = collect($entries)->where('exists', false)->count();
        $expectsProcess = $this->expectsProcess($server, $transport);
        $processMarkers = $expectsProcess ? $this->processMarkers($command, $args) : [];
        $matches = $expectsProcess ? $this->processMatches($processMarkers, $process['lines']) : [];

        return [
            'name' => $name,
            'enabled' => $enabled,
            'type' => $type,
            'transport' => $transport,
            'tools' => (int) ($server['tools'] ?? 0),
            'trust_boundary' => (string) ($server['trust_boundary'] ?? 'unknown'),
            'network_required' => (string) ($server['network_required'] ?? 'unknown'),
            'write_scope' => (string) ($server['write_scope'] ?? 'unknown'),
            'secret_surface_risk' => (string) ($server['secret_surface_risk'] ?? 'unknown'),
            'command' => $this->commandSummary($command),
            'args_count' => count($args),
            'local_entries' => $entries,
            'missing_entries' => $missingEntries,
            'process' => [
                'expected' => $expectsProcess,
                'checked' => $process['available'],
                'matchable' => $processMarkers !== [],
                'marker_count' => count($processMarkers),
                'running' => $matches !== [],
                'matches' => count($matches),
            ],
            'policy' => $this->policyReport($name),
            'status' => $this->serverStatus($enabled, $expectsProcess, $process['available'], $matches !== [], $missingEntries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function policyReport(string $name): array
    {
        $profiles = [];

        foreach (self::POLICY_PROFILES as $profile) {
            try {
                $decision = $this->offlinePolicy->evaluateMcpServer($name, $profile, false);
                $profiles[$profile] = [
                    'allowed' => $decision->allowed,
                    'reason_code' => $this->policyReasonCode($decision->reason),
                    'trust_boundary' => $this->normalizeLabel(
                        $decision->mcpTrustBoundary,
                        array_keys((array) config('offline_policy.mcp_trust_boundaries', []))
                    ),
                ];
            } catch (Throwable) {
                $profiles[$profile] = [
                    'allowed' => false,
                    'reason_code' => 'policy_error',
                    'trust_boundary' => 'unknown',
                ];
            }
        }

        return [
            'profiles' => $profiles,
            'allowed_profiles' => array_keys(array_filter(
                $profiles,
                static fn (array $profile): bool => (bool) ($profile['allowed'] ?? false)
            )),
            'denied_profiles' => array_keys(array_filter(
                $profiles,
                static fn (array $profile): bool => ! (bool) ($profile['allowed'] ?? false)
            )),
            'denied_profile_count' => collect($profiles)
                ->filter(fn (array $profile): bool => ! (bool) ($profile['allowed'] ?? false))
                ->count(),
        ];
    }

    /**
     * @param  list<string>  $args
     * @return list<array<string, mixed>>
     */
    private function entryChecks(array $args): array
    {
        $entries = [];

        foreach ($args as $arg) {
            if (! $this->looksLikeLocalPath($arg)) {
                continue;
            }

            $path = $this->absolutePath($arg);
            $exists = file_exists($path);
            $entries[] = [
                'path_class' => $this->pathClass($path),
                'path' => $this->safePathLabel($path),
                'exists' => $exists,
                'kind' => is_dir($path) ? 'directory' : (is_file($path) ? 'file' : 'missing'),
                'readable' => $exists && is_readable($path),
            ];
        }

        return $entries;
    }

    private function looksLikeLocalPath(string $value): bool
    {
        return str_starts_with($value, '/')
            || str_starts_with($value, './')
            || str_starts_with($value, '../')
            || str_contains($value, base_path());
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function pathClass(string $path): string
    {
        if ($this->underPath($path, base_path())) {
            return 'repo';
        }

        if ($this->underPath($path, storage_path())) {
            return 'storage';
        }

        return 'external_absolute';
    }

    private function safePathLabel(string $path): string
    {
        if ($this->underPath($path, base_path())) {
            return ltrim(str_replace(base_path(), '', $path), '/');
        }

        if ($this->underPath($path, storage_path())) {
            return 'storage/'.ltrim(str_replace(storage_path(), '', $path), '/');
        }

        return basename($path);
    }

    private function underPath(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/').'/');
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function expectsProcess(array $server, string $transport): bool
    {
        if (($server['type'] ?? null) === 'internal' || $transport === 'internal_service') {
            return false;
        }

        return isset($server['command']) || $transport === 'external_process';
    }

    /**
     * @return array<string, mixed>
     */
    private function commandSummary(?string $command): array
    {
        if ($command === null || $command === '') {
            return [
                'configured' => false,
                'label' => null,
                'path_class' => 'none',
            ];
        }

        return [
            'configured' => true,
            'label' => basename($command),
            'path_class' => str_starts_with($command, '/') ? $this->pathClass($command) : 'path_lookup',
        ];
    }

    /**
     * @return list<string>
     */
    private function processMarkers(?string $command, array $args): array
    {
        $markers = [];

        if ($command !== null && $this->commandIsSpecificProcessMarker($command)) {
            $markers[] = trim($command);
        }

        foreach ($args as $arg) {
            $marker = $this->argProcessMarker($arg);
            if ($marker !== null) {
                $markers[] = $marker;
            }
        }

        return array_values(array_unique($markers));
    }

    /**
     * @param  list<string>  $markers
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function processMatches(array $markers, array $lines): array
    {
        if ($markers === [] || $lines === []) {
            return [];
        }

        $matches = [];
        foreach ($lines as $line) {
            foreach ($markers as $marker) {
                if (str_contains($line, $marker)) {
                    $matches[] = $line;
                    break;
                }
            }
        }

        return $matches;
    }

    private function argProcessMarker(string $arg): ?string
    {
        $arg = trim($arg);
        if ($arg === '' || in_array($arg, ['true', 'false'], true) || str_starts_with($arg, '--')) {
            return null;
        }

        if ($this->looksLikeLocalPath($arg)) {
            $path = $this->absolutePath($arg);

            return is_dir($path) ? null : $arg;
        }

        return $arg;
    }

    private function commandIsSpecificProcessMarker(string $command): bool
    {
        $command = trim($command);
        if ($command === '') {
            return false;
        }

        if (str_starts_with($command, '/')) {
            return true;
        }

        return ! in_array($command, [
            'bash',
            'node',
            'npx',
            'php',
            'python',
            'python3',
            'sh',
            'uv',
            'uvx',
        ], true);
    }

    private function serverStatus(bool $enabled, bool $expectsProcess, bool $processChecked, bool $processRunning, int $missingEntries): string
    {
        if (! $enabled) {
            return $processRunning ? 'watch' : 'disabled';
        }

        if ($missingEntries > 0) {
            return 'critical';
        }

        if (! $expectsProcess) {
            return 'ok';
        }

        if (! $processChecked) {
            return 'warning';
        }

        return $processRunning ? 'ok' : 'watch';
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     * @return array<string, int>
     */
    private function summary(array $reports): array
    {
        return [
            'total' => count($reports),
            'enabled' => collect($reports)->where('enabled', true)->count(),
            'disabled' => collect($reports)->where('enabled', false)->count(),
            'internal' => collect($reports)->filter(fn (array $server): bool => ($server['process']['expected'] ?? true) === false)->count(),
            'external' => collect($reports)->filter(fn (array $server): bool => ($server['process']['expected'] ?? false) === true)->count(),
            'ok' => collect($reports)->where('status', 'ok')->count(),
            'watch' => collect($reports)->where('status', 'watch')->count(),
            'warning' => collect($reports)->where('status', 'warning')->count(),
            'critical' => collect($reports)->where('status', 'critical')->count(),
            'missing_entries' => collect($reports)->sum(fn (array $server): int => (int) ($server['missing_entries'] ?? 0)),
            'enabled_missing_entries' => collect($reports)->filter(fn (array $server): bool => (bool) ($server['enabled'] ?? false))
                ->sum(fn (array $server): int => (int) ($server['missing_entries'] ?? 0)),
            'disabled_missing_entries' => collect($reports)->filter(fn (array $server): bool => ! (bool) ($server['enabled'] ?? false))
                ->sum(fn (array $server): int => (int) ($server['missing_entries'] ?? 0)),
            'external_not_running' => collect($reports)->filter(fn (array $server): bool => (bool) ($server['enabled'] ?? false)
                && (bool) data_get($server, 'process.expected', false)
                && ! (bool) data_get($server, 'process.running', false))->count(),
            'disabled_external_running' => collect($reports)->filter(fn (array $server): bool => ! (bool) ($server['enabled'] ?? false)
                && (bool) data_get($server, 'process.expected', false)
                && (bool) data_get($server, 'process.running', false))->count(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, mixed>
     */
    private function configPostureSummary(array $servers): array
    {
        return [
            'trust_boundary_counts' => $this->labelCounts($servers, 'trust_boundary'),
            'write_scope_counts' => $this->labelCounts($servers, 'write_scope'),
            'network_required_counts' => $this->labelCounts($servers, 'network_required'),
            'secret_surface_risk_counts' => $this->labelCounts($servers, 'secret_surface_risk'),
            'external_absolute_entries' => $this->entryPathClassCount($servers, 'external_absolute'),
            'enabled_external_absolute_entries' => $this->entryPathClassCount(
                array_values(array_filter($servers, fn (array $server): bool => (bool) ($server['enabled'] ?? false))),
                'external_absolute'
            ),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, mixed>
     */
    private function policyPostureSummary(array $servers): array
    {
        $profileCounts = [];
        $enabledProfileCounts = [];
        foreach (self::POLICY_PROFILES as $profile) {
            $profileCounts[$profile] = ['allowed' => 0, 'denied' => 0];
            $enabledProfileCounts[$profile] = ['allowed' => 0, 'denied' => 0];
        }

        $enabledDeniedDefault = 0;
        $enabledNoNonDefaultProfile = 0;
        $enabledDenialReasonCounts = [];

        foreach ($servers as $server) {
            $enabled = (bool) ($server['enabled'] ?? false);
            $profiles = (array) data_get($server, 'policy.profiles', []);
            $allowedNonDefault = false;

            foreach (self::POLICY_PROFILES as $profile) {
                $profilePayload = (array) ($profiles[$profile] ?? []);
                $allowed = (bool) ($profilePayload['allowed'] ?? false);
                $bucket = $allowed ? 'allowed' : 'denied';

                $profileCounts[$profile][$bucket]++;

                if (! $enabled) {
                    continue;
                }

                $enabledProfileCounts[$profile][$bucket]++;

                if ($profile === 'default' && ! $allowed) {
                    $enabledDeniedDefault++;
                }

                if ($profile !== 'default' && $allowed) {
                    $allowedNonDefault = true;
                }

                if (! $allowed) {
                    $reason = (string) ($profilePayload['reason_code'] ?? 'policy_denied');
                    $enabledDenialReasonCounts[$reason] = ($enabledDenialReasonCounts[$reason] ?? 0) + 1;
                }
            }

            if ($enabled && ! $allowedNonDefault) {
                $enabledNoNonDefaultProfile++;
            }
        }

        ksort($enabledDenialReasonCounts);

        return [
            'profiles' => self::POLICY_PROFILES,
            'profile_counts' => $profileCounts,
            'enabled_profile_counts' => $enabledProfileCounts,
            'enabled_servers_denied_default' => $enabledDeniedDefault,
            'enabled_servers_with_no_non_default_profile' => $enabledNoNonDefaultProfile,
            'enabled_denial_reason_counts' => $enabledDenialReasonCounts,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     * @return array<string, int>
     */
    private function labelCounts(array $servers, string $key): array
    {
        $counts = [];
        $allowed = match ($key) {
            'trust_boundary' => self::TRUST_BOUNDARY_LABELS,
            'write_scope' => self::WRITE_SCOPE_LABELS,
            'network_required' => self::NETWORK_REQUIRED_LABELS,
            'secret_surface_risk' => self::SECRET_SURFACE_RISK_LABELS,
            default => ['unknown'],
        };

        foreach ($servers as $server) {
            $label = $this->normalizeLabel($server[$key] ?? null, $allowed);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeLabel(mixed $value, array $allowed): string
    {
        $label = trim((string) ($value ?? 'unknown'));
        if ($label === '') {
            return 'unknown';
        }

        return in_array($label, $allowed, true) ? $label : 'other';
    }

    private function policyReasonCode(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'is disabled') => 'server_disabled',
            str_contains($reason, 'not found') => 'server_missing',
            str_contains($reason, 'no trust_boundary') => 'missing_trust_boundary',
            str_contains($reason, 'trust boundary') => 'trust_boundary_denied',
            str_contains($reason, 'offline_profiles_allowed') => 'offline_profile_denied',
            str_contains($reason, 'hybrid_profiles_allowed') => 'hybrid_profile_denied',
            preg_match("/^MCP server '.+' allowed under profile/", $reason) === 1 => 'allowed',
            default => 'policy_denied',
        };
    }

    private function safeServerName(string $name): string
    {
        $name = trim($name);

        if ($name !== '' && preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $name) === 1) {
            return $name;
        }

        return 'server_'.substr(hash('sha256', $name), 0, 12);
    }

    /**
     * @param  list<array<string, mixed>>  $servers
     */
    private function entryPathClassCount(array $servers, string $pathClass): int
    {
        $count = 0;

        foreach ($servers as $server) {
            foreach ((array) ($server['local_entries'] ?? []) as $entry) {
                if (is_array($entry) && ($entry['path_class'] ?? null) === $pathClass) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $reports
     */
    private function overallStatus(array $reports, bool $processChecked): string
    {
        if (collect($reports)->contains(fn (array $server): bool => ($server['status'] ?? null) === 'critical')) {
            return 'critical';
        }

        if (! $processChecked || collect($reports)->contains(fn (array $server): bool => in_array(($server['status'] ?? null), ['warning', 'watch'], true))) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @return array{available:bool,source:string,lines:list<string>}
     */
    private function processLines(?string $processList): array
    {
        if ($processList !== null) {
            return [
                'available' => true,
                'source' => 'provided',
                'lines' => $this->splitLines($processList),
            ];
        }

        $process = new Process(['ps', '-eo', 'pid,ppid,stat,args']);
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return [
                'available' => false,
                'source' => 'ps',
                'lines' => [],
            ];
        }

        return [
            'available' => true,
            'source' => 'ps',
            'lines' => $this->splitLines($process->getOutput()),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        return collect(preg_split('/\R/', $text) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();
    }
}
