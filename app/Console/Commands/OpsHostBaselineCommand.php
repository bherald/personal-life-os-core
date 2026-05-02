<?php

namespace App\Console\Commands;

use App\Contracts\HostCommandRunner;
use App\Services\ShellExecHostCommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;

class OpsHostBaselineCommand extends Command
{
    protected $signature = 'ops:host-baseline
                            {scenario : idle|jobs|deploy}
                            {--repeat=1 : number of captures}
                            {--interval=30 : seconds between captures}';

    protected $description = 'Capture raw host and app baseline telemetry to storage/logs/host-baselines/';

    public function handle(): int
    {
        $scenario = trim((string) $this->argument('scenario'));
        if (! in_array($scenario, ['idle', 'jobs', 'deploy'], true)) {
            $this->error('Scenario must be one of: idle, jobs, deploy');

            return self::FAILURE;
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $interval = max(0, (int) $this->option('interval'));
        $directory = storage_path('logs/host-baselines');

        File::ensureDirectoryExists($directory);

        for ($iteration = 1; $iteration <= $repeat; $iteration++) {
            $capturedAt = now()->utc();
            $payload = $this->buildPayload($scenario, $iteration, $repeat, $capturedAt);
            $path = $this->reserveOutputPath($directory, $scenario, $capturedAt);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                $this->error('Failed to encode host baseline JSON.');

                return self::FAILURE;
            }

            File::put($path, $json.PHP_EOL);
            $this->line('Wrote '.$path);

            if ($iteration < $repeat && $interval > 0) {
                sleep($interval);
            }
        }

        return self::SUCCESS;
    }

    private function buildPayload(string $scenario, int $iteration, int $totalIterations, \Illuminate\Support\Carbon $capturedAt): array
    {
        $payload = [
            'version' => 1,
            'captured_at' => $capturedAt->format('Y-m-d\TH:i:s\Z'),
            'scenario' => $scenario,
            'host' => $this->resolveHostName(),
            'iteration' => $iteration,
            'total_iterations' => $totalIterations,
            'uptime' => $this->readCommand('uptime') ?? '',
            'vmstat' => $this->parseVmstat($this->readCommand('vmstat 1 2 | tail -1')),
            'memory_mb' => $this->parseFreeMemory($this->readCommand('free -m')),
            'disk' => $this->parseDiskUsage($this->readCommand('df -BG /')),
            'top_memory_procs' => $this->parseProcessList($this->readCommand('ps aux --sort=-%mem | head -20')),
            'top_cpu_procs' => $this->parseProcessList($this->readCommand('ps aux --sort=-%cpu | head -20')),
            'gpu' => $this->parseGpuRows(
                $this->readCommand(
                    'nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu,temperature.gpu --format=csv,noheader,nounits'
                )
            ),
            'app' => [
                'queue_depths' => $this->collectQueueDepths(),
                'running_jobs' => $this->collectRunningJobs(),
                'horizon_workers' => $this->collectHorizonWorkers(),
            ],
        ];

        if ($scenario === 'deploy') {
            $payload['deploy'] = $this->collectDeploySnapshot();
        }

        return $payload;
    }

    private function reserveOutputPath(string $directory, string $scenario, \Illuminate\Support\Carbon $capturedAt): string
    {
        $timestamp = $capturedAt->format('Ymd-His');
        $path = $directory.'/'.$scenario.'-'.$timestamp.'.json';

        while (File::exists($path)) {
            sleep(1);
            $timestamp = now()->utc()->format('Ymd-His');
            $path = $directory.'/'.$scenario.'-'.$timestamp.'.json';
        }

        return $path;
    }

    private function resolveHostName(): string
    {
        $host = $this->readCommand('hostname -s');
        if ($host !== null && $host !== '') {
            return $host;
        }

        $fallback = gethostname() ?: 'unknown-host';

        return explode('.', $fallback)[0];
    }

    private function readCommand(string $command): ?string
    {
        return $this->resolveCommandRunner()->run($command, 5);
    }

    private function parseVmstat(?string $output): array
    {
        $keys = [
            'running_procs',
            'blocked_procs',
            'swap_used_kb',
            'memory_free_kb',
            'buffer_kb',
            'cache_kb',
            'swap_in_per_s',
            'swap_out_per_s',
            'blocks_in_per_s',
            'blocks_out_per_s',
            'interrupts_per_s',
            'context_switches_per_s',
            'cpu_user_percent',
            'cpu_system_percent',
            'cpu_idle_percent',
            'cpu_wait_percent',
            'cpu_stolen_percent',
        ];

        $values = preg_split('/\s+/', trim((string) $output));
        $parsed = [];

        foreach ($keys as $index => $key) {
            $parsed[$key] = isset($values[$index]) && is_numeric($values[$index])
                ? (int) $values[$index]
                : null;
        }

        return $parsed;
    }

