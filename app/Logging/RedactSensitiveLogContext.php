<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class RedactSensitiveLogContext
{
    public function __construct(
        private readonly SensitiveLogContextProcessor $processor,
    ) {}

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor($this->processor);
    }
}
