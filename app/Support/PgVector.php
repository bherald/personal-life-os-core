<?php

namespace App\Support;

final class PgVector
{
    public const DEFAULT_LITERAL_PRECISION = 4;

    /**
     * Format a PHP embedding array as a compact pgvector literal.
     */
    public static function literal(array $embedding, int $precision = self::DEFAULT_LITERAL_PRECISION): string
    {
        $epsilon = pow(10, -$precision);

        $parts = array_map(static function ($value) use ($precision, $epsilon): string {
            $rounded = round((float) $value, $precision);
            if (abs($rounded) < $epsilon) {
                $rounded = 0.0;
            }

            $formatted = number_format($rounded, $precision, '.', '');
            $trimmed = rtrim(rtrim($formatted, '0'), '.');

            return $trimmed === '-0' || $trimmed === '' ? '0' : $trimmed;
        }, $embedding);

        return '[' . implode(',', $parts) . ']';
    }
}
