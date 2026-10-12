<?php

namespace App\Services\Ops;

class JetsonTegrastatsParserService
{
    public const SAMPLE_COMMAND = 'timeout 3s tegrastats --interval 1000 2>/dev/null | head -n 1';

    /** @return array<string,mixed>|null */
    public function parse(?string $output): ?array
    {
        if ($output === null || trim($output) === '') {
            return null;
        }

        $lines = preg_split('/\r?\n/', trim($output)) ?: [];
        $sample = $lines[0] ?? '';
        if (! preg_match('/\bRAM\s+(\d+)\/(\d+)MB(?:\s+\(lfb\s+(\d+)x(\d+)MB\))?/i', $sample, $memory)) {
            return null;
        }

        $memoryUsed = (int) $memory[1];
        $memoryTotal = (int) $memory[2];
        $swap = $this->matchIntegers('/\bSWAP\s+(\d+)\/(\d+)MB(?:\s+\(cached\s+(\d+)MB\))?/i', $sample);
        $gpu = $this->utilizationAndFrequency($sample, 'GR3D');
        $emc = $this->utilizationAndFrequency($sample, 'EMC');
        $temperatures = $this->temperatures($sample);

        return [
            'schema' => 'plos.jetson_tegrastats_sample.v1',
            'source' => 'tegrastats',
            'memory_model' => 'unified_system',
            'memory_used_mb' => $memoryUsed,
            'memory_total_mb' => $memoryTotal,
            'memory_available_mb' => max(0, $memoryTotal - $memoryUsed),
            'largest_free_block_count' => isset($memory[3]) ? (int) $memory[3] : null,
            'largest_free_block_mb' => isset($memory[4]) ? (int) $memory[4] : null,
            'swap_used_mb' => $swap[0] ?? null,
            'swap_total_mb' => $swap[1] ?? null,
            'swap_cached_mb' => $swap[2] ?? null,
            'gpu_utilization_percent' => $gpu[0] ?? null,
            'gpu_frequency_mhz' => $gpu[1] ?? null,
            'emc_utilization_percent' => $emc[0] ?? null,
            'emc_frequency_mhz' => $emc[1] ?? null,
            'gpu_temperature_c' => $this->temperatureForZone($temperatures, 'gpu'),
            'max_temperature_c' => $temperatures === [] ? null : max($temperatures),
            'temperature_zones_c' => $temperatures,
            'raw_output_included' => false,
        ];
    }

    /** @return list<int> */
    private function matchIntegers(string $pattern, string $sample): array
    {
        if (! preg_match($pattern, $sample, $matches)) {
            return [];
        }

        return array_values(array_map(
            static fn (string $value): int => (int) $value,
            array_slice($matches, 1)
        ));
    }

    /** @return array{0:int,1:int|null}|array{} */
    private function utilizationAndFrequency(string $sample, string $label): array
    {
        $pattern = '/\b'.preg_quote($label, '/').'(?:_FREQ)?\s+(\d+)%(?:@(?:\[([\d,]+)\]|(\d+)))?/i';
        if (! preg_match($pattern, $sample, $matches)) {
            return [];
        }

        $frequencyList = $matches[2] ?? '';
        $frequency = $frequencyList !== ''
            ? max(array_map('intval', explode(',', $frequencyList)))
            : (isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null);

        return [(int) $matches[1], $frequency];
    }

    /** @return array<string,float> */
    private function temperatures(string $sample): array
    {
        preg_match_all('/\b([A-Za-z][A-Za-z0-9_]*)@(-?\d+(?:\.\d+)?)C\b/', $sample, $matches, PREG_SET_ORDER);
        $temperatures = [];

        foreach ($matches as $match) {
            $value = (float) $match[2];
            if ($value < 0 || $value > 200) {
                continue;
            }

            $temperatures[$match[1]] = $value;
        }

        ksort($temperatures);

        return $temperatures;
    }

    /** @param array<string,float> $temperatures */
    private function temperatureForZone(array $temperatures, string $zone): ?float
    {
        foreach ($temperatures as $name => $value) {
            if (strcasecmp($name, $zone) === 0) {
                return $value;
            }
        }

        return null;
    }
}
