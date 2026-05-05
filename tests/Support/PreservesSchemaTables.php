<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PreservesSchemaTables — shared trait for tests that need to replace a live
 * table with a stub for the duration of the test.
 *
 * Background: several agent-service tests run against a shared dev MySQL
 * database. They previously called `Schema::dropIfExists` on real tables
 * (guardrail_rules, agent_sessions, distributed_agents, …) at both setUp
 * and tearDown, permanently mutating the shared schema. A subsequent
 * `php artisan ops:validate-sql` would then fail because live queries
 * referenced tables that the last test run had dropped, blocking deploy.
 *
 * This trait provides the preserve/restore pattern modeled on
 * `tests/Unit/Services/AdaptiveModeServiceTest.php`:
 *
 *   setUp:     preserveTables(['foo', 'bar'])  // renames to foo_prsv_xxx
 *              // then the test creates its own fresh stub
 *   tearDown:  restorePreservedTables()         // drops stub + renames back
 *
 * Behavior details:
 * - Tables that did NOT exist pre-run have no backup row, but if the test
 *   creates a stub in that slot, restorePreservedTables drops it.
 * - The suffix is unique per test run so concurrent invocations (or a
 *   crash that leaves a backup behind) cannot collide — restoration only
 *   targets the suffix this run created.
 * - restorePreservedTables dropIfExists()s any stub the test created,
 *   then renames the backup back to its original name. That is the only
 *   safe sequence: if we skipped the drop, the rename would fail because
 *   the stub still occupies the name.
 */
trait PreservesSchemaTables
{
    private const MYSQL_SCHEMA_LOCK = 'plos_preserves_schema_tables';

    /** @var list<string> original table slots this test owns until restore */
    private array $preservedTableSlots = [];

    /** @var array<string,string> original_name → backup_name */
    private array $preservedTableBackups = [];

    private ?string $preservedSchemaLockName = null;

    /**
     * Per-original-table snapshot of FK constraints that existed before
     * preservation. We drop them before renaming so the stubs the test
     * creates can re-use the same FK constraint names.
     *
     * @var array<string,list<array{name:string,column:string,referenced_table:string,referenced_column:string,on_delete:string,on_update:string}>>
     */
    private array $preservedForeignKeys = [];

    /**
     * Rename every existing table in the list to a run-unique backup name.
     * Non-existent tables are skipped silently. Foreign-key constraints on
     * the preserved tables are dropped first (snapshot saved) so the test
     * can create stubs using the same FK constraint names without the
     * MySQL "Duplicate foreign key constraint name" collision.
     *
     * Must be called BEFORE the test creates its stub tables.
     *
     * @param  list<string>  $tableNames
     */
    protected function preserveTables(array $tableNames): void
    {
        $this->acquireSchemaMutationLock();

        $this->preservedTableSlots = array_values(array_unique($tableNames));
        $suffix = '_prsv_'.substr(md5(static::class.'|'.microtime(true).'|'.bin2hex(random_bytes(4))), 0, 10);

        // Pass 1: snapshot FK constraints and drop them. Must happen before
        // any renames so child references still resolve.
        foreach ($tableNames as $name) {
            if (! $this->tableExists($name)) {
                continue;
            }
            $this->preservedForeignKeys[$name] = $this->snapshotAndDropForeignKeys($name);
        }

        // Pass 2: rename the tables to the backup name.
        foreach ($tableNames as $name) {
            if (! $this->tableExists($name)) {
                continue;
            }
            $backup = $name.$suffix;
            DB::statement("ALTER TABLE `{$name}` RENAME TO `{$backup}`");
            $this->preservedTableBackups[$name] = $backup;
        }
    }

