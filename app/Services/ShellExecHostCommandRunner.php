<?php

namespace App\Services;

use App\Contracts\HostCommandRunner;

class ShellExecHostCommandRunner implements HostCommandRunner
{
    public function run(string $command, int $timeoutSeconds = 5): ?string
    {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $wrappedCommand = $this->wrapCommand($command, $timeoutSeconds);
        $output = shell_exec($wrappedCommand);

        if ($output === null) {
            return null;
        }

        $trimmed = trim($output);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function wrapCommand(string $command, int $timeoutSeconds): string
    {
        $inner = sprintf(
            'timeout %d bash -lc %s 2>/dev/null',
            $timeoutSeconds,
            escapeshellarg($command)
        );

        return '/usr/bin/env bash -lc '.escapeshellarg($inner);
    }
}
