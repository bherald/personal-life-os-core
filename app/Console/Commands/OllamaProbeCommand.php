<?php

namespace App\Console\Commands;

use App\Contracts\HostCommandRunner;
use App\Services\ShellExecHostCommandRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OllamaProbeCommand extends Command
{
    protected $signature = 'ollama:probe
        {subcommand : health|fit|tokenize}
        {--model= : model name (required for fit and tokenize)}
        {--context=4096 : context length in tokens (fit only)}
        {--prompt= : prompt text (tokenize only); mutually exclusive with --file}
        {--file= : path to a local file whose contents to tokenize (tokenize only)}';

    protected $description = 'Read-only Ollama diagnostic probe (subcommands: health, fit, tokenize). Emits JSON envelope to stdout.';

    public function handle(): int
    {
        $subcommand = trim((string) $this->argument('subcommand'));

        switch ($subcommand) {
            case 'health':
                $envelope = $this->buildHealthEnvelope();
                break;

            case 'fit':
                $envelope = $this->buildFitEnvelope();
                if ($envelope === null) {
                    return 2;
                }
                break;

            case 'tokenize':
                $envelope = $this->buildTokenizeEnvelope();
                if ($envelope === null) {
                    return 2;
                }
                break;

            default:
                $this->error(sprintf('Subcommand "%s" is not implemented in this slice.', $subcommand));

                return 2;
        }

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->error('Failed to encode probe JSON.');

            return self::FAILURE;
        }

        $this->line($json);

        return self::SUCCESS;
    }

    private function buildHealthEnvelope(): array
    {
        $busyLockActive = Cache::has('ollama_busy_lock');
        $instances = $this->loadInstances();
        $runner = $this->resolveCommandRunner();

        $instanceResults = [];
        foreach ($instances as $row) {
            $instanceResults[] = $this->buildInstanceResult($row, $busyLockActive, $runner);
        }

        $gpu = $busyLockActive ? [] : $this->parseGpuRows($runner->run(
            'nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu,temperature.gpu --format=csv,noheader,nounits',
            5
        ));

        return [
            'version' => 1,
            'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'subcommand' => 'health',
            'host' => $this->resolveHostName($runner),
            'busy_lock_active' => $busyLockActive,
            'result' => [
                'instances' => $instanceResults,
                'gpu' => $gpu,
            ],
        ];
    }

    private function buildFitEnvelope(): ?array
    {
        $model = trim((string) $this->option('model'));
        if ($model === '') {
            $this->error('--model is required for fit.');

            return null;
        }

        $context = (int) $this->option('context');
        if ($context <= 0) {
            $context = 4096;
        }

        $busyLockActive = Cache::has('ollama_busy_lock');
        $instances = $this->loadInstances();
        $runner = $this->resolveCommandRunner();
        $gpuFreeBytes = null;
        $gpuTotalBytes = null;

        if (! $busyLockActive) {
            [$gpuFreeBytes, $gpuTotalBytes] = $this->readLocalGpuMemoryBytes($runner);
        }

        $results = [];
        foreach ($instances as $row) {
            $results[] = $this->buildFitInstanceResult(
                $row,
                $model,
                $context,
                $busyLockActive,
                $runner,
                $gpuFreeBytes,
                $gpuTotalBytes
            );
        }

        return [
            'version' => 1,
            'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'subcommand' => 'fit',
            'host' => $this->resolveHostName($runner),
            'busy_lock_active' => $busyLockActive,
            'result' => [
                'instances' => $results,
            ],
        ];
    }

    private function buildTokenizeEnvelope(): ?array
    {
        $model = trim((string) $this->option('model'));
        if ($model === '') {
            $this->error('--model is required for tokenize.');

            return null;
        }

        $promptOpt = $this->option('prompt');
        $fileOpt = $this->option('file');

        $promptProvided = is_string($promptOpt) && $promptOpt !== '';
        $fileProvided = is_string($fileOpt) && $fileOpt !== '';

        if ($promptProvided && $fileProvided) {
            $this->error('--prompt and --file are mutually exclusive.');

            return null;
        }

        if (! $promptProvided && ! $fileProvided) {
            $this->error('One of --prompt or --file is required for tokenize.');

            return null;
        }

        $prompt = null;
        $loadError = null;

        if ($promptProvided) {
            $prompt = (string) $promptOpt;
        } else {
            $path = (string) $fileOpt;
            if (! is_file($path) || ! is_readable($path)) {
                $loadError = 'file_not_readable';
            } else {
                $contents = @file_get_contents($path);
                if ($contents === false) {
                    $loadError = 'file_read_failed';
                } else {
                    $prompt = $contents;
                }
            }
        }

        $busyLockActive = Cache::has('ollama_busy_lock');
        $instances = $this->loadInstances();
        $runner = $this->resolveCommandRunner();

        $results = [];
        foreach ($instances as $row) {
            $results[] = $this->buildTokenizeInstanceResult(
                $row,
                $model,
                $prompt,
                $loadError,
                $busyLockActive,
                $runner
            );
        }

        return [
            'version' => 1,
            'timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'subcommand' => 'tokenize',
            'host' => $this->resolveHostName($runner),
            'busy_lock_active' => $busyLockActive,
            'result' => [
                'instances' => $results,
            ],
        ];
    }

    private function loadInstances(): array
    {
        return DB::select(
            "SELECT id, instance_id, instance_name, base_url, priority, is_healthy, health_score
             FROM llm_instances
             WHERE instance_type = 'ollama' AND is_active = 1
             ORDER BY priority ASC"
        );
    }

    private function buildInstanceResult(object $row, bool $busyLockActive, HostCommandRunner $runner): array
    {
        $base = [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'instance_name' => (string) ($row->instance_name ?? ''),
            'base_url' => (string) ($row->base_url ?? ''),
            'priority' => isset($row->priority) ? (int) $row->priority : null,
            'is_healthy' => isset($row->is_healthy) ? (int) $row->is_healthy : null,
            'health_score' => isset($row->health_score) ? (int) $row->health_score : null,
        ];

        if ($busyLockActive) {
            return array_merge($base, [
                'result' => 'skipped',
                'reachable' => null,
                'installed_models' => [],
                'loaded_models' => [],
                'probe_error' => 'busy_lock_active',
            ]);
        }

        $baseUrl = rtrim($base['base_url'], '/');
        $tagsUrl = $baseUrl.'/api/tags';
        $psUrl = $baseUrl.'/api/ps';

        $tagsRaw = $runner->run('curl -fsS --max-time 5 '.escapeshellarg($tagsUrl), 5);
        $psRaw = $runner->run('curl -fsS --max-time 5 '.escapeshellarg($psUrl), 5);

        $reachable = $tagsRaw !== null;
        $probeError = null;
        $installedModels = [];
        $loadedModels = [];

        if ($tagsRaw === null) {
            $probeError = 'tags_unreachable';
        } else {
            $tagsDecoded = json_decode($tagsRaw, true);
            if (! is_array($tagsDecoded)) {
                $probeError = 'tags_invalid_json';
            } else {
                $installedModels = $this->extractTagNames($tagsDecoded);
            }
        }

        if ($psRaw !== null) {
            $psDecoded = json_decode($psRaw, true);
            if (is_array($psDecoded)) {
                $loadedModels = $this->extractLoadedModels($psDecoded);
            }
        }

        return array_merge($base, [
            'result' => 'ok',
            'reachable' => $reachable,
            'installed_models' => $installedModels,
            'loaded_models' => $loadedModels,
            'probe_error' => $probeError,
        ]);
    }

    private function buildFitInstanceResult(
        object $row,
        string $model,
        int $context,
        bool $busyLockActive,
        HostCommandRunner $runner,
        ?int $gpuFreeBytes,
        ?int $gpuTotalBytes
    ): array {
        $base = [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'base_url' => (string) ($row->base_url ?? ''),
            'model' => $model,
            'context' => $context,
            'model_size_bytes' => null,
            'estimated_vram_bytes' => null,
            'gpu_free_bytes' => null,
            'gpu_total_bytes' => null,
            'gpu_state' => null,
            'margin_mb' => 500,
            'verdict' => 'unknown',
            'reason' => null,
        ];

        if ($busyLockActive) {
            return array_merge($base, [
                'result' => 'skipped',
                'reason' => 'busy_lock_active',
            ]);
        }

        $baseUrl = rtrim((string) ($row->base_url ?? ''), '/');
        $showUrl = $baseUrl.'/api/show';
        $body = json_encode(['name' => $model]);
        $curl = sprintf(
            'curl -fsS --max-time 10 -H %s -d %s %s',
            escapeshellarg('Content-Type: application/json'),
            escapeshellarg($body !== false ? $body : '{}'),
            escapeshellarg($showUrl)
        );
        $showRaw = $runner->run($curl, 10);

        if ($showRaw === null) {
            return array_merge($base, [
                'result' => 'ok',
                'verdict' => 'unknown',
                'reason' => 'show_unreachable',
            ]);
        }

        $showDecoded = json_decode($showRaw, true);
        if (! is_array($showDecoded)) {
            return array_merge($base, [
                'result' => 'ok',
                'verdict' => 'unknown',
                'reason' => 'show_invalid_json',
            ]);
        }

        if (isset($showDecoded['error'])) {
            return array_merge($base, [
                'result' => 'ok',
                'verdict' => 'unknown',
                'reason' => 'show_error:'.(string) $showDecoded['error'],
            ]);
        }

        $modelSizeBytes = $this->extractModelSizeBytes($showDecoded);
        if ($modelSizeBytes === null) {
            return array_merge($base, [
                'result' => 'ok',
                'verdict' => 'unknown',
                'reason' => 'model_size_unknown',
            ]);
        }

        // KV cache heuristic: context * 0.5 MB (conservative; not exact without num_attention_heads).
        $kvBytes = (int) ($context * 0.5 * 1024 * 1024);
        $estimated = (int) ($modelSizeBytes * 1.1) + $kvBytes;

        $gpuState = 'local';
        $isLocal = $this->isLocalBaseUrl((string) ($row->base_url ?? ''));
        if (! $isLocal) {
            $gpuState = 'remote';
        }

        $verdict = 'unknown';
        $reason = null;
        $freeForInstance = $isLocal ? $gpuFreeBytes : null;
        $totalForInstance = $isLocal ? $gpuTotalBytes : null;

        if (! $isLocal) {
            $reason = 'gpu_state_remote';
        } elseif ($freeForInstance === null) {
            $reason = 'gpu_free_unknown';
        } else {
            $margin = 500 * 1024 * 1024;
            if ($estimated + $margin <= $freeForInstance) {
                $verdict = 'fits';
            } elseif ($estimated <= $freeForInstance) {
                $verdict = 'tight';
            } else {
                $verdict = 'no_fit';
                $reason = sprintf(
                    'estimated %d > free %d',
                    $estimated,
                    $freeForInstance
                );
            }
        }

        return array_merge($base, [
            'result' => 'ok',
            'model_size_bytes' => $modelSizeBytes,
            'estimated_vram_bytes' => $estimated,
            'gpu_free_bytes' => $freeForInstance,
            'gpu_total_bytes' => $totalForInstance,
            'gpu_state' => $gpuState,
            'verdict' => $verdict,
            'reason' => $reason,
        ]);
    }

    private function buildTokenizeInstanceResult(
        object $row,
        string $model,
        ?string $prompt,
        ?string $loadError,
        bool $busyLockActive,
        HostCommandRunner $runner
    ): array {
        $base = [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'base_url' => (string) ($row->base_url ?? ''),
            'model' => $model,
            'prompt_chars' => $prompt !== null ? strlen($prompt) : 0,
            'actual_tokens' => null,
            'estimated_tokens' => null,
            'ratio' => null,
            'error' => null,
        ];

        if ($busyLockActive) {
            return array_merge($base, [
                'result' => 'skipped',
                'error' => 'busy_lock_active',
            ]);
        }

        if ($loadError !== null) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => $loadError,
            ]);
        }

        if ($prompt === null) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'prompt_missing',
            ]);
        }

        $baseUrl = rtrim((string) ($row->base_url ?? ''), '/');

        // Confirm model is installed on this instance before sending /api/generate.
        $tagsRaw = $runner->run(
            'curl -fsS --max-time 5 '.escapeshellarg($baseUrl.'/api/tags'),
            5
        );
        if ($tagsRaw === null) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'tags_unreachable',
            ]);
        }

        $tagsDecoded = json_decode($tagsRaw, true);
        if (! is_array($tagsDecoded)) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'tags_invalid_json',
            ]);
        }

        $installed = $this->extractTagNames($tagsDecoded);
        if (! in_array($model, $installed, true)) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'model not installed on this instance',
            ]);
        }

        $body = json_encode([
            'model' => $model,
            'prompt' => $prompt,
            'options' => ['num_predict' => 1],
            'stream' => false,
        ]);
        $curl = sprintf(
            'curl -fsS --max-time 30 -H %s -d %s %s',
            escapeshellarg('Content-Type: application/json'),
            escapeshellarg($body !== false ? $body : '{}'),
            escapeshellarg($baseUrl.'/api/generate')
        );
        $genRaw = $runner->run($curl, 30);

        if ($genRaw === null) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'generate_unreachable',
            ]);
        }

        $decoded = json_decode($genRaw, true);
        if (! is_array($decoded)) {
            return array_merge($base, [
                'result' => 'ok',
                'error' => 'generate_invalid_json',
            ]);
        }

        $actual = null;
        if (isset($decoded['prompt_eval_count']) && is_numeric($decoded['prompt_eval_count'])) {
            $actual = (int) $decoded['prompt_eval_count'];
        }

        $chars = strlen($prompt);
        $estimated = (int) ceil($chars / 1.5);
        $ratio = null;
        if ($actual !== null && $estimated > 0) {
            $ratio = round($actual / $estimated, 3);
        }

        return array_merge($base, [
            'result' => 'ok',
            'actual_tokens' => $actual,
            'estimated_tokens' => $estimated,
            'ratio' => $ratio,
            'error' => $actual === null ? 'prompt_eval_count_missing' : null,
        ]);
    }

    private function extractModelSizeBytes(array $show): ?int
    {
        if (isset($show['size']) && is_numeric($show['size'])) {
            return (int) $show['size'];
        }

        $modelInfo = $show['model_info'] ?? null;
        if (is_array($modelInfo)) {
            $candidates = [
                'general.parameter_count',
                'general.size',
            ];
            foreach ($candidates as $key) {
                if (isset($modelInfo[$key]) && is_numeric($modelInfo[$key])) {
                    // parameter_count is a parameter tally, not bytes. Only treat
                    // it as bytes if no direct size is available — at 1 byte per
                    // parameter this is a floor estimate, flagged by caller logic
                    // elsewhere. Here we simply return null to let the caller
                    // mark the verdict unknown rather than fabricate a number.
                    if ($key === 'general.parameter_count') {
                        continue;
                    }

                    return (int) $modelInfo[$key];
                }
            }
        }

        $details = $show['details'] ?? null;
        if (is_array($details)) {
            foreach (['parameter_size_bytes', 'size'] as $key) {
                if (isset($details[$key]) && is_numeric($details[$key])) {
                    return (int) $details[$key];
                }
            }
        }

        return null;
    }

    private function readLocalGpuMemoryBytes(HostCommandRunner $runner): array
    {
        $raw = $runner->run(
            'nvidia-smi --query-gpu=memory.free,memory.total --format=csv,noheader,nounits',
            5
        );
        if ($raw === null || trim($raw) === '') {
            return [null, null];
        }

        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        if ($lines === []) {
            return [null, null];
        }

        $columns = array_map('trim', str_getcsv((string) $lines[0]));
        if (count($columns) < 2) {
            return [null, null];
        }

        $free = $this->parseIntegerValue($columns[0]);
        $total = $this->parseIntegerValue($columns[1]);

        $freeBytes = $free !== null ? $free * 1024 * 1024 : null;
        $totalBytes = $total !== null ? $total * 1024 * 1024 : null;

        return [$freeBytes, $totalBytes];
    }

    private function isLocalBaseUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $local = ['127.0.0.1', 'localhost', '::1', '0.0.0.0'];

        return in_array($host, $local, true);
    }

    private function extractTagNames(array $tags): array
    {
        $names = [];
        $models = $tags['models'] ?? [];
        if (! is_array($models)) {
            return [];
        }

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = $model['name'] ?? $model['model'] ?? null;
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function extractLoadedModels(array $ps): array
    {
        $rows = [];
        $models = $ps['models'] ?? [];
        if (! is_array($models)) {
            return [];
        }

        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }

            $name = $model['name'] ?? $model['model'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $entry = ['name' => $name];
            if (isset($model['size_vram']) && is_numeric($model['size_vram'])) {
                $entry['size_vram'] = (int) $model['size_vram'];
            }

            $rows[] = $entry;
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

    private function resolveHostName(HostCommandRunner $runner): string
    {
        $host = $runner->run('hostname -s', 5);
        if ($host !== null && $host !== '') {
            return $host;
        }

        $fallback = gethostname() ?: 'unknown-host';

        return explode('.', $fallback)[0];
    }

    private function resolveCommandRunner(): HostCommandRunner
    {
        if (app()->bound(HostCommandRunner::class)) {
            return app(HostCommandRunner::class);
        }

        return new ShellExecHostCommandRunner();
    }
}
