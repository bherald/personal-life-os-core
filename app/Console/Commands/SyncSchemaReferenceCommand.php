<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * ops:sync-schema-reference — Auto-generate docs/schema-reference.md from live DB.
 *
 * Eliminates the #1 bug source (40% of all bugs were schema mismatches from
 * a manually-maintained schema reference). Now the file is always generated
 * from the actual database.
 *
 * Works on both dev and prod. Run on prod for authoritative output.
 */
class SyncSchemaReferenceCommand extends Command
{
    protected $signature = 'ops:sync-schema-reference
                            {--dry-run : Show what would be written without writing}
                            {--diff : Show diff against current file}';

    protected $description = 'Auto-generate docs/schema-reference.md from live database schema';

    public function handle(): int
    {
        $startTime = microtime(true);

        $this->info('Reading MySQL schema...');
        $mysqlTables = $this->getMysqlSchema();

        $this->info('Reading PostgreSQL schema...');
        $pgsqlTables = $this->getPgsqlSchema();

        $content = $this->formatSchemaReference($mysqlTables, $pgsqlTables);

        $path = base_path('docs/schema-reference.md');
        $mysqlCount = count($mysqlTables);
        $pgsqlCount = count($pgsqlTables);
        $duration = round(microtime(true) - $startTime, 1);

        if ($this->option('diff')) {
            $this->showDiff($path, $content);

            return Command::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line($content);
            $this->newLine();
            $this->info("Dry run: {$mysqlCount} MySQL + {$pgsqlCount} PostgreSQL tables ({$duration}s)");

            return Command::SUCCESS;
        }

        file_put_contents($path, $content);

        $this->info("Written: {$path}");
        $this->info("{$mysqlCount} MySQL + {$pgsqlCount} PostgreSQL tables ({$duration}s)");

        return Command::SUCCESS;
    }

    /**
     * Get all MySQL tables and columns from INFORMATION_SCHEMA.
     */
    private function getMysqlSchema(): array
    {
        $dbName = config('database.connections.mysql.database');

        $columns = DB::select('
            SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ', [$dbName]);

        $tables = [];
        foreach ($columns as $col) {
            $tables[$col->TABLE_NAME][] = [
                'name' => $col->COLUMN_NAME,
                'type' => $col->COLUMN_TYPE,
            ];
        }

        ksort($tables);

        return $tables;
    }

    /**
     * Get all PostgreSQL tables and columns from information_schema.
     */
    private function getPgsqlSchema(): array
    {
        try {
            $schemaName = 'public';

            $columns = DB::connection('pgsql_rag')->select("
                SELECT c.table_name, c.column_name, c.data_type,
                       c.character_maximum_length,
                       c.numeric_precision, c.numeric_scale,
                       c.udt_name,
                       tc.constraint_type
                FROM information_schema.columns c
                LEFT JOIN information_schema.key_column_usage kcu
                    ON c.table_schema = kcu.table_schema
                    AND c.table_name = kcu.table_name
                    AND c.column_name = kcu.column_name
                LEFT JOIN information_schema.table_constraints tc
                    ON kcu.constraint_name = tc.constraint_name
                    AND kcu.table_schema = tc.table_schema
                    AND tc.constraint_type = 'UNIQUE'
                WHERE c.table_schema = ?
                    AND c.table_name NOT LIKE 'pg_%'
                    AND c.table_name != 'spatial_ref_sys'
                ORDER BY c.table_name, c.ordinal_position
            ", [$schemaName]);

            $tables = [];
            foreach ($columns as $col) {
                $type = $this->formatPgsqlType($col);
                $suffix = $col->constraint_type === 'UNIQUE' ? ', UNIQUE' : '';
                $tables[$col->table_name][] = [
                    'name' => $col->column_name,
                    'type' => $type.$suffix,
                ];
            }

            ksort($tables);

            return $tables;
        } catch (\Throwable $e) {
            $this->warn("PostgreSQL unavailable: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Format PostgreSQL column type from information_schema metadata.
     */
    private function formatPgsqlType(object $col): string
    {
        // Vector/user-defined types
        if ($col->data_type === 'USER-DEFINED') {
            return $col->udt_name === 'vector' ? 'USER-DEFINED' : $col->udt_name;
        }

        // Character types with length
        if ($col->data_type === 'character varying' && $col->character_maximum_length) {
            return "character varying({$col->character_maximum_length})";
        }

        // Numeric with precision
        if ($col->data_type === 'numeric' && $col->numeric_precision && $col->numeric_scale) {
            return "numeric({$col->numeric_precision},{$col->numeric_scale})";
        }

        return $col->data_type;
    }

    /**
     * Format the full schema-reference.md content.
     */
    private function formatSchemaReference(array $mysqlTables, array $pgsqlTables): string
    {
        $timestamp = now()->format('Y-m-d H:i:s');

        $lines = [];
        $lines[] = '# Database Schema Reference';
        $lines[] = '';
        $lines[] = "Auto-generated from live database on {$timestamp}.";
        $lines[] = 'Regenerate: `php artisan ops:sync-schema-reference`';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## MySQL Tables';
        $lines[] = '';

        foreach ($mysqlTables as $tableName => $columns) {
            $colDefs = array_map(
                fn ($c) => "{$c['name']} ({$c['type']})",
                $columns
            );
            $lines[] = "{$tableName}: ".implode(', ', $colDefs);
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '## PostgreSQL Tables';
        $lines[] = '';

        foreach ($pgsqlTables as $tableName => $columns) {
            $colDefs = array_map(
                fn ($c) => "{$c['name']} ({$c['type']})",
                $columns
            );
            $lines[] = "{$tableName}: ".implode(', ', $colDefs);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Show diff between current file and what would be generated.
     */
    private function showDiff(string $path, string $newContent): void
    {
        if (! file_exists($path)) {
            $this->warn('No existing file to diff against.');

            return;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'schema_');
        file_put_contents($tmpFile, $newContent);

        $diff = Process::timeout(10)->run(['diff', '-u', $path, $tmpFile]);
        unlink($tmpFile);

        $output = trim($diff->output()."\n".$diff->errorOutput());

        if ($output === '') {
            $this->info('No differences — schema-reference.md is up to date.');
        } else {
            $this->line($output);
        }
    }
}
