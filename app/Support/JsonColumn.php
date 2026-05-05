<?php

namespace App\Support;

use InvalidArgumentException;

final class JsonColumn
{
    /**
     * Add a portable JSON scalar equality predicate for trusted column/path inputs.
     */
    public static function whereScalarEquals(mixed $query, string $column, string $path, string $value, string $boolean = 'and'): mixed
    {
        [$sql, $bindings] = self::scalarEqualsSql($query, $column, $path, $value);

        return $query->whereRaw($sql, $bindings, $boolean);
    }

    public static function orWhereScalarEquals(mixed $query, string $column, string $path, string $value): mixed
    {
        return self::whereScalarEquals($query, $column, $path, $value, 'or');
    }

    /**
     * @return array{0:string,1:list<string>}
     */
    private static function scalarEqualsSql(mixed $query, string $column, string $path, string $value): array
    {
        self::assertJsonPath($path);

        $driver = (string) $query->getConnection()->getDriverName();
        $identifier = self::quoteIdentifier($column, $driver);

        if ($driver === 'sqlite') {
            return [
                "CAST(json_extract({$identifier}, ?) AS TEXT) = ?",
                [$path, $value],
            ];
        }

        if ($driver === 'pgsql') {
            return [
                "{$identifier} #>> ? = ?",
                [self::postgresPath($path), $value],
            ];
        }

        return [
            "JSON_UNQUOTE(JSON_EXTRACT({$identifier}, ?)) = ?",
            [$path, $value],
        ];
    }

    private static function quoteIdentifier(string $column, string $driver): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*\z/', $column)) {
            throw new InvalidArgumentException('Unsafe JSON column identifier.');
        }

        $quote = $driver === 'pgsql' ? '"' : '`';

        return implode('.', array_map(
            static fn (string $part): string => $quote.str_replace($quote, $quote.$quote, $part).$quote,
            explode('.', $column),
        ));
    }

    private static function assertJsonPath(string $path): void
    {
        if (! preg_match('/\A\$(?:\.[A-Za-z_][A-Za-z0-9_]*)+\z/', $path)) {
            throw new InvalidArgumentException('Unsafe JSON path.');
        }
    }

    private static function postgresPath(string $path): string
    {
        $segments = explode('.', substr($path, 2));

        return '{'.implode(',', $segments).'}';
    }
}
