<?php

namespace App\Support;

final class SensitiveTextTelemetry
{
    public const SCHEMA = 'plos.sensitive_text_telemetry.v1';

    /**
     * Build a correlatable receipt for private text that is not retained.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function content(string $text, string $scope, string $kind): array
    {
        $kind = strtolower(trim($kind));
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $kind) !== 1) {
            throw new \InvalidArgumentException('Sensitive text kind must be a bounded snake_case identifier.');
        }

        return [
            'private_text_kind' => $kind,
            'private_text_length_bytes' => strlen($text),
            'private_text_fingerprint' => self::fingerprint($text, $scope),
            'private_text_fingerprint_available' => (string) config('app.key') !== '',
            'raw_private_text_logged' => false,
        ];
    }

    /**
     * Build a correlatable, content-free log context for private text.
     *
     * @return array<string, bool|int|string|null>
     */
    public static function query(string $query, string $scope): array
    {
        $fingerprint = self::fingerprint($query, $scope);

        return [
            'query_length_bytes' => strlen($query),
            'query_fingerprint' => $fingerprint,
            'query_fingerprint_available' => $fingerprint !== null,
            'query_text_logged' => false,
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    public static function inventory(): array
    {
        return [
            'schema' => self::SCHEMA,
            'raw_text_logged' => false,
            'fingerprint_algorithm' => 'purpose_scoped_hmac_sha256',
            'fingerprint_key_source' => 'derived_from_app_key_not_logged',
            'missing_key_behavior' => 'length_only_no_unkeyed_hash',
            'fingerprinting_changes_response_content' => false,
            'compression' => false,
            'precompaction' => false,
        ];
    }

    private static function fingerprint(string $text, string $scope): ?string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            return null;
        }

        $key = hash('sha256', self::SCHEMA.'|'.$scope.'|'.$appKey, true);

        return 'hmac-sha256:'.hash_hmac('sha256', $text, $key);
    }
}
