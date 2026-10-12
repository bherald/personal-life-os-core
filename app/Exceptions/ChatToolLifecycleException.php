<?php

namespace App\Exceptions;

use RuntimeException;

class ChatToolLifecycleException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('chat_tool_lifecycle_'.$reason);
    }
}
