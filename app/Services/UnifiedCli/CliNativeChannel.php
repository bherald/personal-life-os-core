<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** One private descriptor protocol for agent tools and operator controls. */
final class CliNativeChannel
{
    public static function open(CliStateStore $store): Closure
    {
        $nativeRequests = fopen('php://fd/3', 'w');
        $nativeResponses = fopen('php://fd/4', 'r');
        if ($nativeRequests === false || $nativeResponses === false) {
            throw new RuntimeException('Native execution channel is unavailable.');
        }
        stream_set_timeout($nativeResponses, 40);
        $nativeSequence = 0;

        return function ($sessionId, $operation, $arguments, $permissions) use ($nativeRequests, $nativeResponses, &$nativeSequence, $store): array {
            $id = ++$nativeSequence;
            $session = $store->session($sessionId);
            $turnId = $session['turns'][array_key_last($session['turns'])]['id'] ?? null;
            $line = json_encode(compact('id', 'sessionId', 'turnId', 'operation', 'arguments', 'permissions'), JSON_THROW_ON_ERROR)."\n";
            $offset = 0;
            while ($offset < strlen($line)) {
                $written = fwrite($nativeRequests, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Native execution channel closed; action outcome is unknown.');
                }
                $offset += $written;
            }
            fflush($nativeRequests);
            $response = fgets($nativeResponses);
            if ($response === false) {
                throw new RuntimeException('Native response unavailable; action was not replayed.');
            }
            $result = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
            if (($result['id'] ?? null) !== $id || isset($result['error']) || ! is_array($result['result'] ?? null)) {
                throw new RuntimeException($result['error']['message'] ?? 'Invalid native response; action was not replayed.');
            }

            return $result['result'];
        };
    }
}
