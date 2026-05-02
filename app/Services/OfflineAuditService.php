<?php

namespace App\Services;

use App\DTOs\PolicyDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OfflineAuditService — P02g receipt writer for 3b policy decisions and
 * mode transitions. Every row in offline_audit_events is replay-friendly:
 * classification fields live in columns, full context in JSON.
 *
 * All write paths are silently no-op when the table does not exist so the
 * policy layer remains callable in environments where the migration has
 * not yet been applied.
 */
class OfflineAuditService
{
    private ?bool $tableCache = null;

    public function summarizeWindow(int $hours = 24): array
    {
        $hours = max(1, $hours);

        if (! $this->tableExists()) {
            return [
                'result' => 'table_missing',
                'hours' => $hours,
                'total' => 0,
                'denied' => 0,
                'allowed' => 0,
                'confirmations' => 0,
                'mode_changes' => 0,
                'denied_per_hour' => 0.0,
                'top_denials' => [],
                'profiles' => [],
            ];
        }

        try {
            $summary = DB::selectOne(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN event_type = 'policy_deny' THEN 1 ELSE 0 END) AS denied,
                    SUM(CASE WHEN event_type = 'policy_allow' THEN 1 ELSE 0 END) AS allowed,
                    SUM(CASE WHEN event_type = 'policy_confirm' THEN 1 ELSE 0 END) AS confirmations,
                    SUM(CASE WHEN event_type = 'mode_change' THEN 1 ELSE 0 END) AS mode_changes
                 FROM offline_audit_events
                 WHERE created_at >= (NOW() - INTERVAL ? HOUR)",
                [$hours]
            );

            $topDenials = DB::select(
                "SELECT COALESCE(operation, '(none)') AS operation,
                        COALESCE(tool_class, '(none)') AS tool_class,
                        COALESCE(profile, '(none)') AS profile,
                        COUNT(*) AS count
                 FROM offline_audit_events
                 WHERE event_type = 'policy_deny'
                   AND created_at >= (NOW() - INTERVAL ? HOUR)
                 GROUP BY operation, tool_class, profile
                 ORDER BY count DESC, operation ASC
                 LIMIT 5",
                [$hours]
            );

            $profiles = DB::select(
                "SELECT COALESCE(profile, '(none)') AS profile,
                        COUNT(*) AS total,
                        SUM(CASE WHEN event_type = 'policy_deny' THEN 1 ELSE 0 END) AS denied
                 FROM offline_audit_events
                 WHERE created_at >= (NOW() - INTERVAL ? HOUR)
                 GROUP BY profile
                 ORDER BY total DESC, profile ASC",
                [$hours]
            );
        } catch (\Throwable $e) {
            Log::debug('OfflineAuditService: summarize query failed', [
                'error' => $e->getMessage(),
                'hours' => $hours,
            ]);

            return [
                'result' => 'query_failed',
                'hours' => $hours,
                'error' => $e->getMessage(),
            ];
        }

        $denied = (int) ($summary->denied ?? 0);

