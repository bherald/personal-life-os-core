<?php

namespace App\Services\UnifiedCli;

use RuntimeException;

/** Exact-origin trust metadata. Public CA bytes never become a global trust change. */
final class CliTlsPolicy
{
    public function __construct(private readonly array $policy = [])
    {
        if ($policy === []) {
            return;
        }
        if (array_diff(array_keys($policy), ['require_lan_tls', 'authorities']) !== []
            || ! is_bool($policy['require_lan_tls'] ?? null)
            || ! is_array($policy['authorities'] ?? null)
            || ($policy['authorities'] !== [] && array_is_list($policy['authorities']))) {
            throw new RuntimeException('TLS policy requires a boolean and exact-origin authorities object.');
        }
        foreach ($policy['authorities'] as $origin => $entry) {
            if (! is_string($origin) || $origin !== self::origin($origin)
                || ! str_starts_with($origin, 'https://') || ! is_array($entry)
                || count($entry) !== 2 || array_diff(array_keys($entry), ['ca_file', 'ca_sha256']) !== []
                || ! is_string($entry['ca_file'] ?? null) || str_contains($entry['ca_file'], "\0")
                || ! (str_starts_with($entry['ca_file'], '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $entry['ca_file']))
                || ! is_string($entry['ca_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $entry['ca_sha256'])) {
                throw new RuntimeException('TLS authorities require canonical HTTPS origins, absolute CA files and SHA-256 identities.');
            }
        }
    }

    /** Bind only the selected origin; changing unrelated authorities does not retire a route. */
    public function routeBinding(?string $url, string $mode): array
    {
        if ($this->policy === []) {
            return [];
        }
        $origin = self::origin($url);
        $required = $this->policy['require_lan_tls'];
        // An explicit secure connection profile cannot be bypassed by switching
        // network modes. This deliberately also rejects HTTP loopback clients.
        if ($required && ! str_starts_with($origin, 'https://')) {
            throw new RuntimeException('This connection profile requires HTTPS; plaintext was rejected before credentials or I/O.');
        }
        $authority = $this->policy['authorities'][$origin] ?? null;
        if (! $required && $authority === null) {
            return [];
        }
        if ($authority !== null) {
            $this->caBytes($authority);
        }

        return ['tls_binding_sha256' => hash('sha256', json_encode([
            'origin' => $origin, 'require_lan_tls' => $required,
            'authority' => $authority === null ? null : ['ca_file' => $authority['ca_file'], 'ca_sha256' => $authority['ca_sha256']],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
    }

    public function options(string $url, string $mode): array
    {
        if ($this->routeBinding($url, $mode) === []) {
            return [];
        }
        $authority = $this->policy['authorities'][self::origin($url)] ?? null;
        if (! extension_loaded('curl') || ! defined('CURLOPT_CAINFO_BLOB')) {
            throw new RuntimeException('Scoped TLS requires local cURL CA-blob support. No alternate or insecure transport was used.');
        }
        $options = ['verify' => true, 'curl' => []];
        if ($authority !== null) {
            // Copy checked bytes into cURL. Never let a later path replacement
            // silently alter this request's admitted trust anchor.
            $options['curl'][CURLOPT_CAINFO_BLOB] = $this->caBytes($authority);
        }

        return $options;
    }

    private function caBytes(array $authority): string
    {
        try {
            $bytes = CliLocalBindings::readPrivateFile($authority['ca_file']);
            if (strlen($bytes) > 1048576 || ! hash_equals($authority['ca_sha256'], hash('sha256', $bytes))
                || ! preg_match('/\A\s*(?:-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----\s*)+\z/D', $bytes)) {
                throw new RuntimeException;
            }
            preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bytes, $certificates);
            foreach ($certificates[0] as $certificate) {
                if (@openssl_x509_read($certificate) === false) {
                    throw new RuntimeException;
                }
            }

            return $bytes;
        } catch (\Throwable) {
            throw new RuntimeException('Scoped TLS CA is unavailable, invalid or changed. No request was sent.');
        }
    }

    private static function origin(?string $url): string
    {
        $parts = is_string($url) && ! preg_match('/[\x00-\x20\x7f]/', $url) ? parse_url($url) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('TLS trust requires an explicit HTTP(S) origin without credentials.');
        }
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('TLS origin has an invalid port.');
        }

        return $parts['scheme'].'://'.strtolower($parts['host']).':'.$port;
    }
}
