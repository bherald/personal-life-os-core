<?php

namespace App\Services\Ops;

use App\Services\OfflinePolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class BackendQuarantineService
{
    public const SCHEMA = 'plos.backend_quarantine.v1';

    private const PRIMARY_PROVIDER_IDS = [
        'ollama_primary',
        'ollama_secondary',
        'codex_exec',
    ];

    public function __construct(private readonly OfflinePolicyService $offlinePolicy) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function quarantine(string $type, string $id, array $options = []): array
    {
        $type = strtolower(trim($type));
        $id = trim($id);
        $dryRun = (bool) ($options['dry_run'] ?? true);
        $confirm = (bool) ($options['confirm'] ?? false);
        $allowPrimary = (bool) ($options['allow_primary'] ?? false);
        $actor = $this->bounded((string) ($options['actor'] ?? 'operator'), 80);
        $reason = $this->reason((string) ($options['reason'] ?? 'manual quarantine'));

        if (! in_array($type, ['provider', 'tool'], true)) {
            return $this->failure($type, $id, 'invalid_type', 'Type must be provider or tool.');
        }

        if ($id === '') {
            return $this->failure($type, $id, 'missing_id', 'Target id is required.');
        }

        if (! $dryRun && ! $confirm) {
            return $this->failure($type, $id, 'confirm_required', 'Use --confirm for quarantine writes.');
        }

        return $type === 'provider'
            ? $this->quarantineProvider($id, $reason, $actor, $dryRun, $allowPrimary)
            : $this->quarantineTool($id, $reason, $actor, $dryRun);
    }

    /**
     * @return array<string, mixed>
     */
    private function quarantineProvider(string $instanceId, string $reason, string $actor, bool $dryRun, bool $allowPrimary): array
    {
        if (! Schema::hasTable('llm_instances')) {
            return $this->failure('provider', $instanceId, 'missing_llm_instances_table', 'llm_instances table is missing.');
        }

        $row = DB::table('llm_instances')->where('instance_id', $instanceId)->first();
        if ($row === null) {
            return $this->failure('provider', $instanceId, 'provider_not_found', "Provider '{$instanceId}' was not found.");
        }

        $isPrimary = in_array($instanceId, self::PRIMARY_PROVIDER_IDS, true)
            || (string) ($row->instance_type ?? '') === 'ollama';

        if ($isPrimary && ! $allowPrimary) {
            return [
                'success' => false,
                'schema' => self::SCHEMA,
                'type' => 'provider',
                'id' => $instanceId,
                'dry_run' => $dryRun,
                'blocked' => true,
                'error_code' => 'primary_lane_requires_allow_primary',
                'message' => 'Primary local/Codex lanes require --allow-primary before quarantine.',
                'current' => $this->providerPayload($row),
            ];
        }

        $now = now();
        $updates = $this->filterExistingColumns('llm_instances', [
            'is_active' => 0,
            'is_healthy' => 0,
            'routability' => 'blocked',
            'circuit_state' => 'open',
            'circuit_opened_at' => $now,
            'circuit_retry_at' => null,
            'quarantine_status' => 'quarantined',
            'quarantined_at' => $now,
            'quarantine_reason' => $reason,
            'quarantine_source' => $actor,
            'last_failure_at' => $now,
            'notes' => $this->appendNote($row->notes ?? null, $this->noteLine('provider', $reason, $actor)),
            'updated_at' => $now,
        ]);

        if (! $dryRun) {
            DB::table('llm_instances')
                ->where('instance_id', $instanceId)
                ->update($updates);
            Cache::forget('external_api_providers');
        }

        return [
            'success' => true,
            'schema' => self::SCHEMA,
            'type' => 'provider',
            'id' => $instanceId,
            'dry_run' => $dryRun,
            'primary_lane' => $isPrimary,
            'provider_class' => $this->offlinePolicy->classifyProvider($row),
            'current' => $this->providerPayload($row),
            'changes' => $this->changesOnly($updates),
            'cache_cleared' => ! $dryRun,
            'result_text' => $dryRun
                ? "Dry-run provider quarantine for {$instanceId}; no DB changes written."
                : "Provider {$instanceId} quarantined.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quarantineTool(string $toolName, string $reason, string $actor, bool $dryRun): array
    {
        if (! Schema::hasTable('agent_tool_registry')) {
            return $this->failure('tool', $toolName, 'missing_agent_tool_registry_table', 'agent_tool_registry table is missing.');
        }

        $row = DB::table('agent_tool_registry')->where('name', $toolName)->first();
        if ($row === null) {
            return $this->failure('tool', $toolName, 'tool_not_found', "Tool '{$toolName}' was not found.");
        }

        $now = now();
        $updates = $this->filterExistingColumns('agent_tool_registry', [
            'enabled' => 0,
            'availability_status' => 'disabled',
            'last_checked_at' => $now,
            'last_error' => 'Quarantined: '.$reason,
            'notes' => $this->appendNote($row->notes ?? null, $this->noteLine('tool', $reason, $actor)),
            'updated_at' => $now,
        ]);

        if (! $dryRun) {
            DB::table('agent_tool_registry')
                ->where('name', $toolName)
                ->update($updates);
        }

        return [
            'success' => true,
            'schema' => self::SCHEMA,
            'type' => 'tool',
            'id' => $toolName,
            'dry_run' => $dryRun,
            'current' => $this->toolPayload($row),
            'changes' => $this->changesOnly($updates),
            'result_text' => $dryRun
                ? "Dry-run tool quarantine for {$toolName}; no DB changes written."
                : "Tool {$toolName} quarantined.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $type, string $id, string $code, string $message): array
    {
        return [
            'success' => false,
            'schema' => self::SCHEMA,
            'type' => $type,
            'id' => $id,
            'error_code' => $code,
            'message' => $message,
            'result_text' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerPayload(object $row): array
    {
        return [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'instance_type' => (string) ($row->instance_type ?? ''),
            'is_active' => (int) ($row->is_active ?? 0),
            'is_healthy' => (int) ($row->is_healthy ?? 0),
            'routability' => (string) ($row->routability ?? ''),
            'circuit_state' => (string) ($row->circuit_state ?? ''),
            'quarantine_status' => (string) ($row->quarantine_status ?? 'none'),
            'quarantined_at' => $row->quarantined_at ?? null,
            'quarantine_reason' => $this->bounded($this->redact((string) ($row->quarantine_reason ?? '')), 500),
            'allows_private_data' => (int) ($row->allows_private_data ?? 0),
            'data_privacy_scope' => (string) ($row->data_privacy_scope ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolPayload(object $row): array
    {
        return [
            'name' => (string) ($row->name ?? ''),
            'enabled' => (int) ($row->enabled ?? 0),
            'risk_level' => (string) ($row->risk_level ?? ''),
            'category' => (string) ($row->category ?? ''),
            'availability_status' => (string) ($row->availability_status ?? ''),
            'privacy_class' => (string) ($row->privacy_class ?? ''),
            'allows_private_data' => (int) ($row->allows_private_data ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function changesOnly(array $updates): array
    {
        $changes = $updates;
        unset($changes['notes'], $changes['updated_at']);

        foreach ($changes as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $changes[$key] = $value->format('c');
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @return array<string, mixed>
     */
    private function filterExistingColumns(string $table, array $updates): array
    {
        $columns = array_flip(Schema::getColumnListing($table));

        return array_filter(
            $updates,
            static fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function noteLine(string $type, string $reason, string $actor): string
    {
        return sprintf(
            '[%s] HWR-008 quarantine type=%s actor=%s reason=%s',
            now()->toDateTimeString(),
            $type,
            $actor,
            $reason
        );
    }

    private function appendNote(mixed $existing, string $line): string
    {
        $existing = trim((string) ($existing ?? ''));
        $value = $existing === '' ? $line : $existing."\n".$line;

        return Str::limit($value, 4000, '');
    }

    private function reason(string $reason): string
    {
        $reason = $this->redact($reason);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));

        return $this->bounded($reason === '' ? 'manual quarantine' : $reason, 500);
    }

    private function bounded(string $value, int $limit): string
    {
        return Str::limit(trim($value), $limit, '');
    }

    private function redact(string $text): string
    {
        $text = (string) preg_replace('/\b(?:password|passwd|pwd|api[_-]?key|apikey|secret|token|bearer|authorization)\s*[:=]\s*["\']?[^"\'\s,;{}<>]{3,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=-]{10,}/i', '[REDACTED_SECRET]', $text);
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9]{20,}\b/', '[REDACTED_KEY]', $text);
        $text = (string) preg_replace('~/home/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);
        $text = (string) preg_replace('~/Users/[^\\s"\'<>),;]+~', '[REDACTED_LOCAL_PATH]', $text);

        return $text;
    }
}