    /**
     * Drop any stub that replaced the preserved table, then rename the
     * backup back and re-add the FK constraints we dropped during preserve.
     * Safe to call from tearDown even if preserveTables was never invoked.
     *
     * Must be called AFTER the test finishes using its stub tables.
     */
    protected function restorePreservedTables(): void
    {
        try {
            // Pass 1: drop stubs for every table slot this test owns.
            foreach ($this->preservedTableSlots as $original) {
                Schema::dropIfExists($original);
            }

            // Pass 2: restore the backup names.
            foreach ($this->preservedTableBackups as $original => $backup) {
                if ($this->tableExists($backup)) {
                    DB::statement("ALTER TABLE `{$backup}` RENAME TO `{$original}`");
                }
            }

            // Pass 3: re-add FK constraints. Deferred to a second pass so every
            // referenced table has been renamed back before we try to add FKs
            // that reference it.
            foreach ($this->preservedForeignKeys as $table => $fks) {
                if (! $this->tableExists($table)) {
                    continue;
                }
                foreach ($fks as $fk) {
                    try {
                        DB::statement(sprintf(
                            'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                            $table,
                            $fk['name'],
                            $fk['column'],
                            $fk['referenced_table'],
                            $fk['referenced_column'],
                            $fk['on_delete'] !== '' ? $fk['on_delete'] : 'RESTRICT',
                            $fk['on_update'] !== '' ? $fk['on_update'] : 'RESTRICT',
                        ));
                    } catch (\Throwable $e) {
                        // If an FK cannot be re-added (e.g., referenced table
                        // itself was not preserved), surface it loudly —
                        // silent loss of FKs is worse than a noisy test.
                        throw new \RuntimeException(sprintf(
                            'PreservesSchemaTables: failed to re-add FK `%s` on `%s`: %s',
                            $fk['name'], $table, $e->getMessage()
                        ), 0, $e);
                    }
                }
            }

            $this->preservedTableSlots = [];
            $this->preservedTableBackups = [];
            $this->preservedForeignKeys = [];
        } finally {
            $this->releaseSchemaMutationLock();
        }
    }

    /**
     * Snapshot foreign-key constraints on the given table and then DROP
     * them so the table can be renamed + stubbed without MySQL rejecting
     * the test's stub FKs as duplicate constraint names.
     *
     * @return list<array{name:string,column:string,referenced_table:string,referenced_column:string,on_delete:string,on_update:string}>
     */
    private function snapshotAndDropForeignKeys(string $table): array
    {
        $rows = DB::select(
            'SELECT k.CONSTRAINT_NAME AS name,
                    k.COLUMN_NAME AS column_name,
                    k.REFERENCED_TABLE_NAME AS referenced_table,
                    k.REFERENCED_COLUMN_NAME AS referenced_column,
                    r.DELETE_RULE AS on_delete,
                    r.UPDATE_RULE AS on_update
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA
              AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = DATABASE()
               AND k.TABLE_NAME   = ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$table]
        );

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[] = [
                'name' => (string) $row->name,
                'column' => (string) $row->column_name,
                'referenced_table' => (string) $row->referenced_table,
                'referenced_column' => (string) $row->referenced_column,
                'on_delete' => (string) ($row->on_delete ?? 'RESTRICT'),
                'on_update' => (string) ($row->on_update ?? 'RESTRICT'),
            ];
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$row->name}`");
        }

        return $snapshot;
    }

    private function acquireSchemaMutationLock(): void
    {
        if ($this->preservedSchemaLockName !== null || ! $this->usesMysqlConnection()) {
            return;
        }

        $row = DB::selectOne('SELECT GET_LOCK(?, 30) AS acquired', [self::MYSQL_SCHEMA_LOCK]);
        if ((int) ($row->acquired ?? 0) !== 1) {
            throw new \RuntimeException('PreservesSchemaTables: failed to acquire schema mutation lock.');
        }

        $this->preservedSchemaLockName = self::MYSQL_SCHEMA_LOCK;
    }

    private function releaseSchemaMutationLock(): void
    {
        if ($this->preservedSchemaLockName === null || ! $this->usesMysqlConnection()) {
            $this->preservedSchemaLockName = null;

            return;
        }

        try {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->preservedSchemaLockName]);
        } finally {
            $this->preservedSchemaLockName = null;
        }
    }

    private function usesMysqlConnection(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    private function tableExists(string $table): bool
    {
        if (! $this->usesMysqlConnection()) {
            return Schema::hasTable($table);
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS table_count
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?',
            [$table]
        );

        return (int) ($row->table_count ?? 0) > 0;
    }
}
