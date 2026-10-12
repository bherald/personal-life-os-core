<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class WhiteroomSidecarDoctorCommand extends Command
{
    protected $signature = 'whiteroom:sidecar-doctor
        {--format=human : Output format: human or json}
        {--project=* : Filter by one or more project keys}
        {--skip-probes : Emit the planned checks without running host probes}
        {--strict : Treat warnings as command failures}';

    protected $description = 'Read-only readiness checks for whiteroom sidecar pilot candidates';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('Invalid --format. Use human or json.');

            return self::FAILURE;
        }

        $projectKeys = $this->normalizedOptionSet('project');
        $plans = $this->filteredPlans($projectKeys);
        if ($projectKeys !== [] && $plans === []) {
            $this->error('No sidecar readiness plans matched --project.');

            return self::FAILURE;
        }

        $payload = [
            'schema' => 'plos_whiteroom_sidecar_doctor.v1',
            'probe_mode' => (bool) $this->option('skip-probes') ? 'planned-only' : 'host-probe',
            'strict' => (bool) $this->option('strict'),
            'policy' => config('whiteroom_projects.policy'),
            'projects' => $this->evaluatePlans($plans),
        ];
        $payload['summary'] = $this->summary($payload['projects']);
        $payload['status'] = $this->overallStatus($payload['summary']);

        if ($format === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($payload);
        }

        return $this->shouldFail($payload) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $projectKeys
     * @return array<string, array<string, mixed>>
     */
    private function filteredPlans(array $projectKeys): array
    {
        $readiness = config('whiteroom_projects.sidecar_readiness', []);
        if (! is_array($readiness)) {
            return [];
        }

        if ($projectKeys === []) {
            return $readiness;
        }

        return array_filter(
            $readiness,
            fn (string $key): bool => in_array(strtolower($key), $projectKeys, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $plans
     * @return array<int, array<string, mixed>>
     */
    private function evaluatePlans(array $plans): array
    {
        $projects = config('whiteroom_projects.projects', []);
        $projectMeta = [];
        foreach (is_array($projects) ? $projects : [] as $project) {
            $key = (string) ($project['key'] ?? '');
            if ($key !== '') {
                $projectMeta[$key] = $project;
            }
        }

        $results = [];
        foreach ($plans as $key => $plan) {
            $checks = [];
            foreach ((array) ($plan['checks'] ?? []) as $check) {
                $checks[] = $this->evaluateCheck((string) $key, (array) $check);
            }

            $checkSummary = $this->summary($checks);
            $results[] = [
                'key' => (string) $key,
                'name' => $projectMeta[$key]['name'] ?? $key,
                'repo' => $projectMeta[$key]['repo'] ?? null,
                'priority' => $projectMeta[$key]['priority'] ?? null,
                'status' => $this->overallStatus($checkSummary),
                'runtime_policy' => $plan['runtime_policy'] ?? null,
                'promotion_gate' => $plan['promotion_gate'] ?? null,
                'artifact_contract' => $plan['artifact_contract'] ?? null,
                'summary' => $checkSummary,
                'checks' => $checks,
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function evaluateCheck(string $projectKey, array $check): array
    {
        $base = [
            'project' => $projectKey,
            'key' => (string) ($check['key'] ?? 'unknown'),
            'label' => (string) ($check['label'] ?? $check['key'] ?? 'unknown'),
            'type' => (string) ($check['type'] ?? 'binary'),
            'required' => (bool) ($check['required'] ?? false),
            'status' => 'skip',
            'message' => '',
            'command' => null,
            'path' => null,
            'version' => null,
            'min_version' => $check['min_version'] ?? null,
            'notes' => $check['notes'] ?? null,
        ];

        if ((bool) $this->option('skip-probes')) {
            $base['message'] = 'planned check only; host probe skipped';

            return $base;
        }

        if (($check['type'] ?? 'binary') !== 'binary') {
            $base['message'] = 'unsupported check type';
            $base['status'] = $base['required'] ? 'fail' : 'warn';

            return $base;
        }

        $commands = array_values(array_filter(array_map(
            fn ($value): string => trim((string) $value),
            (array) ($check['commands'] ?? [$check['command'] ?? ''])
        )));

        [$command, $path] = $this->findFirstExecutable($commands);
        if ($path === null) {
            $base['status'] = $base['required'] ? 'fail' : 'warn';
            $base['message'] = 'binary not found: '.implode(', ', $commands);

            return $base;
        }

        $base['command'] = $command;
        $base['path'] = $path;

        $version = $this->readVersion($path, (array) ($check['version_args'] ?? []));
        $base['version'] = $version['version'];
        $base['raw_version'] = $version['raw'];

        $minVersion = isset($check['min_version']) ? (string) $check['min_version'] : null;
        if ($minVersion !== null && $version['version'] !== null && version_compare($this->normalizeVersion($version['version']), $minVersion, '<')) {
            $base['status'] = $base['required'] ? 'fail' : 'warn';
            $base['message'] = "found {$version['version']}, below required {$minVersion}";

            return $base;
        }

        if ($minVersion !== null && $version['version'] === null) {
            $base['status'] = $base['required'] ? 'warn' : 'warn';
            $base['message'] = "binary found but version could not be parsed; required {$minVersion}";

            return $base;
        }

        $base['status'] = 'pass';
        $base['message'] = 'binary available'.($version['version'] ? " ({$version['version']})" : '');

        return $base;
    }

    /**
     * @param  array<int, string>  $commands
     * @return array{0: string|null, 1: string|null}
     */
    private function findFirstExecutable(array $commands): array
    {
        $finder = new ExecutableFinder;
        $extraDirs = array_values(array_filter([
            env('HOME') ? rtrim((string) env('HOME'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.local'.DIRECTORY_SEPARATOR.'bin' : null,
            base_path('node_modules/.bin'),
        ], fn ($path): bool => is_string($path) && $path !== ''));

        foreach ($commands as $command) {
            $path = $finder->find($command, null, $extraDirs);
            if ($path !== null) {
                return [$command, $path];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<int, string>  $args
     * @return array{raw: string|null, version: string|null}
     */
    private function readVersion(string $path, array $args): array
    {
        if ($args === []) {
            return ['raw' => null, 'version' => null];
        }

        try {
            $process = new Process(array_merge([$path], array_map('strval', $args)), base_path());
            $process->setTimeout(5);
            $process->run();
            $raw = trim($process->getOutput()."\n".$process->getErrorOutput());
        } catch (\Throwable $e) {
            return ['raw' => $e->getMessage(), 'version' => null];
        }

        $version = null;
        if (preg_match('/(?<!\d)v?(\d+(?:\.\d+){0,2})/', $raw, $matches) === 1) {
            $version = $matches[1];
        }

        return [
            'raw' => $raw !== '' ? mb_substr($raw, 0, 500) : null,
            'version' => $version,
        ];
    }

    private function normalizeVersion(string $version): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0, 'total' => count($items)];
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? 'skip');
            if (! array_key_exists($status, $summary)) {
                $status = 'skip';
            }
            $summary[$status]++;
        }

        return $summary;
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function overallStatus(array $summary): string
    {
        if (($summary['fail'] ?? 0) > 0) {
            return 'fail';
        }

        if (($summary['warn'] ?? 0) > 0) {
            return 'warn';
        }

        if (($summary['pass'] ?? 0) > 0) {
            return 'pass';
        }

        return 'skip';
    }

    /**
     * @return array<int, string>
     */
    private function normalizedOptionSet(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            $values = [$values];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($value): string => strtolower(trim((string) $value)),
            $values
        ))));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldFail(array $payload): bool
    {
        if (($payload['summary']['fail'] ?? 0) > 0) {
            return true;
        }

        return (bool) $this->option('strict') && ($payload['summary']['warn'] ?? 0) > 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->info('Whiteroom sidecar doctor');
        $this->line('  Probe mode: '.$payload['probe_mode']);
        $this->line('  Status: '.$payload['status']);
        $this->line(sprintf(
            '  Totals: pass=%d warn=%d fail=%d skip=%d total=%d',
            $payload['summary']['pass'],
            $payload['summary']['warn'],
            $payload['summary']['fail'],
            $payload['summary']['skip'],
            $payload['summary']['total'],
        ));
        $this->newLine();

        foreach ($payload['projects'] as $project) {
            $this->line(sprintf('[%s] %s - %s', $project['status'], $project['key'], $project['runtime_policy']));
            $this->line('  Gate: '.$project['promotion_gate']);
            foreach ($project['checks'] as $check) {
                $this->line(sprintf(
                    '  - %s %s: %s',
                    strtoupper((string) $check['status']),
                    $check['label'],
                    $check['message']
                ));
            }
        }
    }
}
