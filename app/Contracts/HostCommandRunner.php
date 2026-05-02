<?php

namespace App\Contracts;

interface HostCommandRunner
{
    public function run(string $command, int $timeoutSeconds = 5): ?string;
}
