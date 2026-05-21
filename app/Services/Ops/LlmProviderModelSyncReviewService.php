<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

class LlmProviderModelSyncReviewService
{
    public function collect(
        bool $includeInactive = false,
        bool $probeLive = true,
        int $connectTimeout = 5,
        int $timeout = 15,
        bool $compact = false
    ): array {
        $generatedAt = now();

        if (! Schema::hasTable('llm_instances')) {
            return [
                'generated_at' => $generatedAt->toIso8601String(),
                'status' => 'fail',
                'message' => 'llm_instances table is missing.',
                'summary' => [],
                'issues' => [
                    [
                        'code' => 'missing_llm_instances_table',
                        'severity' => 'error',
                        'message' => 'llm_instances table is missing.',
                    ],
                ],
            ];
        }

        $rows = DB::table('llm_instances')
            ->select([
                'instance_id',
                'instance_name',
                'instance_type',
                'base_url',
                'api_key',
                'api_key_env',
                'priority',
                'is_active',
                'routability',
                'compat_status',
                'capabilities',
                'supported_models',
                'config',
            ])
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', 1))
            ->orderBy('priority')
            ->orderBy('instance_id')
            ->get();

        $instances = [];
        $issues = [];
        $summary = [
            'instances_checked' => 0,
            'active_instances' => 0,
            'live_probe_ok' => 0,
            'live_probe_skipped' => 0,
            'live_probe_failed' => 0,
            'providers_with_new_models' => 0,
            'providers_with_deprecated_models' => 0,
            'new_model_count' => 0,
            'deprecated_model_count' => 0,
            'role_model_mismatch_count' => 0,
            'role_model_live_mismatch_count' => 0,
            'role_reasoning_effort_mismatch_count' => 0,
            'capability_role_mismatch_count' => 0,
            'active_non_authoritative_compat' => 0,
            'active_not_allowed' => 0,
            'pending_review_items' => 0,
        ];

        foreach ($rows as $row) {
            $instance = $this->instanceReport($row, $probeLive, $connectTimeout, $timeout, $compact);
            $instances[] = $instance;
            $instanceIssues = $this->issuesForInstance($instance);
            array_push($issues, ...$instanceIssues);
            $this->incrementSummary($summary, $instance, $instanceIssues);
        }

        $pendingReview = $this->pendingReviewItemSummary();
        $summary['pending_review_items'] = $pendingReview['count'];

        $hasError = collect($issues)->contains(fn (array $issue): bool => ($issue['severity'] ?? null) === 'error');
        $status = empty($issues) ? 'pass' : ($hasError ? 'fail' : 'review_needed');

        return [
            'generated_at' => $generatedAt->toIso8601String(),
            'status' => $status,
            'mode' => [
                'include_inactive' => $includeInactive,
                'probe_live' => $probeLive,
                'read_only' => true,
                'writes_review_queue' => false,
                'auto_promotes_roles' => false,
            ],
            'summary' => $summary,
            'pending_review' => $pendingReview,
            'issues' => $issues,
            'instances' => $instances,
        ];
    }

