<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowSuspendedException extends RuntimeException
{
    public function __construct(
        public readonly int $gateId,
        public readonly int $runId,
        public readonly string $executionId,
        public readonly string $nodeId,
    ) {
        parent::__construct("Workflow execution is awaiting approval at gate {$gateId}.");
    }
}
