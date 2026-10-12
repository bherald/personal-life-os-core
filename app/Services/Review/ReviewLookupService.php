<?php

namespace App\Services\Review;

use App\Services\WorkflowExecutionRedactionService;
use InvalidArgumentException;

final class ReviewLookupService
{
    public function __construct(
        private readonly ReviewDeskService $reviewDesk,
        private readonly WorkflowExecutionRedactionService $redactor,
    ) {}

    public function lookup(string $reference): ?array
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $reference)) {
            throw new InvalidArgumentException('A reference of 1 to 255 characters is required.');
        }
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 255 || str_starts_with($reference, '-')) {
            throw new InvalidArgumentException('A reference of 1 to 255 characters is required.');
        }

        $item = $this->reviewDesk->lookup($reference);

        return $item === null ? null : $this->redactPaths($this->redactor->forDisplay($item)['value']);
    }

    private function redactPaths(mixed $value, int|string|null $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->redactPaths($childValue, $childKey);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        if (is_string($key) && preg_match('/(?:^|_)(?:path|filepath|file_path|local_uri|host_path)(?:$|_)/i', $key)
            && preg_match('~^(?:/|[A-Za-z]:\\\\|file:|sqlite:)~i', $value)) {
            return '[REDACTED_LOCAL_PATH]';
        }

        return (string) preg_replace([
            '~([?&](?:token|key|signature|sig|session|credential|auth|password)=)[^&#\\s]+~i',
            '~(^|[\\s"\x27(=])/(?:home|Users|root|srv|var|tmp|mnt|opt|etc|run)/[^\\s"\x27<>),;]+~',
            '~\\b[A-Za-z]:\\\\(?:[^\\\\\\s"\x27<>),;]+\\\\?)+~',
            '~\\b(?:file|sqlite)://[^\\s"\x27<>),;]+~i',
        ], ['$1[REDACTED]', '$1[REDACTED_LOCAL_PATH]', '[REDACTED_LOCAL_PATH]', '[REDACTED_LOCAL_PATH]'], $value);
    }
}
