<?php

namespace App\Console\Commands;

use App\Services\NextcloudFileApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * N130: Nextcloud maintenance command.
 *
 * Wraps docker exec nextcloud-app php occ commands for routine maintenance.
 * Nextcloud is the file storage backbone for PLOS libraries.
 *
 * Recommended schedule: weekly Sunday 3:00 AM (before 4 AM ops_maintenance).
 */
class NextcloudMaintenanceCommand extends Command
{
    protected $signature = 'nextcloud:maintenance
                            {--status : Show Nextcloud health status}
                            {--scan : Rescan filesystem for new/changed/deleted files}
                            {--path= : With --scan, rescan one existing configured Nextcloud library file}
                            {--cleanup : Clean orphaned filecache and mount entries}
                            {--repair : Run maintenance:repair}
                            {--db-check : Check and add missing DB indices/columns/keys}
                            {--mimetype : Update MIME type mappings}
                            {--trashbin : Expire the configured user\'s trashbin using its retention policy}
                            {--purge-trashbin : Permanently remove all deleted files for the configured user; excluded from --full}
                            {--previews : Clean up old generated previews}
                            {--full : Run routine maintenance in sequence, excluding trashbin purge}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Nextcloud Docker maintenance — file scans, cleanup, repair, DB checks';

    private const CONTAINER = 'nextcloud-app';

    private const DEFAULT_OCC_TIMEOUT_SECONDS = 300;

    private const FILE_SCAN_TIMEOUT_SECONDS = 1800;

    private const DOCKER_INSPECT_TIMEOUT_SECONDS = 30;

    private const DOCKER_INSPECT_RETRIES = 2;

    private const DOCKER_STATUS_TIMEOUT_SECONDS = 30;

    private int $tasksPassed = 0;

    private int $tasksFailed = 0;

    private array $results = [];

    private function getStorageRoot(): string
    {
        return rtrim(config('services.storage.root', '/srv/nextcloud'), '/');
    }

    private function getOccUser(): string
    {
        return (string) config('services.nextcloud.occ_user', config('services.nextcloud.username', 'plos'));
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $full = $this->option('full');
        $scanPath = $this->option('path');
        $scopedScanArgs = null;

        if ($scanPath !== null) {
            if (! $this->option('scan') || $full) {
                $this->error('--path requires --scan and cannot be combined with --full.');

                return Command::FAILURE;
            }

            $scopedScanArgs = $this->buildScopedScanArgs((string) $scanPath);
            if ($scopedScanArgs === null) {
                $this->error('The requested scan path is not a contained, readable Nextcloud source file.');

                return Command::FAILURE;
            }
        }

        // Default to --status if no options given
        $hasOption = $full || $this->option('status') || $this->option('scan')
            || $this->option('cleanup') || $this->option('repair')
            || $this->option('db-check') || $this->option('mimetype')
            || $this->option('trashbin') || $this->option('purge-trashbin') || $this->option('previews');

        if (! $hasOption) {
            $this->info('No option specified — running --status. Use --full for complete maintenance.');
            $this->line('');

            return $this->runStatus();
        }

        // Verify container is running before any maintenance
        if (! $this->isContainerRunning()) {
            $this->error("Docker container '".self::CONTAINER."' is not running.");
            Log::error('nextcloud:maintenance — container not running');

            return Command::FAILURE;
        }

        if ($this->option('status') || $full) {
            $this->runStatus();
            $this->line('');
        }

        if ($full || $this->option('db-check')) {
            $this->runTask('DB: Missing Indices', 'db:add-missing-indices', $dryRun);
            $this->runTask('DB: Missing Columns', 'db:add-missing-columns', $dryRun);
            $this->runTask('DB: Missing Primary Keys', 'db:add-missing-primary-keys', $dryRun);
        }

        if ($full || $this->option('mimetype')) {
            $this->runTask('MIME Type Update', 'maintenance:mimetype:update-db', $dryRun);
        }

        if ($full || $this->option('scan')) {
            $this->runTask(
                'File Scan',
                $scopedScanArgs ?? 'files:scan '.$this->getOccUser().' --no-interaction',
                $dryRun
            );
        }

        if ($full || $this->option('cleanup')) {
            $this->runTask('File Cleanup', 'files:cleanup --no-interaction', $dryRun);
            $this->runTask('File Tree Repair', 'files:repair-tree --no-interaction', $dryRun);
        }

        if ($full || $this->option('trashbin')) {
            $this->runTask('Trashbin Expire', 'trashbin:expire '.$this->getOccUser(), $dryRun);
        }

        if ($this->option('purge-trashbin')) {
            $this->runTask('Trashbin Purge', 'trashbin:cleanup '.$this->getOccUser(), $dryRun);
        }

        if ($full || $this->option('previews')) {
            $this->runTask('Preview Cleanup', 'preview:cleanup', $dryRun);
        }

        if ($full || $this->option('repair')) {
            $this->runTask('Maintenance Repair', 'maintenance:repair --no-interaction', $dryRun);
            $this->runTask('Share Owner Repair', 'maintenance:repair-share-owner --no-interaction', $dryRun);
        }

        // Summary
        $this->line('');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $total = $this->tasksPassed + $this->tasksFailed;
        $status = $this->tasksFailed === 0 ? 'ALL PASSED' : "{$this->tasksFailed} FAILED";
        $this->info("Nextcloud Maintenance: {$total} tasks — {$status}");

        if ($dryRun) {
            $this->line('(dry-run mode — no changes made)');
        }

        // Log results
        $logData = [
            'tasks_passed' => $this->tasksPassed,
            'tasks_failed' => $this->tasksFailed,
            'dry_run' => $dryRun,
            'results' => $this->results,
        ];

        if ($this->tasksFailed > 0) {
            Log::warning('nextcloud:maintenance completed with failures', $logData);
        } else {
            Log::info('nextcloud:maintenance completed', $logData);
        }

        $this->info("[ITEMS_PROCESSED:{$total}]");

        return $this->tasksFailed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function runStatus(): int
    {
        $this->info('━━━ Nextcloud Status ━━━');

        // Container health
        if (! $this->isContainerRunning()) {
            $this->error('Container: NOT RUNNING');

            return Command::FAILURE;
        }

        $uptime = $this->getContainerUptime();
        $this->line("Container: UP ({$uptime})");

        // Nextcloud version & status
        $status = $this->occ('status --output=json');
        if ($status !== null) {
            $data = @json_decode($status, true);
            if ($data) {
                $this->line(sprintf(
                    'Version: %s | Maintenance: %s | DB Upgrade: %s',
                    $data['versionstring'] ?? '?',
                    ($data['maintenance'] ?? false) ? 'ON' : 'off',
                    ($data['needsDbUpgrade'] ?? false) ? 'NEEDED' : 'no'
                ));
            }
        }

        // Cron status
        $lastCron = $this->occ('config:app:get core lastcron');
        if ($lastCron !== null) {
            $ts = (int) trim($lastCron);
            if ($ts > 0) {
                $age = time() - $ts;
                $ageMin = round($age / 60);
                $healthy = $age < 900; // 15 min threshold
                $this->line(sprintf(
                    'Cron: last %d min ago [%s]',
                    $ageMin,
                    $healthy ? 'OK' : 'STALE'
                ));
            }
        }

        // Cron container
        $cronRunning = $this->isContainerRunning('nextcloud-cron');
        $this->line('Cron Container: '.($cronRunning ? 'UP' : 'NOT RUNNING'));

        // Disk usage
        $storageRoot = $this->getStorageRoot();
        $storageFree = @disk_free_space($storageRoot);
        $storageTotal = @disk_total_space($storageRoot);
        if ($storageTotal && $storageTotal > 0) {
            $usedPct = round(($storageTotal - $storageFree) / $storageTotal * 100);
            $freeGb = round($storageFree / 1073741824);
            $this->line("Storage: {$usedPct}% used ({$freeGb}GB free)");
        }

        // File counts from Nextcloud
        $fileCount = $this->occ('files:scan --count '.$this->getOccUser());
        // Not all NC versions support --count, so just show if available

        // Docker disk
        $dockerDf = $this->dockerSystemDf();
        if ($dockerDf) {
            $this->line('');
            $this->info('━━━ Docker Disk ━━━');
            foreach (explode("\n", trim($dockerDf)) as $line) {
                $this->line($line);
            }
        }

        // Recent NC log errors (last 24h)
        $this->line('');
        $this->info('━━━ Recent Nextcloud Errors ━━━');
        $logOutput = Process::timeout(30)->run([
            'docker',
            'exec',
            self::CONTAINER,
            'tail',
            '-100',
            '/var/www/html/data/nextcloud.log',
        ])->output();
        if ($logOutput) {
            $errorCount = 0;
            $warnCount = 0;
            $cutoff = time() - 86400;
            foreach (explode("\n", $logOutput) as $line) {
                $entry = @json_decode($line, true);
                if (! $entry) {
                    continue;
                }
                $entryTime = strtotime($entry['time'] ?? '') ?: 0;
                if ($entryTime < $cutoff) {
                    continue;
                }
                $level = (int) ($entry['level'] ?? 0);
                if ($level >= 3) {
                    $errorCount++;
                } elseif ($level >= 2) {
                    $warnCount++;
                }
            }
            $this->line("Last 24h: {$errorCount} errors, {$warnCount} warnings");
        } else {
            $this->line('Could not read Nextcloud log');
        }

        return Command::SUCCESS;
    }

    private function dockerSystemDf(): ?string
    {
        try {
            $result = Process::timeout(self::DOCKER_STATUS_TIMEOUT_SECONDS)->run([
                'docker',
                'system',
                'df',
                '--format',
                'table {{.Type}}\t{{.Size}}\t{{.Reclaimable}}',
            ]);
        } catch (\Throwable $e) {
            Log::warning('nextcloud:maintenance docker disk status timed out', [
                'timeout_seconds' => self::DOCKER_STATUS_TIMEOUT_SECONDS,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $result->successful()) {
            Log::warning('nextcloud:maintenance docker disk status failed', [
                'exit_code' => $result->exitCode(),
                'error' => trim($result->errorOutput() ?: $result->output()),
            ]);

            return null;
        }

        return $result->output();
    }

    private function runTask(string $name, string|array $occCommand, bool $dryRun): void
    {
        $this->line('');
        $this->info("► {$name}");

        if ($dryRun) {
            $this->line('  [dry-run] would execute: occ '.$this->formatOccCommand($occCommand));
            $this->results[$name] = 'dry-run';
            $this->tasksPassed++;

            return;
        }

        $startTime = microtime(true);
        $errorOutput = null;
        $output = $this->occ($occCommand, $this->resolveOccTimeout($occCommand), $errorOutput);
        $elapsed = round(microtime(true) - $startTime, 1);

        if ($output === null) {
            $this->error("  FAILED ({$elapsed}s)");
            if (! empty($errorOutput)) {
                $errorLines = array_slice(array_filter(explode("\n", trim($errorOutput))), 0, 8);
                foreach ($errorLines as $line) {
                    $this->line("  {$line}");
                }
            }
            $this->results[$name] = $errorOutput ? 'failed: '.substr(trim($errorOutput), 0, 240) : 'failed';
            $this->tasksFailed++;

            return;
        }

        // Show output (trimmed)
        $trimmed = trim($output);
        $lines = $trimmed !== '' ? array_filter(explode("\n", $trimmed)) : [];
        $lineCount = count($lines);
        if ($lineCount > 0) {
            // Show first 10 lines, summarize rest
            $show = array_slice($lines, 0, 10);
            foreach ($show as $line) {
                $this->line("  {$line}");
            }
            if ($lineCount > 10) {
                $this->line('  ... and '.($lineCount - 10).' more lines');
            }
        } else {
            $this->line('  OK (no output)');
        }

        $this->line("  Completed in {$elapsed}s");
        $this->results[$name] = "ok ({$elapsed}s)";
        $this->tasksPassed++;
    }

    private function resolveOccTimeout(string|array $command): int
    {
        $commandName = is_array($command) ? ($command[0] ?? '') : $command;
        if (str_starts_with($commandName, 'files:scan')) {
            return self::FILE_SCAN_TIMEOUT_SECONDS;
        }

        return self::DEFAULT_OCC_TIMEOUT_SECONDS;
    }

    /**
     * Execute an occ command inside the Nextcloud container.
     */
    private function occ(string|array $command, ?int $timeoutSeconds = null, ?string &$errorOutput = null): ?string
    {
        $args = is_array($command) ? array_values($command) : (preg_split('/\s+/', trim($command)) ?: []);
        $result = Process::timeout($timeoutSeconds ?? self::DEFAULT_OCC_TIMEOUT_SECONDS)->run(array_merge([
            'docker',
            'exec',
            self::CONTAINER,
            'php',
            'occ',
        ], $args));

        if (! $result->successful()) {
            $errorOutput = trim($result->errorOutput() ?: $result->output());
            Log::warning('nextcloud:maintenance occ failed', [
                'command' => is_array($command) ? $this->formatOccCommand($command) : $command,
                'timeout_seconds' => $timeoutSeconds ?? self::DEFAULT_OCC_TIMEOUT_SECONDS,
                'exit_code' => $result->exitCode(),
                'error' => $errorOutput,
            ]);

            return null;
        }

        $errorOutput = trim($result->errorOutput());

        return rtrim($result->output());
    }

    /** Build an OCC argv for one existing file below the configured library root. */
    private function buildScopedScanArgs(string $requestedPath): ?array
    {
        if ($requestedPath === '' || strlen($requestedPath) > 4096
            || $requestedPath[0] !== '/' || str_contains($requestedPath, "\0")
            || str_contains($requestedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $requestedPath)
            || str_contains($requestedPath, '//') || str_ends_with($requestedPath, '/')) {
            return null;
        }

        $libraryRoot = config('services.nextcloud.library_root');
        if (! is_string($libraryRoot) || $libraryRoot === '' || $libraryRoot[0] !== '/' || $libraryRoot === '/'
            || str_contains($libraryRoot, '\\') || str_contains($libraryRoot, '//')
            || preg_match('/[\x00-\x20\x7f]/', $libraryRoot)) {
            return null;
        }
        $libraryParts = explode('/', trim($libraryRoot, '/'));
        if (in_array('', $libraryParts, true) || in_array('.', $libraryParts, true) || in_array('..', $libraryParts, true)) {
            return null;
        }
        $libraryPrefix = rtrim($libraryRoot, '/');
        if (strlen($requestedPath) <= strlen($libraryPrefix)
            || strncasecmp($requestedPath, $libraryPrefix, strlen($libraryPrefix)) !== 0
            || $requestedPath[strlen($libraryPrefix)] !== '/') {
            return null;
        }
        $parts = explode('/', ltrim($requestedPath, '/'));
        if (in_array('.', $parts, true) || in_array('..', $parts, true)) {
            return null;
        }

        $user = $this->getOccUser();
        if ($user === '' || strpbrk($user, '/\\') !== false || preg_match('/[\x00-\x20\x7f]/', $user)) {
            return null;
        }

        $localPath = app(NextcloudFileApiService::class)->localPath($requestedPath);
        $configuredRoot = config('services.nextcloud.data_path');
        if (! is_string($configuredRoot) || $configuredRoot === '' || $localPath === null) {
            return null;
        }

        $root = realpath($configuredRoot);
        $resolved = realpath($localPath);
        if ($root === false || $resolved === false || ! is_file($resolved) || ! is_readable($resolved)
            || ! str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            return null;
        }

        return ['files:scan', '--path='.$user.'/files'.$requestedPath, '--no-interaction'];
    }

    private function formatOccCommand(string|array $command): string
    {
        if (! is_array($command)) {
            return $command;
        }

        return implode(' ', array_map(static function (string $argument): string {
            return preg_match('/\s/', $argument) ? escapeshellarg($argument) : $argument;
        }, $command));
    }

    private function isContainerRunning(string $container = self::CONTAINER): bool
    {
        $output = $this->dockerInspect($container, '{{.State.Running}}');

        return $output !== null && trim($output) === 'true';
    }

    private function getContainerUptime(): string
    {
        $output = $this->dockerInspect(self::CONTAINER, '{{.State.StartedAt}}');
        if ($output === null || $output === '') {
            return '?';
        }

        $started = strtotime(trim($output));
        if (! $started) {
            return '?';
        }

        $age = time() - $started;
        if ($age < 3600) {
            return round($age / 60).'m';
        }
        if ($age < 86400) {
            return round($age / 3600, 1).'h';
        }

        return round($age / 86400, 1).'d';
    }

    /**
     * Docker socket can stall under load (concurrent file_scan, disk pressure).
     * Retry on timeout so a slow inspect doesn't abort the whole maintenance run.
     * Returns null when every attempt times out or the inspect fails.
     */
    private function dockerInspect(string $container, string $format): ?string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::DOCKER_INSPECT_RETRIES; $attempt++) {
            try {
                $result = Process::timeout(self::DOCKER_INSPECT_TIMEOUT_SECONDS)->run([
                    'docker',
                    'inspect',
                    '-f',
                    $format,
                    $container,
                ]);
                if ($result->successful()) {
                    return $result->output();
                }
                $lastError = trim($result->errorOutput() ?: $result->output());
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('nextcloud:maintenance docker inspect timed out', [
                    'container' => $container,
                    'attempt' => $attempt,
                    'timeout_seconds' => self::DOCKER_INSPECT_TIMEOUT_SECONDS,
                ]);
            }
        }

        Log::warning('nextcloud:maintenance docker inspect gave up', [
            'container' => $container,
            'attempts' => self::DOCKER_INSPECT_RETRIES,
            'error' => $lastError,
        ]);

        return null;
    }
}
