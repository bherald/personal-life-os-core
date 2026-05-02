<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * dev:setup — Sync dev environment config tables from an approved source (schema + config match, no processing).
 *
 * Pulls config-only tables from a configured source environment via SSH,
 * imports to dev with everything disabled:
 * - scheduled_jobs: all enabled=0
 * - llm_instances: all is_active=0
 * - workflows: all active=0
 * - compute_instances, review_type_registry, agent_tool_registry: as-is
 * - Seeds minimal file_registry + rag_documents rows for query testing
 *
 * Safety: refuses to run on prod (APP_ENV check).
 * Usage: php artisan dev:setup [--config] [--seed] [--schema]
 *
 * Source sync is private/opt-in. Configure DEV_SETUP_SOURCE_* env values in
 * your local .env before running --schema or --config.
 */
class DevSetupCommand extends Command
{
    protected $signature = 'dev:setup
                            {--config : Sync config tables from prod only}
                            {--seed : Seed test data only}
                            {--schema : Run schema sync from prod only}';

    protected $description = 'Sync dev environment with source schema + config (all processing disabled)';

    /** Config tables to sync from the configured source (no user data, just system config) */
    private const CONFIG_TABLES = [
        'scheduled_jobs',
        'llm_instances',
        'workflows',
        'compute_instances',
        'review_type_registry',
        'agent_tool_registry',
        'system_configs',
        'recursion_config',
    ];

    /** Tables to disable after sync */
    private const DISABLE_RULES = [
        'scheduled_jobs' => 'UPDATE scheduled_jobs SET enabled = 0',
        'llm_instances' => 'UPDATE llm_instances SET is_active = 0',
        'workflows' => 'UPDATE workflows SET active = 0',
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('REFUSED: This command cannot run on production.');

            return Command::FAILURE;
        }

        $doAll = ! $this->option('config') && ! $this->option('seed') && ! $this->option('schema');
        $startTime = microtime(true);

        if ($doAll || $this->option('schema')) {
            $this->syncSchema();
        }

        if ($doAll || $this->option('config')) {
            $this->syncConfigTables();
        }

        if ($doAll || $this->option('seed')) {
            $this->seedTestData();
        }

        $duration = round(microtime(true) - $startTime, 1);
        $this->newLine();
        $this->info("Dev setup complete ({$duration}s). Run `php artisan ops:smoke-test` to verify.");

