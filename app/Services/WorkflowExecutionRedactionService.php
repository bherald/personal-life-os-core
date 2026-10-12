<?php

namespace App\Services;

final class WorkflowExecutionRedactionService
{
    public const VERSION = 'workflow-execution-display-redaction-2026-07-13';

    private const REDACTED = '[REDACTED]';

    /**
     * Redact a workflow execution payload for browser/API display without
     * changing the stored values used by checkpoint replay and retry.
     *
     * @return array{value:mixed,redaction:array<string,mixed>}
     */
    public function forDisplay(mixed $value): array
    {
        $redactedFields = 0;
        $safeValue = $this->redactValue($value, null, $redactedFields);

        return [
            'value' => $safeValue,
            'redaction' => [
                'version' => self::VERSION,
                'enforced' => true,
                'raw_storage_preserved' => true,
                'redacted_fields' => $redactedFields,
            ],
        ];
    }

    private function redactValue(mixed $value, int|string|null $key, int &$redactedFields): mixed
    {
        if ($this->isSensitiveKey($key)) {
            $redactedFields++;

            return self::REDACTED;
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $safe = [];
            $rowValueIsSensitive = isset($value['key'])
                && is_string($value['key'])
                && $this->isSensitiveKey($value['key']);

            foreach ($value as $childKey => $childValue) {
                if ($rowValueIsSensitive && $childKey === 'value') {
                    $safe[$childKey] = self::REDACTED;
                    $redactedFields++;

                    continue;
                }

                $safe[$childKey] = $this->redactValue($childValue, $childKey, $redactedFields);
            }

            return $safe;
        }

        if (! is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $safe = $this->redactValue($decoded, $key, $redactedFields);

            return json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $safe = $this->redactString($value);
        if ($safe !== $value) {
            $redactedFields++;
        }

        return $safe;
    }

    private function isSensitiveKey(int|string|null $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $snakeKey = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $snakeKey));

        if ($normalized === 'token' || str_ends_with($normalized, '_token')) {
            return true;
        }

        return preg_match(
            '/(?:^|_)(?:password|passwd|pwd|secret|client_secret|private_key|api_key|apikey|access_token|refresh_token|id_token|auth_token|authorization|bearer|cookie|set_cookie|credential|credentials)(?:$|_)/',
            $normalized
        ) === 1;
    }

    private function redactString(string $value): string
    {
        return (string) preg_replace([
            '/\bAuthorization\s*:\s*Bearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/=:-]{8,}/i',
            '/\b(?:password|passwd|pwd|api[_-]?key|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|client[_-]?secret|secret|authorization)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i',
            '/([?&](?:password|passwd|pwd|api[_-]?key|access[_-]?token|refresh[_-]?token|auth[_-]?token|client[_-]?secret|secret)=)[^&\s]+/i',
            '/([A-Za-z][A-Za-z0-9+.-]*:\/\/)([^:@\/\s]+):([^@\/\s]+)@/i',
            '/\b(?:sk|pk|rk|ghp|gho|ghu|github_pat|glpat|xox[baprs]?)-[A-Za-z0-9_=-]{8,}\b/i',
        ], [
            self::REDACTED,
            'Bearer '.self::REDACTED,
            self::REDACTED,
            '$1'.self::REDACTED,
            '$1'.self::REDACTED.'@',
            self::REDACTED,
        ], $value);
    }
}
