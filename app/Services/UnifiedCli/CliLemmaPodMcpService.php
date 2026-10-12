<?php

namespace App\Services\UnifiedCli;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Call the pinned local Lemma pod through its actual MCP endpoint. */
final class CliLemmaPodMcpService
{
    public static function call(string $tool, array $arguments, array $definition, string $root, string $node, int $timeoutMs): array
    {
        $pilot = $definition['env']['LEMMA_POD_PILOT_PATH'] ?? null;
        $credential = $definition['env']['LEMMA_POD_CREDENTIAL_PATH'] ?? null;
        if (! is_string($pilot) || ! is_string($credential) || ! is_file($pilot) || ! is_file($credential)
            || ! str_starts_with($tool, 'lemma_pod_') || ! is_executable($node)) {
            throw new RuntimeException('Lemma pod MCP host binding is unavailable.');
        }
        $process = new Process([$node, $root.'/scripts/lemma-pod-mcp-call.mjs'], $root,
            ['PATH' => dirname($node).PATH_SEPARATOR.'/usr/bin:/bin'],
            json_encode(['pilot_path' => $pilot, 'credential_path' => $credential,
                'tool' => $tool, 'arguments' => $arguments], JSON_THROW_ON_ERROR),
            max(1, ($timeoutMs - 2000) / 1000));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Lemma pod MCP call did not complete.');
        }
        try {
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Lemma pod MCP returned an invalid result.');
        }
        if (! is_array($result) || ! is_array($result['content'] ?? null)) {
            throw new RuntimeException('Lemma pod MCP returned an incomplete result.');
        }

        return $result;
    }

    public static function result(string $tool, array $arguments, array $definition, string $root, string $node, int $timeoutMs): array
    {
        $response = self::call($tool, $arguments, $definition, $root, $node, $timeoutMs);
        if (($response['isError'] ?? false) === true || ! is_string($response['content'][0]['text'] ?? null)) {
            throw new RuntimeException('Lemma pod operation was refused.');
        }
        try {
            $result = json_decode($response['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Lemma pod result is invalid.');
        }
        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            throw new RuntimeException('Lemma pod operation did not succeed.');
        }

        return $result;
    }
}
