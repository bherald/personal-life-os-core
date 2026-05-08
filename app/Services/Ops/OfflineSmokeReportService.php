<?php

namespace App\Services\Ops;

use App\Engine\MCPRouter;
use App\Services\OfflineAuditService;
use App\Services\OperatorEvidenceService;

class OfflineSmokeReportService
{
    private const INTERNET_MCP_SERVERS = ['research', 'puppeteer', 'web-research', 'searxng'];

    public function __construct(
        private readonly OperatorEvidenceService $operatorEvidence,
        private readonly OfflineAuditService $audit,
        private readonly MCPRouter $mcpRouter,
    ) {}

    public function collect(string $profile = 'offline_review', int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));

        $sections = [
            'offline_status' => $this->offlineStatusSection(),
            'audit_summary' => $this->auditSummarySection($hours),
            'catalog_boundary' => $this->catalogBoundarySection($profile),
        ];
        $sections['local_runtime'] = $this->localRuntimeSection($sections['offline_status']['payload'] ?? []);

        return [
            'version' => 1,
            'mode' => 'observe',
            'generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'profile' => $profile,
            'window_hours' => $hours,
            'status' => $this->overallStatus($sections),
            'summary' => $this->summary($sections),
            'sections' => $sections,
            'note' => 'Manual report-only offline smoke. Does not switch profiles, run remediation, execute network calls, or write policy audit receipts.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function compactPayload(array $payload): array
    {
        $sections = [];
        foreach ((array) ($payload['sections'] ?? []) as $name => $section) {
            if (! is_string($name) || ! is_array($section)) {
                continue;
            }

            $sections[$name] = $this->compactSection($name, $section);
        }

        return [
            'version' => (int) ($payload['version'] ?? 1),
            'mode' => 'observe',
            'compact' => true,
            'generated_at' => is_scalar($payload['generated_at'] ?? null) ? (string) $payload['generated_at'] : null,
            'profile' => $this->safeLabel($payload['profile'] ?? null),
            'window_hours' => (int) ($payload['window_hours'] ?? 0),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'degraded')),
            'posture' => $this->compactPosture(),
            'summary' => [
                'healthy' => (int) ($payload['summary']['healthy'] ?? 0),
                'watch' => (int) ($payload['summary']['watch'] ?? 0),
                'degraded' => (int) ($payload['summary']['degraded'] ?? 0),
                'blocked' => (int) ($payload['summary']['blocked'] ?? 0),
            ],
            'sections' => $sections,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function offlineStatusSection(): array
    {
        try {
            $payload = $this->operatorEvidence->collectOfflineStatus();

            return [
                'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'unknown')),
                'detail' => sprintf(
                    'runtime=%s profile=%s offline_mode=%s',
                    $payload['section']['counts']['runtime_state'] ?? 'unknown',
                    $payload['section']['counts']['active_profile'] ?? 'unknown',
                    ($payload['section']['counts']['offline_mode_active'] ?? false) ? 'enabled' : 'disabled',
                ),
                'payload' => $payload,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'detail' => 'offline status unavailable: '.$e->getMessage(),
                'payload' => null,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function auditSummarySection(int $hours): array
    {
        try {
            $summary = $this->audit->summarizeWindow($hours);
            $ok = ($summary['result'] ?? null) === 'ok';

            return [
                'status' => $ok ? 'healthy' : 'degraded',
                'detail' => $ok
                    ? sprintf(
                        'audit reader ok: total=%d denied=%d mode_changes=%d',
                        (int) ($summary['total'] ?? 0),
                        (int) ($summary['denied'] ?? 0),
                        (int) ($summary['mode_changes'] ?? 0),
                    )
                    : 'audit reader returned '.(string) ($summary['result'] ?? 'unknown'),
                'summary' => $summary,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'detail' => 'audit summary unavailable: '.$e->getMessage(),
                'summary' => null,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function catalogBoundarySection(string $profile): array
    {
        try {
            $tools = $this->mcpRouter->getAvailableToolsForProfile($profile);
            $servers = array_values(array_unique(array_filter(array_map(
                static fn (array $tool): ?string => is_string($tool['server'] ?? null) ? $tool['server'] : null,
                $tools,
            ))));
            sort($servers);
            $leaked = array_values(array_intersect($servers, self::INTERNET_MCP_SERVERS));

            return [
                'status' => $leaked === [] ? 'healthy' : 'blocked',
                'detail' => $leaked === []
                    ? "{$profile} catalog excludes internet MCP servers"
                    : "{$profile} catalog exposes internet MCP servers: ".implode(', ', $leaked),
                'profile' => $profile,
                'tool_count' => count($tools),
                'servers' => $servers,
                'leaked_internet_servers' => $leaked,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'blocked',
                'detail' => 'catalog boundary unavailable: '.$e->getMessage(),
                'profile' => $profile,
                'tool_count' => null,
                'servers' => [],
                'leaked_internet_servers' => self::INTERNET_MCP_SERVERS,
            ];
        }
    }

    /**
     * @param  array<string,mixed>|null  $offlinePayload
     * @return array<string,mixed>
     */
    private function localRuntimeSection(?array $offlinePayload): array
    {
        $runtime = $offlinePayload['section']['counts']['local_runtime'] ?? null;
        if (! is_array($runtime)) {
            return [
                'status' => 'degraded',
                'detail' => 'local runtime scorecard unavailable',
                'runtime' => null,
            ];
        }

        $runtimeStatus = $this->normalizeStatus((string) ($runtime['status'] ?? 'unknown'));
        $status = $runtimeStatus === 'healthy' ? 'healthy' : 'degraded';

        return [
            'status' => $status,
            'detail' => sprintf(
                'local=%s healthy=%s selected=%s model=%s',
                $runtime['local_instances'] ?? 'unknown',
                $runtime['healthy_local_instances'] ?? 'unknown',
                $runtime['selected_local_id'] ?? 'none',
                $runtime['selected_local_model'] ?? 'none',
            ),
            'runtime' => $runtime,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $sections
     */
    private function overallStatus(array $sections): string
    {
        $statuses = array_map(static fn (array $section): string => (string) ($section['status'] ?? 'unknown'), $sections);

        if (in_array('blocked', $statuses, true)) {
            return 'blocked';
        }

        if (in_array('degraded', $statuses, true)) {
            return 'degraded';
        }

        if (in_array('watch', $statuses, true)) {
            return 'watch';
        }

        return 'healthy';
    }

    /**
     * @param  array<string,array<string,mixed>>  $sections
     * @return array<string,int>
     */
    private function summary(array $sections): array
    {
        return [
            'healthy' => count(array_filter($sections, static fn (array $section): bool => ($section['status'] ?? null) === 'healthy')),
            'watch' => count(array_filter($sections, static fn (array $section): bool => ($section['status'] ?? null) === 'watch')),
            'degraded' => count(array_filter($sections, static fn (array $section): bool => ($section['status'] ?? null) === 'degraded')),
            'blocked' => count(array_filter($sections, static fn (array $section): bool => ($section['status'] ?? null) === 'blocked')),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['healthy', 'watch', 'degraded', 'blocked'], true)
            ? $status
            : 'degraded';
    }

    /**
     * @param  array<string,mixed>  $section
     * @return array<string,mixed>
     */
    private function compactSection(string $name, array $section): array
    {
        $status = $this->normalizeStatus((string) ($section['status'] ?? 'degraded'));
        $compact = [
            'status' => $status,
            'reason_code' => $this->sectionReasonCode($name, $section, $status),
        ];

        if ($name === 'offline_status') {
            $counts = (array) ($section['payload']['section']['counts'] ?? []);
            $runtime = is_array($counts['local_runtime'] ?? null) ? $counts['local_runtime'] : [];

            return $compact + [
                'runtime_state' => $this->safeLabel($counts['runtime_state'] ?? null),
                'active_profile' => $this->safeLabel($counts['active_profile'] ?? null),
                'offline_mode_active' => (bool) ($counts['offline_mode_active'] ?? false),
                'local_runtime_status' => $this->safeLabel($runtime['status'] ?? null),
            ];
        }

        if ($name === 'audit_summary') {
            $summary = is_array($section['summary'] ?? null) ? $section['summary'] : [];

            return $compact + [
                'result' => $this->safeLabel($summary['result'] ?? null),
                'total' => (int) ($summary['total'] ?? 0),
                'denied' => (int) ($summary['denied'] ?? 0),
                'mode_changes' => (int) ($summary['mode_changes'] ?? 0),
            ];
        }

        if ($name === 'catalog_boundary') {
            return $compact + [
                'profile' => $this->safeLabel($section['profile'] ?? null),
                'tool_count' => (int) ($section['tool_count'] ?? 0),
                'leaked_internet_server_count' => count((array) ($section['leaked_internet_servers'] ?? [])),
            ];
        }

        if ($name === 'local_runtime') {
            $runtime = is_array($section['runtime'] ?? null) ? $section['runtime'] : [];

            return $compact + [
                'runtime_status' => $this->safeLabel($runtime['status'] ?? null),
                'local_instances' => (int) ($runtime['local_instances'] ?? 0),
                'healthy_local_instances' => (int) ($runtime['healthy_local_instances'] ?? 0),
                'selected_local_id' => $this->safeLabel($runtime['selected_local_id'] ?? null),
                'selected_local_model' => $this->safeLabel($runtime['selected_local_model'] ?? null),
            ];
        }

        return $compact;
    }

    /**
     * @return array<string,bool|string>
     */
    private function compactPosture(): array
    {
        return [
            'scope' => 'aggregate_only',
            'profile_switch_performed' => false,
            'network_calls_executed' => false,
            'remediation_executed' => false,
            'policy_audit_receipts_written' => false,
            'server_names_included' => false,
            'tool_names_included' => false,
            'raw_details_included' => false,
            'raw_payloads_included' => false,
            'paths_included' => false,
            'prompts_included' => false,
            'traces_included' => false,
            'environment_values_included' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $section
     */
    private function sectionReasonCode(string $name, array $section, string $status): string
    {
        return match ($name) {
            'offline_status' => match ($status) {
                'healthy' => 'offline_status_healthy',
                'blocked' => 'offline_status_unavailable',
                default => 'offline_status_degraded',
            },
            'audit_summary' => match ($status) {
                'healthy' => 'audit_reader_ok',
                'blocked' => 'audit_summary_unavailable',
                default => 'audit_reader_degraded',
            },
            'catalog_boundary' => count((array) ($section['leaked_internet_servers'] ?? [])) > 0
                ? 'internet_mcp_server_exposed'
                : ($status === 'healthy' ? 'catalog_boundary_clean' : 'catalog_boundary_degraded'),
            'local_runtime' => match ($status) {
                'healthy' => 'local_runtime_healthy',
                'blocked' => 'local_runtime_unavailable',
                default => 'local_runtime_degraded',
            },
            default => "section_{$status}",
        };
    }

    private function safeLabel(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $text) === 1 ? $text : 'redacted';
    }
}
