<?php

namespace App\Support;

use JsonSerializable;
use RuntimeException;
use Stringable;

/**
 * Formula detection adapts Promptfoo's MIT-licensed escapeCsvFormula:
 * https://github.com/promptfoo/promptfoo/blob/fcde2e89a89dc4ca79dcc3012927f50193251759/src/util/eval/evalTableUtils.ts
 *
 * Copyright (c) Promptfoo 2025. SPDX-License-Identifier: MIT.
 * PLOS modifications: PHP encoder, no disable override, and OWASP full-width
 * plus leading-control trigger coverage. See NOTICE.md.
 */
final class SpreadsheetSafeCsv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<array<string, mixed>>  $rows
     */
    public static function encode(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to open temporary CSV stream.');
        }

        try {
            self::writeRow($handle, $headers);

            foreach ($rows as $row) {
                $cells = [];
                foreach ($headers as $header) {
                    $cells[] = $row[$header] ?? null;
                }

                self::writeRow($handle, $cells);
            }

            rewind($handle);
            $contents = stream_get_contents($handle);

            if ($contents === false) {
                throw new RuntimeException('Unable to read temporary CSV stream.');
            }

            return $contents;
        } finally {
            fclose($handle);
        }
    }

    public static function cell(mixed $value): string
    {
        $text = self::stringValue($value);
        if ($text === '') {
            return '';
        }

        // Adapted from promptfoo's MIT-licensed escapeCsvFormula boundary.
        // PLOS additionally covers OWASP-listed full-width formula triggers.
        $dangerous = preg_match(
            '/\A(?:[\x09\x0A\x0D]|[\s\x{200B}\x{200C}\x{200D}\x{FEFF}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}])/u',
            $text
        );

        if ($dangerous === 1) {
            return "'".$text;
        }

        if ($dangerous === false) {
            $unpadded = ltrim($text, " \t\n\r\0\x0B");
            if ($unpadded !== '' && str_contains('=+-@', $unpadded[0])) {
                return "'".$text;
            }
        }

        return $text;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $cells
     */
    private static function writeRow($handle, array $cells): void
    {
        $written = fputcsv(
            $handle,
            array_map(self::cell(...), $cells),
            ',',
            '"',
            '',
            "\n"
        );

        if ($written === false) {
            throw new RuntimeException('Unable to write temporary CSV stream.');
        }
    }

    private static function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        if ($value instanceof JsonSerializable || is_array($value) || is_object($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return '';
    }
}
