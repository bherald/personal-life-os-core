<?php

namespace App\Services\UnifiedCli;

use App\Services\AgentToolRegistryService;
use App\Services\MCPToolResultValidatorService;
use RuntimeException;

/** Adapt all synced tool definitions to the existing registry and native API schemas. */
final class CliToolCatalog
{
    public function __construct(private readonly CliConfiguration $configuration) {}

    public function definitions(array $session): array
    {
        return $this->fromRows($this->configuration->definitions($session, 'agent_tool_registry'));
    }

    public function fromRows(array $rows): array
    {
        $tools = [];
        foreach ($rows as $row) {
            if (! $this->truth($row['enabled'] ?? false) || in_array($row['availability_status'] ?? '', ['disabled', 'unavailable'], true)) {
                continue;
            }
            $tools[$row['name']] = ['service' => $row['service_class'], 'method' => $row['method'],
                'description' => $row['description'], 'parameters' => CliSettingValue::decode($row['parameters'] ?? [], 'json'),
                'permissions' => CliSettingValue::decode($row['permissions'] ?? [], 'json'),
                'risk_level' => $row['risk_level'], 'category' => $row['category'],
                'requires_confirmation' => $this->truth($row['requires_confirmation'] ?? false),
                'returns' => $row['returns_description'] ?? null, 'max_calls_per_run' => $row['max_calls_per_run'] ?? null,
                'mcp_server' => $row['mcp_server'], 'mcp_tool' => $row['mcp_tool'],
                'max_tokens_per_call' => $row['max_tokens_per_call'] ?? null, 'max_result_bytes' => $row['max_result_bytes'] ?? null,
                'availability_status' => $row['availability_status'], 'schema_generation' => $row['schema_generation'] ?? null,
                'privacy_class' => $row['privacy_class'] ?? 'unspecified',
                'allows_private_data' => $this->truth($row['allows_private_data'] ?? false)];
            $tools[$row['name']] = (new CliLegacyToolMapping)->adapt($row['name'], $tools[$row['name']]);
        }

        return $tools;
    }

    public function forSkill(array $session, AgentToolRegistryService $registry, array $skill): array
    {
        $contract = $registry->resolveToolContractForAgent($skill['name'], $skill['configuration']);
        if ($contract['unresolved_declared_tools'] !== []) {
            throw new RuntimeException('Selected skill has unavailable or unauthorized declared tools: '.implode(', ', $contract['unresolved_declared_tools']));
        }
        $native = [];
        foreach ($contract['tools'] as $name => $definition) {
            if (! CliOperatorModes::allows($this->configuration->effective($session)['values']['cli.operator_mode'], $definition)) {
                continue;
            }
            $native[] = ['type' => 'function', 'function' => ['name' => $name,
                'description' => $definition['description'], 'parameters' => $this->schema($definition['parameters'])]];
        }

        return $native;
    }

    public function schema(array $parameters): array
    {
        if (isset($parameters['optional']) || (isset($parameters['required']) && ! isset($parameters['type']) && array_is_list($parameters['required']))) {
            throw new RuntimeException('This legacy names-only tool definition needs a typed parameter mapping before native execution.');
        }
        if (($parameters['type'] ?? null) === 'object') {
            $schema = $parameters;
        } else {
            $properties = [];
            $required = [];
            foreach ($parameters as $key => $definition) {
                if (! is_array($definition)) {
                    throw new RuntimeException('Malformed tool parameter definition.');
                }
                $name = is_int($key) ? ($definition['name'] ?? null) : $key;
                if (! is_string($name) || $name === '' || isset($properties[$name])) {
                    throw new RuntimeException('Tool parameter names must be unique.');
                }
                if (($definition['x_context_only'] ?? false) === true) {
                    continue;
                }
                if ($this->truth($definition['required'] ?? false)) {
                    $required[] = $name;
                }
                unset($definition['required'], $definition['name']);
                $properties[$name] = $definition;
            }
            $schema = ['type' => 'object', 'additionalProperties' => false];
            if ($properties !== []) {
                $schema['properties'] = $properties;
            }
            if ($required !== []) {
                $schema['required'] = $required;
            }
        }
        foreach ($schema['properties'] ?? [] as $name => $definition) {
            if (($definition['x_context_only'] ?? false) === true) {
                unset($schema['properties'][$name]);
                $schema['required'] = array_values(array_diff($schema['required'] ?? [], [$name]));
            }
        }
        if (($schema['properties'] ?? null) === []) {
            unset($schema['properties']);
        }
        if (($schema['required'] ?? null) === []) {
            unset($schema['required']);
        }
        // Undeclared arguments cannot carry runtime authority into the dispatcher.
        $schema['additionalProperties'] = false;
        $schema = $this->normalizeTypes($schema);
        if (! (new MCPToolResultValidatorService)->validateSchema($schema)['valid']) {
            throw new RuntimeException('The registered tool schema is not valid for native calls.');
        }

        return $schema;
    }

    private function normalizeTypes(array $schema): array
    {
        if (is_string($schema['type'] ?? null)) {
            $schema['type'] = match ($schema['type']) {
                'int' => 'integer', 'float', 'double' => 'number', 'bool' => 'boolean', default => $schema['type'],
            };
        }
        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (is_array($property)) {
                $schema['properties'][$key] = $this->normalizeTypes($property);
            }
        }
        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = $this->normalizeTypes($schema['items']);
        }

        return $schema;
    }

    private function truth(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }
}
