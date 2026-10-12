<?php

namespace App\Support;

final class RuntimeBoolean
{
    private const TRUE_STRINGS = ['1', 'true', 'yes', 'on'];

    private const FALSE_STRINGS = ['', '0', 'false', 'no', 'off'];

    public static function isTrue(mixed $value): bool
    {
        return self::parse($value) === true;
    }

    public static function parse(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ($value) {
                1, 1.0 => true,
                0, 0.0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, self::TRUE_STRINGS, true)) {
            return true;
        }

        if (in_array($normalized, self::FALSE_STRINGS, true)) {
            return false;
        }

        return null;
    }
}
