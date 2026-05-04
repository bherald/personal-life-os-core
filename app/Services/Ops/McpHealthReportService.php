<?php

namespace App\Services\Ops;

use Symfony\Component\Process\Process;

class McpHealthReportService
{
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
            'attention' => $servers
                ->filter(fn (mixed $server): bool => is_array($server)
                    && ! in_array(($server['status'] ?? null), ['ok', 'disabled'], true))
                ->map(fn (array $server): array => [
                    'name' => (string) ($server['name'] ?? ''),
                    'status' => (string) ($server['status'] ?? 'unknown'),
                    'enabled' => (bool) ($server['enabled'] ?? false),
                    'transport' => (string) ($server['transport'] ?? 'unknown'),
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
            'status' => $this->serverStatus($enabled, $expectsProcess, $process['available'], $matches !== [], $missingEntries),
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
            'external_not_running' => collect($reports)->filter(fn (array $server): bool => (bool) ($server['enabled'] ?? false)
                && (bool) data_get($server, 'process.expected', false)
                && ! (bool) data_get($server, 'process.running', false))->count(),
        ];
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