    private function parseFreeMemory(?string $output): array
    {
        $parsed = [
            'total' => null,
            'used' => null,
            'free' => null,
            'available' => null,
            'swap_total' => null,
            'swap_used' => null,
            'swap_free' => null,
        ];

        $lines = preg_split('/\r?\n/', trim((string) $output)) ?: [];
        foreach ($lines as $line) {
            $tokens = preg_split('/\s+/', trim($line)) ?: [];
            if ($tokens === []) {
                continue;
            }

            if (($tokens[0] ?? '') === 'Mem:') {
                $parsed['total'] = isset($tokens[1]) && is_numeric($tokens[1]) ? (int) $tokens[1] : null;
                $parsed['used'] = isset($tokens[2]) && is_numeric($tokens[2]) ? (int) $tokens[2] : null;
                $parsed['free'] = isset($tokens[3]) && is_numeric($tokens[3]) ? (int) $tokens[3] : null;
                $parsed['available'] = isset($tokens[6]) && is_numeric($tokens[6]) ? (int) $tokens[6] : null;
            }

            if (($tokens[0] ?? '') === 'Swap:') {
                $parsed['swap_total'] = isset($tokens[1]) && is_numeric($tokens[1]) ? (int) $tokens[1] : null;
                $parsed['swap_used'] = isset($tokens[2]) && is_numeric($tokens[2]) ? (int) $tokens[2] : null;
                $parsed['swap_free'] = isset($tokens[3]) && is_numeric($tokens[3]) ? (int) $tokens[3] : null;
            }
        }

        return $parsed;
    }

    private function parseDiskUsage(?string $output): array
    {
        $parsed = [
            'filesystem' => null,
            'total_gb' => null,
            'used_gb' => null,
            'available_gb' => null,
            'use_percent' => null,
            'mount' => null,
        ];

        $lines = preg_split('/\r?\n/', trim((string) $output)) ?: [];
        $line = $lines[1] ?? null;
        if ($line === null) {
            return $parsed;
        }

        $tokens = preg_split('/\s+/', trim($line)) ?: [];
        if (count($tokens) < 6) {
            return $parsed;
        }

        $parsed['filesystem'] = $tokens[0];
        $parsed['total_gb'] = $this->parseIntegerValue($tokens[1]);
        $parsed['used_gb'] = $this->parseIntegerValue($tokens[2]);
        $parsed['available_gb'] = $this->parseIntegerValue($tokens[3]);
        $parsed['use_percent'] = $this->parseIntegerValue($tokens[4]);
        $parsed['mount'] = $tokens[5];

        return $parsed;
    }

