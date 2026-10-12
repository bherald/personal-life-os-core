<?php

namespace App\Services;

use App\Exceptions\ChatToolLifecycleException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Database\QueryException;
use Throwable;

class ChatRunFailureService
{
    public const SCHEMA = 'plos.chat_run_failure.v1';

    /**
     * @return array<string, bool|string>
     */
    public function event(Throwable $exception, string $runId): array
    {
        [$errorCode, $message, $retryable] = $this->classification($exception);

        return [
            'type' => 'run_error',
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'error_code' => $errorCode,
            'message' => $message,
            'retryable' => $retryable,
            'raw_error_included' => false,
        ];
    }

    /**
     * @return array{string, string, bool}
     */
    private function classification(Throwable $exception): array
    {
        if ($exception instanceof ChatToolLifecycleException) {
            return ['tool_lifecycle_invalid', 'The tool response could not be safely correlated.', false];
        }

        if ($exception instanceof ConnectException) {
            return ['provider_connection_failed', 'The AI provider could not be reached.', true];
        }

        if ($exception instanceof RequestException) {
            $status = $exception->getResponse()?->getStatusCode();

            return match (true) {
                $status === 429 => ['provider_rate_limited', 'The AI provider is temporarily rate limited.', true],
                $status === 408 || $status === 504 => ['provider_timeout', 'The AI provider did not respond in time.', true],
                $status !== null && $status >= 500 => ['provider_unavailable', 'The AI provider is temporarily unavailable.', true],
                $status === 401 || $status === 403 => ['provider_authentication_failed', 'The AI provider could not authenticate.', false],
                default => ['provider_request_failed', 'The AI provider rejected the request.', false],
            };
        }

        if ($exception instanceof TransferException) {
            return ['provider_transport_failed', 'The AI provider connection failed.', true];
        }

        if ($exception instanceof QueryException) {
            return ['chat_persistence_failed', 'The chat response could not be saved.', true];
        }

        return ['chat_run_failed', 'The AI request failed before completion.', false];
    }
}
