<?php

namespace App\Services\UnifiedCli;

use Closure;
use Dotenv\Dotenv;
use RuntimeException;

/** Host-local references only. Resolved values never enter registry/session state. */
final class CliLocalBindings
{
    public function __construct(
        private readonly array $bindings = [],
        private readonly ?Closure $environment = null,
    ) {}

    public static function fromFile(string $path, ?Closure $environment = null): self
    {
        try {
            $bindings = json_decode(self::readPrivateFile($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($bindings) || ($bindings !== [] && array_is_list($bindings))) {
                throw new RuntimeException('Expected reference metadata.');
            }
            foreach ($bindings as $reference => $binding) {
                if (! is_string($reference) || ! preg_match('/^(?:mcp|llm|host):[A-Za-z0-9][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_]*$/D', $reference)
                    || ! is_array($binding) || ! in_array($binding['source'] ?? null, ['env', 'file', 'dotenv'], true)
                    || array_diff(array_keys($binding), ['source', 'name', 'path']) !== []) {
                    throw new RuntimeException('Invalid reference metadata.');
                }
            }

            return new self($bindings, $environment);
        } catch (\Throwable) {
            throw new RuntimeException('Local binding manifest is unavailable or invalid.');
        }
    }

    public function resolveMcp(string $reference): string
    {
        if (! preg_match('/^mcp:[A-Za-z0-9][A-Za-z0-9_-]*:([A-Za-z_][A-Za-z0-9_]*)$/D', $reference, $match)) {
            throw new RuntimeException('Invalid MCP environment reference.');
        }
        $resolver = isset($this->bindings[$reference]) ? $this
            : new self([$reference => ['source' => 'env', 'name' => $match[1]]], $this->environment);

        return $resolver->resolve($reference);
    }

    public function resolve(string $reference): string
    {
        if (! preg_match('/^(?:mcp|llm|host):[A-Za-z0-9][A-Za-z0-9_-]*:[A-Za-z_][A-Za-z0-9_]*$/D', $reference)) {
            throw new RuntimeException('Invalid local binding reference.');
        }
        $binding = $this->bindings[$reference] ?? null;
        if (! is_array($binding) || array_diff(array_keys($binding), ['source', 'name', 'path']) !== []) {
            throw new RuntimeException('Local binding is unavailable: '.$reference);
        }
        try {
            $value = match ($binding['source'] ?? null) {
                'env' => $this->environmentValue($binding['name'] ?? null),
                'file' => self::readPrivateFile($binding['path'] ?? null),
                'dotenv' => $this->dotenvValue($binding),
                default => throw new RuntimeException('Unsupported binding source.'),
            };
            if (! is_string($value) || str_contains($value, "\0")) {
                throw new RuntimeException('Binding did not resolve to a usable value.');
            }

            return $value;
        } catch (\Throwable) {
            // Resolver failures may contain credentials or private file content.
            throw new RuntimeException('Local binding is unavailable: '.$reference);
        }
    }

    public function modelHeaders(array $instance): array
    {
        $config = CliSettingValue::decode($instance['config'] ?? [], 'json');
        $headers = $config['extra_headers'] ?? [];
        if (! is_array($headers)) {
            throw new RuntimeException('Invalid model header configuration.');
        }
        $seen = [];
        foreach ($headers as $header => $value) {
            if (! is_string($header) || ! preg_match('/^[!#$%&\x27*+.^_`|~0-9A-Za-z-]+$/D', $header)
                || ! is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)
                || isset($seen[strtolower($header)])
                || in_array(strtolower($header), ['authorization', 'proxy-authorization', 'cookie', 'set-cookie',
                    'content-length', 'transfer-encoding', 'connection', 'content-type', 'accept'], true)) {
                throw new RuntimeException('Model headers contain an invalid, duplicate, credential or transport-owned field.');
            }
            $seen[strtolower($header)] = true;
        }
        $name = $instance['api_key_env'] ?? null;
        if ($name === null || $name === '') {
            return $headers;
        }
        $id = $instance['instance_id'] ?? null;
        if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)
            || ! is_string($id) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $id)) {
            throw new RuntimeException('Invalid model credential reference.');
        }
        $reference = 'llm:'.$id.':'.$name;
        // Existing table-owned api_key_env works without a duplicate manifest.
        $resolver = isset($this->bindings[$reference]) ? $this
            : new self([$reference => ['source' => 'env', 'name' => $name]], $this->environment);
        $value = $resolver->resolve($reference);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) {
            throw new RuntimeException('Model credential must be a nonempty header-safe value: '.$reference);
        }

        return $headers + ['Authorization' => 'Bearer '.$value];
    }

    private function environmentValue(mixed $name): mixed
    {
        if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new RuntimeException('Invalid local environment variable name.');
        }

        return $this->environment === null ? getenv($name) : ($this->environment)($name);
    }

    private function dotenvValue(array $binding): mixed
    {
        $name = $binding['name'] ?? null;
        if (! is_string($name) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            throw new RuntimeException('Invalid local dotenv variable name.');
        }

        // Parse into an isolated array. Never load credentials into the process
        // environment or execute the file as shell/PHP code.
        return Dotenv::parse(self::readPrivateFile($binding['path'] ?? null))[$name] ?? null;
    }

    /** Mutable SQLite custody: inspect permissions and fixed sidecars, never read database bytes. */
    public static function assertPrivateSqlite(string $path): void
    {
        if (! is_file($path) || is_link($path) || str_contains($path, "\0")) {
            throw new RuntimeException('Worker SQLite journal is unavailable.');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            CliWindowsPrivateFile::verifySqlite($path);

            return;
        }
        if (! str_starts_with($path, '/') || realpath($path) !== $path || realpath(dirname($path)) !== dirname($path)) {
            throw new RuntimeException('Worker SQLite journal requires a canonical private local path.');
        }
        if (! function_exists('posix_geteuid')) {
            throw new RuntimeException('Local journal ownership inspection is unavailable.');
        }
        $ancestor = dirname(dirname($path));
        while (true) {
            clearstatcache(true, $ancestor);
            $metadata = lstat($ancestor);
            if ($metadata === false || ($metadata['mode'] & 0170000) !== 0040000
                || ! in_array($metadata['uid'], [0, posix_geteuid()], true)
                || (($metadata['mode'] & 0022) !== 0 && ($metadata['mode'] & 01000) === 0)) {
                throw new RuntimeException('Worker journal ancestor permits untrusted replacement.');
            }
            if (dirname($ancestor) === $ancestor) {
                break;
            }
            $ancestor = dirname($ancestor);
        }
        foreach ([dirname($path), $path, $path.'-wal', $path.'-shm', $path.'-journal'] as $file) {
            clearstatcache(true, $file);
            if (! file_exists($file) && ! is_link($file)) {
                continue;
            }
            $stat = lstat($file);
            $kind = $file === dirname($path) ? 0040000 : 0100000;
            if ($stat === false || ($stat['mode'] & 0170000) !== $kind || ($stat['mode'] & 0077) !== 0
                || ! function_exists('posix_geteuid') || $stat['uid'] !== posix_geteuid()) {
                throw new RuntimeException('Worker SQLite journal, private parent and sidecars must belong only to this user.');
            }
        }
    }

    public static function readPrivateFile(mixed $path): string
    {
        if (! is_string($path) || str_contains($path, "\0") || is_link($path) || ! is_file($path)
            || ! (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path))) {
            throw new RuntimeException('Local binding file is unavailable.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Local binding file cannot be read.');
        }
        try {
            $before = fstat($handle);
            if (PHP_OS_FAMILY !== 'Windows' && (($before['mode'] & 0077) !== 0
                || (function_exists('posix_geteuid') && $before['uid'] !== posix_geteuid()))) {
                throw new RuntimeException('Local binding file must be private to this user.');
            }
            $value = stream_get_contents($handle);
            $after = fstat($handle);
            if (! is_string($value) || $before['ino'] !== $after['ino'] || $before['size'] !== $after['size']
                || $before['mtime'] !== $after['mtime'] || strlen($value) !== $after['size']) {
                throw new RuntimeException('Local binding file changed while reading.');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                CliWindowsPrivateFile::verify($path, $value);
            }

            return $value;
        } finally {
            fclose($handle);
        }
    }
}
