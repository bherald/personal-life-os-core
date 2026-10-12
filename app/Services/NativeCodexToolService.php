<?php

namespace App\Services;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** Shared registered tools; the owning PLOS session supplies the native executor. */
final class NativeCodexToolService
{
    public function __construct(private readonly ?Closure $dispatch = null) {}

    public function start(string $command, string $path, bool $tty = false, int $timeout_ms = 30000): array
    {
        if ($command === '' || str_contains($command, "\0") || $path === '' || str_contains($path, "\0")
            || $timeout_ms < 1 || $timeout_ms > 3600000) {
            throw new InvalidArgumentException('Native command requires complete command text, working directory and a valid timeout.');
        }

        return $this->call('start', compact('command', 'path', 'tty', 'timeout_ms'));
    }

    public function workspaceDiff(string $path, bool $staged = false): array
    {
        return $this->call('workspace_diff', compact('path', 'staged'));
    }

    public function input(string $process_id, string $input_base64 = '', bool $close_stdin = false): array
    {
        $this->processId($process_id);
        if (base64_decode($input_base64, true) === false) {
            throw new InvalidArgumentException('Native process input must be valid base64, preserving the original bytes.');
        }

        return $this->call('input', compact('process_id', 'input_base64', 'close_stdin'));
    }

    public function output(string $process_id, int $offset = 0): array
    {
        $this->processId($process_id);
        if ($offset < 0) {
            throw new InvalidArgumentException('Native output offset must be nonnegative.');
        }

        return $this->call('output', compact('process_id', 'offset'));
    }

    public function cancel(string $process_id): array
    {
        $this->processId($process_id);

        return $this->call('cancel', compact('process_id'));
    }

    public function resize(string $process_id, int $rows, int $columns): array
    {
        $this->processId($process_id);
        if ($rows < 1 || $rows > 65535 || $columns < 1 || $columns > 65535) {
            throw new InvalidArgumentException('Native terminal dimensions must be between 1 and 65535.');
        }

        return $this->call('resize', compact('process_id', 'rows', 'columns'));
    }

    private function processId(string $processId): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $processId)) {
            throw new InvalidArgumentException('Native process ID is invalid.');
        }
    }

    private function call(string $operation, array $arguments): array
    {
        if ($this->dispatch === null) {
            throw new RuntimeException('Native Codex requires the current PLOS session executor; no standalone execution fallback is allowed.');
        }

        return ($this->dispatch)($operation, $arguments);
    }
}
