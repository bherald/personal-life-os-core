<?php

namespace App\Exceptions;

use App\Exceptions\AI\InferenceAdmissionException;
use RuntimeException;

/** Only a proven pre-dispatch, custody-clear refusal may be retried. */
class ModelHostControllerUnavailableException extends RuntimeException
{
    public readonly array $capacityShortage;

    public function __construct(
        public readonly string $reason,
        public readonly bool $dispatched = false,
        public readonly ?string $lastWaitReason = null,
        ?\Throwable $previous = null,
        array $capacityShortage = [],
        public readonly bool $capacityCustodyCleared = false,
    ) {
        parent::__construct('Model host unavailable: '.$reason, 0, $previous);
        $this->capacityShortage = InferenceAdmissionException::sanitizeControllerFailure([
            'wait_reason' => $reason, 'last_wait_reason' => $lastWaitReason, 'capacity_shortage' => $capacityShortage,
        ])['capacity_shortage'] ?? [];
    }

    /** Issued only after original journal cleanup and a persisted terminal capacity wait. */
    public function allowsCapacityFallback(): bool
    {
        return $this->reason === 'wait_deadline' && $this->lastWaitReason === 'capacity'
            && ! $this->dispatched && $this->capacityCustodyCleared;
    }
}
