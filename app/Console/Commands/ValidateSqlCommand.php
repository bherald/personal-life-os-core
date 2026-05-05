<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ops:validate-sql — Catches schema mismatches before they reach production.
 *
 * Two modes:
 *   --static  (DEV, pre-deploy): Scans all PHP for DB:: calls, extracts SQL,
 *             validates table/column references against live DESCRIBE output.
 *   --explain (PROD, post-deploy): Runs EXPLAIN on extracted SQL against the
 *             live database to catch runtime schema errors.
 *
 * Run --static before every PROD1 deploy. Run --explain after deploy.
 *
 * Usage:
 *   php artisan ops:validate-sql                    # Static mode (default)
 *   php artisan ops:validate-sql --explain          # EXPLAIN mode
 *   php artisan ops:validate-sql --file=SomeService # Scan specific file pattern
 *   php artisan ops:validate-sql --json             # Machine-readable output
 *   php artisan ops:validate-sql --fix-report       # Write report to storage/logs/
 */
class ValidateSqlCommand extends Command
{
    protected $signature = 'ops:validate-sql
                            {--explain : Run EXPLAIN on extracted SQL against live DB}
                            {--file= : Filter to files matching this pattern}
                            {--json : Output results as JSON}
                            {--fix-report : Write detailed report to storage/logs/sql-validation.log}
                            {--schema-lock-timeout=30 : Seconds to wait for schema-preservation tests before reading MySQL schema}';

    protected $description = 'Validate SQL statements in codebase against database schema';

    private array $mysqlSchema = [];

    private array $pgsqlSchema = [];

    private array $errors = [];

    private array $warnings = [];

    private int $filesScanned = 0;

    private int $statementsChecked = 0;

    private int $statementsSkipped = 0;

    private const MYSQL_SCHEMA_MUTATION_LOCK = 'plos_preserves_schema_tables';

    private ?string $schemaReadLockName = null;

    // Tables that may not exist in dev but are referenced with try/catch or conditional logic
    private array $optionalTables = [
        'pg_tables',
        'genealogy_name_embeddings',
    ];

    public function handle(): int
    {
        $startTime = microtime(true);

        return $this->withSchemaReadLock($startTime, function () use ($startTime): int {
            if ($this->option('explain')) {
                return $this->runExplainMode($startTime);
            }

            return $this->runStaticMode($startTime);
        });
    }

    private function withSchemaReadLock(float $startTime, callable $callback): int
    {
        if (! $this->usesMysqlConnection()) {
            return $callback();
        }

        if (! $this->acquireSchemaReadLock()) {
            $this->errors[] = [
                'file' => 'N/A',
                'line' => 0,
                'sql' => 'GET_LOCK',
                'error' => sprintf(
                    'Cannot acquire schema read lock `%s`; schema-preservation tests may still be restoring tables',
                    self::MYSQL_SCHEMA_MUTATION_LOCK
                ),
                'severity' => 'fatal',
            ];

            return $this->outputResults($startTime);
        }

        try {
            return $callback();
        } finally {
            $this->releaseSchemaReadLock();
        }
    }

    private function acquireSchemaReadLock(): bool
    {
        $timeout = max(0, (int) $this->option('schema-lock-timeout'));
        $row = DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [self::MYSQL_SCHEMA_MUTATION_LOCK, $timeout]);

        if ((int) ($row->acquired ?? 0) !== 1) {
            return false;
        }

        $this->schemaReadLockName = self::MYSQL_SCHEMA_MUTATION_LOCK;

