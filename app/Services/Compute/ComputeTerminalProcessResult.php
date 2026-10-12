<?php

namespace App\Services\Compute;

use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\ProcessResult;

/** Preserve the child's exact binary streams while retaining Laravel's result API. */
class ComputeTerminalProcessResult extends ProcessResult
{
    public function __construct(private readonly ?ProcessResultContract $transport, private readonly array $receipt,
        private readonly string $stdout, private readonly string $stderr) {}

    public function command()
    {
        return $this->transport?->command() ?? '';
    }

    public function successful()
    {
        return $this->receipt['exit_code'] === 0;
    }

    public function exitCode()
    {
        return $this->receipt['exit_code'];
    }

    public function output()
    {
        return $this->stdout;
    }

    public function errorOutput()
    {
        return $this->stderr;
    }
}
