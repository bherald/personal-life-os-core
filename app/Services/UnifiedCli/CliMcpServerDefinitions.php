<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

/** Portable, credential-free MCP launch definitions; no process is started here. */
final class CliMcpServerDefinitions
{
    public const TABLE = 'mcp_server_definitions';

    private const FIELDS = ['type', 'service', 'command', 'args', 'runtime_pin', 'description',
        'trust_boundary', 'transport', 'network_required', 'write_scope', 'secret_surface_risk',
        'offline_profiles_allowed', 'hybrid_profiles_allowed'];

    public function fromConfiguration(array $servers, string $projectRoot): array
    {
        $rows = [];
        ksort($servers);
        foreach ($servers as $name => $server) {
            if (! is_array($server)) {
                throw new InvalidArgumentException('MCP source configuration must contain server objects.');
            }
            $definition = array_intersect_key($server, array_flip(self::FIELDS));
            $definition['type'] ??= 'external';
            $definition['args'] ??= [];
            $definition['environment_bindings'] = [];
            foreach ($server['env'] ?? [] as $key => $value) {
                // Do not export resolved values, even if an unfamiliar variable
                // looks harmless. Local bindings are supplied independently.
                $definition['environment_bindings'][] = ['name' => $key,
                    'value_ref' => 'mcp:'.$name.':'.$key, 'source_value_present' => $value !== null && $value !== ''];
            }
            foreach (['command', 'args'] as $field) {
                if (isset($definition[$field])) {
                    $definition[$field] = $this->portable($definition[$field], $projectRoot);
                }
            }
            $rows[] = ['server_name' => $name, 'enabled' => CliSettingValue::decode($server['enabled'] ?? false, 'boolean'),
                'definition' => $definition, 'source' => 'config:mcp.servers'];
        }
        $this->validate($rows);

        return $rows;
    }

    public function validate(array $rows): void
    {
        $names = [];
        foreach ($rows as $row) {
            $name = $row['server_name'] ?? null;
            if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $name) || isset($names[$name])
                || ! in_array($row['enabled'] ?? null, [true, false, 0, 1, '0', '1'], true)) {
                throw new InvalidArgumentException('MCP definitions require unique stable server names and explicit enabled flags.');
            }
            $names[$name] = true;
            $definition = CliSettingValue::decode($row['definition'] ?? null, 'json');
            if (! is_array($definition) || ! in_array($definition['type'] ?? null, ['internal', 'external'], true)
                || ! is_string($definition['trust_boundary'] ?? null) || ! is_string($definition['network_required'] ?? null)) {
                throw new InvalidArgumentException('MCP definitions require a transport type and explicit trust/network metadata.');
            }
            if (isset($definition['env']) || isset($definition['headers'])) {
                throw new InvalidArgumentException('MCP credentials/environment values must be local bindings, not synchronized values.');
            }
            if ($definition['type'] === 'internal' && ! is_string($definition['service'] ?? null)) {
                throw new InvalidArgumentException('Internal MCP definitions require an exact service class.');
            }
            if ($definition['type'] === 'external') {
                if (! is_string($definition['command'] ?? null) || $definition['command'] === ''
                    || ! is_array($definition['args'] ?? null) || ! array_is_list($definition['args'])) {
                    throw new InvalidArgumentException('External MCP definitions require an executable and ordered argument list.');
                }
                $arguments = [$definition['command'], ...$definition['args']];
                foreach ($arguments as $argument) {
                    if (! is_string($argument) || str_contains($argument, "\0") || preg_match('~(?:--?(?:password|token|secret|api[-_]key|authorization)\b|https?://[^/\s]+@|Bearer\s+)~i', $argument)) {
                        throw new InvalidArgumentException('MCP launch arguments cannot contain credential flags or authenticated URLs.');
                    }
                }
            }
            $bindings = $definition['environment_bindings'] ?? [];
            if (! is_array($bindings) || ! array_is_list($bindings)) {
                throw new InvalidArgumentException('MCP environment bindings must be an explicit list.');
            }
            $variables = [];
            foreach ($bindings as $binding) {
                if (! is_array($binding)) {
                    throw new InvalidArgumentException('Invalid MCP environment reference.');
                }
                $variable = $binding['name'] ?? null;
                if (! is_string($variable) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $variable) || isset($variables[$variable])
                    || ($binding['value_ref'] ?? null) !== 'mcp:'.$name.':'.$variable
                    || array_diff(array_keys($binding), ['name', 'value_ref', 'source_value_present']) !== []
                    || (array_key_exists('source_value_present', $binding) && ! is_bool($binding['source_value_present']))) {
                    throw new InvalidArgumentException('Invalid or value-bearing MCP environment reference.');
                }
                $variables[$variable] = true;
            }
            // Keep the retired definition discoverable, but never executable.
            if ($name === 'prompt-compressor' && in_array($row['enabled'], [true, 1, '1'], true)) {
                throw new InvalidArgumentException('Prompt compression cannot be enabled in the unified CLI.');
            }
        }
    }

    public function forSession(CliConfiguration $configuration, array $session): array
    {
        $rows = $configuration->definitions($session, self::TABLE);
        $this->validate($rows);
        $servers = [];
        foreach ($rows as $row) {
            $servers[$row['server_name']] = array_merge(CliSettingValue::decode($row['definition'], 'json'),
                ['enabled' => in_array($row['enabled'], [true, 1, '1'], true), 'definition_source' => $row['source'] ?? 'database']);
        }

        return $servers;
    }

    private function portable(mixed $value, string $projectRoot): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->portable($item, $projectRoot), $value);
        }
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $path = is_string($value) ? str_replace('\\', '/', $value) : $value;
        if (is_string($path) && ($path === $root || str_starts_with($path, $root.'/'))) {
            return '${PLOS_ROOT}'.substr($path, strlen($root));
        }

        return $value;
    }
}
