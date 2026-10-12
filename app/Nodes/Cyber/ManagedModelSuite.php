<?php

namespace App\Nodes\Cyber;

use App\Services\CyberManagedModelSuiteService;

/** One admitted synthetic model-led suite; all tool gates remain inside the existing CLI. */
final class ManagedModelSuite
{
    public function __construct(private array $config) {}

    public function execute(array $input): array
    {
        return (new CyberManagedModelSuiteService)->execute($input);
    }
}
