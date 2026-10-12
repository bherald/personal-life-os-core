<?php

namespace App\Exceptions;

use RuntimeException;

class ChatStreamCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('OpenAI-compatible monitored chat cancelled by operator');
    }
}
