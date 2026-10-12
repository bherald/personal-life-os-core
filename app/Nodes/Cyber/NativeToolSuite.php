<?php

namespace App\Nodes\Cyber;

use App\Services\CyberNativeToolSuiteService;

final class NativeToolSuite
{
    public function __construct(private array $config) {}

    public function execute(array $input): array
    {
        return (new CyberNativeToolSuiteService)->execute($input);
    }
}