        return true;
    }

    private function releaseSchemaReadLock(): void
    {
        if ($this->schemaReadLockName === null) {
            return;
        }

        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->schemaReadLockName]);
        } finally {
            $this->schemaReadLockName = null;
        }
    }

    private function usesMysqlConnection(): bool
    {
        try {
            return DB::connection()->getDriverName() === 'mysql';
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── STATIC MODE ────────────────────────────────────────────────────

    private function runStaticMode(float $startTime): int
    {
        if (! $this->option('json')) {
            $this->info("=== SQL Static Validation ===\n");
            $this->info('Loading schema from MySQL and PostgreSQL...');
        }

        $this->loadMysqlSchema();
        $this->loadPgsqlSchema();

        if (! $this->option('json')) {
            $this->info('  MySQL: '.count($this->mysqlSchema).' tables');
            $this->info('  PostgreSQL: '.count($this->pgsqlSchema)." tables\n");
        }

        $phpFiles = $this->findPhpFiles();
        if (! $this->option('json')) {
            $this->info('Scanning '.count($phpFiles)." PHP files...\n");
        }

        foreach ($phpFiles as $file) {
            $this->validateFile($file);
        }

        return $this->outputResults($startTime);
    }

    private function loadMysqlSchema(): void
    {
        try {
            $tables = DB::select('SHOW TABLES');
            $dbName = config('database.connections.mysql.database');
            $key = "Tables_in_{$dbName}";

            foreach ($tables as $table) {
                $tableName = $table->$key ?? (array_values((array) $table)[0] ?? null);
                if (! $tableName) {
                    continue;
                }

                $columns = DB::select("DESCRIBE `{$tableName}`");
                $this->mysqlSchema[$tableName] = [];
                foreach ($columns as $col) {
                    $this->mysqlSchema[$tableName][$col->Field] = [
                        'type' => $col->Type,
                        'null' => $col->Null === 'YES',
                        'key' => $col->Key,
                        'default' => $col->Default,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->errors[] = [
                'file' => 'N/A',
                'line' => 0,
                'sql' => 'SHOW TABLES',
                'error' => 'Cannot load MySQL schema: '.$e->getMessage(),
                'severity' => 'fatal',
            ];
        }
    }

    private function loadPgsqlSchema(): void
    {
        try {
            $tables = DB::connection('pgsql_rag')->select("
                SELECT table_name FROM information_schema.tables
                WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ");

            foreach ($tables as $table) {
                $tableName = $table->table_name;
                $columns = DB::connection('pgsql_rag')->select("
                    SELECT column_name, data_type, is_nullable, column_default
                    FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = ?
                ", [$tableName]);

                $this->pgsqlSchema[$tableName] = [];
                foreach ($columns as $col) {
                    $this->pgsqlSchema[$tableName][$col->column_name] = [
                        'type' => $col->data_type,
                        'null' => $col->is_nullable === 'YES',
                        'default' => $col->column_default,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->warnings[] = [
                'file' => 'N/A',
                'line' => 0,
                'message' => 'Cannot load PostgreSQL schema: '.$e->getMessage(),
            ];
        }
    }

    private function loadSchemaForConnection(string $connection): array
    {
        if ($connection === 'pgsql_rag') {
            if (empty($this->pgsqlSchema)) {
                $this->loadPgsqlSchema();
            }

            return $this->pgsqlSchema;
        }

        if (empty($this->mysqlSchema)) {
            $this->loadMysqlSchema();
        }

        return $this->mysqlSchema;
    }

    private function findPhpFiles(): array
    {
        $dirs = [
            base_path('app/Services'),
            base_path('app/Console/Commands'),
            base_path('app/Http/Controllers'),
        ];

        $files = [];
        $filter = $this->option('file');

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if ($filter && stripos($file->getPathname(), $filter) === false) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function validateFile(string $filePath): void
    {
        $content = file_get_contents($filePath);
        if (stripos($content, 'DB::') === false) {
            return;
        }

        $this->filesScanned++;
        $relativePath = str_replace(base_path().'/', '', $filePath);

        $statements = $this->extractSqlStatements($content, $relativePath);

        foreach ($statements as $stmt) {
            $this->validateStatement($stmt, $relativePath);
        }
    }

    /**
     * Extract SQL statements from PHP source code.
     * Returns array of ['sql' => string, 'line' => int, 'connection' => string, 'method' => string]
     */
    private function extractSqlStatements(string $content, string $file): array
    {
        $statements = [];
        $lines = explode("\n", $content);

        // Match DB:: calls with SQL string arguments
        // Handles: DB::select("..."), DB::connection('pgsql_rag')->select("..."), etc.
        $pattern = '/DB::(?:connection\((?:[\'"]([^\'"]+)[\'"]|\$this->(?:connection|dbConnection))\)->)?'
            .'(select|selectOne|insert|update|delete|statement|scalar)\s*\(\s*'
            .'(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')/s';

        // Detect class-level connection property for files using $this->connection or $this->dbConnection
        $classConnection = 'mysql';
        if (preg_match('/(?:private|protected)\s+(?:string\s+)?\$(?:connection|dbConnection)\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $connMatch)) {
            $classConnection = $connMatch[1];
        }
        // Also detect DB::connection($this->connection/dbConnection) pattern
        $usesThisConn = (bool) preg_match('/DB::connection\(\$this->(?:connection|dbConnection)\)/', $content);

        // We need to track line numbers, so scan line by line and accumulate multi-line strings
        $fullContent = $content;

        if (preg_match_all($pattern, $fullContent, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $offset = $match[0][1];
                $lineNum = substr_count($fullContent, "\n", 0, $offset) + 1;

                $connection = $match[1][0] ?: ($usesThisConn ? $classConnection : 'mysql');
                $method = $match[2][0];
                $sql = $match[3][0] !== '' ? $match[3][0] : ($match[4][0] ?? '');

                // Unescape
                $sql = str_replace(['\\n', '\\t', '\\"', "\\'"], ["\n", "\t", '"', "'"], $sql);

                // Skip empty or purely dynamic SQL
                $trimmed = trim($sql);
                if (empty($trimmed) || $trimmed === '?') {
                    $this->statementsSkipped++;

                    continue;
                }

                // Skip SQL that's clearly dynamic (starts with variable interpolation)
                if (preg_match('/^\s*\$/', $trimmed) || preg_match('/^\s*\{/', $trimmed)) {
                    $this->statementsSkipped++;

                    continue;
                }

                $statements[] = [
                    'sql' => $trimmed,
                    'line' => $lineNum,
                    'connection' => $connection,
                    'method' => $method,
                    'file' => $file,
                    'try_catch' => $this->isInsideTryCatch($lines, $lineNum),
                ];
            }
        }

        // Also catch concatenated SQL: $sql = "SELECT ..."; DB::select($sql, ...)
        // Look for patterns where SQL is built in a variable and referenced
        // This catches: $sql = "SELECT ... FROM {tableName} ..."; followed by DB::select($sql
        if (preg_match_all('/\$\w+\s*=\s*"((?:SELECT|INSERT|UPDATE|DELETE|EXPLAIN)\s+(?:[^"\\\\]|\\\\.)*)"/si',
            $fullContent, $varMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($varMatches as $match) {
                $offset = $match[0][1];
                $lineNum = substr_count($fullContent, "\n", 0, $offset) + 1;
                $sql = str_replace(['\\n', '\\t', '\\"'], ["\n", "\t", '"'], $match[1][0]);
                $trimmed = trim($sql);
                if (empty($trimmed)) {
                    continue;
                }

                // Detect connection from context (look backward for pgsql_rag) or class-level property
                $contextStart = max(0, $offset - 500);
                $context = substr($fullContent, $contextStart, $offset - $contextStart + strlen($match[0][0]) + 500);
                if (stripos($context, 'pgsql_rag') !== false || stripos($context, "pgsql'") !== false) {
                    $connection = 'pgsql_rag';
                } elseif ($usesThisConn) {
                    $connection = $classConnection;
                } else {
                    $connection = 'mysql';
                }

                $statements[] = [
                    'sql' => $trimmed,
                    'line' => $lineNum,
                    'connection' => $connection,
                    'method' => 'variable',
                    'file' => $file,
                    'try_catch' => $this->isInsideTryCatch($lines, $lineNum),
                ];
            }
        }

        return $statements;
    }

    private function validateStatement(array $stmt, string $file): void
    {
        $this->statementsChecked++;
        $sql = $stmt['sql'];
        $schema = $stmt['connection'] === 'pgsql_rag' ? $this->pgsqlSchema : $this->mysqlSchema;
        $dbLabel = $stmt['connection'] === 'pgsql_rag' ? 'PostgreSQL' : 'MySQL';
        $inTryCatch = $stmt['try_catch'] ?? false;

        // Extract tables referenced in the SQL
        $tables = $this->extractTables($sql);
        // Extract columns referenced
        $columns = $this->extractColumns($sql);

        // Pre-filter: collect confirmed table names so we can detect column-as-table false positives
        $confirmedTables = [];
        foreach ($tables as $table) {
            if (isset($schema[$table])) {
                $confirmedTables[] = $table;
            }
        }

        foreach ($tables as $table) {
            // Skip subquery aliases and dynamic placeholders
            if (preg_match('/^\(|^\$|^{|^[0-9]/', $table)) {
                continue;
            }
            // Skip if it looks like a function or keyword
            if (in_array(strtoupper($table), ['DUAL', 'INFORMATION_SCHEMA', 'NULL'])) {
                continue;
            }
            if (in_array($table, $this->optionalTables, true)) {
                continue;
            }

            // Skip false positives: if this "table" name is a column in any confirmed table
            // in the same SQL (e.g., column name from INSERT INTO table (col1, col2, ...))
            $isColumnInSameSql = false;
            foreach ($confirmedTables as $ct) {
                if (isset($schema[$ct][$table])) {
                    $isColumnInSameSql = true;
                    break;
                }
            }
            if ($isColumnInSameSql) {
                continue;
            }

            if (! isset($schema[$table])) {
                // Check if it exists in the other schema (cross-DB reference)
                $otherSchema = $stmt['connection'] === 'pgsql_rag' ? $this->mysqlSchema : $this->pgsqlSchema;
                $otherLabel = $stmt['connection'] === 'pgsql_rag' ? 'MySQL' : 'PostgreSQL';

                $entry = [
                    'file' => $file,
                    'line' => $stmt['line'],
                    'sql_preview' => mb_substr($sql, 0, 120),
                    'severity' => $inTryCatch ? 'warning' : 'error',
                ];
                if (isset($otherSchema[$table])) {
                    $entry['error'] = "Cross-DB reference: `{$table}` exists in {$otherLabel} but query runs on {$dbLabel}";
                } else {
                    $entry['error'] = "Unknown table `{$table}` on {$dbLabel}";
                }
                if ($inTryCatch) {
                    $entry['error'] .= ' (in try/catch)';
                    $this->warnings[] = $entry;
                } else {
                    $this->errors[] = $entry;
                }
            }
        }

        // Validate columns against known tables
        foreach ($columns as $colRef) {
            $table = $colRef['table'] ?? null;
            $column = $colRef['column'];
            $alias = $colRef['alias'] ?? null;

            if (! $table) {
                continue;
            } // Can't validate unqualified columns without deeper analysis
            if ($alias) {
                continue;
            } // Skip aliased table references we can't resolve

            // Resolve table alias from SQL
            $resolvedTable = $this->resolveTableAlias($table, $sql);
            if (! $resolvedTable) {
                continue;
            }

            // Skip if table not in schema (already reported as error above)
            if (! isset($schema[$resolvedTable])) {
                continue;
            }

            // Skip * and expressions
            if ($column === '*' || preg_match('/[\(\)]/', $column)) {
                continue;
            }

            if (! isset($schema[$resolvedTable][$column])) {
                $entry = [
                    'file' => $file,
                    'line' => $stmt['line'],
                    'sql_preview' => mb_substr($sql, 0, 120),
                    'error' => "Unknown column `{$resolvedTable}`.`{$column}` on {$dbLabel}",
                    'severity' => $inTryCatch ? 'warning' : 'error',
                ];
                if ($inTryCatch) {
                    $entry['error'] .= ' (in try/catch)';
                    $this->warnings[] = $entry;
                } else {
                    $this->errors[] = $entry;
                }
            }
        }
    }

    /**
     * Check if a given line number is inside a try block (heuristic: brace counting).
     */
    private function isInsideTryCatch(array $lines, int $lineNum): bool
    {
        $tryDepth = 0;
        for ($i = 0; $i < min($lineNum, count($lines)); $i++) {
            $line = $lines[$i];
            if (preg_match('/\btry\s*\{/', $line)) {
                $tryDepth++;
            }
            // Count closing braces that correspond to catch blocks
            if (preg_match('/\}\s*catch\s*\(/', $line)) {
                $tryDepth = max(0, $tryDepth - 1);
            }
        }

        return $tryDepth > 0;
    }

    /**
     * Extract table names from SQL.
     */
    private function extractTables(string $sql): array
    {
        $tables = [];
        $normalized = preg_replace('/\s+/', ' ', $sql);

        // Strip INSERT INTO column lists to prevent column names being parsed as tables
        // The column list is the first (...) after INSERT INTO table_name
        $normalized = preg_replace('/(\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?\w+`?)\s*\([^)]+\)/i', '$1', $normalized);

        // FROM table, JOIN table, INSERT INTO table, UPDATE table
        $patterns = [
            '/\bFROM\s+`?(\w+)`?/i',
            '/\bJOIN\s+`?(\w+)`?/i',
            '/\bINSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?/i',
            '/\bUPDATE\s+`?(\w+)`?/i',
            '/\bDELETE\s+FROM\s+`?(\w+)`?/i',
            '/\bTRUNCATE\s+(?:TABLE\s+)?`?(\w+)`?/i',
            '/\bDROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i',
            '/\bALTER\s+TABLE\s+`?(\w+)`?/i',
            '/\bDESCRIBE\s+`?(\w+)`?/i',
            '/\bEXISTS\s*\(\s*SELECT\s+.*?\bFROM\s+`?(\w+)`?/i',
        ];

        // SQL keywords that regex might accidentally capture as table names
        $keywords = [
            'set', 'select', 'values', 'where', 'on', 'as', 'if', 'not',
            'exists', 'null', 'ignore', 'low_priority', 'delayed', 'dual',
            'high_priority', 'straight_join', 'sql_small_result', 'sql_big_result',
            'sql_buffer_result', 'sql_cache', 'sql_no_cache', 'sql_calc_found_rows',
            'table', 'into', 'from', 'join', 'inner', 'outer', 'left', 'right',
            'cross', 'natural', 'using', 'order', 'group', 'having', 'limit',
            'offset', 'union', 'intersect', 'except', 'case', 'when', 'then',
            'else', 'end', 'and', 'or', 'between', 'like', 'in', 'is',
            'true', 'false', 'asc', 'desc', 'distinct', 'all', 'any', 'some',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $normalized, $matches)) {
                // The capturing group may be at different positions
                $lastGroup = count($matches) - 1;
                foreach ($matches[$lastGroup] as $table) {
                    if (empty($table)) {
                        continue;
                    }
                    $lower = strtolower($table);
                    if (in_array($lower, $keywords)) {
                        continue;
                    }
                    // Skip if looks like a column name (too short and no underscore — heuristic)
                    // Real table names in this codebase use snake_case with underscores
                    // But some don't (e.g., claims, verdicts) so we can't filter on underscore
                    $tables[$table] = true;
                }
            }
        }

        return array_keys($tables);
    }

    /**
     * Extract qualified column references (table.column) from SQL.
     * Only returns columns with explicit table prefix — unqualified columns are skipped
     * since we cannot reliably determine which table they belong to.
     */
    private function extractColumns(string $sql): array
    {
        $columns = [];
        $normalized = preg_replace('/\s+/', ' ', $sql);

        // Match table.column or table.`column` patterns
        if (preg_match_all('/\b`?(\w+)`?\s*\.\s*`?(\w+)`?/', $normalized, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $tableOrAlias = $match[1];
                $column = $match[2];

                // Skip numeric prefixes (subquery artifacts)
                if (is_numeric($tableOrAlias)) {
                    continue;
                }
                // Skip SQL functions
                if (in_array(strtoupper($tableOrAlias), ['DATE', 'TIME', 'YEAR', 'MONTH', 'DAY',
                    'CONCAT', 'COALESCE', 'IFNULL', 'JSON_EXTRACT', 'JSON_UNQUOTE', 'JSON_VALID', 'SUBSTRING',
                    'NOW', 'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'GROUP'])) {
                    continue;
                }

                $columns[] = [
                    'table' => $tableOrAlias,
                    'column' => $column,
                ];
            }
        }

        return $columns;
    }

    /**
     * Resolve a table alias back to the real table name within the SQL context.
     */
    private function resolveTableAlias(string $aliasOrTable, string $sql): ?string
    {
        $normalized = preg_replace('/\s+/', ' ', $sql);

        // Check if it's already a real table
        $allSchema = array_merge(array_keys($this->mysqlSchema), array_keys($this->pgsqlSchema));
        if (in_array($aliasOrTable, $allSchema)) {
            return $aliasOrTable;
        }

        // Look for "table AS alias" or "table alias" patterns
        // FROM genealogy_sources s | FROM genealogy_sources AS s
        $pattern = '/\b`?(\w+)`?\s+(?:AS\s+)?`?'.preg_quote($aliasOrTable, '/').'`?(?:\s|,|$|\)|ON)/i';
        if (preg_match($pattern, $normalized, $match)) {
            $candidate = $match[1];
            // Verify it's a real table
            if (in_array($candidate, $allSchema)) {
                return $candidate;
            }
        }

        return null; // Can't resolve — skip validation
    }

    // ─── EXPLAIN MODE ───────────────────────────────────────────────────

    private function runExplainMode(float $startTime): int
    {
        if (! $this->option('json')) {
            $this->info("=== SQL EXPLAIN Validation ===\n");
            $this->info('Scanning PHP files for SQL statements...');
        }

        $phpFiles = $this->findPhpFiles();
        $allStatements = [];

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if (stripos($content, 'DB::') === false) {
                continue;
            }
            $this->filesScanned++;
            $relativePath = str_replace(base_path().'/', '', $file);
            $stmts = $this->extractSqlStatements($content, $relativePath);
            $allStatements = array_merge($allStatements, $stmts);
        }

        if (! $this->option('json')) {
            $this->info('Found '.count($allStatements)." SQL statements in {$this->filesScanned} files\n");
            $this->info("Running EXPLAIN against live database...\n");
        }

        $seen = []; // Dedup identical SQL

        foreach ($allStatements as $stmt) {
            $sql = $stmt['sql'];

            // Normalize for dedup
            $dedupKey = md5($sql.$stmt['connection']);
            if (isset($seen[$dedupKey])) {
                $this->statementsSkipped++;

                continue;
            }
            $seen[$dedupKey] = true;

            $this->explainStatement($stmt);
        }

        return $this->outputResults($startTime);
    }

    private function explainStatement(array $stmt): void
    {
        $sql = $stmt['sql'];
        $connection = $stmt['connection'];

        // Only EXPLAIN works on SELECT, INSERT, UPDATE, DELETE
        $normalized = ltrim($sql);
        $firstWord = strtoupper(strtok($normalized, " \t\n\r"));

        if (! in_array($firstWord, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])) {
            $this->statementsSkipped++;

            return;
        }

        // Skip SQL with interpolated PHP variables that we can't substitute
        if (preg_match('/\$\w+|\{\$|\.\\s*\$/', $sql)) {
            $this->statementsSkipped++;

            return;
        }

        $schema = $connection === 'pgsql_rag'
            ? $this->loadSchemaForConnection('pgsql_rag')
            : $this->loadSchemaForConnection('mysql');
        foreach ($this->extractTables($sql) as $table) {
            if (in_array($table, $this->optionalTables, true) && ! isset($schema[$table])) {
                $this->statementsSkipped++;

                return;
            }
        }

        // Replace ? placeholders with safe dummy values
        $explainSql = $this->substitutePlaceholders($sql, $connection);

        // Wrap with EXPLAIN
        $explainSql = "EXPLAIN {$explainSql}";

        $this->statementsChecked++;

        try {
            $db = $connection === 'pgsql_rag'
                ? DB::connection('pgsql_rag')
                : DB::connection();

            $db->select($explainSql);
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();

            // Filter out expected errors from dummy values
            if ($this->isExpectedExplainError($errorMsg)) {
                return;
            }

            $inTryCatch = $stmt['try_catch'] ?? false;
            $severity = $inTryCatch ? 'warning' : 'error';
            $errorText = $this->cleanErrorMessage($errorMsg);
            if ($inTryCatch) {
                $errorText .= ' (in try/catch)';
            }

            $entry = [
                'file' => $stmt['file'],
                'line' => $stmt['line'],
                'sql_preview' => mb_substr($sql, 0, 120),
                'error' => $errorText,
                'severity' => $severity,
            ];

            if ($inTryCatch) {
                $this->warnings[] = $entry;
            } else {
                $this->errors[] = $entry;
            }
        }
    }

    /**
     * Replace ? placeholders with safe dummy values for EXPLAIN.
     */
    private function substitutePlaceholders(string $sql, string $connection): string
    {
        // Replace ?::vector with a dummy vector literal (pgvector)
        $sql = preg_replace('/\?\s*::vector/', "'[0]'::vector", $sql);
        // Replace ?::jsonb with dummy jsonb
        $sql = preg_replace('/\?\s*::jsonb/', "'{}'::jsonb", $sql);
        // Replace ?::uuid with dummy uuid
        $sql = preg_replace('/\?\s*::uuid/', "'00000000-0000-0000-0000-000000000000'::uuid", $sql);

        // Replace remaining ? with '1' (works for both int and string contexts in EXPLAIN)
        $sql = preg_replace('/\?/', "'1'", $sql);

        // Replace LIMIT with small value to keep EXPLAIN fast
        $sql = preg_replace('/LIMIT\s+\'1\'/i', 'LIMIT 1', $sql);
        $sql = preg_replace('/OFFSET\s+\'1\'/i', 'OFFSET 0', $sql);

        return $sql;
    }

    /**
     * Expected errors from dummy value substitution that aren't schema bugs.
     */
    private function isExpectedExplainError(string $msg): bool
    {
        $expected = [
            // Data type mismatches from dummy '1' substitution
            'Data too long',
            'Incorrect integer value',
            'Incorrect datetime value',
            'Incorrect TIMESTAMP value',
            'Incorrect date value',
            'Incorrect time value',
            'Out of range value',
            'Truncated incorrect',
            'Data truncated',
            'cannot be cast',
            'invalid input syntax',
            'value too long',
            // Vector dimension mismatch (dummy [0] vs real dimensions)
            'expected 768 dimensions',
            'expected 128 dimensions',
            'expected 384 dimensions',
            'expected 1024 dimensions',
            'dimensions, not 1',
            // Operator/collation issues from dummy data
            'operator does not exist',
            'could not determine which collation',
            'different collation',
            'Illegal mix of collations',
            // Structural issues from dummy substitution
            'Subquery returns more than 1 row',
            'specify target table',  // UPDATE with subquery referencing same table
            // Syntax errors from truncated/dynamic SQL (empty IN, incomplete strings)
            'syntax error at end of input',
            'syntax error at or near',
            'right syntax to use near',
            // Empty IN clause from placeholder substitution
            'IN ()',
            // Set-returning functions not supported in some EXPLAIN contexts
            'set-returning functions are not allowed',
        ];

        foreach ($expected as $pattern) {
            if (stripos($msg, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function cleanErrorMessage(string $msg): string
    {
        // Strip SQLSTATE prefix and connection info
        $msg = preg_replace('/SQLSTATE\[\w+\]:\s*/', '', $msg);
        $msg = preg_replace('/\(Connection:.*?\)\s*/', '', $msg);
        $msg = preg_replace('/\(SQL:.*$/s', '', $msg);

        return trim($msg);
    }

    // ─── OUTPUT ─────────────────────────────────────────────────────────

    private function outputResults(float $startTime): int
    {
        $duration = round(microtime(true) - $startTime, 1);
        $mode = $this->option('explain') ? 'EXPLAIN' : 'Static';

        if ($this->option('json')) {
            $this->line(json_encode([
                'mode' => $mode,
                'files_scanned' => $this->filesScanned,
                'statements_checked' => $this->statementsChecked,
                'statements_skipped' => $this->statementsSkipped,
                'errors' => $this->errors,
                'warnings' => $this->warnings,
                'duration_seconds' => $duration,
            ], JSON_PRETTY_PRINT));
        } else {
            if (! empty($this->errors)) {
                $this->newLine();
                $this->error('ERRORS ('.count($this->errors).'):');
                $this->newLine();

                $grouped = [];
                foreach ($this->errors as $err) {
                    $grouped[$err['file']][] = $err;
                }

                foreach ($grouped as $file => $fileErrors) {
                    $this->line("  <comment>{$file}</comment>");
                    foreach ($fileErrors as $err) {
                        $this->line("    L{$err['line']}: <fg=red>{$err['error']}</>");
                        if (! empty($err['sql_preview'])) {
                            $this->line('           '.mb_substr($err['sql_preview'], 0, 100));
                        }
                    }
                    $this->newLine();
                }
            }

            if (! empty($this->warnings)) {
                $this->warn('WARNINGS ('.count($this->warnings).'):');
                foreach ($this->warnings as $w) {
                    $this->line("  {$w['file']}:L{$w['line']}: {$w['error']}");
                }
                $this->newLine();
            }

            $this->newLine();
            $this->info("--- {$mode} Validation Summary ---");
            $this->info("Files: {$this->filesScanned} | Checked: {$this->statementsChecked} | Skipped: {$this->statementsSkipped}");

            $errorCount = count($this->errors);
            $warnCount = count($this->warnings);

            if ($errorCount === 0 && $warnCount === 0) {
                $this->info("Result: <fg=green>PASS</> ({$duration}s)");
            } elseif ($errorCount === 0) {
                $this->info("Result: <fg=yellow>PASS with warnings</> ({$warnCount} warnings, {$duration}s)");
            } else {
                $this->error("Result: FAIL ({$errorCount} errors, {$warnCount} warnings, {$duration}s)");
            }

            // Machine-readable tag for CI/scripts
            $this->line("[ITEMS_PROCESSED:{$this->statementsChecked}]");
        }

        if ($this->option('fix-report')) {
            $reportPath = storage_path('logs/sql-validation.log');
            $report = date('Y-m-d H:i:s')." | {$mode} mode | "
                .count($this->errors).' errors, '.count($this->warnings)." warnings\n";
            foreach ($this->errors as $err) {
                $report .= "  ERROR: {$err['file']}:L{$err['line']}: {$err['error']}\n";
            }
            file_put_contents($reportPath, $report);
            if (! $this->option('json')) {
                $this->info("Report written to: {$reportPath}");
            }
        }

        return count($this->errors) > 0 ? 1 : 0;
    }
}
