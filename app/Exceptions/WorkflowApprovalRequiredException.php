<?php

namespace App\Exceptions;

use RuntimeException;

class WorkflowApprovalRequiredException extends RuntimeException
{
    /** @param array<int,array<string,mixed>> $responseFields */
    public function __construct(
        public readonly string $title,
        public readonly string $instructions,
        public readonly array $responseFields,
        public readonly int $timeoutMinutes,
    ) {
        parent::__construct('Workflow execution requires operator approval.');
    }
}
