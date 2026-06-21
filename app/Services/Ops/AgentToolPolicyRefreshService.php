<?php

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AgentToolPolicyRefreshService
{
    /**
     * @return array<string, mixed>
     */
    public function refresh(bool $apply = false, ?string $name = null, int $limit = 0): array
    {
        $startedAt = now();

        if (! Schema::hasTable('agent_tool_registry')) {
            return [
                'success' => false,
                'status' => 'blocked',
                'message' => 'agent_tool_registry table is missing.',
                'generated_at' => $startedAt->toIso8601String(),
            ];
        }

        $columns = array_flip(Schema::getColumnListing('agent_tool_registry'));
        $required = ['id', 'name', 'service_class', 'method', 'enabled'];
        foreach ($required as $column) {
            if (! isset($columns[$column])) {
                return [
                    'success' => false,
                    'status' => 'blocked',
                    'message' => "agent_tool_registry.{$column} column is missing.",
                    'generated_at' => $startedAt->toIso8601String(),
                ];
            }
        }

        $select = array_values(array_intersect([
            'id',
            'name',
            'service_class',
            'method',
            'permissions',
            'risk_level',
            'category',
            'mcp_server',
            'mcp_tool',
            'enabled',
            'availability_status',
            'last_checked_at',
            'last_error',
            'schema_generation',
            'privacy_class',
            'allows_private_data',
            'max_result_bytes',
        ], array_keys($columns)));

        $query = DB::table('agent_tool_registry')->select($select)->orderBy('name');
        if ($name !== null && trim($name) !== '') {
            $query->where('name', trim($name));
        }
        if ($limit > 0) {
            $query->limit(min($limit, 1000));
        }

        $rows = $query->get();
        $summary = [
            'total_scanned' => 0,
            'changed_total' => 0,
            'unchanged_total' => 0,
            'applied_total' => 0,
            'available_total' => 0,
            'unavailable_total' => 0,
            'disabled_total' => 0,
            'internal_private_total' => 0,
            'external_no_private_total' => 0,
            'allows_private_data_total' => 0,
            'denies_private_data_total' => 0,
        ];
        $changes = [];
        $now = now();

        foreach ($rows as $row) {
            $summary['total_scanned']++;
            $decision = $this->classify($row);
            $summary[$decision['availability_status'].'_total'] = ($summary[$decision['availability_status'].'_total'] ?? 0) + 1;
            $summary[$decision['privacy_class'].'_total'] = ($summary[$decision['privacy_class'].'_total'] ?? 0) + 1;
            ((bool) $decision['allows_private_data'])
                ? $summary['allows_private_data_total']++
                : $summary['denies_private_data_total']++;

            $updates = $this->buildUpdates($row, $decision, $columns, $now);
            if ($updates === []) {
                $summary['unchanged_total']++;

                continue;
            }

            $summary['changed_total']++;
            $changes[] = [
                'name' => (string) $row->name,
                'availability_status' => $decision['availability_status'],
                'privacy_class' => $decision['privacy_class'],
                'allows_private_data' => (bool) $decision['allows_private_data'],
                'last_error' => $decision['last_error'],
                'changed_fields' => array_keys($updates),
            ];

            if ($apply) {
                DB::table('agent_tool_registry')
                    ->where('id', $row->id)
                    ->update($updates);
                $summary['applied_total']++;
            }
        }

        return [
            'success' => true,
            'status' => $summary['unavailable_total'] > 0 ? 'watch' : 'ok',
            'mode' => $apply ? 'apply' : 'dry_run',
            'generated_at' => $startedAt->toIso8601String(),
            'summary' => $summary,
            'changes' => $changes,
        ];
    }

    /**
     * @return array{availability_status: string, last_error: ?string, privacy_class: string, allows_private_data: bool, schema_generation: int, max_result_bytes: int}
     */
    private function classify(object $row): array
    {
        [$availability, $lastError] = $this->availability($row);
        [$privacyClass, $allowsPrivateData] = $this->privacy($row);

        return [
            'availability_status' => $availability,
            'last_error' => $lastError,
            'privacy_class' => $privacyClass,
            'allows_private_data' => $allowsPrivateData,
            'schema_generation' => (int) config('agent_tool_policy.schema_generation', 2),
            'max_result_bytes' => $this->maxResultBytes($row),
        ];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function availability(object $row): array
    {
        if (! (bool) ($row->enabled ?? false)) {
            return ['disabled', 'Tool is disabled in agent_tool_registry.'];
        }

        $mcpServer = trim((string) ($row->mcp_server ?? ''));
        $mcpTool = trim((string) ($row->mcp_tool ?? ''));
        if ($mcpServer !== '' || $mcpTool !== '') {
            if ($mcpServer === '' || $mcpTool === '') {
                return ['unavailable', 'MCP bridge row is missing mcp_server or mcp_tool.'];
            }

            $serverConfig = config("mcp.servers.{$mcpServer}");
            if (! is_array($serverConfig)) {
                return ['unavailable', "MCP server is not configured: {$mcpServer}."];
            }

            if (! (bool) ($serverConfig['enabled'] ?? false)) {
                return ['disabled', "MCP server is disabled: {$mcpServer}."];
            }

            return ['available', null];
        }

        $serviceClass = trim((string) ($row->service_class ?? ''));
        $method = trim((string) ($row->method ?? ''));
        if ($serviceClass === '' || ! class_exists($serviceClass)) {
            return ['unavailable', "Service class not found: {$serviceClass}."];
        }

        if ($method === '' || ! method_exists($serviceClass, $method)) {
            return ['unavailable', "Service method not found: {$serviceClass}::{$method}."];
        }

        return ['available', null];
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function privacy(object $row): array
    {
        if ($this->isExternalNoPrivateTool($row)) {
            return [
                (string) config('agent_tool_policy.privacy.external_no_private_class', 'external_no_private'),
                false,
            ];
        }

        return [
            (string) config('agent_tool_policy.privacy.default_class', 'internal_private'),
            (bool) config('agent_tool_policy.privacy.default_allows_private_data', true),
        ];
    }

    private function isExternalNoPrivateTool(object $row): bool
    {
        $name = Str::lower((string) ($row->name ?? ''));
        $serviceClass = (string) ($row->service_class ?? '');
        $mcpServer = Str::lower((string) ($row->mcp_server ?? ''));

        $externalServers = array_map(
            static fn (mixed $value): string => Str::lower((string) $value),
            (array) config('agent_tool_policy.privacy.external_no_private_mcp_servers', [])
        );
        if ($mcpServer !== '' && in_array($mcpServer, $externalServers, true)) {
            return true;
        }

        foreach ((array) config('agent_tool_policy.privacy.external_no_private_tool_prefixes', []) as $prefix) {
            $prefix = Str::lower((string) $prefix);
            if ($prefix !== '' && Str::startsWith($name, $prefix)) {
                return true;
            }
        }

        foreach ((array) config('agent_tool_policy.privacy.external_no_private_service_patterns', []) as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && str_contains($serviceClass, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function maxResultBytes(object $row): int
    {
        $current = (int) ($row->max_result_bytes ?? 0);
        if ($current > 0) {
            return $current;
        }

        $risk = strtolower(trim((string) ($row->risk_level ?? 'default')));
        $limits = (array) config('agent_tool_policy.result_limits', []);

        return (int) ($limits[$risk] ?? $limits['default'] ?? 8000);
    }

    /**
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function buildUpdates(object $row, array $decision, array $columns, mixed $now): array
    {
        $updates = [];
        foreach ([
            'availability_status',
            'last_error',
            'schema_generation',
            'privacy_class',
            'allows_private_data',
            'max_result_bytes',
        ] as $field) {
            if (! isset($columns[$field])) {
                continue;
            }

            $current = $row->{$field} ?? null;
            $next = $decision[$field];
            if ($field === 'allows_private_data') {
                $current = (bool) $current;
                $next = (bool) $next;
            } elseif ($field === 'schema_generation' || $field === 'max_result_bytes') {
                $current = (int) $current;
                $next = (int) $next;
            } else {
                $current = $current === null ? null : (string) $current;
                $next = $next === null ? null : (string) $next;
            }

            if ($current !== $next) {
                $updates[$field] = $next;
            }
        }

        if (isset($columns['last_checked_at'])) {
            $updates['last_checked_at'] = $now;
        }
        if (isset($columns['updated_at']) && $updates !== []) {
            $updates['updated_at'] = $now;
        }

        return $updates;
    }
}
