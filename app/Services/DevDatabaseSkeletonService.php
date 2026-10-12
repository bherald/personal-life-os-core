<?php

namespace App\Services;

use App\Services\UnifiedCli\RegistrySnapshotValidator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RuntimeException;

/** Production schema/history plus explicitly projected framework rows, never a data clone. */
final class DevDatabaseSkeletonService
{
    public function plan(array $snapshot, array $manifest): array
    {
        (new RegistrySnapshotValidator)->validate($snapshot);
        $skeleton = $snapshot['mysql_skeleton'] ?? [];
        if (($skeleton['schema'] ?? null) !== 'plos.dev.mysql-skeleton.v1'
            || ($skeleton['operational_rows_exported'] ?? null) !== 0
            || ! is_array($skeleton['tables'] ?? null)
            || ! isset($skeleton['tables']['migrations'])
            || ! is_string($skeleton['source_server_uuid'] ?? null)
            || $skeleton['source_server_uuid'] === ''
            || ! is_array($skeleton['migrations'] ?? null)) {
            throw new InvalidArgumentException('A complete schema and migration-history export is required.');
        }
        foreach ($skeleton['tables'] as $table => $ddl) {
            $this->identifier($table);
            if (! is_string($ddl) || ! str_starts_with($ddl, 'CREATE TABLE `'.$table.'` (')
                || preg_match('/\b(?:DATA DIRECTORY|INDEX DIRECTORY|TABLESPACE)\b/i', $ddl)) {
                throw new InvalidArgumentException('Unsupported table definition: '.$table);
            }
        }
        foreach ($skeleton['migrations'] as $row) {
            if (! is_array($row) || array_diff(array_keys($row), ['id', 'migration', 'batch']) !== []
                || ! is_int($row['id'] ?? null) || ! is_int($row['batch'] ?? null)
                || ! is_string($row['migration'] ?? null) || $row['migration'] === '') {
                throw new InvalidArgumentException('Invalid production migration history.');
            }
        }
        $rows = [];
        $derived = [];
        foreach ($snapshot['tables'] as $table => $records) {
            $projection = $manifest['tables'][$table] ?? null;
            if (! is_array($projection) || ! is_array($projection['columns'] ?? null)) {
                throw new InvalidArgumentException('Unapproved data table: '.$table);
            }
            foreach ($records as $record) {
                if (array_diff(array_keys($record), $projection['columns']) !== []) {
                    throw new InvalidArgumentException('Unapproved data columns: '.$table);
                }
                foreach ($projection['where_in'] ?? [] as $column => $allowed) {
                    if (! in_array($record[$column] ?? null, $allowed, true)) {
                        throw new InvalidArgumentException('Data exceeds the selected configuration filter.');
                    }
                }
            }
            if (($projection['dev_import'] ?? true) === false) {
                $derived[] = $table;

                continue;
            }
            // A config-derived MCP definition may precede its normal production migration.
            // Keep it in the CLI snapshot; do not invent a dev-only table or migration row.
            if (! isset($skeleton['tables'][$table])) {
                if (($snapshot['definition_sources'][$table] ?? '') !== 'database'
                    && ($projection['optional'] ?? false) === true) {
                    $derived[] = $table;

                    continue;
                }
                throw new InvalidArgumentException('Selected table is absent from production schema: '.$table);
            }
            foreach (explode("\n", $skeleton['tables'][$table]) as $definition) {
                if (preg_match('/^  `([^`]+)` (.+)$/D', $definition, $column)
                    && str_contains($column[2], 'NOT NULL') && ! str_contains($column[2], 'DEFAULT ')
                    && ! str_contains($column[2], 'AUTO_INCREMENT') && ! str_contains($column[2], 'GENERATED ALWAYS')) {
                    foreach ($records as $record) {
                        if (! array_key_exists($column[1], $record)) {
                            throw new InvalidArgumentException('Required projected column missing: '.$table.'.'.$column[1]);
                        }
                    }
                }
            }
            $rows[$table] = $records;
        }
        $rows['migrations'] = $skeleton['migrations'];

        return ['schema' => $skeleton['tables'], 'rows' => $rows, 'projections' => $manifest['tables'],
            'source_database' => $skeleton['source_database'] ?? '',
            'source_server_uuid' => $skeleton['source_server_uuid'],
            'summary' => ['schema_tables' => count($skeleton['tables']),
                'migration_records' => count($skeleton['migrations']),
                'selected_rows' => array_map('count', $rows), 'snapshot_only_definitions' => $derived,
                'operational_rows_imported' => 0]];
    }

