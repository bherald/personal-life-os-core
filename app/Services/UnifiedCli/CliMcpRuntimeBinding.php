<?php

namespace App\Services\UnifiedCli;

use App\Services\McpExternalRuntimeAdmissionService;
use Closure;
use RuntimeException;

/** Bind portable definitions to this installed PLOS root and local environment. */
final class CliMcpRuntimeBinding
{
    public function __construct(private readonly string $plosRoot, private readonly ?string $nodeExecutable = null) {}

    public function resolve(string $name, array $definition, Closure $environmentValue): array
    {
        (new CliMcpServerDefinitions)->validate([['server_name' => $name,
            'enabled' => $definition['enabled'] ?? false, 'definition' => $definition]]);
        if (($definition['enabled'] ?? false) !== true) {
            throw new RuntimeException('Selected MCP server is disabled.');
        }
        $bound = $definition;
        foreach (['command', 'args'] as $field) {
            if (isset($bound[$field])) {
                $bound[$field] = $this->expand($bound[$field]);
            }
        }
        $bound['env'] = [];
        foreach ($definition['environment_bindings'] ?? [] as $binding) {
            try {
                $value = $environmentValue($binding['value_ref']);
            } catch (\Throwable) {
                throw new RuntimeException('Local MCP environment binding unavailable: '.$binding['value_ref']);
            }
            if (! is_string($value) || str_contains($value, "\0")) {
                // The error identifies the reference, never a resolved value.
                throw new RuntimeException('Local MCP environment binding unavailable: '.$binding['value_ref']);
            }
            $bound['env'][$binding['name']] = $value;
        }
        unset($bound['environment_bindings']);
        if ($bound['type'] === 'external') {
            if (($this->nodeExecutable !== null || PHP_OS_FAMILY === 'Windows')
                && ($bound['runtime_pin']['type'] ?? null) === 'npm_lock') {
                $bound = (new CliNpmEntrypoint)->bind($bound, $this->plosRoot, $this->nodeExecutable ?? '');
            }
            $admission = (new McpExternalRuntimeAdmissionService($this->plosRoot))->inspect($bound);
            if (! $admission['admitted']) {
                throw new RuntimeException('Local MCP executable admission failed: '.$admission['reason_code']);
            }
            $bound['runtime_admission'] = $admission;
        } elseif (! class_exists($bound['service'])) {
            throw new RuntimeException('Internal MCP service is not installed on this execution host.');
        }

        return $bound;
    }

    private function expand(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->expand($item), $value);
        }
        if (is_string($value) && str_starts_with($value, '${PLOS_ROOT}')) {
            $suffix = substr($value, strlen('${PLOS_ROOT}'));
            if (($suffix !== '' && ! str_starts_with($suffix, '/')) || str_contains($suffix, '\\')
                || str_contains($suffix, '${') || preg_match('~(?:^|/)\.\.(?:/|$)~', $suffix)) {
                throw new RuntimeException('Invalid portable MCP runtime path.');
            }

            return rtrim($this->plosRoot, '/\\').str_replace('/', DIRECTORY_SEPARATOR, $suffix);
        }
        if (is_string($value) && str_contains($value, '${')) {
            throw new RuntimeException('MCP runtime paths support only the explicit PLOS_ROOT binding.');
        }

        return $value;
    }
}
