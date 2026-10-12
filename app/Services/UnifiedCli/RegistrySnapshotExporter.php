<?php

namespace App\Services\UnifiedCli;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

/** Explicit read-only projections. Never dump tables, credentials or live jobs. */
final class RegistrySnapshotExporter
{
    public function export(ConnectionInterface $source, array $manifest): array
    {
        if (($manifest['schema'] ?? null) !== 'plos.cli.export-manifest.v1' || ! is_array($manifest['tables'] ?? null)) {
            throw new InvalidArgumentException('A versioned table projection manifest is required.');
        }
        foreach (RegistrySnapshotValidator::REQUIRED_TABLES as $table) {
            if (! isset($manifest['tables'][$table])) {
                throw new InvalidArgumentException('Missing required table projection: '.$table);
            }
        }
        foreach ($manifest['tables'] as $table => $projection) {
            $this->identifier($table);
            RegistrySnapshotValidator::assertDefinitionTable($table);
            if (! is_array($projection) || ! is_array($projection['columns'] ?? null) || $projection['columns'] === []) {
                throw new InvalidArgumentException('Explicit projected columns are required for '.$table);
            }
            foreach ($projection['columns'] as $column) {
                $this->identifier($column);
                if (in_array($column, ['api_key', 'password', 'secret', 'access_token', 'refresh_token', 'authorization', 'cookie', 'private_key'], true)) {
                    throw new InvalidArgumentException('Raw credential columns cannot be exported.');
                }
            }
            foreach ($projection['where_in'] ?? [] as $column => $values) {
                $this->identifier($column);
                if (! is_array($values) || ! array_is_list($values)) {
                    throw new InvalidArgumentException('Projection filters require explicit value lists.');
                }
            }
        }

        if ($source->transactionLevel() !== 0) {
            throw new RuntimeException('Registry export requires its own consistent read transaction.');
        }
        if (in_array($source->getDriverName(), ['mysql', 'mariadb'], true)) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $source->statement('SET TRANSACTION READ ONLY');
        }

        return $source->transaction(function () use ($source, $manifest): array {
            $tables = [];
            $columnTypes = [];
            $definitionSources = [];
            foreach ($manifest['tables'] as $table => $projection) {
                if (($projection['optional'] ?? false) === true && ! $source->getSchemaBuilder()->hasTable($table)) {
                    $tables[$table] = [];
                    $definitionSources[$table] = 'missing';

                    continue;
                }
                $definitionSources[$table] = 'database';
                // Export only projected type/nullability, never defaults,
                // comments, credentials or other unprojected schema content.
                $columns = array_column($source->getSchemaBuilder()->getColumns($table), null, 'name');
                foreach ($projection['columns'] as $column) {
                    if (! isset($columns[$column])) {
                        throw new RuntimeException('Projected column is absent from current source schema: '.$table.'.'.$column);
                    }
                    $columnTypes[$table][$column] = ['type' => strtolower($columns[$column]['type_name']),
                        'nullable' => $columns[$column]['nullable']];
                }
                $query = $source->table($table)->select($projection['columns']);
                foreach ($projection['where_in'] ?? [] as $column => $values) {
                    $query->whereIn($column, $values);
                }
                // Deterministic serialization gives unchanged generations stable hashes.
                foreach ($projection['order_by'] ?? $projection['columns'] as $column) {
                    $this->identifier($column);
                    if (! in_array($column, $projection['columns'], true)) {
                        throw new InvalidArgumentException('Sort keys must be projected columns.');
                    }
                    $query->orderBy($column);
                }
                $tables[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
                if (isset($projection['expected_count']) && count($tables[$table]) !== $projection['expected_count']) {
                    throw new RuntimeException('Registry export count changed for '.$table.'; review the projection.');
                }
            }
            $snapshot = [
                'schema' => RegistrySnapshotValidator::SCHEMA,
                'source' => $manifest['source'] ?? '',
                'exported_at' => gmdate('c'),
                'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
                'related_tables' => $manifest['related_tables'] ?? [],
                'relationships' => $manifest['relationships'] ?? [],
                'tables' => $tables,
                'column_types' => $columnTypes,
                'definition_sources' => $definitionSources,
            ];
            (new RegistrySnapshotValidator)->validate($snapshot);

            return $snapshot;
        });
    }

    private function identifier(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-z][a-z0-9_]*$/D', $value)) {
            throw new InvalidArgumentException('Invalid projection identifier.');
        }
    }
}