    private function parseProcessList(?string $output): array
    {
        $rows = [];
        $lines = preg_split('/\r?\n/', trim((string) $output)) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'USER ')) {
                continue;
            }

            $tokens = preg_split('/\s+/', $trimmed, 11) ?: [];
            if (count($tokens) < 11) {
                continue;
            }

            $rows[] = [
                'user' => $tokens[0],
                'pid' => is_numeric($tokens[1]) ? (int) $tokens[1] : null,
                'cpu_percent' => is_numeric($tokens[2]) ? (float) $tokens[2] : null,
                'mem_percent' => is_numeric($tokens[3]) ? (float) $tokens[3] : null,
                'vsz_kb' => is_numeric($tokens[4]) ? (int) $tokens[4] : null,
                'rss_kb' => is_numeric($tokens[5]) ? (int) $tokens[5] : null,
                'tty' => $tokens[6],
                'stat' => $tokens[7],
                'start' => $tokens[8],
                'time' => $tokens[9],
                'command' => mb_substr($tokens[10], 0, 240),
            ];
        }

        return $rows;
    }

    private function parseGpuRows(?string $output): array
    {
        if ($output === null || trim($output) === '') {
            return [];
        }

        $rows = [];
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];

        foreach ($lines as $line) {
            $columns = array_map('trim', str_getcsv($line));
            if (count($columns) < 6) {
                continue;
            }

            $rows[] = [
                'name' => $columns[0],
                'memory_total_mb' => $this->parseIntegerValue($columns[1]),
                'memory_used_mb' => $this->parseIntegerValue($columns[2]),
                'memory_free_mb' => $this->parseIntegerValue($columns[3]),
                'utilization_gpu_percent' => $this->parseIntegerValue($columns[4]),
                'temperature_c' => $this->parseIntegerValue($columns[5]),
            ];
        }

        return $rows;
    }

    private function collectQueueDepths(): array
    {
        $queueNames = $this->resolveQueueNames();

        if (config('queue.default') !== 'redis') {
            return array_fill_keys($queueNames, 0);
        }

        try {
            $connectionName = config('queue.connections.redis.connection', 'default');
            $redis = Redis::connection($connectionName);
            $depths = [];

            foreach ($queueNames as $queueName) {
                $pending = $redis->llen("queues:{$queueName}") ?? 0;
                $delayed = $redis->zcard("queues:{$queueName}:delayed") ?? 0;
                $reserved = $redis->zcard("queues:{$queueName}:reserved") ?? 0;

                $depths[$queueName] = (int) $pending + (int) $delayed + (int) $reserved;
            }

            return $depths;
        } catch (\Throwable) {
            return array_fill_keys($queueNames, 0);
        }
    }

    private function resolveQueueNames(): array
    {
        $names = [config('queue.connections.redis.queue', 'default')];

        foreach ((array) config('horizon.defaults', []) as $supervisor) {
            foreach ((array) ($supervisor['queue'] ?? []) as $queueName) {
                $names[] = (string) $queueName;
            }
        }

        foreach ((array) config('horizon.waits', []) as $key => $_threshold) {
            if (is_string($key) && str_contains($key, ':')) {
                $names[] = substr($key, strrpos($key, ':') + 1);
            }
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($name): string => trim((string) $name),
            $names
        ))));
    }

    private function collectRunningJobs(): array
    {
        try {
            $rows = DB::select(
                "SELECT id, name, last_run_at
                 FROM scheduled_jobs
                 WHERE last_run_status = 'running'
                 ORDER BY last_run_at ASC"
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(static function (object $row): array {
            return [
                'id' => (int) ($row->id ?? 0),
                'name' => (string) ($row->name ?? ''),
                'last_run_at' => isset($row->last_run_at) ? (string) $row->last_run_at : null,
            ];
        }, $rows);
    }

    private function collectHorizonWorkers(): ?int
    {
        foreach (['http://127.0.0.1/horizon/api/workers', 'http://localhost/horizon/api/workers'] as $url) {
            $response = $this->readCommand('curl -fsS --connect-timeout 2 --max-time 3 '.escapeshellarg($url));
            if ($response === null) {
                continue;
            }

            $decoded = json_decode($response, true);
            if (! is_array($decoded)) {
                continue;
            }

            if (array_is_list($decoded)) {
                return count($decoded);
            }

            if (isset($decoded['workers']) && is_array($decoded['workers'])) {
                return count($decoded['workers']);
            }
        }

        return null;
    }

    private function collectDeploySnapshot(): array
    {
        $gitProcesses = [];
        $pgrepOutput = $this->readCommand('pgrep -af git');
        $lines = preg_split('/\r?\n/', trim((string) $pgrepOutput)) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed, 2) ?: [];
            $command = $parts[1] ?? '';
            if ($this->isSelfGitProbeProcess($command)) {
                continue;
            }

            $gitProcesses[] = [
                'pid' => isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : null,
                'command' => $command,
            ];
        }

        $lockPath = base_path('.git/index.lock');
        $lockPresent = File::exists($lockPath);

        return [
            'git_processes' => $gitProcesses,
            'index_lock' => [
                'present' => $lockPresent,
                'age_seconds' => $lockPresent ? max(0, time() - File::lastModified($lockPath)) : null,
            ],
        ];
    }

    private function isSelfGitProbeProcess(string $command): bool
    {
        if ($command === '') {
            return false;
        }

        return str_contains($command, 'pgrep -af git')
            || str_contains($command, 'timeout 5 bash -lc pgrep')
            || str_contains($command, 'ops:host-baseline');
    }

    private function parseIntegerValue(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-]/', '', $value);
        if ($normalized === null || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function resolveCommandRunner(): HostCommandRunner
    {
        if (app()->bound(HostCommandRunner::class)) {
            return app(HostCommandRunner::class);
        }

        return new ShellExecHostCommandRunner;
    }
}