    public function apply(ConnectionInterface $db, array $plan, bool $replace = false): array
    {
        $this->assertLocalTarget($db, $plan);
        $objects = $db->select('SELECT TABLE_NAME AS name, TABLE_TYPE AS type FROM information_schema.tables WHERE table_schema = ?', [$db->getDatabaseName()]);
        if ($objects !== [] && ! $replace) {
            throw new RuntimeException('Development database is not empty; explicit replacement is required.');
        }
        foreach ($objects as $object) {
            $this->identifier($object->name);
            if ($object->type !== 'BASE TABLE') {
                throw new RuntimeException('Existing development views require explicit handling.');
            }
        }
        $existingNames = array_map(fn ($object) => $object->name, $objects);
        $expectedNames = array_keys($plan['schema']);
        sort($existingNames);
        sort($expectedNames);
        $schemaMatches = $existingNames === $expectedNames;
        if ($schemaMatches) {
            foreach ($plan['schema'] as $table => $ddl) {
                $current = (array) $db->selectOne('SHOW CREATE TABLE `'.$table.'`');
                if ($this->normalizedDdl($current['Create Table']) !== $this->normalizedDdl($ddl)) {
                    $schemaMatches = false;
                    break;
                }
            }
        }
        // Caller creates a dedicated PDO connection with multi-statements disabled.
        $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if (! $schemaMatches) {
                foreach ($objects as $object) {
                    $db->statement('DROP TABLE `'.$object->name.'`');
                }
                foreach ($plan['schema'] as $ddl) {
                    $db->unprepared($ddl);
                }
            }
            $db->transaction(function () use ($db, $plan, $schemaMatches) {
                if ($schemaMatches) {
                    foreach (array_keys($plan['schema']) as $table) {
                        $db->table($table)->delete();
                    }
                }
                foreach ($plan['rows'] as $table => $rows) {
                    foreach (array_chunk($rows, 100) as $chunk) {
                        $db->table($table)->insert($chunk);
                    }
                }
            });
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $this->verify($db, $plan) + ['schema_rebuilt' => ! $schemaMatches];
    }

