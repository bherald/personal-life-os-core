<?php

namespace App\Logging;

use Monolog\LogRecord;
use stdClass;
use Throwable;

class SensitiveLogContextProcessor
{
    public const REDACTED = '[REDACTED]';

    public const PRIVATE_TEXT_REDACTED = '[PRIVATE_TEXT_REDACTED]';

    private const SAFE_TOKEN_METRIC_KEYS = [
        'cached_tokens',
        'completion_tokens',
        'context_tokens',
        'estimated_tokens',
        'input_tokens',
        'max_tokens',
        'output_tokens',
        'prompt_tokens',
        'reasoning_tokens',
        'remaining_tokens',
        'token_budget',
        'token_count',
        'token_estimate',
        'token_limit',
        'token_type',
        'token_usage',
        'tokens_per_second',
        'tokens_used',
        'total_tokens',
    ];

    private const SENSITIVE_KEYS = [
        'api_key',
        'apikey',
        'authorization',
        'client_secret',
        'connection_string',
        'cookie',
        'credential',
        'credentials',
        'database_url',
        'dsn',
        'password',
        'passphrase',
        'passwd',
        'private_key',
        'proxy_authorization',
        'redis_url',
        'secret',
        'set_cookie',
        'signed_url',
        'token',
        'webhook_url',
        'x_api_key',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * Redact secret-bearing keyed values without changing diagnostic objects.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public function redact(array $values): array
    {
        return $this->redactArray($values, 0);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redactArray(array $values, int $depth): array
    {
        if ($depth >= 16) {
            return ['redaction_depth_limit' => self::REDACTED];
        }

        foreach ($values as $key => $value) {
            if ($value instanceof Throwable) {
                $values[$key] = $this->sanitizeThrowable($value);

                continue;
            }

            if (is_string($key) && $this->isPrivateTextKey($key)) {
                $values[$key] = self::PRIVATE_TEXT_REDACTED;

                continue;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                $values[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redactArray($value, $depth + 1);

                continue;
            }

            if ($value instanceof stdClass) {
                $values[$key] = (object) $this->redactArray((array) $value, $depth + 1);
            }
        }

        return $values;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        if (in_array($normalized, self::SAFE_TOKEN_METRIC_KEYS, true)) {
            return false;
        }

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        return preg_match(
            '/(?:^|_)(?:api_key|credential|credentials|cookie|password|passphrase|passwd|private_key|secret|token)$/',
            $normalized,
        ) === 1 || preg_match(
            '/(?:^|_)(?:access|encryption|secret|signing)_key(?:_id)?$/',
            $normalized,
        ) === 1;
    }

    private function normalizeKey(string $key): string
    {
        $key = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($key)) ?? $key;
        $key = strtolower($key);

        return trim(preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key, '_');
    }

    private function isPrivateTextKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);

        if ($normalized === 'message') {
            return true;
        }

        return preg_match(
            '/(?:^|_)(?:claims?|prompts?|quer(?:y|ies))(?:_text|_prefix|_preview)?$/',
            $normalized,
        ) === 1 || preg_match(
            '/(?:^|_)(?:errors?|exceptions?|throwables?)(?:_message|_messages|_text|_detail|_details|_body|_response|_output|_trace|_stacktrace)?$/',
            $normalized,
        ) === 1;
    }

    /**
     * Preserve failure classification without letting Monolog normalize private
     * exception messages or traces into the final sink payload.
     *
     * @return array<string, bool|int|string|list<string>>
     */
    private function sanitizeThrowable(Throwable $throwable): array
    {
        $previousTypes = [];
        $previous = $throwable->getPrevious();

        while ($previous instanceof Throwable && count($previousTypes) < 8) {
            $previousTypes[] = $previous::class;
            $previous = $previous->getPrevious();
        }

        return [
            'type' => $throwable::class,
            'code' => (int) $throwable->getCode(),
            'previous_types' => $previousTypes,
            'chain_truncated' => $previous instanceof Throwable,
            'message_logged' => false,
            'stacktrace_logged' => false,
        ];
    }
}