        return [
            'result' => 'ok',
            'hours' => $hours,
            'total' => (int) ($summary->total ?? 0),
            'denied' => $denied,
            'allowed' => (int) ($summary->allowed ?? 0),
            'confirmations' => (int) ($summary->confirmations ?? 0),
            'mode_changes' => (int) ($summary->mode_changes ?? 0),
            'denied_per_hour' => round($denied / $hours, 2),
            'top_denials' => array_map(static fn ($row) => [
                'operation' => (string) $row->operation,
                'tool_class' => (string) $row->tool_class,
                'profile' => (string) $row->profile,
                'count' => (int) $row->count,
            ], $topDenials),
            'profiles' => array_map(static fn ($row) => [
                'profile' => (string) $row->profile,
                'total' => (int) $row->total,
                'denied' => (int) $row->denied,
            ], $profiles),
        ];
    }

    public function recentEvents(int $limit = 20, int $hours = 24): array
    {
        $limit = max(1, min(100, $limit));
        $hours = max(1, $hours);

        if (! $this->tableExists()) {
            return [];
        }

        try {
            $rows = DB::select(
                "SELECT id, event_type, profile, offline_mode_active, operation,
                        tool_class, mcp_server, path_class, provider_class,
                        remote_domain_class, target, actor, reason, created_at
                 FROM offline_audit_events
                 WHERE created_at >= (NOW() - INTERVAL ? HOUR)
                 ORDER BY id DESC
                 LIMIT {$limit}",
                [$hours]
            );
        } catch (\Throwable $e) {
            Log::debug('OfflineAuditService: recent events query failed', [
                'error' => $e->getMessage(),
                'hours' => $hours,
                'limit' => $limit,
            ]);

            return [];
        }

        return array_map(static fn ($row) => [
            'id' => (int) $row->id,
            'event_type' => (string) $row->event_type,
            'profile' => $row->profile !== null ? (string) $row->profile : null,
            'offline_mode_active' => (bool) $row->offline_mode_active,
            'operation' => $row->operation !== null ? (string) $row->operation : null,
            'tool_class' => $row->tool_class !== null ? (string) $row->tool_class : null,
            'mcp_server' => $row->mcp_server !== null ? (string) $row->mcp_server : null,
            'path_class' => $row->path_class !== null ? (string) $row->path_class : null,
            'provider_class' => $row->provider_class !== null ? (string) $row->provider_class : null,
            'remote_domain_class' => $row->remote_domain_class !== null ? (string) $row->remote_domain_class : null,
            'target' => $row->target !== null ? (string) $row->target : null,
            'actor' => $row->actor !== null ? (string) $row->actor : null,
            'reason' => $row->reason !== null ? (string) $row->reason : null,
            'created_at' => (string) $row->created_at,
        ], $rows);
    }

    public function recordDecision(
        PolicyDecision $decision,
        string $operation = '',
        array $context = [],
        ?string $actor = null,
        ?string $mcpServer = null,
        ?string $target = null,
        ?bool $offlineModeActive = null,
    ): bool {
        if (! $this->tableExists()) {
            return false;
        }

        $eventType = $decision->allowed
            ? ($decision->requiresConfirmation ? 'policy_confirm' : 'policy_allow')
            : 'policy_deny';

        try {
            DB::insert(
                'INSERT INTO offline_audit_events (
                    event_type, profile, offline_mode_active,
                    operation, tool_class, mcp_server, mcp_trust_boundary,
                    path_class, provider_class, remote_domain_class,
                    target, actor, reason, context, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $eventType,
                    $decision->profile,
                    $offlineModeActive === null ? 0 : (int) $offlineModeActive,
                    $operation !== '' ? $operation : null,
                    $decision->toolClass,
                    $mcpServer,
                    $decision->mcpTrustBoundary,
                    $decision->pathClass,
                    $decision->providerClass,
                    $decision->remoteDomainClass,
                    $target,
                    $actor,
                    mb_substr($decision->reason, 0, 500),
                    json_encode([
                        'decision' => $decision->toArray(),
                        'context' => $context,
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            Log::debug('OfflineAuditService: decision insert failed', [
                'error' => $e->getMessage(),
                'profile' => $decision->profile,
            ]);

            return false;
        }
    }

    public function recordModeChange(
        ?string $from,
        string $to,
        ?string $actor = 'routing:profile',
        ?string $reason = null,
        array $context = [],
        ?bool $offlineModeActive = null,
    ): bool {
        if (! $this->tableExists()) {
            return false;
        }

        // R4 (2026-04-19 defect fix): previously this always wrote 0,
        // which made every mode_change receipt claim offline_mode was
        // disabled — useless for post-incident replay. When the caller
        // does not supply the state explicitly, resolve it from the
        // OfflinePolicyService so the row reflects reality.
        if ($offlineModeActive === null) {
            try {
                $offlineModeActive = app(OfflinePolicyService::class)->isOfflineModeActive();
            } catch (\Throwable $e) {
                // Fail-closed resolution: we cannot prove offline_mode is
                // off, so record it as on. This matches the
                // `isOfflineModeActive()` fail-closed policy and keeps
                // post-mortems defensible.
                $offlineModeActive = true;
            }
        }

        try {
            DB::insert(
                'INSERT INTO offline_audit_events (
                    event_type, profile, offline_mode_active,
                    actor, reason, context, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    'mode_change',
                    $to,
                    (int) $offlineModeActive,
                    $actor,
                    $reason !== null
                        ? mb_substr($reason, 0, 500)
                        : sprintf('Profile change: %s → %s', $from ?? 'default', $to),
                    json_encode(array_merge(['from' => $from, 'to' => $to], $context), JSON_UNESCAPED_SLASHES),
                ]
            );

            return true;
        } catch (\Throwable $e) {
            Log::debug('OfflineAuditService: mode_change insert failed', [
                'error' => $e->getMessage(),
                'from' => $from,
                'to' => $to,
            ]);

            return false;
        }
    }

    private function tableExists(): bool
    {
        if ($this->tableCache !== null) {
            return $this->tableCache;
        }

        try {
            $row = DB::selectOne("SHOW TABLES LIKE 'offline_audit_events'");

            return $this->tableCache = ($row !== null);
        } catch (\Throwable $e) {
            return $this->tableCache = false;
        }
    }
}
