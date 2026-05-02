<?php

namespace App\Services\Setup;

use App\Support\Setup\CheckResult;

class ServiceChecker
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<CheckResult>
     */
    public function run(string $profile, array $manifest): array
    {
        $results = [];
        $timeout = (int) ($manifest['connect_timeout_seconds'] ?? 2);

        foreach ($this->profilesFor($profile) as $tier) {
            $services = (array) ($manifest[$tier] ?? []);
            foreach ($services as $service) {
                if (! is_array($service)) {
                    continue;
                }
                foreach ($this->checkService($service, $timeout) as $result) {
                    $results[] = $result;
                }
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function profilesFor(string $profile): array
    {
        return match ($profile) {
            'core' => ['core'],
            'media' => ['core', 'media'],
            'gpu' => ['core', 'gpu'],
            'full' => ['core', 'media', 'gpu', 'full'],
            'personal' => ['core', 'media', 'gpu', 'full', 'personal'],
            default => ['core'],
        };
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<CheckResult>
     */
    private function checkService(array $service, int $timeout): array
    {
        $name = (string) ($service['name'] ?? 'unknown');
        $required = (bool) ($service['required'] ?? false);

        [$host, $port] = $this->resolveHostPort($service);

        if ($host === null || $port === null) {
            return [CheckResult::warn('services', $name, "could not resolve host/port for {$name}")];
        }

        if (! $this->isLocalhost($host)) {
            return [CheckResult::warn('services', $name, "{$name} configured for non-localhost host '{$host}' — skipping probe", [
                'host' => $host,
                'port' => $port,
            ])];
        }

        $reachable = $this->probe($host, $port, $timeout);
        $context = ['host' => $host, 'port' => $port];

        if ($reachable) {
            $results = [CheckResult::pass('services', $name, "{$name} reachable at {$host}:{$port}", $context)];
            $versionResult = $this->checkServiceVersion($service, $timeout);
            if ($versionResult !== null) {
                $results[] = $versionResult;
            }
            $modelsResult = $this->checkServiceModels($service, $timeout);
            if ($modelsResult !== null) {
                $results[] = $modelsResult;
            }

            return $results;
        }

        return [$required
            ? CheckResult::fail('services', $name, "{$name} not reachable at {$host}:{$port}", $context)
            : CheckResult::warn('services', $name, "{$name} not reachable at {$host}:{$port}", $context)];
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveHostPort(array $service): array
    {
        if (isset($service['url_env']) || isset($service['url_default'])) {
            $url = $this->envValue((string) ($service['url_env'] ?? ''))
                ?? (string) ($service['url_default'] ?? '');
            if ($url === '') {
                return [null, null];
            }
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if ($port === null && is_string($scheme)) {
                $port = $scheme === 'https' ? 443 : 80;
            }

            return [is_string($host) ? $host : null, is_int($port) ? $port : null];
        }

        $host = $this->envValue((string) ($service['env'] ?? ''))
            ?? (string) ($service['host_default'] ?? '127.0.0.1');
        $portRaw = $this->envValue((string) ($service['port_env'] ?? ''))
            ?? (string) ($service['port_default'] ?? '');

        $port = is_numeric($portRaw) ? (int) $portRaw : null;

        return [$host !== '' ? $host : null, $port];
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function checkServiceVersion(array $service, int $timeout): ?CheckResult
    {
        $path = (string) ($service['version_path'] ?? '');
        if ($path === '') {
            return null;
        }

        $name = (string) ($service['name'] ?? 'unknown');
        $baseUrl = $this->resolveBaseUrl($service);
        if ($baseUrl === null) {
            return CheckResult::warn('services', "{$name}.version", "could not resolve version URL for {$name}");
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $body = $this->fetchUrl($url, $timeout);
        if ($body === null || trim($body) === '') {
            return CheckResult::warn('services', "{$name}.version", "could not fetch {$name} version", ['url' => $url]);
        }

        $regex = (string) ($service['version_regex'] ?? '/(\d+(?:\.\d+){0,3})/');
        if (! preg_match($regex, $body, $matches)) {
            return CheckResult::warn('services', "{$name}.version", "could not parse {$name} version", ['url' => $url]);
        }

        $version = (string) ($matches[1] ?? '');
        $minVersion = (string) ($service['min_version'] ?? '');
        $context = ['url' => $url, 'version' => $version];
        if ($minVersion !== '') {
            $context['min_version'] = $minVersion;
            if (version_compare($version, $minVersion, '<')) {
                return CheckResult::warn('services', "{$name}.version", "{$name} {$version} below recommended {$minVersion}", $context);
            }
        }

        return CheckResult::pass('services', "{$name}.version", "{$name} {$version}", $context);
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function checkServiceModels(array $service, int $timeout): ?CheckResult
    {
        $path = (string) ($service['model_tags_path'] ?? '');
        if ($path === '') {
            return null;
        }

        $name = (string) ($service['name'] ?? 'unknown');
        $baseUrl = $this->resolveBaseUrl($service);
        if ($baseUrl === null) {
            return CheckResult::warn('services', "{$name}.models", "could not resolve model list URL for {$name}");
        }

        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $body = $this->fetchUrl($url, $timeout);
        if ($body === null || trim($body) === '') {
            return CheckResult::warn('services', "{$name}.models", "could not fetch {$name} model list", ['url' => $url]);
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return CheckResult::warn('services', "{$name}.models", "could not parse {$name} model list JSON", ['url' => $url]);
        }

        $installed = $this->modelNamesFromPayload($payload);
        if ($installed === []) {
            return CheckResult::warn('services', "{$name}.models", "{$name} has no installed models; run ollama pull for configured models", ['url' => $url]);
        }

        $configured = $this->configuredModels($service);
        if ($configured === []) {
            return CheckResult::warn('services', "{$name}.models", "no configured {$name} models found to verify", ['url' => $url]);
        }

        $missing = array_values(array_diff($configured, $installed));
        if ($missing !== []) {
            $pulls = implode(', ', array_map(fn ($model) => "ollama pull {$model}", $missing));

            return CheckResult::warn('services', "{$name}.models", "{$name} missing configured model(s): ".implode(', ', $missing)."; run {$pulls}", [
                'url' => $url,
                'missing' => $missing,
                'installed_count' => count($installed),
            ]);
        }

        return CheckResult::pass('services', "{$name}.models", "{$name} configured models are installed", [
            'url' => $url,
            'models' => $configured,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function modelNamesFromPayload(array $payload): array
    {
        $names = [];
        foreach ((array) ($payload['models'] ?? []) as $model) {
            if (is_string($model) && $model !== '') {
                $names[] = $model;

                continue;
            }
            if (! is_array($model)) {
                continue;
            }
            $name = $model['name'] ?? $model['model'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    private function configuredModels(array $service): array
    {
        $models = [];

        foreach ((array) ($service['model_names'] ?? []) as $model) {
            if (is_string($model) && trim($model) !== '') {
                $models[] = trim($model);
            }
        }

        foreach ((array) ($service['model_envs'] ?? []) as $entry) {
            if (is_string($entry)) {
                $value = $this->envValue($entry);
            } elseif (is_array($entry)) {
                $env = (string) ($entry['env'] ?? '');
                $value = $this->envValue($env) ?? (string) ($entry['default'] ?? '');
            } else {
                $value = null;
            }

            if (is_string($value) && trim($value) !== '') {
                $models[] = trim($value);
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function resolveBaseUrl(array $service): ?string
    {
        $url = $this->envValue((string) ($service['url_env'] ?? ''))
            ?? (string) ($service['url_default'] ?? '');

        return $url === '' ? null : $url;
    }

    private function envValue(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        $value = env($key);
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    protected function isLocalhost(string $host): bool
    {
        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    /**
     * Open a TCP connection to host:port within timeout. Read-only — no
     * data is sent, the socket is closed immediately.
     */
    protected function probe(string $host, int $port, int $timeout): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    protected function fetchUrl(string $url, int $timeout): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);

        return is_string($body) ? $body : null;
    }
}
