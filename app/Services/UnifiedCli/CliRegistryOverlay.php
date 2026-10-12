<?php

namespace App\Services\UnifiedCli;

use InvalidArgumentException;

/** Typed local field overrides; the synchronized generation is never modified. */
final class CliRegistryOverlay
{
    public function apply(array $snapshot, array $overrides): array
    {
        (new RegistrySnapshotValidator)->validate($snapshot);
        foreach ($overrides as $key => $value) {
            if (! str_starts_with($key, 'registry.')) {
                continue;
            }
            $parts = explode('.', $key, 4);
            if (count($parts) !== 4) {
                throw new InvalidArgumentException('Use registry.TABLE.SELECTOR.COLUMN[.JSON_PATH].');
            }
            [, $table, $selector, $field] = $parts;
            if (! isset($snapshot['tables'][$table])) {
                throw new InvalidArgumentException('Override table is absent from the pinned snapshot.');
            }
            $identity = match ($table) {
                'llm_instances', 'compute_instances' => 'instance_id',
                'llm_model_profiles' => 'profile_name',
                'agent_tool_registry', 'guardrail_rules' => 'name',
                'agent_handoff_agents' => 'agent_id',
                'mcp_server_definitions' => 'server_name',
                default => 'id',
            };
            $selectorColumn = (str_starts_with($selector, '#') && ctype_digit(substr($selector, 1)))
                || ($table === 'compute_instances' && ctype_digit($selector)) ? 'id' : $identity;
            $selectorValue = $selectorColumn === 'id' && str_starts_with($selector, '#') ? substr($selector, 1) : $selector;
            $matches = array_keys(array_filter($snapshot['tables'][$table],
                fn ($row) => (string) ($row[$selectorColumn] ?? '') === $selectorValue));
            if (count($matches) !== 1) {
                throw new InvalidArgumentException('Override requires one exact registry row identity.');
            }
            $row = &$snapshot['tables'][$table][$matches[0]];
            $path = explode('.', $field);
            $column = array_shift($path);
            if (in_array($column, ['id', $identity], true) || ! array_key_exists($column, $row)) {
                throw new InvalidArgumentException('Override cannot change a row identity or invent a column.');
            }
            $original = $row[$column];
            $json = $path !== [] && is_string($original);
            $updated = $json ? json_decode($original, true, flags: JSON_THROW_ON_ERROR) : $original;
            $cell = &$updated;
            foreach ($path as $segment) {
                if (! is_array($cell) || ! array_key_exists($segment, $cell)) {
                    throw new InvalidArgumentException('Override JSON path is absent from the synced definition.');
                }
                $cell = &$cell[$segment];
            }
            $declared = $path === [] ? ($snapshot['column_types'][$table][$column] ?? null) : null;
            if ($declared !== null) {
                $this->validateDeclaredValue($declared, $value);
            } elseif ($cell === null && $value !== null) {
                throw new InvalidArgumentException('An unset source field needs a declared type before it can be overridden.');
            }
            if ($declared === null && get_debug_type($cell) !== get_debug_type($value) && ! (is_float($cell) && is_int($value))) {
                throw new InvalidArgumentException('Override must preserve the synced field type: '.get_debug_type($cell).'.');
            }
            $this->rejectTransformations($field, $value);
            if ($path === [] && is_string($value) && in_array($column, ['config', 'metadata', 'permissions', 'parameters', 'definition'], true)) {
                $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                $this->rejectTransformations($field, $decoded);
            }
            $cell = $value;
            unset($cell);
            $row[$column] = $json ? json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $updated;
            unset($row);
        }
        // Relations, skill hashes and secret exclusions still apply after overlays.
        (new RegistrySnapshotValidator)->validate($snapshot);

        return $snapshot;
    }

    private function validateDeclaredValue(array $definition, mixed $value): void
    {
        if ($value === null && $definition['nullable']) {
            return;
        }
        $valid = match ($definition['type']) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'int2', 'int4', 'int8' => is_int($value),
            'real', 'float', 'double', 'double precision', 'decimal', 'numeric' => is_int($value) || (is_float($value) && is_finite($value))
                || (is_string($value) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)),
            'bool', 'boolean' => is_bool($value),
            'json', 'jsonb' => is_string($value) && $this->isJson($value),
            'char', 'varchar', 'character varying', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set',
            'date', 'datetime', 'timestamp', 'time', 'year', 'uuid' => is_string($value),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Override must match declared source type '.$definition['type'].' and nullability.');
        }
    }

    private function isJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function rejectTransformations(string $path, mixed $value): void
    {
        if (preg_match('/compact|compress|optim|summari|rewrit|truncat/i', $path) && ! in_array($value, [false, null, 0, 'disabled'], true)) {
            throw new InvalidArgumentException('Registry overrides cannot enable prompt transformations.');
        }
        if (is_array($value)) {
            foreach ($value as $name => $nested) {
                $this->rejectTransformations($path.'.'.$name, $nested);
            }
        }
    }
}