    private function instanceReport(object $row, bool $probeLive, int $connectTimeout, int $timeout, bool $compact): array
    {
        $active = (int) ($row->is_active ?? 0) === 1;
        $supportedModels = $this->decodeStringList($row->supported_models ?? null);
        $config = $this->decodeAssoc($row->config ?? null);
        $roleModels = $this->roleModels($config);
        $roleReasoningEfforts = $this->roleReasoningEfforts($config);
        $roleReasoningEffortMismatches = $this->roleReasoningEffortMismatches($roleReasoningEfforts, $roleModels, $config);
        $capabilities = $this->decodeCapabilities($row->capabilities ?? null);
        $allowLiveExtraModels = (bool) ($config['allow_live_extra_models'] ?? false);
        $live = $probeLive
            ? $this->probeProviderModels($row, $connectTimeout, $timeout)
            : ['status' => 'skipped', 'reason' => 'live_probe_disabled', 'models' => []];
        $liveModels = $live['models'];

        $newModels = $live['status'] === 'ok' && ! $allowLiveExtraModels
            ? array_values(array_diff($liveModels, $supportedModels))
            : [];
        $deprecatedModels = $live['status'] === 'ok'
            ? array_values(array_diff($supportedModels, $liveModels))
            : [];

        sort($newModels);
        sort($deprecatedModels);

        $roleNotSupported = [];
        $roleNotLive = [];
        foreach ($roleModels as $role => $model) {
            if (! in_array($model, $supportedModels, true)) {
                $roleNotSupported[] = [
                    'role' => $role,
                    'model' => $model,
                ];
            }

            if ($live['status'] === 'ok' && ! in_array($model, $liveModels, true)) {
                $roleNotLive[] = [
                    'role' => $role,
                    'model' => $model,
                ];
            }
        }

        $capabilityRoleMismatches = $this->capabilityRoleMismatches($roleModels, $capabilities);

        $payload = [
            'instance_id' => (string) ($row->instance_id ?? ''),
            'instance_name' => (string) ($row->instance_name ?? ''),
            'instance_type' => (string) ($row->instance_type ?? ''),
            'base_url_host' => $this->baseUrlHost((string) ($row->base_url ?? '')),
            'priority' => (int) ($row->priority ?? 0),
            'active' => $active,
            'routability' => (string) ($row->routability ?? 'blocked'),
            'compat_status' => (string) ($row->compat_status ?? 'provisional'),
            'capabilities' => array_keys($capabilities),
            'supported_model_count' => count($supportedModels),
            'role_models' => $roleModels,
            'role_reasoning_efforts' => $roleReasoningEfforts,
            'live_status' => $live['status'],
            'live_reason' => $live['reason'] ?? null,
            'live_extra_models_allowed' => $allowLiveExtraModels,
            'live_model_count' => count($liveModels),
            'new_models' => $compact ? $this->compactList($newModels) : $newModels,
            'deprecated_models' => $compact ? $this->compactList($deprecatedModels) : $deprecatedModels,
            'role_model_not_in_supported_models' => $roleNotSupported,
            'role_model_not_live' => $roleNotLive,
            'role_reasoning_effort_mismatches' => $roleReasoningEffortMismatches,
            'capability_role_mismatches' => $capabilityRoleMismatches,
        ];

        if (! $compact) {
            $payload['supported_models'] = $supportedModels;
            $payload['live_models'] = $liveModels;
        }

        return $payload;
    }

    private function probeProviderModels(object $row, int $connectTimeout, int $timeout): array
    {
        $instanceType = (string) ($row->instance_type ?? '');
        $baseUrl = rtrim((string) ($row->base_url ?? ''), '/');

        if ($instanceType === 'claude_cli') {
            return [
                'status' => 'skipped',
                'reason' => 'claude_cli_has_no_model_list_endpoint',
                'models' => [],
            ];
        }

        if ($instanceType === 'codex_cli') {
            return $this->probeCodexModels($row, $timeout);
        }

        if ($baseUrl === '') {
            return [
                'status' => 'skipped',
                'reason' => 'missing_base_url',
                'models' => [],
            ];
        }

        try {
            if ($instanceType === 'ollama') {
                $response = Http::connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->get($baseUrl.'/api/tags');

                if (! $response->successful()) {
                    return [
                        'status' => 'failed',
                        'reason' => 'http_'.$response->status(),
                        'models' => [],
                    ];
                }

                return [
                    'status' => 'ok',
                    'reason' => null,
                    'models' => $this->namesFromOllamaPayload((array) $response->json('models', [])),
                ];
            }

            if (in_array($instanceType, ['custom', 'openai', 'azure_openai', 'google_gemini'], true)) {
                $token = $this->runtimeSecret((string) ($row->api_key ?? ''), (string) ($row->api_key_env ?? ''));
                if ($token === null) {
                    return [
                        'status' => 'skipped',
                        'reason' => 'missing_api_key',
                        'models' => [],
                    ];
                }

                $headers = $this->extraHeaders($row->config ?? null);
                $response = Http::withHeaders($headers)
                    ->withToken($token)
                    ->connectTimeout($connectTimeout)
                    ->timeout($timeout)
                    ->get($baseUrl.'/models');

                if (! $response->successful()) {
                    return [
                        'status' => 'failed',
                        'reason' => 'http_'.$response->status(),
                        'models' => [],
                    ];
                }

                return [
                    'status' => 'ok',
                    'reason' => null,
                    'models' => $this->idsFromOpenAiPayload((array) $response->json('data', [])),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'reason' => $this->shortError($e->getMessage()),
                'models' => [],
            ];
        }

        return [
            'status' => 'skipped',
            'reason' => 'provider_type_not_supported',
            'models' => [],
        ];
    }

    private function issuesForInstance(array $instance): array
    {
        $issues = [];
        $context = [
            'instance_id' => $instance['instance_id'],
            'instance_type' => $instance['instance_type'],
        ];

        if ($instance['active'] && $instance['routability'] !== 'allowed') {
            $issues[] = [
                'code' => 'active_provider_not_allowed',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} is active but routability is {$instance['routability']}.",
                'context' => $context,
            ];
        }

        if ($instance['active'] && $instance['compat_status'] !== 'authoritative') {
            $issues[] = [
                'code' => 'active_provider_non_authoritative_compat',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} active compatibility status is {$instance['compat_status']}.",
                'context' => $context,
            ];
        }

