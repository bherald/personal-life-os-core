<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class RepoEditRecoveryService
{
    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function locked(callable $callback): mixed
    {
        $directory = storage_path('app/dev-agent/recovery');
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the repo edit lock directory.');
        }
        @chmod($directory, 0700);

        $handle = @fopen($directory.'/.lock', 'c');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open the repo edit lock.');
        }
        @chmod($directory.'/.lock', 0600);

        $timeout = $this->clamp((int) config('dev_agent.repo_edit_recovery.lock_timeout_seconds', 10), 1, 60);
        $deadline = microtime(true) + $timeout;
        try {
            do {
                if (@flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        return $callback();
                    } finally {
                        @flock($handle, LOCK_UN);
                    }
                }
                usleep(50000);
            } while (microtime(true) < $deadline);

            throw new RuntimeException('Repo edit lock timed out.');
        } finally {
            @fclose($handle);
        }
    }

    /**
     * @param  list<array{path:string,absolute:string}>  $files
     * @return array<string, mixed>
     */
    public function capture(array $files): array
    {
        $config = $this->config();
        if (! $config['enabled']) {
            return $this->unavailable('disabled');
        }
        if ($config['integrity_key'] === '') {
            return $this->unavailable('integrity_key_unavailable');
        }
        if (count($files) > $config['max_files']) {
            return $this->unavailable('too_many_files');
        }

        $this->pruneExpired($config);

        try {
            $before = $this->captureStates($files, $config, 0);
        } catch (RuntimeException $e) {
            return $this->unavailable($e->getMessage());
        }

        $createdAt = time();

        return [
            'available' => true,
            'token' => 'rec_'.bin2hex(random_bytes(16)),
            'created_at' => $createdAt,
            'expires_at' => $createdAt + ($config['retention_minutes'] * 60),
            'files' => $before['states'],
            'captured_bytes' => $before['bytes'],
            'reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array{path:string,absolute:string}>  $files
     * @return array<string, mixed>
     */
    public function finalize(array $context, array $files): array
    {
        if (! ($context['available'] ?? false)) {
            return $this->publicSummary($context);
        }

        $config = $this->config();
        try {
            $after = $this->captureStates($files, $config, (int) ($context['captured_bytes'] ?? 0));
            $combined = $this->combineStates($context['files'] ?? [], $after['states']);
            if ($this->statesUnchanged($combined)) {
                return $this->unavailable('no_changes');
            }
            $packet = [
                'version' => 1,
                'token' => (string) $context['token'],
                'created_at' => (int) $context['created_at'],
                'expires_at' => (int) $context['expires_at'],
                'files' => $combined,
            ];
            $packet['hmac'] = $this->sign($packet, $config['integrity_key']);
            $this->writePacket($packet, $config);
        } catch (\Throwable $e) {
            return $this->unavailable($e instanceof RuntimeException ? $e->getMessage() : 'snapshot_failed');
        }

        return $this->publicSummary($context);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $token, bool $historicalEvidence = false): array
    {
        if (preg_match('/^rec_[a-f0-9]{32}$/', $token) !== 1) {
            throw new RuntimeException('Recovery token is invalid.');
        }

        $config = $this->config();
        $path = $this->packetPath($token, $config);
        $json = @file_get_contents($path);
        if (! is_string($json)) {
            throw new RuntimeException('Recovery packet was not found.');
        }

        $packet = json_decode($json, true);
        if (! is_array($packet) || (int) ($packet['version'] ?? 0) !== 1) {
            throw new RuntimeException('Recovery packet is invalid.');
        }

        $hmac = (string) ($packet['hmac'] ?? '');
        unset($packet['hmac']);
        if ($config['integrity_key'] === '' || ! hash_equals($hmac, $this->sign($packet, $config['integrity_key']))) {
            throw new RuntimeException('Recovery packet integrity check failed.');
        }
        if (($packet['token'] ?? null) !== $token || ! is_array($packet['files'] ?? null)) {
            throw new RuntimeException('Recovery packet is invalid.');
        }
        // Historical readback grants no restore authority. Preserve signed
        // originals when an independent terminal observer follows later.
        if (! $historicalEvidence && (int) ($packet['expires_at'] ?? 0) < time()) {
            @unlink($path);

            throw new RuntimeException('Recovery packet has expired.');
        }

        return $packet;
    }

    /**
     * Summarize the net effect of an ordered set of recovery-backed edits.
     *
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    public function summarize(array $tokens): array
    {
        $maximum = $this->config()['max_session_operations'];
        if ($tokens === [] || count($tokens) > $maximum) {
            throw new RuntimeException("Recovery summary requires 1 to {$maximum} unique tokens.");
        }
        $tokens = array_map('trim', $tokens);
        if (count(array_unique($tokens)) !== count($tokens)) {
            throw new RuntimeException('Recovery summary tokens must be unique and ordered.');
        }

        $chains = [];
        foreach ($tokens as $token) {
            $packet = $this->load($token);
            foreach ($packet['files'] as $file) {
                if (! is_array($file) || ! is_string($file['path'] ?? null)
                    || ! is_array($file['before'] ?? null) || ! is_array($file['after'] ?? null)) {
                    throw new RuntimeException('Recovery packet is invalid.');
                }

                $path = $file['path'];
                if (isset($chains[$path]) && ! $this->sameState($file['before'], $chains[$path]['after'])) {
                    throw new RuntimeException("Recovery tokens are not a continuous ordered edit chain for '{$path}'.");
                }

                $chains[$path] ??= [
                    'before' => $file['before'],
                    'after' => $file['after'],
                ];
                $chains[$path]['after'] = $file['after'];
            }
        }

        ksort($chains);
        $files = [];
        $additions = 0;
        $deletions = 0;
        $binaryFiles = 0;
        $lineStatsUnavailableFiles = 0;
        $unchangedFiles = 0;
        foreach ($chains as $path => $chain) {
            if ($this->sameState($chain['before'], $chain['after'])) {
                $unchangedFiles++;

                continue;
            }

            $stats = $this->lineStats($chain['before'], $chain['after']);
            if (! $stats['available']) {
                $lineStatsUnavailableFiles++;
            }
            if ($stats['binary']) {
                $binaryFiles++;
            } elseif ($stats['available']) {
                $additions += (int) $stats['additions'];
                $deletions += (int) $stats['deletions'];
            }
            $files[] = [
                'path' => $path,
                'change' => $this->changeType($chain['before'], $chain['after']),
                'additions' => $stats['additions'],
                'deletions' => $stats['deletions'],
                'binary' => $stats['binary'],
                'line_stats_available' => $stats['available'],
            ];
        }

        return [
            'schema' => 'plos.repo_edit_summary.v1',
            'status' => 'ok',
            'operation_count' => count($tokens),
            'files_changed' => count($files),
            'files_unchanged_after_session' => $unchangedFiles,
            'additions' => $additions,
            'deletions' => $deletions,
            'binary_files' => $binaryFiles,
            'line_stats_unavailable_files' => $lineStatsUnavailableFiles,
            'line_stats_complete' => $lineStatsUnavailableFiles === 0,
            'files' => $files,
            'posture' => [
                'content_included' => false,
                'recovery_tokens_included' => false,
                'continuous_ordered_chain' => true,
                'write_authority_changed' => false,
            ],
        ];
    }

    /**
     * @param  array<string, string>  $absolutePaths
     * @return array<string, mixed>
     */
    public function restore(string $token, array $absolutePaths, bool $checkOnly = false): array
    {
        $packet = $this->load($token);
        $config = $this->config();
        $conflicts = [];
        $paths = [];

        foreach ($packet['files'] as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null)) {
                throw new RuntimeException('Recovery packet is invalid.');
            }
            $path = $file['path'];
            $absolute = $absolutePaths[$path] ?? null;
            if (! is_string($absolute)) {
                throw new RuntimeException('Recovery packet path was not authorized.');
            }

            $current = $this->captureState($path, $absolute, $config);
            if (! $this->sameState($current, $file['after'] ?? null)) {
                $conflicts[] = $path;
            }
            $paths[] = $path;
        }

        if ($conflicts !== []) {
            return [
                'status' => 'conflict',
                'restored' => false,
                'check_only' => $checkOnly,
                'paths' => $paths,
                'conflicts' => $conflicts,
                'reason' => 'files_changed_after_edit',
            ];
        }

        if ($checkOnly) {
            return [
                'status' => 'ready',
                'restored' => false,
                'check_only' => true,
                'paths' => $paths,
                'conflicts' => [],
                'reason' => null,
            ];
        }

        $written = [];
        try {
            foreach ($packet['files'] as $file) {
                $current = $this->captureState($file['path'], $absolutePaths[$file['path']], $config);
                if (! $this->sameState($current, $file['after'] ?? null)) {
                    $this->rollbackRestoredStates($written, $absolutePaths, $config);

                    return [
                        'status' => 'conflict',
                        'restored' => false,
                        'check_only' => false,
                        'paths' => $paths,
                        'conflicts' => [$file['path']],
                        'reason' => 'files_changed_during_restore',
                    ];
                }
                $this->writeState($absolutePaths[$file['path']], $file['before']);
                $written[] = $file;
            }
        } catch (\Throwable $e) {
            $this->rollbackRestoredStates($written, $absolutePaths, $config);

            throw new RuntimeException('Recovery restore failed; the post-edit state was reapplied where possible.', 0, $e);
        }

        @unlink($this->packetPath($token, $config));

        return [
            'status' => 'restored',
            'restored' => true,
            'check_only' => false,
            'paths' => $paths,
            'conflicts' => [],
            'reason' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, string>  $absolutePaths
     * @param  array<string, mixed>  $config
     */
    private function rollbackRestoredStates(array $files, array $absolutePaths, array $config): void
    {
        foreach ($files as $file) {
            try {
                $path = (string) $file['path'];
                $current = $this->captureState($path, $absolutePaths[$path], $config);
                if ($this->sameState($current, $file['before'] ?? null)) {
                    $this->writeState($absolutePaths[$path], $file['after']);
                }
            } catch (\Throwable) {
                // Preserve newer external content and the original recovery result.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'token' => null,
            'expires_at' => null,
            'paths' => [],
            'reason' => $reason,
        ];
    }

    /**
     * @return array{enabled:bool,dir:string,retention_minutes:int,max_files:int,max_file_bytes:int,max_total_bytes:int,max_session_operations:int,integrity_key:string}
     */
    private function config(): array
    {
        return [
            'enabled' => (bool) config('dev_agent.repo_edit_recovery.enabled', true),
            'dir' => storage_path('app/dev-agent/recovery'),
            'retention_minutes' => $this->clamp((int) config('dev_agent.repo_edit_recovery.retention_minutes', 120), 5, 1440),
            'max_files' => $this->clamp((int) config('dev_agent.repo_edit_recovery.max_files', 50), 1, 200),
            'max_file_bytes' => $this->clamp((int) config('dev_agent.repo_edit_recovery.max_file_bytes', 4194304), 1024, 16777216),
            'max_total_bytes' => $this->clamp((int) config('dev_agent.repo_edit_recovery.max_total_bytes', 16777216), 2048, 67108864),
            'max_session_operations' => $this->clamp((int) config('dev_agent.repo_edit_recovery.max_session_operations', 50), 1, 200),
            'integrity_key' => (string) config('app.key', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{additions:int|null,deletions:int|null,binary:bool,available:bool}
     */
    private function lineStats(array $before, array $after): array
    {
        $beforeContent = $this->stateContent($before);
        $afterContent = $this->stateContent($after);
        if ($beforeContent === null || $afterContent === null) {
            return ['additions' => null, 'deletions' => null, 'binary' => false, 'available' => false];
        }
        if (str_contains($beforeContent, "\0") || str_contains($afterContent, "\0")) {
            return ['additions' => null, 'deletions' => null, 'binary' => true, 'available' => false];
        }

        $directory = $this->config()['dir'];
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return ['additions' => null, 'deletions' => null, 'binary' => false, 'available' => false];
        }

        $beforePath = tempnam($directory, '.summary-before-');
        $afterPath = tempnam($directory, '.summary-after-');
        if ($beforePath === false || $afterPath === false) {
            if (is_string($beforePath)) {
                @unlink($beforePath);
            }
            if (is_string($afterPath)) {
                @unlink($afterPath);
            }

            return ['additions' => null, 'deletions' => null, 'binary' => false, 'available' => false];
        }

        try {
            @chmod($beforePath, 0600);
            @chmod($afterPath, 0600);
            if (@file_put_contents($beforePath, $beforeContent, LOCK_EX) === false
                || @file_put_contents($afterPath, $afterContent, LOCK_EX) === false) {
                return ['additions' => null, 'deletions' => null, 'binary' => false, 'available' => false];
            }

            $result = Process::path(base_path())->timeout(10)->run([
                'git', 'diff', '--no-index', '--no-ext-diff', '--no-textconv', '--numstat', '--no-renames', '--',
                $beforePath, $afterPath,
            ]);
            if (! in_array($result->exitCode(), [0, 1], true)) {
                return ['additions' => null, 'deletions' => null, 'binary' => false, 'available' => false];
            }

            $line = trim($result->output());
            if ($line === '') {
                return ['additions' => 0, 'deletions' => 0, 'binary' => false, 'available' => true];
            }
            $columns = explode("\t", strtok($line, "\n") ?: '');
            if (count($columns) < 2 || $columns[0] === '-' || $columns[1] === '-') {
                return ['additions' => null, 'deletions' => null, 'binary' => true, 'available' => false];
            }

            return [
                'additions' => max(0, (int) $columns[0]),
                'deletions' => max(0, (int) $columns[1]),
                'binary' => false,
                'available' => true,
            ];
        } finally {
            @unlink($beforePath);
            @unlink($afterPath);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function stateContent(array $state): ?string
    {
        if (! ($state['exists'] ?? false)) {
            return '';
        }

        $content = base64_decode((string) ($state['content'] ?? ''), true);
        if (! is_string($content) || hash('sha256', $content) !== ($state['sha256'] ?? null)) {
            return null;
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function changeType(array $before, array $after): string
    {
        if (! ($before['exists'] ?? false)) {
            return 'added';
        }
        if (! ($after['exists'] ?? false)) {
            return 'deleted';
        }
        if (($before['sha256'] ?? null) === ($after['sha256'] ?? null)) {
            return 'mode_changed';
        }

        return 'modified';
    }

    /**
     * @param  list<array{path:string,absolute:string}>  $files
     * @param  array<string, mixed>  $config
     * @return array{states:list<array<string,mixed>>,bytes:int}
     */
    private function captureStates(array $files, array $config, int $initialBytes): array
    {
        $states = [];
        $bytes = $initialBytes;
        foreach ($files as $file) {
            $state = $this->captureState($file['path'], $file['absolute'], $config);
            $bytes += (int) ($state['bytes'] ?? 0);
            if ($bytes > $config['max_total_bytes']) {
                throw new RuntimeException('total_too_large');
            }
            $states[] = $state;
        }

        return ['states' => $states, 'bytes' => $bytes];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function captureState(string $path, string $absolute, array $config): array
    {
        clearstatcache(true, $absolute);
        if (is_link($absolute)) {
            throw new RuntimeException('symlink_not_supported');
        }
        if (! is_file($absolute)) {
            return ['path' => $path, 'exists' => false, 'sha256' => null, 'bytes' => 0, 'mode' => null, 'content' => null];
        }

        $size = @filesize($absolute);
        if (! is_int($size) || $size > $config['max_file_bytes']) {
            throw new RuntimeException('file_too_large');
        }
        $content = @file_get_contents($absolute);
        if (! is_string($content)) {
            throw new RuntimeException('unreadable_file');
        }
        if (strlen($content) > $config['max_file_bytes']) {
            throw new RuntimeException('file_too_large');
        }

        $permissions = @fileperms($absolute);

        return [
            'path' => $path,
            'exists' => true,
            'sha256' => hash('sha256', $content),
            'bytes' => strlen($content),
            'mode' => is_int($permissions) ? ($permissions & 0777) : 0644,
            'content' => base64_encode($content),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function combineStates(mixed $before, mixed $after): array
    {
        if (! is_array($before) || ! is_array($after) || count($before) !== count($after)) {
            throw new RuntimeException('snapshot_failed');
        }

        $combined = [];
        foreach ($before as $index => $state) {
            $afterState = $after[$index] ?? null;
            if (! is_array($state) || ! is_array($afterState) || ($state['path'] ?? null) !== ($afterState['path'] ?? null)) {
                throw new RuntimeException('snapshot_failed');
            }
            $combined[] = [
                'path' => $state['path'],
                'before' => $state,
                'after' => $afterState,
            ];
        }

        return $combined;
    }

    private function sameState(array $current, mixed $expected): bool
    {
        return is_array($expected)
            && ($current['exists'] ?? null) === ($expected['exists'] ?? null)
            && ($current['sha256'] ?? null) === ($expected['sha256'] ?? null)
            && ($current['mode'] ?? null) === ($expected['mode'] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function statesUnchanged(array $files): bool
    {
        foreach ($files as $file) {
            if (! is_array($file['before'] ?? null) || ! $this->sameState($file['before'], $file['after'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(string $absolute, array $state): void
    {
        if (! ($state['exists'] ?? false)) {
            if ((is_file($absolute) || is_link($absolute)) && ! @unlink($absolute)) {
                throw new RuntimeException('Unable to remove a file during recovery.');
            }

            return;
        }

        $content = base64_decode((string) ($state['content'] ?? ''), true);
        if (! is_string($content) || hash('sha256', $content) !== ($state['sha256'] ?? null)) {
            throw new RuntimeException('Recovery content integrity check failed.');
        }

        $directory = dirname($absolute);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create a recovery target directory.');
        }
        $temporary = tempnam($directory, '.plos-recovery-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a temporary recovery file.');
        }

        try {
            if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write a temporary recovery file.');
            }
            @chmod($temporary, (int) ($state['mode'] ?? 0644));
            if (! @rename($temporary, $absolute)) {
                throw new RuntimeException('Unable to install a recovered file.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function writePacket(array $packet, array $config): void
    {
        $directory = $config['dir'];
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('snapshot_failed');
        }
        @chmod($directory, 0700);

        $json = json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = tempnam($directory, '.packet-');
        if ($temporary === false) {
            throw new RuntimeException('snapshot_failed');
        }

        try {
            if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new RuntimeException('snapshot_failed');
            }
            @chmod($temporary, 0600);
            if (! @rename($temporary, $this->packetPath($packet['token'], $config))) {
                throw new RuntimeException('snapshot_failed');
            }
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function sign(array $packet, string $key): string
    {
        return hash_hmac('sha256', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $key);
    }

    private function packetPath(string $token, array $config): string
    {
        return rtrim($config['dir'], '/').'/'.$token.'.json';
    }

    private function pruneExpired(array $config): void
    {
        if (! is_dir($config['dir'])) {
            return;
        }

        $cutoff = time() - ($config['retention_minutes'] * 60);
        $checked = 0;
        foreach (new \FilesystemIterator($config['dir'], \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($checked++ >= 200) {
                break;
            }
            if ($item->isFile() && str_starts_with($item->getFilename(), 'rec_') && $item->getMTime() < $cutoff) {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function publicSummary(array $context): array
    {
        if (! ($context['available'] ?? false)) {
            return $this->unavailable((string) ($context['reason'] ?? 'snapshot_failed'));
        }

        return [
            'available' => true,
            'token' => $context['token'],
            'expires_at' => gmdate(DATE_ATOM, (int) $context['expires_at']),
            'paths' => array_values(array_map(
                static fn (array $file): string => (string) $file['path'],
                is_array($context['files'] ?? null) ? $context['files'] : []
            )),
            'reason' => null,
        ];
    }

    private function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }
}