    /** Routine sync never runs DDL, replaces migration history or clears domain data. */
    public function refresh(ConnectionInterface $db, array $plan): array
    {
        $this->assertLocalTarget($db, $plan);
        if ((int) ($db->selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled ?? 0) !== 1) {
            throw new RuntimeException('Configuration refresh requires foreign-key enforcement.');
        }
        $selected = array_diff_key($plan['rows'], ['migrations' => true]);
        $keys = [];
        $references = [];
        foreach ($selected as $table => $rows) {
            $this->identifier($table);
            $current = (array) $db->selectOne('SHOW CREATE TABLE `'.$table.'`');
            if (! isset($current['Create Table'])
                || $this->normalizedDdl($current['Create Table']) !== $this->normalizedDdl($plan['schema'][$table])) {
                throw new RuntimeException('Configuration schema differs: '.$table.'. Apply normal migrations; routine sync will not reset the database.');
            }
            $metadata = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$db->getDatabaseName(), $table]);
            if (strtolower($metadata->engine ?? '') !== 'innodb'
                || $db->select('SELECT TRIGGER_NAME FROM information_schema.triggers WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$db->getDatabaseName(), $table]) !== []) {
                throw new RuntimeException('Configuration refresh requires transactional tables without write triggers: '.$table);
            }
            $keys[$table] = array_map(fn ($row) => $row->column_name, $db->select(
                "SELECT COLUMN_NAME AS column_name FROM information_schema.key_column_usage WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                [$db->getDatabaseName(), $table]));
            if ($keys[$table] === [] || array_diff($keys[$table], $plan['projections'][$table]['columns']) !== []) {
                throw new RuntimeException('Configuration refresh requires projected primary keys: '.$table);
            }
            foreach ($keys[$table] as $key) {
                $this->identifier($key);
            }
            $seen = [];
            foreach ($rows as $row) {
                $identity = $this->rowIdentity($row, $keys[$table]);
                if (isset($seen[$identity])) {
                    throw new RuntimeException('Duplicate configuration identity: '.$table);
                }
                $seen[$identity] = true;
            }
            $references[$table] = collect($db->select(
                'SELECT TABLE_SCHEMA AS child_schema, TABLE_NAME AS child_table, CONSTRAINT_NAME AS constraint_name, COLUMN_NAME AS child_column, REFERENCED_COLUMN_NAME AS parent_column FROM information_schema.key_column_usage WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ? ORDER BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
                [$db->getDatabaseName(), $table]))->groupBy(fn ($row) => $row->child_schema.'.'.$row->child_table.'.'.$row->constraint_name)->all();
        }

        $db->transaction(function () use ($db, $plan, $selected, $keys, $references): void {
            $previous = [];
            foreach ($selected as $table => $rows) {
                $previous[$table] = $this->selectedQuery($db, $plan, $table)->lockForUpdate()->get()->map(fn ($row) => (array) $row)->all();
                foreach ($rows as $row) {
                    $identity = array_intersect_key($row, array_flip($keys[$table]));
                    $existing = $db->table($table)->where($identity)->lockForUpdate()->first();
                    if ($existing !== null) {
                        foreach ($plan['projections'][$table]['where_in'] ?? [] as $column => $values) {
                            if (! in_array(((array) $existing)[$column], $values, true)) {
                                throw new RuntimeException('Configuration identity conflicts with a row outside the sync scope: '.$table);
                            }
                        }
                        $this->protectReferences($db, $table, $references[$table], (array) $existing, $row);
                        $updates = array_diff_key($row, $identity);
                        if ($updates !== []) {
                            $db->table($table)->where($identity)->update($updates);
                        }
                    } else {
                        $db->table($table)->insert($row);
                    }
                }
            }
            // Child rows generally follow parents in the export manifest.
            foreach (array_reverse(array_keys($selected)) as $table) {
                $wanted = array_fill_keys(array_map(fn ($row) => $this->rowIdentity($row, $keys[$table]), $selected[$table]), true);
                foreach ($previous[$table] as $row) {
                    if (! isset($wanted[$this->rowIdentity($row, $keys[$table])])) {
                        $this->protectReferences($db, $table, $references[$table], $row);
                        $db->table($table)->where(array_intersect_key($row, array_flip($keys[$table])))->delete();
                    }
                }
                $actual = $this->selectedQuery($db, $plan, $table)->get($plan['projections'][$table]['columns'])->map(fn ($row) => (array) $row)->all();
                if ($this->fingerprints($actual) !== $this->fingerprints($selected[$table])) {
                    throw new RuntimeException('Configuration refresh verification failed: '.$table);
                }
            }
        });

        return ['verified' => true, 'database' => $db->getDatabaseName(), 'mode' => 'configuration_refresh',
            'selected_rows' => array_map('count', $selected), 'schema_rebuilt' => false,
            'migration_history_preserved' => true, 'unselected_data_preserved' => true,
            'operational_rows_imported' => 0, 'snapshot_only_definitions' => $plan['summary']['snapshot_only_definitions']];
    }

    private function selectedQuery(ConnectionInterface $db, array $plan, string $table): Builder
    {
        $query = $db->table($table);
        foreach ($plan['projections'][$table]['where_in'] ?? [] as $column => $values) {
            $query->whereIn($column, $values);
        }

        return $query;
    }

    private function rowIdentity(array $row, array $keys): string
    {
        $identity = [];
        foreach ($keys as $key) {
            if (! isset($row[$key])) {
                throw new RuntimeException('Configuration row is missing its primary key.');
            }
            $identity[] = (string) $row[$key];
        }

        return json_encode($identity, JSON_THROW_ON_ERROR);
    }

    private function protectReferences(ConnectionInterface $db, string $table, array $references, array $old, ?array $new = null): void
    {
        foreach ($references as $columns) {
            if ($columns->contains(fn ($column) => $old[$column->parent_column] === null)) {
                continue;
            }
            $changed = $new === null;
            foreach ($columns as $column) {
                $name = $column->parent_column;
                $changed = $changed || (array_key_exists($name, $new ?? []) && $new[$name] !== $old[$name]);
            }
            if (! $changed) {
                continue;
            }
            $first = $columns->first();
            $query = $db->table($first->child_schema.'.'.$first->child_table);
            foreach ($columns as $column) {
                $query->where($column->child_column, $old[$column->parent_column]);
            }
            if ($query->lockForUpdate()->first() !== null) {
                throw new RuntimeException('Configuration change would affect referenced local data: '.$table.'. Resolve the references explicitly; no changes committed.');
            }
        }
    }

    private function fingerprints(array $rows): array
    {
        $values = array_map(static function (array $row): string {
            ksort($row);

            return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
        }, $rows);
        sort($values);

        return $values;
    }

    private function assertLocalTarget(ConnectionInterface $db, array $plan): void
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Skeleton import requires the local development MySQL connection.');
        }
        if (($db->selectOne('SELECT @@server_uuid AS id')->id ?? null) === $plan['source_server_uuid']) {
            throw new RuntimeException('The source production server cannot be a skeleton import target.');
        }
    }

    public function verify(ConnectionInterface $db, array $plan): array
    {
        $objects = $db->select('SELECT TABLE_NAME AS name FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME', [$db->getDatabaseName()]);
        $names = array_map(fn ($row) => $row->name, $objects);
        $expected = array_keys($plan['schema']);
        sort($expected);
        if ($names !== $expected) {
            throw new RuntimeException('Development schema table set differs from production.');
        }
        foreach ($plan['schema'] as $table => $ddl) {
            $actual = (array) $db->selectOne('SHOW CREATE TABLE `'.$table.'`');
            if ($this->normalizedDdl($actual['Create Table']) !== $this->normalizedDdl($ddl)) {
                throw new RuntimeException('Development table definition differs: '.$table);
            }
            if ((int) $db->table($table)->count() !== count($plan['rows'][$table] ?? [])) {
                throw new RuntimeException('Unexpected development rows: '.$table);
            }
            $expectedRows = $plan['rows'][$table] ?? [];
            if ($expectedRows !== []) {
                $actualRows = $db->table($table)->get(array_keys($expectedRows[0]))
                    ->map(fn ($row) => (array) $row)->all();
                if ($this->fingerprints($actualRows) !== $this->fingerprints($expectedRows)) {
                    throw new RuntimeException('Selected configuration values differ: '.$table);
                }
            }
        }
        $migrations = $db->table('migrations')->orderBy('id')->get(['id', 'migration', 'batch'])
            ->map(fn ($row) => (array) $row)->all();
        if ($migrations !== $plan['rows']['migrations']) {
            throw new RuntimeException('Development migration history differs from production.');
        }

        return $plan['summary'] + ['verified' => true, 'database' => $db->getDatabaseName()];
    }

    private function normalizedDdl(string $ddl): string
    {
        // MySQL may spell out a charset already implied by the same collation
        // after importing SHOW CREATE TABLE across patch releases.
        $ddl = preg_replace('/ CHARACTER SET ([a-z0-9]+)(?= COLLATE \1_)/', '', $ddl);

        return preg_replace('/\bAUTO_INCREMENT=\d+\s*/', '', $ddl);
    }

    private function identifier(mixed $name): void
    {
        if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new InvalidArgumentException('Invalid schema table identifier.');
        }
    }
}
