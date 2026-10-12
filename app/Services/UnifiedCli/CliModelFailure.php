<?php

namespace App\Services\UnifiedCli;

use GuzzleHttp\Exception\ConnectException;
use Throwable;

/** Operator diagnostics without provider bodies, credentials or transport dumps. */
final class CliModelFailure
{
    public static function message(array $route, Throwable $error, ?int $status = null): string
    {
        $reason = match ($status) {
            401, 403 => 'access was refused; check the configured credentials and provider permissions',
            404 => 'the configured model endpoint was not found',
            429 => 'the provider is busy or its request limit has been reached',
            500, 502, 503, 504 => 'the provider is temporarily unavailable (HTTP '.$status.')',
            null => 'the request did not complete',
            default => 'the provider returned HTTP '.$status,
        };
        if ($error instanceof ConnectException) {
            $reason = match ($error->getHandlerContext()['errno'] ?? null) {
                6 => 'the configured hostname could not be resolved',
                7 => 'the configured engine could not be reached',
                28 => 'the connection timed out',
                35, 60 => 'the secure connection could not be established',
                default => 'the connection could not be established',
            };
        }
        $model = json_encode($route['model'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $instance = json_encode($route['instance_id'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return 'Model '.$model.' on '.$instance.': '.$reason.'. Your session and captured output are retained. Check the connection/settings or choose /model. Review partial results before retrying; no automatic retry or model switch occurred.';
    }
}
