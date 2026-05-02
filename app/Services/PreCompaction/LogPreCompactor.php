<?php

namespace App\Services\PreCompaction;

/**
 * Framework B7 — deterministic log pre-compaction pilot.
 *
 * First implementation of the content-specific strategies described in
 * docs/plos-precompaction-design.md (§ "Logs"). Runs before any LLM call
 * so tokens-paid-to-the-model measures compressed, not raw, input.
 *
 * This pilot is pure, no DB, no LLM. It returns both the compacted text
 * and the reduction stats so callers can decide whether the reduction
 * is worth paying for a downstream call at all.
 *
 * Deterministic rules (exactly as spec'd in the design doc):
 *   - Normalize timestamps, UUIDs, and PIDs to stable placeholders so
 *     lines that differ only on volatile tokens collapse into one
 *     signature.
 *   - Collapse duplicate signatures into a counted group, preserving the
 *     first-seen and last-seen raw example per signature so the caller
 *     can still cite a concrete line.
 *   - Preserve stack-trace headers ("#0 /path/to/File.php(123): ...")
 *     verbatim — those encode call sites that are the whole point of
 *     the log for debugging.
 *   - Do not re-order output. Signature groups appear in the order of
 *     their first occurrence so time-ordered logs stay reconstructible.
 */
class LogPreCompactor
{
    private const TIMESTAMP_PATTERN = '~\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:\s*[A-Z]{3,4})?\]|\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:\s*[A-Z]{3,4})?~';

    private const UUID_PATTERN = '~\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b~i';

    private const PID_PATTERN = '~\bpid[:\s=]+\d+\b~i';

    /**
     * @return array{compacted: string, stats: array{input_lines:int, output_lines:int, signatures:int, bytes_in:int, bytes_out:int}}
     */
    public function compact(string $input): array
    {
        $bytesIn = strlen($input);
        if ($input === '') {
            return [
                'compacted' => '',
                'stats' => [
                    'input_lines' => 0,
                    'output_lines' => 0,
                    'signatures' => 0,
                    'bytes_in' => 0,
                    'bytes_out' => 0,
                ],
            ];
        }

        $rawLines = preg_split("~\r?\n~", $input);
        $inputLines = count($rawLines);

        // Build signature map. Order-preserving: each signature remembers
        // the index of its first occurrence so output is time-stable.
        $signatures = [];
        foreach ($rawLines as $lineIdx => $raw) {
            if ($this->isStackFrame($raw)) {
                // Stack frames are passthrough — never normalized, never
                // collapsed. They attach to whichever signature group
                // immediately precedes them.
                $signatures[] = ['type' => 'stack', 'line' => $raw, 'order' => $lineIdx];
                continue;
            }

            $normalized = $this->normalize($raw);
            $key = 'sig:'.sha1($normalized);

            $existingIdx = $this->findSignature($signatures, $key);
            if ($existingIdx === null) {
                $signatures[] = [
                    'type' => 'sig',
                    'key' => $key,
                    'normalized' => $normalized,
                    'first_raw' => $raw,
                    'last_raw' => $raw,
                    'count' => 1,
                    'order' => $lineIdx,
                ];
                continue;
            }

            $signatures[$existingIdx]['count']++;
            $signatures[$existingIdx]['last_raw'] = $raw;
        }

        // Emit in order-first-seen.
        $out = [];
        $uniqueSigs = 0;
        foreach ($signatures as $sig) {
            if ($sig['type'] === 'stack') {
                $out[] = $sig['line'];
                continue;
            }

            $uniqueSigs++;
            if ($sig['count'] === 1) {
                $out[] = $sig['first_raw'];
                continue;
            }

            $out[] = sprintf('[×%d] %s', $sig['count'], $sig['first_raw']);
            if ($sig['count'] > 2 && $sig['first_raw'] !== $sig['last_raw']) {
                $out[] = sprintf('    (last) %s', $sig['last_raw']);
            }
        }

        $compacted = implode("\n", $out);

        return [
            'compacted' => $compacted,
            'stats' => [
                'input_lines' => $inputLines,
                'output_lines' => count($out),
                'signatures' => $uniqueSigs,
                'bytes_in' => $bytesIn,
                'bytes_out' => strlen($compacted),
            ],
        ];
    }

    private function normalize(string $line): string
    {
        $line = preg_replace(self::TIMESTAMP_PATTERN, '[TS]', $line) ?? $line;
        $line = preg_replace(self::UUID_PATTERN, '[UUID]', $line) ?? $line;
        $line = preg_replace(self::PID_PATTERN, 'pid=[PID]', $line) ?? $line;
        return trim($line);
    }

    private function isStackFrame(string $line): bool
    {
        // Typical PHP stack frames: "#0 /path/File.php(123): Class->method()"
        // Also: "  at App\Service->foo() (/path/File.php:123)"
        return (bool) preg_match('~^\s*(#\d+\s+|at\s+)~', $line);
    }

    /**
     * @param array<int, array{type:string, key?:string}> $sigs
     */
    private function findSignature(array $sigs, string $key): ?int
    {
        foreach ($sigs as $idx => $sig) {
            if (($sig['type'] ?? '') === 'sig' && ($sig['key'] ?? '') === $key) {
                return $idx;
            }
        }
        return null;
    }
}
