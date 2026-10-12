<?php

namespace App\Services\UnifiedCli;

use Closure;
use RuntimeException;

/** Decode framing only. The caller durably captures every original byte first. */
final class ChatStreamDecoder
{
    public function decode(iterable $chunks, string $model, Closure $capture, array $acceptedResponseModels = [], ?Closure $wireTerminal = null): array
    {
        $buffer = '';
        $content = '';
        $reasoning = '';
        $calls = [];
        $finish = null;
        $seenModel = false;
        $reportedModel = null;
        $done = false;
        $usage = null;
        foreach ($chunks as $bytes) {
            if (! is_string($bytes)) {
                throw new RuntimeException('Invalid stream bytes.');
            }
            $capture($bytes);
            $buffer .= $bytes;
            while (preg_match('/\r\n\r\n|\n\n|\r\r/', $buffer, $boundary, PREG_OFFSET_CAPTURE)) {
                [$separator, $offset] = $boundary[0];
                $event = substr($buffer, 0, $offset);
                $buffer = substr($buffer, $offset + strlen($separator));
                $lines = [];
                foreach (preg_split('/\r\n|\n|\r/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $line = substr($line, 5);
                        $lines[] = str_starts_with($line, ' ') ? substr($line, 1) : $line;
                    }
                }
                if ($lines === []) {
                    continue;
                }
                if ($done) {
                    throw new RuntimeException('Provider sent data after completion. Original bytes are retained.');
                }
                $data = implode("\n", $lines);
                if ($data === '[DONE]') {
                    $wireTerminal?->__invoke('done_marker');
                    $done = true;

                    continue;
                }
                $record = json_decode($data, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($record) || isset($record['error'])) {
                    throw new RuntimeException('Provider returned an invalid or error event. Original bytes are retained.');
                }
                if (isset($record['model'])) {
                    if (! in_array($record['model'], [$model, ...$acceptedResponseModels], true)
                        || ($seenModel && $record['model'] !== $reportedModel)) {
                        throw new RuntimeException('Provider model identity changed. No fallback or action is allowed.');
                    }
                    $seenModel = true;
                    $reportedModel = $record['model'];
                }
                if (isset($record['usage'])) {
                    $usage = $record['usage'];
                }
                foreach ($record['choices'] ?? [] as $choice) {
                    if (($choice['index'] ?? 0) !== 0) {
                        throw new RuntimeException('Unexpected additional completion choice.');
                    }
                    $delta = $choice['delta'] ?? [];
                    foreach (['content', 'reasoning_content'] as $field) {
                        if (isset($delta[$field]) && ! is_string($delta[$field])) {
                            throw new RuntimeException('Non-text provider delta requires a supported media adapter.');
                        }
                    }
                    $content .= $delta['content'] ?? '';
                    $reasoning .= $delta['reasoning_content'] ?? '';
                    foreach ($delta['tool_calls'] ?? [] as $part) {
                        $index = $part['index'] ?? null;
                        if (! is_int($index) || $index < 0) {
                            throw new RuntimeException('Missing stable tool-call stream index.');
                        }
                        $calls[$index] ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                        if (isset($part['type']) && $part['type'] !== 'function') {
                            throw new RuntimeException('Unsupported provider action type.');
                        }
                        if (isset($part['id'])) {
                            if (! is_string($part['id']) || ($calls[$index]['id'] !== '' && $calls[$index]['id'] !== $part['id'])) {
                                throw new RuntimeException('Provider changed a tool-call identity.');
                            }
                            $calls[$index]['id'] = $part['id'];
                        }
                        foreach (['name', 'arguments'] as $field) {
                            if (isset($part['function'][$field])) {
                                if (! is_string($part['function'][$field])) {
                                    throw new RuntimeException('Malformed tool-call delta.');
                                }
                                $calls[$index]['function'][$field] .= $part['function'][$field];
                            }
                        }
                    }
                    if (isset($choice['finish_reason'])) {
                        if ($finish !== null && $finish !== $choice['finish_reason']) {
                            throw new RuntimeException('Provider changed its completion status.');
                        }
                        $finish = $choice['finish_reason'];
                        if (count($record['choices']) === 1 && ($choice['index'] ?? null) === 0
                            && in_array($finish, ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'], true)) {
                            $wireTerminal?->__invoke('finish_reason');
                        }
                    }
                }
            }
        }
        ksort($calls);

        return ['model' => $seenModel ? $model : null,
            'reported_model' => $reportedModel,
            'completed' => $done && $seenModel && $buffer === '' && in_array($finish, ['stop', 'tool_calls'], true),
            'content' => $content, 'reasoning_content' => $reasoning,
            'tool_calls' => array_values($calls), 'finish_reason' => $finish, 'usage' => $usage];
    }
}
