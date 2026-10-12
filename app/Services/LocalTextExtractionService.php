<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/** Shared explicit local extraction recipes; callers own routing and persistence. */
final class LocalTextExtractionService
{
    public function extract(string $method, string $path, string $language = 'eng', ?Closure $runner = null): array
    {
        if (! preg_match('/^[a-z][a-z0-9_]*(?:\+[a-z][a-z0-9_]*)*$/D', $language)) {
            throw new InvalidArgumentException('OCR language must be an explicit installed language code or combination.');
        }
        [$command, $timeout] = match ($method) {
            'pdf' => [['pdftotext', '-layout', $path, '-'], 60],
            'ocr' => [['tesseract', $path, 'stdout', '-l', $language], 120],
            default => throw new InvalidArgumentException('Select pdf or ocr explicitly; no extraction fallback is performed.'),
        };
        if ($runner !== null) {
            return $runner($command, $timeout);
        }
        $result = Process::timeout($timeout)->run($command);

        return ['success' => $result->successful(), 'exit_code' => $result->exitCode(),
            'stdout' => $result->output(), 'stderr' => $result->errorOutput()];
    }
}