        return Command::SUCCESS;
    }

    private function syncSchema(): void
    {
        $this->info("\n[Schema Sync]");

        $source = $this->sourceSyncConfig(requireMysql: true);
        $localMysql = $this->localMysqlConfig();

        if ($source === null || $localMysql === null) {
            $this->warn('  Skipping schema sync; configure DEV_SETUP_SOURCE_* and local database settings first.');

            return;
        }

        // MySQL
        $this->line('  Dumping source MySQL schema...');
        $cmd = $this->sshCommand($source, $this->mysqlDumpCommand($source, ['--no-data', '--skip-add-drop-table']));
        $sql = shell_exec($cmd);

        if (empty($sql)) {
            $this->error('  Failed to dump source MySQL schema.');

            return;
        }

        // Make CREATE TABLE idempotent
        $sql = str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $sql);
        $tmpFile = tempnam(sys_get_temp_dir(), 'dev_schema_');
        file_put_contents($tmpFile, $sql);

        $importCmd = $this->mysqlImportCommand($localMysql, $tmpFile, '2>&1');
        shell_exec($importCmd);
        unlink($tmpFile);

        $count = DB::selectOne('SELECT COUNT(*) as c FROM information_schema.tables WHERE table_schema = ?', [$localMysql['database']])->c ?? 0;
        $this->info("  MySQL: {$count} tables");

        // PostgreSQL
        $sourcePg = $this->sourcePostgresConfig($source);
        $localPg = $this->localPostgresConfig();

        if ($sourcePg !== null && $localPg !== null) {
            $this->line('  Dumping source PostgreSQL schema...');
            $pgSql = shell_exec($this->sshCommand($source, $this->postgresDumpCommand($sourcePg)));

            if (! empty($pgSql)) {
                $tmpPg = tempnam(sys_get_temp_dir(), 'dev_pg_schema_');
                file_put_contents($tmpPg, $pgSql);
                shell_exec($this->postgresImportCommand($localPg, $tmpPg));
                unlink($tmpPg);
                $this->info('  PostgreSQL: schema synced');
            }
        } else {
            $this->warn('  PostgreSQL schema sync skipped; configure DEV_SETUP_SOURCE_RAG_DB_* and local pgsql_rag settings.');
        }
    }

    private function syncConfigTables(): void
    {
        $this->info("\n[Config Sync from Source]");

        $source = $this->sourceSyncConfig(requireMysql: true);
        $localMysql = $this->localMysqlConfig();

        if ($source === null || $localMysql === null) {
            $this->warn('  Skipping config sync; configure DEV_SETUP_SOURCE_* and local database settings first.');

            return;
        }

        foreach (self::CONFIG_TABLES as $table) {
            $this->line("  Syncing {$table}...");

            $cmd = $this->sshCommand($source, $this->mysqlDumpCommand($source, [
                $table,
                '--no-create-info',
                '--complete-insert',
                '--skip-extended-insert',
            ]));
            $sql = shell_exec($cmd);

            if (empty($sql) || ! str_contains($sql, 'INSERT')) {
                $this->warn("    No data for {$table}");

                continue;
            }

            // Truncate + reimport
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                DB::statement("TRUNCATE TABLE {$table}");
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e) {
                $this->warn("    Cannot truncate {$table}: ".$e->getMessage());

                continue;
            }

            $tmpFile = tempnam(sys_get_temp_dir(), "dev_{$table}_");
            file_put_contents($tmpFile, $sql);

            shell_exec($this->mysqlImportCommand($localMysql, $tmpFile));
            unlink($tmpFile);

            $count = (int) (DB::selectOne("SELECT COUNT(*) as c FROM {$table}")->c ?? 0);
            $this->info("    {$count} rows imported");
        }

        // Disable everything
        $this->line("\n  Disabling all jobs, LLM, workflows...");
        foreach (self::DISABLE_RULES as $table => $sql) {
            try {
                $affected = DB::update($sql);
                $this->info("    {$table}: {$affected} rows disabled");
            } catch (\Throwable $e) {
                $this->warn("    {$table}: ".$e->getMessage());
            }
        }
    }

    private function seedTestData(): void
    {
        $this->info("\n[Seed Test Data]");

        // file_registry: 20 test rows (images + docs) for pipeline query validation
        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff'];
        $docExts = ['pdf', 'docx', 'txt', 'csv'];
        $seeded = 0;

        $existing = (int) (DB::selectOne('SELECT COUNT(*) as c FROM file_registry')->c ?? 0);
        if ($existing > 0) {
            $this->line("  file_registry: {$existing} rows already exist, skipping");
        } else {
            foreach (array_merge($imageExts, $docExts) as $i => $ext) {
                $uuid = sprintf('test-%04d-%s', $i, $ext);
                $isImage = in_array($ext, $imageExts);

                $path = "/test/path/test_file.{$ext}";
                DB::insert("
                    INSERT INTO file_registry
                        (asset_uuid, filename, extension, current_path, path_hash, file_size, mime_type, status,
                         face_scan_at, ai_analyzed_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, NOW(), NOW())
                ", [
                    $uuid,
                    "test_file.{$ext}",
                    $ext,
                    $path,
                    hash('sha256', $path),
                    1024 * ($i + 1),
                    $isImage ? "image/{$ext}" : "application/{$ext}",
                    $isImage ? now()->toDateTimeString() : null,  // images: scanned
                    now()->toDateTimeString(),                     // all: AI analyzed
                ]);
                $seeded++;
            }
            // Add 5 "pending" images (not yet scanned/analyzed) to test backlog queries
            for ($i = 0; $i < 5; $i++) {
                $uuid = sprintf('test-pending-%04d', $i);
                $pendingPath = "/test/pending_{$i}.jpg";
                DB::insert("
                    INSERT INTO file_registry
                        (asset_uuid, filename, extension, current_path, path_hash, file_size, mime_type, status,
                         face_scan_at, ai_analyzed_at, created_at, updated_at)
                    VALUES (?, ?, 'jpg', ?, ?, 2048, 'image/jpeg', 'active', NULL, NULL, NOW(), NOW())
                ", [$uuid, "pending_{$i}.jpg", $pendingPath, hash('sha256', $pendingPath)]);
                $seeded++;
            }
            $this->info("  file_registry: {$seeded} test rows seeded (5 pending, {$seeded} total)");
        }

        // rag_documents: 5 test rows for PostgreSQL query validation
        try {
            $ragExisting = (int) (DB::connection('pgsql_rag')->selectOne('SELECT COUNT(*) as c FROM rag_documents')->c ?? 0);
            if ($ragExisting > 0) {
                $this->line("  rag_documents: {$ragExisting} rows already exist, skipping");
            } else {
                $ragSeeded = 0;
                $lengths = [200, 800, 1500, 3000, 5000]; // Spread across screening thresholds
                foreach ($lengths as $i => $len) {
                    $content = str_repeat('Test document content for smoke testing. ', (int) ceil($len / 40));
                    $embedding = '['.implode(',', array_fill(0, 768, '0.01')).']';

                    DB::connection('pgsql_rag')->insert("
                        INSERT INTO rag_documents
                            (document_type, title, content, embedding, source_type, created_at, updated_at,
                             raptor_eligible, se_eligible)
                        VALUES (?, ?, ?, ?::vector, 'test', NOW(), NOW(), ?, ?)
                    ", [
                        'test',
                        "Test Document {$i}",
                        substr($content, 0, $len),
                        $embedding,
                        $len >= 4000 ? 1 : ($len < 1000 ? 0 : null),
                        $len >= 2000 ? 1 : ($len < 500 ? 0 : null),
                    ]);
                    $ragSeeded++;
                }
                $this->info("  rag_documents: {$ragSeeded} test rows seeded");
            }
        } catch (\Throwable $e) {
            $this->warn('  rag_documents: '.$e->getMessage());
        }
    }

    /**
     * @return array<string,string>|null
     */
    private function sourceSyncConfig(bool $requireMysql): ?array
    {
        $source = [
            'ssh_host' => $this->envString('DEV_SETUP_SOURCE_SSH_HOST'),
            'ssh_user' => $this->envString('DEV_SETUP_SOURCE_SSH_USER', get_current_user() ?: ''),
            'mysql_host' => $this->envString('DEV_SETUP_SOURCE_MYSQL_HOST', '127.0.0.1'),
            'mysql_port' => $this->envString('DEV_SETUP_SOURCE_MYSQL_PORT', '3306'),
            'mysql_database' => $this->envString('DEV_SETUP_SOURCE_MYSQL_DATABASE'),
            'mysql_username' => $this->envString('DEV_SETUP_SOURCE_MYSQL_USERNAME'),
            'mysql_password' => $this->envString('DEV_SETUP_SOURCE_MYSQL_PASSWORD'),
        ];

        $required = ['ssh_host', 'ssh_user'];
        if ($requireMysql) {
            array_push($required, 'mysql_database', 'mysql_username', 'mysql_password');
        }

        foreach ($required as $key) {
            if ($source[$key] === '') {
                $this->warn("  Missing DEV_SETUP_SOURCE_* value for {$key}.");

                return null;
            }
        }

        return $source;
    }

    /**
     * @param  array<string,string>  $source
     * @return array<string,string>|null
     */
    private function sourcePostgresConfig(array $source): ?array
    {
        $pg = [
            'ssh_host' => $source['ssh_host'],
            'ssh_user' => $source['ssh_user'],
            'host' => $this->envString('DEV_SETUP_SOURCE_RAG_DB_HOST', '127.0.0.1'),
            'port' => $this->envString('DEV_SETUP_SOURCE_RAG_DB_PORT', '5432'),
            'database' => $this->envString('DEV_SETUP_SOURCE_RAG_DB_DATABASE'),
            'username' => $this->envString('DEV_SETUP_SOURCE_RAG_DB_USERNAME'),
            'password' => $this->envString('DEV_SETUP_SOURCE_RAG_DB_PASSWORD'),
        ];

        foreach (['database', 'username', 'password'] as $key) {
            if ($pg[$key] === '') {
                return null;
            }
        }

        return $pg;
    }

    /**
     * @return array<string,string>|null
     */
    private function localMysqlConfig(): ?array
    {
        $config = [
            'host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
            'port' => (string) config('database.connections.mysql.port', '3306'),
            'database' => (string) config('database.connections.mysql.database', ''),
            'username' => (string) config('database.connections.mysql.username', ''),
            'password' => (string) config('database.connections.mysql.password', ''),
        ];

        foreach (['database', 'username'] as $key) {
            if ($config[$key] === '') {
                $this->warn("  Missing local MySQL {$key} configuration.");

                return null;
            }
        }

        return $config;
    }

    /**
     * @return array<string,string>|null
     */
    private function localPostgresConfig(): ?array
    {
        $connection = config('database.connections.pgsql_rag', []);

        if (! is_array($connection)) {
            return null;
        }

        $config = [
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '5432'),
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
        ];

        foreach (['database', 'username'] as $key) {
            if ($config[$key] === '') {
                return null;
            }
        }

        return $config;
    }

    /**
     * @param  array<string,string>  $source
     */
    private function sshCommand(array $source, string $remoteCommand): string
    {
        $target = $source['ssh_user'].'@'.$source['ssh_host'];

        return 'ssh '.escapeshellarg($target).' '.escapeshellarg($remoteCommand);
    }

    /**
     * @param  array<string,string>  $source
     * @param  array<int,string>  $extraArgs
     */
    private function mysqlDumpCommand(array $source, array $extraArgs): string
    {
        $args = [
            'mysqldump',
            '-h', $source['mysql_host'],
            '-P', $source['mysql_port'],
            '-u', $source['mysql_username'],
            $source['mysql_database'],
            ...$extraArgs,
        ];

        return 'MYSQL_PWD='.escapeshellarg($source['mysql_password']).' '.$this->shellJoin($args).' 2>/dev/null';
    }

    /**
     * @param  array<string,string>  $config
     */
    private function mysqlImportCommand(array $config, string $path, string $redirect = '2>/dev/null'): string
    {
        $args = [
            'mysql',
            '-h', $config['host'],
            '-P', $config['port'],
            '-u', $config['username'],
            $config['database'],
        ];

        return 'MYSQL_PWD='.escapeshellarg($config['password']).' '.$this->shellJoin($args).' < '.escapeshellarg($path).' '.$redirect;
    }

    /**
     * @param  array<string,string>  $config
     */
    private function postgresDumpCommand(array $config): string
    {
        $args = [
            'pg_dump',
            '-h', $config['host'],
            '-p', $config['port'],
            '-U', $config['username'],
            '-d', $config['database'],
            '--schema-only',
        ];

        return 'PGPASSWORD='.escapeshellarg($config['password']).' '.$this->shellJoin($args).' 2>/dev/null';
    }

    /**
     * @param  array<string,string>  $config
     */
    private function postgresImportCommand(array $config, string $path): string
    {
        $args = [
            'psql',
            '-h', $config['host'],
            '-p', $config['port'],
            '-U', $config['username'],
            '-d', $config['database'],
        ];

        return 'PGPASSWORD='.escapeshellarg($config['password']).' '.$this->shellJoin($args).' < '.escapeshellarg($path).' 2>/dev/null';
    }

    /**
     * @param  array<int,string>  $parts
     */
    private function shellJoin(array $parts): string
    {
        return implode(' ', array_map('escapeshellarg', $parts));
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = env($key, $default);

        return is_string($value) ? trim($value) : $default;
    }
}
