<?php

namespace App\Services\UnifiedCli;

use App\Support\RuntimeBoolean;
use InvalidArgumentException;

final class CliSettingValue
{
    public static function decode(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        $decoded = match ($type) {
            'bool', 'boolean' => RuntimeBoolean::parse($value) ?? throw new InvalidArgumentException('Invalid synced boolean setting.'),
            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException('Invalid synced integer setting.'),
            'float', 'double', 'number' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException('Invalid synced numeric setting.'),
            'json', 'array' => is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value,
            'string', 'text', 'datetime' => $value,
            default => throw new InvalidArgumentException('Unsupported synced setting type: '.$type),
        };
        // A nullable source cell remains unset, rather than a fabricated value.
        if ($decoded !== null) {
            self::validate($decoded, $type);
        }

        return $decoded;
    }

    public static function validate(mixed $value, string $type): void
    {
        $valid = match ($type) {
            'boolean', 'bool' => is_bool($value),
            'integer', 'int' => is_int($value),
            'float', 'double', 'number' => (is_float($value) || is_int($value)) && is_finite((float) $value),
            'json', 'array' => is_array($value),
            'datetime' => is_string($value) && strtotime($value) !== false,
            'string', 'text' => is_string($value),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException("Expected a typed {$type} value.");
        }
    }
}
