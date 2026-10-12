<?php

namespace App\Services\UnifiedCli;

use Closure;

/** Observed receive timings only; ChatStreamDecoder remains the acceptance gate. */
final class ChatStreamTiming
{
    private string $buffer = '';

    private array $seen = [];

    private bool $limited = false;

    private bool $modelSeen = false;

    private int $started;

    public function __construct(private readonly array $models, private readonly Closure $publish,
        private readonly ?Closure $clock = null)
    {
        $this->started = $this->now();
    }

    public function mark(string $phase): void
    {
        if (! isset($this->seen[$phase])) {
            $this->seen[$phase] = true;
            ($this->publish)(['phase' => $phase, 'elapsed_ms' => max(0, (int) (($this->now() - $this->started) / 1_000_000)),
                'observed_only' => true]);
        }
    }

    public function receive(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->mark('first_response_bytes');
        if ($this->limited) {
            return;
        }
        // Bound this diagnostic probe only. The transport independently retains
        // every original byte, including events larger than this probe limit.
        foreach (str_split($bytes, 8192) as $part) {
            $this->buffer .= $part;
            if (strlen($this->buffer) > 1_048_576) {
                $this->limited = true;
                $this->buffer = '';
                $this->mark('timing_probe_limit');

                return;
            }
            while (preg_match('/\r\n\r\n|\n\n|\r\r/', $this->buffer, $boundary, PREG_OFFSET_CAPTURE)) {
                [$separator, $offset] = $boundary[0];
                $event = substr($this->buffer, 0, $offset);
                $this->buffer = substr($this->buffer, $offset + strlen($separator));
                $data = [];
                foreach (preg_split('/\r\n|\n|\r/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $line = substr($line, 5);
                        $data[] = str_starts_with($line, ' ') ? substr($line, 1) : $line;
                    }
                }
                if ($data === []) {
                    continue;
                }
                $data = implode("\n", $data);
                if ($data === '[DONE]') {
                    if ($this->modelSeen) {
                        $this->mark('done_frame');
                    }

                    continue;
                }
                $record = json_decode($data, true);
                if (! is_array($record) || isset($record['error'])) {
                    continue;
                }
                if (isset($record['model'])) {
                    $this->modelSeen = in_array($record['model'], $this->models, true);
                }
                if (! $this->modelSeen) {
                    continue;
                }
                $this->mark('first_model_frame');
                if (is_string($record['plos_raw_output'] ?? null) && $record['plos_raw_output'] !== '') {
                    $this->mark('first_generated_output');
                }
                foreach (is_array($record['choices'] ?? null) ? $record['choices'] : [] as $choice) {
                    if (! is_array($choice) || ($choice['index'] ?? 0) !== 0) {
                        continue;
                    }
                    $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
                    foreach (['content', 'reasoning_content'] as $field) {
                        if (is_string($delta[$field] ?? null) && $delta[$field] !== '') {
                            $this->mark('first_generated_output');
                        }
                    }
                    foreach (is_array($delta['tool_calls'] ?? null) ? $delta['tool_calls'] : [] as $call) {
                        if (! is_array($call) || ! is_array($call['function'] ?? null)) {
                            continue;
                        }
                        foreach (['name', 'arguments'] as $field) {
                            if (is_string($call['function'][$field] ?? null) && $call['function'][$field] !== '') {
                                $this->mark('first_generated_output');
                            }
                        }
                    }
                    if (is_string($choice['finish_reason'] ?? null)) {
                        $this->mark('finish_frame');
                    }
                }
            }
        }
    }

    private function now(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }
}
