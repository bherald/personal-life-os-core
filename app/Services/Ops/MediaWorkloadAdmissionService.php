<?php

namespace App\Services\Ops;

class MediaWorkloadAdmissionService
{
    /**
     * @param  array{force?:bool,path?:string|null,estimated_write_gb?:float|int|null}  $options
     * @return array<string, mixed>
     */
    public function evaluate(string $workload, array $options = []): array
    {
        $config = (array) config('media_workloads.admission', []);
        $enabled = (bool) ($config['enabled'] ?? true);
        $enforce = (bool) ($config['enforce'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $workloadConfig = (array) data_get($config, 'workloads.'.$workload, []);

        if (! $enabled) {
            return $this->basePayload($workload, $workloadConfig) + [
                'status' => 'disabled',
                'allowed' => true,
                'would_block' => false,
                'forced' => $force,
                'enforced' => false,
                'blockers' => [],
            ];
        }

        $path = $this->resolvePath((string) ($options['path'] ?? $workloadConfig['path'] ?? storage_path('app')));
        $totalBytes = @disk_total_space($path);
        $freeBytes = @disk_free_space($path);

        if (! is_numeric($totalBytes) || ! is_numeric($freeBytes) || (float) $totalBytes <= 0) {
            return $this->basePayload($workload, $workloadConfig) + [
                'status' => $enforce && ! $force ? 'blocked' : 'observe_warning',
                'allowed' => ! $enforce || $force,
                'would_block' => true,
                'forced' => $force,
                'enforced' => $enforce,
                'path_class' => $this->pathClass($path),
                'blockers' => ['disk_metrics_unavailable'],
                'metrics' => [
                    'free_gb' => null,
                    'total_gb' => null,
                    'used_percent' => null,
                ],
            ];
        }

        $minFreeGb = max(0.0, (float) ($workloadConfig['min_free_gb'] ?? $config['min_free_gb'] ?? 8));
        $writeAmplificationGb = max(0.0, (float) ($options['estimated_write_gb'] ?? $workloadConfig['write_amplification_gb'] ?? 0));
        $requiredFreeGb = $minFreeGb + $writeAmplificationGb;
        $maxUsedPercent = max(0.0, min(100.0, (float) ($workloadConfig['max_used_percent'] ?? $config['max_used_percent'] ?? 92)));
        $freeGb = round(((float) $freeBytes) / 1073741824, 2);
        $totalGb = round(((float) $totalBytes) / 1073741824, 2);
        $usedPercent = round((1 - (((float) $freeBytes) / ((float) $totalBytes))) * 100, 2);
        $blockers = [];

        if ($freeGb < $requiredFreeGb) {
            $blockers[] = 'free_space_below_required';
        }

        if ($usedPercent > $maxUsedPercent) {
            $blockers[] = 'used_percent_above_limit';
        }

        $wouldBlock = $blockers !== [];
        $allowed = ! $wouldBlock || ! $enforce || $force;

        return $this->basePayload($workload, $workloadConfig) + [
            'status' => $wouldBlock ? ($allowed ? 'observe_warning' : 'blocked') : 'admitted',
            'allowed' => $allowed,
            'would_block' => $wouldBlock,
            'forced' => $force,
            'enforced' => $enforce,
            'path_class' => $this->pathClass($path),
            'blockers' => $blockers,
            'metrics' => [
                'free_gb' => $freeGb,
                'total_gb' => $totalGb,
                'used_percent' => $usedPercent,
                'required_free_gb' => round($requiredFreeGb, 2),
                'min_free_gb' => round($minFreeGb, 2),
                'write_amplification_gb' => round($writeAmplificationGb, 2),
                'max_used_percent' => $maxUsedPercent,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    public function compactLine(array $decision): string
    {
        $metrics = (array) ($decision['metrics'] ?? []);

        return sprintf(
            'media admission workload=%s status=%s allowed=%s would_block=%s free_gb=%s required_free_gb=%s used_percent=%s',
            (string) ($decision['workload'] ?? 'unknown'),
            (string) ($decision['status'] ?? 'unknown'),
            $this->boolWord($decision['allowed'] ?? false),
            $this->boolWord($decision['would_block'] ?? false),
            $this->metricText($metrics['free_gb'] ?? null),
            $this->metricText($metrics['required_free_gb'] ?? null),
            $this->metricText($metrics['used_percent'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $workloadConfig
     * @return array<string, mixed>
     */
    private function basePayload(string $workload, array $workloadConfig): array
    {
        return [
            'schema' => 'media_workload_admission.v1',
            'mode' => 'observe_or_gate',
            'workload' => $workload,
            'label' => (string) ($workloadConfig['label'] ?? $workload),
            'writes_performed' => false,
        ];
    }

    private function resolvePath(string $path): string
    {
        $path = $path !== '' ? $path : storage_path('app');
        if (is_dir($path)) {
            return $path;
        }

        $parent = dirname($path);

        return is_dir($parent) ? $parent : storage_path('app');
    }

    private function pathClass(string $path): string
    {
        if ($this->underPath($path, storage_path())) {
            return 'storage';
        }

        if ($this->underPath($path, base_path())) {
            return 'repo';
        }

        return 'external_absolute';
    }

    private function underPath(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function boolWord(mixed $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function metricText(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : 'unknown';
    }
}
