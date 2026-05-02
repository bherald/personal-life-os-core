<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ExtensionCookieStoreService
{
    private const CACHE_PREFIX = 'extension_cookies:';
    private const TTL_HOURS = 24;

    /**
     * Store cookies for a domain (from browser extension)
     */
    public function store(string $domain, array $cookies): bool
    {
        $domain = $this->normalizeDomain($domain);
        $key = self::CACHE_PREFIX . $domain;

        Cache::put($key, [
            'domain' => $domain,
            'cookies' => $cookies,
            'stored_at' => now()->toIso8601String(),
            'count' => count($cookies),
        ], now()->addHours(self::TTL_HOURS));

        Log::info('ExtensionCookieStore: Stored cookies', [
            'domain' => $domain,
            'count' => count($cookies),
        ]);

        return true;
    }

    /**
     * Get stored cookies for a domain
     */
    public function get(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        $key = self::CACHE_PREFIX . $domain;
        $data = Cache::get($key);

        return $data ? $data['cookies'] : null;
    }

    /**
     * Build a Cookie header string from stored cookies
     */
    public function buildCookieHeader(string $domain): ?string
    {
        $cookies = $this->get($domain);
        if (!$cookies || empty($cookies)) {
            return null;
        }

        $pairs = array_map(fn($c) => $c['name'] . '=' . $c['value'], $cookies);
        return implode('; ', $pairs);
    }

    /**
     * Clear stored cookies for a domain
     */
    public function clear(string $domain): bool
    {
        $domain = $this->normalizeDomain($domain);
        return Cache::forget(self::CACHE_PREFIX . $domain);
    }

    /**
     * Get metadata about stored cookies (without values)
     */
    public function getInfo(string $domain): ?array
    {
        $domain = $this->normalizeDomain($domain);
        $data = Cache::get(self::CACHE_PREFIX . $domain);

        if (!$data) {
            return null;
        }

        return [
            'domain' => $data['domain'],
            'count' => $data['count'],
            'stored_at' => $data['stored_at'],
            'cookie_names' => array_column($data['cookies'], 'name'),
        ];
    }

    private function normalizeDomain(string $domain): string
    {
        return strtolower(preg_replace('/^(www\.|m\.)/', '', trim($domain)));
    }
}