        if ($instance['live_status'] === 'failed') {
            $issues[] = [
                'code' => 'provider_model_probe_failed',
                'severity' => 'warning',
                'message' => "{$instance['instance_id']} live model probe failed.",
                'context' => $context + [
                    'reason' => $instance['live_reason'],
                ],
            ];
        }

        if ($this->listCount($instance['new_models']) > 0) {
            $issues[] = [
                'code' => 'provider_has_new_models',
                'severity' => 'info',
                'message' => "{$instance['instance_id']} has live models not listed in supported_models.",
                'context' => $context + [
                    'count' => $this->listCount($instance['new_models']),
                ],
            ];
        }

        if ($this->listCount($instance['deprecated_models']) > 0) {
            $issues[] = [
                'code' => 'provider_has_deprecated_models',
                'severity' => $instance['active'] ? 'error' : 'warning',
                'message' => "{$instance['instance_id']} has supported_models that are absent from the live provider list.",
                'context' => $context + [
                    'count' => $this->listCount($instance['deprecated_models']),
                ],
            ];
        }

        foreach ($instance['role_model_not_in_supported_models'] as $mismatch) {
            $issues[] = [
                'code' => 'role_model_not_in_supported_models',
                'severity' => $instance['active'] ? 'warning' : 'info',
                'message' => "{$instance['instance_id']} {$mismatch['role']} role model is not in supported_models.",
                'context' => $context + $mismatch,
            ];
        }

        foreach ($instance['role_model_not_live'] as $mismatch) {
            $issues[] = [
                'code' => 'role_model_not_live',
                'severity' => $instance['active'] ? 'error' : 'warning',
                'message' => "{$instance['instance_id']} {$mismatch['role']} role model is absent from live provider list.",
                'context' => $context + $mismatch,
            ];
        }

        foreach ($instance['role_reasoning_effort_mismatches'] as $mismatch) {
            $issues[] = [
                'code' => 'role_reasoning_effort_not_supported',
                'severity' => $instance['active'] ? 'warning' : 'info',
                'message' => "{$instance['instance_id']} {$mismatch['role']} role reasoning effort is not supported.",
                'context' => $context + $mismatch,
            ];
        }

        foreach ($instance['capability_role_mismatches'] as $mismatch) {
            $issues[] = [
                'code' => 'capability_role_mismatch',
                'severity' => $instance['active'] ? 'warning' : 'info',
                'message' => "{$instance['instance_id']} {$mismatch['capability']} capability and role map disagree.",
                'context' => $context + $mismatch,
            ];
        }

