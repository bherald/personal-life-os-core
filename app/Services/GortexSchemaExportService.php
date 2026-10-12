<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Metadata only: no application rows, defaults, comments or credentials. */
final class GortexSchemaExportService
{
    public function export(array $connections, string $directory): array
    {
        $files = [];
        $counts = [];
        foreach ($connections as $name) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                throw new RuntimeException('Invalid configured connection name.');
            }
            $connection = DB::connection($name);
            $driver = $connection->getDriverName();
            if (! in_array($driver, ['mysql', 'pgsql'], true)) {
                throw new RuntimeException('Unsupported schema export driver.');
            }
            $schema = $connection->getSchemaBuilder();
            $scope = $driver === 'mysql' ? $connection->getDatabaseName() : 'public';
            $tables = [];
            foreach ($schema->getTables($scope) as $table) {
                $qualified = $table['schema_qualified_name'];
                $tables[] = ['name' => $table['name'], 'schema' => $table['schema'],
                    'columns' => array_map(static fn (array $column): array => array_intersect_key($column,
                        array_flip(['name', 'type', 'nullable'])), $schema->getColumns($qualified)),
                    'indexes' => $schema->getIndexes($qualified), 'foreign_keys' => $schema->getForeignKeys($qualified)];
            }
            $files[$name.'-schema.sql'] = $this->ddl($tables, $driver);
            $counts[$name] = count($tables);
        }
        // Gather every connection first. A failed read never replaces a good snapshot.
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create schema snapshot directory.');
        }
        chmod($directory, 0700);
        $manifest = ['generated_at' => gmdate('c'), 'metadata_only' => true, 'tables' => $counts, 'files' => []];
        foreach ($files as $name => $content) {
            $target = $directory.'/'.$name;
            if (is_link($target)) {
                throw new RuntimeException('Refusing a schema snapshot symlink.');
            }
            $temporary = tempnam($directory, '.schema-');
            try {
                chmod($temporary, 0600);
                if (file_put_contents($temporary, $content) !== strlen($content) || ! rename($temporary, $target)) {
                    throw new RuntimeException('Cannot publish schema snapshot.');
                }
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            $manifest['files'][$name] = hash('sha256', $content);
        }
        $receipt = $directory.'/manifest.json';
        file_put_contents($receipt, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", LOCK_EX);
        chmod($receipt, 0600);

        return $manifest;
    }

    public function ddl(array $tables, string $driver): string
    {
        $quote = static fn (string $name): string => preg_match('/^[a-z_][a-z0-9_]*$/D', $name) ? $name : '"'.str_replace('"', '""', $name).'"';
        $list = static fn (array $names): string => implode(', ', array_map($quote, $names));
        $result = '-- PLOS metadata snapshot: '.$driver.'; '.gmdate('c')."\n"
            ."-- Navigation only. Omits rows, defaults, comments, triggers and routines; not a restore script.\n\n";
        foreach ($tables as $table) {
            $qualified = $quote($table['schema']).'.'.$quote($table['name']);
            $lines = [];
            foreach ($table['columns'] as $column) {
                $lines[] = $quote($column['name']).' '.$column['type'].($column['nullable'] ? '' : ' NOT NULL');
            }
            foreach ($table['indexes'] as $index) {
                if ($index['primary']) {
                    $lines[] = 'PRIMARY KEY ('.$list($index['columns']).')';
                } elseif ($index['unique']) {
                    $lines[] = 'UNIQUE ('.$list($index['columns']).')';
                }
            }
            foreach ($table['foreign_keys'] as $foreign) {
                $lines[] = 'FOREIGN KEY ('.$list($foreign['columns']).') REFERENCES '
                    .$quote($foreign['foreign_schema'] ?? $table['schema']).'.'.$quote($foreign['foreign_table'])
                    .' ('.$list($foreign['foreign_columns']).')';
            }
            $result .= 'CREATE TABLE '.$qualified." (\n    ".implode(",\n    ", $lines)."\n);\n\n";
        }

        return $result;
    }
}
