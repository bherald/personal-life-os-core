<?php

namespace App\Exceptions;

use Exception;

/**
 * Node Timeout Exception
 *
 * Thrown when a workflow node execution exceeds its configured timeout.
 */
class NodeTimeoutException extends Exception
{
    private string $nodeType;
    private int $timeoutSeconds;
    private int $elapsedSeconds;

    public function __construct(
        string $nodeType,
        int $timeoutSeconds,
        int $elapsedSeconds,
        ?string $message = null
    ) {
        $this->nodeType = $nodeType;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->elapsedSeconds = $elapsedSeconds;

        $defaultMessage = "Node '{$nodeType}' execution timed out after {$elapsedSeconds}s (limit: {$timeoutSeconds}s)";
        parent::__construct($message ?? $defaultMessage);
    }

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function getElapsedSeconds(): int
    {
        return $this->elapsedSeconds;
    }

    public function getContext(): array
    {
        return [
            'node_type' => $this->nodeType,
            'timeout_seconds' => $this->timeoutSeconds,
            'elapsed_seconds' => $this->elapsedSeconds,
        ];
    }
}