        return $issues;
    }

    private function incrementSummary(array &$summary, array $instance, array $issues): void
    {
        $summary['instances_checked']++;

        if ($instance['active']) {
            $summary['active_instances']++;
        }

        if ($instance['live_status'] === 'ok') {
            $summary['live_probe_ok']++;
        } elseif ($instance['live_status'] === 'failed') {
            $summary['live_probe_failed']++;
        } else {
            $summary['live_probe_skipped']++;
        }

        $newCount = $this->listCount($instance['new_models']);
        $deprecatedCount = $this->listCount($instance['deprecated_models']);
        if ($newCount > 0) {
            $summary['providers_with_new_models']++;
            $summary['new_model_count'] += $newCount;
        }
        if ($deprecatedCount > 0) {
            $summary['providers_with_deprecated_models']++;
            $summary['deprecated_model_count'] += $deprecatedCount;
        }

        $summary['role_model_mismatch_count'] += count($instance['role_model_not_in_supported_models']);
        $summary['role_model_live_mismatch_count'] += count($instance['role_model_not_live']);
        $summary['role_reasoning_effort_mismatch_count'] += count($instance['role_reasoning_effort_mismatches']);
        $summary['capability_role_mismatch_count'] += count($instance['capability_role_mismatches']);

        foreach ($issues as $issue) {
            if (($issue['code'] ?? null) === 'active_provider_non_authoritative_compat') {
                $summary['active_non_authoritative_compat']++;
            }
            if (($issue['code'] ?? null) === 'active_provider_not_allowed') {
                $summary['active_not_allowed']++;
            }
        }
    }

    private function pendingReviewItemSummary(): array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return [
                'count' => 0,
                'latest' => null,
            ];
        }

        $rows = DB::table('agent_review_queue')
            ->select(['title', 'details', 'updated_at'])
            ->where('review_type', 'ai_model_update')
            ->where('agent_id', 'ai-ops')
            ->where('status', 'pending')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        $latest = $rows->first();

        return [
            'count' => $rows->count(),
            'latest' => $latest ? [
                'title' => (string) $latest->title,
                'updated_at' => $this->nullableIso8601($latest->updated_at ?? null),
                'provider_count' => count((array) data_get(json_decode((string) $latest->details, true), 'providers', [])),
            ] : null,
        ];
    }

    private function roleModels(array $config): array
    {
        $models = $config['models'] ?? [];
        if (! is_array($models)) {
            return [];
        }

        $out = [];
        foreach ($models as $role => $model) {
            if (is_string($role) && is_string($model) && trim($model) !== '') {
                $out[$role] = trim($model);
            }
        }

        ksort($out);

        return $out;
    }

    private function roleReasoningEfforts(array $config): array
    {
        $efforts = $config['reasoning_effort'] ?? [];
        if (! is_array($efforts)) {
            return [];
        }

        $out = [];
        foreach ($efforts as $role => $effort) {
            if (is_string($role) && is_string($effort) && trim($effort) !== '') {
                $out[$role] = trim($effort);
            }
        }

        ksort($out);

        return $out;
    }

    private function roleReasoningEffortMismatches(array $roleReasoningEfforts, array $roleModels, array $config): array
    {
        $mismatches = [];
        foreach ($roleReasoningEfforts as $role => $effort) {
            $model = $roleModels[$role] ?? $roleModels['standard'] ?? null;
            $supported = $this->supportedReasoningEffortsForModel($config, $model);
            if (! in_array($effort, $supported, true)) {
                $mismatches[] = [
                    'role' => $role,
                    'model' => $model,
                    'effort' => $effort,
                    'supported_efforts' => $supported,
                ];
            }
        }

        return $mismatches;
    }

    private function supportedReasoningEffortsForModel(array $config, ?string $model): array
    {
        $configured = $config['supported_reasoning_efforts'] ?? null;
        if (is_array($configured) && $model !== null && isset($configured[$model]) && is_array($configured[$model])) {
            return $this->decodeStringList($configured[$model]);
        }

        if (is_array($configured) && array_is_list($configured)) {
            $list = $this->decodeStringList($configured);

            return $list === [] ? ['low', 'medium', 'high', 'xhigh'] : $list;
        }

        return ['low', 'medium', 'high', 'xhigh'];
    }

    private function capabilityRoleMismatches(array $roleModels, array $capabilities): array
    {
        $mismatches = [];
        foreach (['vision', 'embedding'] as $capability) {
            $hasRole = isset($roleModels[$capability]);
            $hasCapability = isset($capabilities[$capability]);

            if ($hasRole !== $hasCapability) {
                $mismatches[] = [
                    'capability' => $capability,
                    'has_role' => $hasRole,
                    'has_capability' => $hasCapability,
                ];
            }
        }

        return $mismatches;
    }

    private function decodeCapabilities(mixed $raw): array
    {
        $decoded = $this->decodeJson($raw);
        $capabilities = [];

        if (is_array($decoded) && array_is_list($decoded)) {
            foreach ($decoded as $capability) {
                if (is_string($capability) && $capability !== '') {
                    $capabilities[$capability] = true;
                }
            }
        } elseif (is_array($decoded)) {
            foreach ($decoded as $capability => $enabled) {
                if ($enabled) {
                    $capabilities[(string) $capability] = true;
                }
            }
        }

        ksort($capabilities);

        return $capabilities;
    }

    private function decodeStringList(mixed $raw): array
    {
        $decoded = $this->decodeJson($raw);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        $items = array_values(array_unique($items));
        sort($items);

        return $items;
    }

    private function decodeAssoc(mixed $raw): array
    {
        $decoded = $this->decodeJson($raw);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeJson(mixed $raw): mixed
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function namesFromOllamaPayload(array $models): array
    {
        $names = [];
        foreach ($models as $model) {
            if (is_array($model) && isset($model['name']) && is_string($model['name'])) {
                $names[] = $model['name'];
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    private function idsFromOpenAiPayload(array $models): array
    {
        $ids = [];
        foreach ($models as $model) {
            if (is_array($model) && isset($model['id']) && is_string($model['id'])) {
                $ids[] = $model['id'];
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function probeCodexModels(object $row, int $timeout): array
    {
        $config = $this->decodeAssoc($row->config ?? null);
        $executable = is_string($config['executable'] ?? null) && trim($config['executable']) !== ''
            ? trim($config['executable'])
            : 'codex';

        try {
            $result = Process::timeout($timeout)->run([$executable, 'debug', 'models']);
            if (! $result->successful()) {
                return [
                    'status' => 'failed',
                    'reason' => 'codex_models_failed',
                    'models' => [],
                ];
            }

            $decoded = json_decode(trim($result->output()), true);
            $models = $this->idsFromCodexCatalog($decoded);
            if ($models === []) {
                return [
                    'status' => 'failed',
                    'reason' => 'codex_models_empty',
                    'models' => [],
                ];
            }

            return [
                'status' => 'ok',
                'reason' => null,
                'models' => $models,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'reason' => $this->shortError($e->getMessage()),
                'models' => [],
            ];
        }
    }

    private function idsFromCodexCatalog(mixed $payload): array
    {
        $rows = is_array($payload) ? ($payload['models'] ?? []) : [];
        if (! is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['slug'] ?? $row['id'] ?? null;
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function extraHeaders(mixed $configRaw): array
    {
        $config = $this->decodeAssoc($configRaw);
        $headers = $config['extra_headers'] ?? [];

        return is_array($headers) ? $headers : [];
    }

    private function runtimeSecret(string $stored, string $envKey): ?string
    {
        if ($stored !== '') {
            return $stored;
        }

        if ($envKey === '') {
            return null;
        }

        $value = getenv($envKey);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $value = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function baseUrlHost(string $baseUrl): string
    {
        if ($baseUrl === '') {
            return '';
        }

        return (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '');
    }

    private function shortError(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? '';

        if (str_contains($message, 'cURL error 7')) {
            return 'connection_failed';
        }

        if (str_contains($message, 'cURL error 28') || str_contains(strtolower($message), 'timed out')) {
            return 'timeout';
        }

        if (str_contains(strtolower($message), 'could not resolve')) {
            return 'dns_failed';
        }

        return 'request_failed';
    }

    private function nullableIso8601(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return is_string($value) ? $value : null;
        }
    }

    private function compactList(array $items): array
    {
        return [
            'count' => count($items),
            'sample' => array_slice($items, 0, 10),
        ];
    }

    private function listCount(array $items): int
    {
        if (array_key_exists('count', $items) && is_int($items['count'])) {
            return $items['count'];
        }

        return count($items);
    }
}
