<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LlmCapabilityWatchCommand extends Command
{
    protected $signature = 'llm:capability-watch
        {--json : Emit machine-readable JSON}
        {--compact : Emit aggregate-only output}';

    protected $description = 'Read-only LLM/runtime capability posture and watch-family summary';

    public function handle(): int
    {
        $payload = $this->collect();
        $output = $this->option('compact') ? $this->compact($payload) : $payload;

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $summary = $payload['summary'];
        $this->line(sprintf(
            'LLM capability watch: %s active=%d local=%d sidecars=%d bitnet=%d public_external_active=%d recent_logs_24h=%d issues=%d',
            strtoupper($payload['status']),
            $summary['active_instances'],
            $summary['active_local_instances'],
            $summary['active_local_sidecars'],
            $summary['active_bitnet_instances'],
            $summary['active_public_external_instances'],
            $summary['recent_request_logs_24h'],
            count($payload['issues'])
        ));

        foreach ($payload['issues'] as $issue) {
            $this->line(sprintf('[%s] %s: %s', $issue['severity'], $issue['code'], $issue['message']));
        }

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function collect(): array
    {
        $instances = $this->instances();
        $models = $this->models();
        $requestLog = $this->requestLogSummary();
        $watchConfig = config('llm_capability_watch', []);
        $evalConfig = config('ollama_eval', []);

        $summary = [
            'instances_total' => count($instances),
            'active_instances' => 0,
            'active_local_instances' => 0,
            'active_local_sidecars' => 0,
            'active_bitnet_instances' => 0,
            'active_codex_instances' => 0,
            'active_public_external_instances' => 0,
            'bench_only_external_instances' => 0,
            'models_total' => count($models),
            'available_models' => 0,
            'vetted_models' => 0,
            'testing_models' => 0,
            'bench_models' => 0,
            'watch_models' => 0,
            'zero_usage_available_models' => 0,
            'recent_request_logs_24h' => $requestLog['total_24h'],
            'recent_bitnet_logs_24h' => $requestLog['bitnet_24h'],
            'watch_project_count' => count($watchConfig['projects'] ?? []),
            'watch_capability_count' => count($watchConfig['capabilities'] ?? []),
            'eval_case_count' => count($evalConfig['eval_cases'] ?? []),
            'eval_regression_case_count' => count($evalConfig['regression_cases'] ?? []),
            'eval_candidate_count' => count($evalConfig['candidate_queue'] ?? []),
        ];

        foreach ($instances as $instance) {
            $active = (bool) ($instance->is_active ?? false);
            $type = (string) ($instance->instance_type ?? '');
            $scope = (string) ($instance->data_privacy_scope ?? '');
            $instanceId = strtolower((string) ($instance->instance_id ?? ''));
            $backend = strtolower((string) ($instance->compat_backend ?? ''));

            if (! $active) {
                if (($instance->routability ?? null) === 'bench_only' && ! in_array($type, ['ollama', 'local_llm', 'codex_cli'], true)) {
                    $summary['bench_only_external_instances']++;
                }

                continue;
            }

            $summary['active_instances']++;
            if (in_array($type, ['ollama', 'local_llm'], true)) {
                $summary['active_local_instances']++;
            }
            if ($type === 'local_llm' || str_contains($instanceId, 'bitnet') || $backend === 'bitnet.cpp') {
                $summary['active_local_sidecars']++;
            }
            if (str_contains($instanceId, 'bitnet') || $backend === 'bitnet.cpp') {
                $summary['active_bitnet_instances']++;
            }
            if ($type === 'codex_cli') {
                $summary['active_codex_instances']++;
            }
            if (! in_array($type, ['ollama', 'local_llm', 'codex_cli'], true) && $scope === 'public_only') {
                $summary['active_public_external_instances']++;
            }
        }

        foreach ($models as $model) {
            if ((bool) ($model->is_available ?? false)) {
                $summary['available_models']++;
                if ((int) ($model->total_requests ?? 0) === 0) {
                    $summary['zero_usage_available_models']++;
                }
            }

            match ((string) ($model->status ?? '')) {
                'vetted' => $summary['vetted_models']++,
                'testing' => $summary['testing_models']++,
                'bench' => $summary['bench_models']++,
                'watch' => $summary['watch_models']++,
                default => null,
            };
        }

        $issues = $this->issues($summary, $requestLog);

        return [
            'generated_at' => now()->toIso8601String(),
            'status' => $this->status($issues),
            'read_only' => true,
            'no_write' => true,
            'private_data_shared' => false,
            'summary' => $summary,
            'request_log' => $requestLog,
            'watch' => [
                'source_posture' => $watchConfig['source_posture'] ?? 'watch_only',
                'capabilities' => array_values($watchConfig['capabilities'] ?? []),
                'project_families' => $this->projectFamilies($watchConfig['projects'] ?? []),
                'promotion_requirements' => array_values($watchConfig['promotion_requirements'] ?? []),
            ],
            'issues' => $issues,
            'next_actions' => $this->nextActions($summary, $issues),
        ];
    }

    /** @return list<object> */
    private function instances(): array
    {
        if (! Schema::hasTable('llm_instances')) {
            return [];
        }

        return DB::table('llm_instances')
            ->select(['instance_id', 'instance_type', 'is_active', 'routability', 'data_privacy_scope', 'compat_backend'])
            ->get()
            ->all();
    }

    /** @return list<object> */
    private function models(): array
    {
        if (! Schema::hasTable('ollama_models')) {
            return [];
        }

        return DB::table('ollama_models')
            ->select(['model_name', 'status', 'is_available', 'total_requests'])
            ->get()
            ->all();
    }

    /** @return array<string,mixed> */
    private function requestLogSummary(): array
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return [
                'available' => false,
                'total_24h' => 0,
                'success_24h' => 0,
                'failure_24h' => 0,
                'bitnet_24h' => 0,
                'distinct_callers_24h' => 0,
            ];
        }

        $row = DB::table('llm_model_request_logs')
            ->selectRaw('COUNT(*) AS total_24h')
            ->selectRaw('SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS success_24h')
            ->selectRaw('SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failure_24h')
            ->selectRaw("SUM(CASE WHEN instance_id LIKE '%bitnet%' THEN 1 ELSE 0 END) AS bitnet_24h")
            ->selectRaw('COUNT(DISTINCT caller) AS distinct_callers_24h')
            ->where('created_at', '>=', now()->subDay())
            ->first();

        return [
            'available' => true,
            'total_24h' => (int) ($row->total_24h ?? 0),
            'success_24h' => (int) ($row->success_24h ?? 0),
            'failure_24h' => (int) ($row->failure_24h ?? 0),
            'bitnet_24h' => (int) ($row->bitnet_24h ?? 0),
            'distinct_callers_24h' => (int) ($row->distinct_callers_24h ?? 0),
        ];
    }

    /** @param list<array<string,string>> $projects */
    private function projectFamilies(array $projects): array
    {
        $families = [];
        foreach ($projects as $project) {
            $family = (string) ($project['family'] ?? 'other');
            $families[$family] = ($families[$family] ?? 0) + 1;
        }
        ksort($families);

        return $families;
    }

    private function issues(array $summary, array $requestLog): array
    {
        $issues = [];

        if (! $requestLog['available']) {
            $issues[] = [
                'code' => 'model_request_log_missing',
                'severity' => 'notice',
                'message' => 'llm_model_request_logs is not migrated yet; per-call model/caller telemetry is unavailable.',
            ];
        }

        if (($summary['active_public_external_instances'] ?? 0) > 0) {
            $issues[] = [
                'code' => 'public_external_active',
                'severity' => 'warning',
                'message' => 'A public-only external provider is active; keep private-data gates strict.',
            ];
        }

        if (($summary['active_bitnet_instances'] ?? 0) > 0 && $requestLog['available'] && ($requestLog['bitnet_24h'] ?? 0) === 0) {
            $issues[] = [
                'code' => 'bitnet_no_recent_telemetry',
                'severity' => 'notice',
                'message' => 'BitNet is active but has no per-call telemetry in the last 24 hours.',
            ];
        }

        if (($summary['zero_usage_available_models'] ?? 0) > 0) {
            $issues[] = [
                'code' => 'available_models_without_usage_counters',
                'severity' => 'notice',
                'message' => 'Some available models still have zero aggregate usage counters; allow new telemetry to burn in, then review stale candidates.',
            ];
        }

        return $issues;
    }

    private function status(array $issues): string
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === 'warning') {
                return 'watch';
            }
        }

        return $issues === [] ? 'pass' : 'observe';
    }

    private function nextActions(array $summary, array $issues): array
    {
        $actions = [
            'Run ollama:drift-check --json --compact --no-fail before promoting local model changes.',
            'Run ollama:eval-scorecard --json --compact and focused eval packs before changing active roles.',
            'Keep public external providers bench-only/public-only unless privacy and reliability gates pass.',
        ];

        if (($summary['active_bitnet_instances'] ?? 0) > 0) {
            $actions[] = 'Use BitNet only for narrow privacy/junk/wrong-subject prefiltering until evals justify a wider role.';
        }

        if ($issues !== []) {
            $actions[] = 'Review capability-watch issues before enabling new runtime routes.';
        }

        return $actions;
    }

    private function compact(array $payload): array
    {
        return [
            'generated_at' => $payload['generated_at'],
            'status' => $payload['status'],
            'read_only' => true,
            'no_write' => true,
            'private_data_shared' => false,
            'summary' => $payload['summary'],
            'issue_counts' => array_count_values(array_map(static fn (array $issue): string => $issue['severity'], $payload['issues'])),
            'issues' => array_map(static fn (array $issue): array => [
                'code' => $issue['code'],
                'severity' => $issue['severity'],
            ], $payload['issues']),
            'watch_project_families' => $payload['watch']['project_families'],
            'next_actions' => $payload['next_actions'],
        ];
    }
}
