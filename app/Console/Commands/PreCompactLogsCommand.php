<?php

namespace App\Console\Commands;

use App\Services\PreCompaction\LogPreCompactor;
use Illuminate\Console\Command;

/**
 * Framework B7 — CLI driver for the deterministic log pre-compactor.
 *
 * Reads a log file (or stdin via --stdin), runs the deterministic
 * compaction pass, and prints either the compacted output (default) or
 * stats-only (--stats). Use to measure token savings on real prod logs
 * before wiring the compactor into specific LLM callers.
 *
 * Example:
 *   tail -2000 storage/logs/laravel.log > /tmp/sample.log
 *   php artisan precompact:logs /tmp/sample.log --stats
 *
 * Since the compactor is pure PHP (no DB, no LLM, no network), this
 * command is safe to run anywhere.
 */
class PreCompactLogsCommand extends Command
{
    protected $signature = 'precompact:logs
        {file? : Path to log file (omit when using --stdin)}
        {--stdin : Read input from stdin}
        {--stats : Print stats only, skip compacted output}
        {--lines= : Cap input to the last N lines}';

    protected $description = 'Framework B7: deterministic log pre-compaction driver';

    public function handle(LogPreCompactor $compactor): int
    {
        $content = $this->readInput();
        if ($content === null) {
            return self::FAILURE;
        }

        $result = $compactor->compact($content);
        $stats = $result['stats'];

        $reductionPct = $stats['bytes_in'] > 0
            ? round((1 - $stats['bytes_out'] / $stats['bytes_in']) * 100, 1)
            : 0.0;

        if (! $this->option('stats')) {
            $this->line($result['compacted']);
            $this->line('');
            $this->line(str_repeat('─', 60));
        }

        $this->info('Log pre-compaction summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Input lines', number_format($stats['input_lines'])],
                ['Output lines', number_format($stats['output_lines'])],
                ['Unique signatures', number_format($stats['signatures'])],
                ['Bytes in', number_format($stats['bytes_in'])],
                ['Bytes out', number_format($stats['bytes_out'])],
                ['Reduction', sprintf('%.1f%%', $reductionPct)],
            ]
        );

        $this->info(sprintf('[ITEMS_PROCESSED:%d]', $stats['signatures']));

        return self::SUCCESS;
    }

    private function readInput(): ?string
    {
        if ($this->option('stdin')) {
            $stream = fopen('php://stdin', 'r');
            if ($stream === false) {
                $this->error('Could not open stdin.');
                return null;
            }
            $content = (string) stream_get_contents($stream);
            fclose($stream);
            return $this->capLines($content);
        }

        $file = (string) $this->argument('file');
        if ($file === '') {
            $this->error('Missing file argument. Pass a path or use --stdin.');
            return null;
        }
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("File not readable: {$file}");
            return null;
        }

        return $this->capLines((string) file_get_contents($file));
    }

    private function capLines(string $content): string
    {
        $cap = (int) ($this->option('lines') ?: 0);
        if ($cap <= 0) {
            return $content;
        }
        $lines = preg_split("~\r?\n~", $content);
        if ($lines === false) {
            return $content;
        }
        return implode("\n", array_slice($lines, -$cap));
    }
}
