<?php

namespace App\Console\Commands;

use App\Services\Ops\McpHealthReportService;
use Illuminate\Console\Command;

class OpsMcpHealthCommand extends Command
{
    protected $signature = 'ops:mcp-health
        {--json : Emit machine-readable JSON}
        {--compact : Emit compact operator summary}';

    protected $description = 'Observe-only MCP configuration, entry-file, and process health scorecard';

    public function handle(McpHealthReportService $service): int
    {
        $payload = $service->collect();
        $output = $this->option('compact') ? $service->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode MCP health JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return self::SUCCESS;
        }

        if ($this->option('compact')) {
            $this->writeCompact($output);

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->line(sprintf(
            'MCP health: %s  total=%d  enabled=%d  external=%d  watch=%d  warning=%d  critical=%d  missing_entries=%d  enabled_missing_entries=%d  disabled_missing_entries=%d  external_not_running=%d  disabled_external_running=%d',
            strtoupper((string) $payload['status']),
            (int) ($summary['total'] ?? 0),
            (int) ($summary['enabled'] ?? 0),
            (int) ($summary['external'] ?? 0),
            (int) ($summary['watch'] ?? 0),
            (int) ($summary['warning'] ?? 0),
            (int) ($summary['critical'] ?? 0),
            (int) ($summary['missing_entries'] ?? 0),
            (int) ($summary['enabled_missing_entries'] ?? 0),
            (int) ($summary['disabled_missing_entries'] ?? 0),
            (int) ($summary['external_not_running'] ?? 0),
            (int) ($summary['disabled_external_running'] ?? 0),
        ));

        foreach ($payload['servers'] as $server) {
            $this->line(sprintf(
                'server=%s status=%s enabled=%s transport=%s process_expected=%s process_running=%s missing_entries=%d',
                (string) ($server['name'] ?? ''),
                (string) ($server['status'] ?? 'unknown'),
                ((bool) ($server['enabled'] ?? false)) ? 'true' : 'false',
                (string) ($server['transport'] ?? 'unknown'),
                ((bool) data_get($server, 'process.expected', false)) ? 'true' : 'false',
                ((bool) data_get($server, 'process.running', false)) ? 'true' : 'false',
                (int) ($server['missing_entries'] ?? 0),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $compact
     */
    private function writeCompact(array $compact): void
    {
        $summary = $compact['summary'] ?? [];
        $this->line(sprintf(
            'MCP health compact: %s  total=%d  enabled=%d  external=%d  watch=%d  critical=%d  missing_entries=%d  enabled_missing_entries=%d  disabled_missing_entries=%d  external_not_running=%d  disabled_external_running=%d',
            strtoupper((string) ($compact['status'] ?? 'unknown')),
            (int) ($summary['total'] ?? 0),
            (int) ($summary['enabled'] ?? 0),
            (int) ($summary['external'] ?? 0),
            (int) ($summary['watch'] ?? 0),
            (int) ($summary['critical'] ?? 0),
            (int) ($summary['missing_entries'] ?? 0),
            (int) ($summary['enabled_missing_entries'] ?? 0),
            (int) ($summary['disabled_missing_entries'] ?? 0),
            (int) ($summary['external_not_running'] ?? 0),
            (int) ($summary['disabled_external_running'] ?? 0),
        ));

        $posture = $compact['config_posture'] ?? [];
        if (is_array($posture)) {
            $this->line(sprintf(
                'config-posture: external_absolute_entries=%d enabled_external_absolute_entries=%d trust_boundary=%s write_scope=%s network_required=%s secret_surface_risk=%s',
                (int) ($posture['external_absolute_entries'] ?? 0),
                (int) ($posture['enabled_external_absolute_entries'] ?? 0),
                $this->formatCounts((array) ($posture['trust_boundary_counts'] ?? [])),
                $this->formatCounts((array) ($posture['write_scope_counts'] ?? [])),
                $this->formatCounts((array) ($posture['network_required_counts'] ?? [])),
                $this->formatCounts((array) ($posture['secret_surface_risk_counts'] ?? [])),
            ));
        }

        foreach ((array) ($compact['attention'] ?? []) as $server) {
            if (! is_array($server)) {
                continue;
            }

            $this->line(sprintf(
                'attention=%s status=%s enabled=%s process_matchable=%s process_running=%s process_marker_count=%d missing_entries=%d',
                (string) ($server['name'] ?? ''),
                (string) ($server['status'] ?? 'unknown'),
                ((bool) ($server['enabled'] ?? false)) ? 'true' : 'false',
                ((bool) ($server['process_matchable'] ?? false)) ? 'true' : 'false',
                ((bool) ($server['process_running'] ?? false)) ? 'true' : 'false',
                (int) ($server['process_marker_count'] ?? 0),
                (int) ($server['missing_entries'] ?? 0),
            ));
        }
    }

    /**
     * @param  array<string, int|string>  $counts
     */
    private function formatCounts(array $counts): string
    {
        if ($counts === []) {
            return 'none';
        }

        ksort($counts);

        return implode(',', array_map(
            fn (string $key, int|string $value): string => $key.':'.(int) $value,
            array_keys($counts),
            array_values($counts)
        ));
    }
}
