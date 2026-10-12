<?php

namespace App\Services\Ops;

use App\Services\AIService;
use App\Services\OfflinePolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LlmPortfolioReviewService
{
    private const POLICY_KEY = 'llm_portfolio_review_policy';

    private const STATE_KEY = 'llm_portfolio_review_state';

    public function __construct(
        private readonly LlmProviderModelSyncReviewService $inventory,
        private readonly AIService $ai,
        private readonly OfflinePolicyService $offlinePolicy,
    ) {}

    /** @return array<string,mixed> */
    public function run(bool $force = false, bool $useCodex = true, bool $probeLive = true): array
    {
        $policy = $this->policy();
        if (($policy['enabled'] ?? false) !== true) {
            return $this->result('disabled', ['reason' => 'policy_disabled']);
        }

        $due = $this->dueState($policy, $force);
        if (! $due['due']) {
            return $this->result('not_due', ['due' => $due]);
        }

        $inventory = $this->inventory->collect(
            includeInactive: true,
            probeLive: $probeLive,
            connectTimeout: (int) data_get($policy, 'inventory.connect_timeout_seconds', 5),
            timeout: (int) data_get($policy, 'inventory.timeout_seconds', 20),
            compact: false,
        );
        $evidence = [
            'inventory' => $this->inventoryForCodex($inventory),
            'hardware_policy' => $this->hardwarePolicy(),
            'telemetry_14d' => $this->telemetrySummary(),
            'decision_contract' => [
                'required_capability_lanes' => $policy['required_capability_lanes'] ?? [],
                'official_sources_required_for_new_or_retire' => true,
                'source_classes' => [
                    'official_hugging_face_organization_or_model_card',
                    'official_repository_or_release_notes',
                    'vendor_model_catalog',
                    'technical_report_or_research_paper',
                    'credible_independent_evaluation',
                    'sustained_community_feedback_as_lead_only',
                    'local_plos_telemetry',
                ],
                'source_requirements' => [
                    'exact_model_and_revision',
                    'license_or_use_terms',
                    'artifact_size_and_hardware_fit_for_local_models',
                    'claimed_context_tool_schema_vision_capabilities',
                    'evidence_grade_and_feedback_signal',
                ],
                'candidate_is_not_production' => true,
                'operator_approval_required' => true,
                'auto_install' => false,
                'auto_promote' => false,
                'auto_retire' => false,
                'prompt_compression' => false,
                'precompaction' => false,
            ],
        ];

        if (! $useCodex) {
            return $this->result('observed', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => ['executed' => false, 'reason' => 'disabled_by_caller'],
                'review' => null,
            ]);
        }

        $providerDecision = $this->codexProviderDecision();
        if (! $providerDecision['allowed']) {
            return $this->result('blocked', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => ['executed' => false, 'reason' => $providerDecision['reason']],
                'review' => null,
            ]);
        }

        try {
            $codexResult = $this->ai->process($this->prompt($evidence), [
                'prefer_codex' => true,
                'codex_required' => true,
                'use_cache' => false,
                'dedup' => false,
                'task_type' => 'llm_portfolio_review',
                'model_role' => (string) data_get($policy, 'codex.model_role', 'quality'),
                'codex_reasoning_effort' => (string) data_get($policy, 'codex.reasoning_effort', 'high'),
                'codex_sandbox' => 'read-only',
                'timeout' => (int) data_get($policy, 'codex.timeout_seconds', 900),
                'output_schema' => resource_path('schemas/llm-portfolio-review.schema.json'),
            ]);
        } catch (\Throwable $e) {
            $codexResult = [
                'success' => false,
                'error_type' => 'execution_failed',
                'error' => $e->getMessage(),
            ];
        }

        if (! ($codexResult['success'] ?? false)) {
            return $this->result('codex_failed', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => [
                    'executed' => true,
                    'success' => false,
                    'model' => $codexResult['model'] ?? null,
                    'error_type' => $codexResult['error_type'] ?? 'unknown',
                ],
                'review' => null,
            ]);
        }

        $analysis = json_decode((string) ($codexResult['response'] ?? ''), true);
        if (! is_array($analysis)) {
            return $this->result('codex_failed', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => ['executed' => true, 'success' => false, 'error_type' => 'invalid_json'],
                'review' => null,
            ]);
        }

        $coverageLanes = array_values(array_filter(array_map(
            static fn (mixed $row): ?string => is_array($row) && is_string($row['capability_lane'] ?? null)
                ? $row['capability_lane']
                : null,
            (array) ($analysis['coverage'] ?? [])
        )));
        $requiredLanes = array_values(array_map('strval', (array) ($policy['required_capability_lanes'] ?? [])));
        $missingLanes = array_values(array_diff($requiredLanes, array_unique($coverageLanes)));
        if ($missingLanes !== [] || count($coverageLanes) !== count(array_unique($coverageLanes))) {
            return $this->result('codex_failed', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => [
                    'executed' => true,
                    'success' => false,
                    'model' => $codexResult['model'] ?? null,
                    'error_type' => 'incomplete_capability_coverage',
                    'missing_lane_count' => count($missingLanes),
                    'duplicate_lane_count' => count($coverageLanes) - count(array_unique($coverageLanes)),
                ],
                'review' => null,
            ]);
        }

        $actions = array_values(array_filter((array) ($analysis['actions'] ?? []), 'is_array'));
        [$actions, $sourceUrlNormalization] = $this->normalizeActionSourceUrls($actions);
        $analysis['actions'] = $actions;
        $analysis['validation_warnings'] = [
            'discarded_source_url_count' => $sourceUrlNormalization['discarded_count'],
        ];
        $actionValidationError = $this->validateActions($actions, $requiredLanes);
        if ($actionValidationError !== null) {
            return $this->result('codex_failed', [
                'due' => $due,
                'inventory_status' => $inventory['status'] ?? 'unknown',
                'inventory_summary' => $inventory['summary'] ?? [],
                'codex' => [
                    'executed' => true,
                    'success' => false,
                    'model' => $codexResult['model'] ?? null,
                    'error_type' => $actionValidationError,
                    'discarded_source_url_count' => $sourceUrlNormalization['discarded_count'],
                ],
                'review' => null,
            ]);
        }
        $review = $this->syncReview($analysis, $actions, $inventory, $codexResult);
        $this->recordCompletion($codexResult, count($actions), $review);

        return $this->result($actions === [] ? 'no_action' : 'review_required', [
            'due' => $due,
            'inventory_status' => $inventory['status'] ?? 'unknown',
            'inventory_summary' => $inventory['summary'] ?? [],
            'codex' => [
                'executed' => true,
                'success' => true,
                'model' => $codexResult['model'] ?? null,
                'reasoning_effort' => $codexResult['reasoning_effort'] ?? null,
                'discarded_source_url_count' => $sourceUrlNormalization['discarded_count'],
            ],
            'action_counts' => $this->actionCounts($actions),
            'review' => $review,
        ]);
    }

    /** @return array<string,mixed> */
    private function policy(): array
    {
        if (! Schema::hasTable('system_configs')) {
            return [];
        }

        $value = DB::table('system_configs')
            ->where('section', 'ops')
            ->where('config_key', self::POLICY_KEY)
            ->value('config_value');

        return $this->decodeMap($value);
    }

    /** @return array{due:bool,forced:bool,last_completed_at:?string,next_due_at:?string} */
    private function dueState(array $policy, bool $force): array
    {
        $state = Schema::hasTable('system_configs')
            ? $this->decodeMap(DB::table('system_configs')->where('section', 'ops')->where('config_key', self::STATE_KEY)->value('config_value'))
            : [];
        $last = isset($state['last_completed_at']) ? now()->parse((string) $state['last_completed_at']) : null;
        $days = max(1, (int) ($policy['minimum_days_between_reviews'] ?? 14));
        $next = $last?->copy()->addDays($days);

        return [
            'due' => $force || $next === null || now()->gte($next),
            'forced' => $force,
            'last_completed_at' => $last?->toIso8601String(),
            'next_due_at' => $next?->toIso8601String(),
        ];
    }

    /** @return array{allowed:bool,reason:string} */
    private function codexProviderDecision(): array
    {
        $row = Schema::hasTable('llm_instances')
            ? DB::table('llm_instances')->where('instance_id', 'codex_exec')->first()
            : null;
        if ($row === null) {
            return ['allowed' => false, 'reason' => 'codex_provider_missing'];
        }

        $decision = $this->offlinePolicy->evaluateProvider($row);

        return [
            'allowed' => $decision->allowed,
            'reason' => $decision->allowed ? 'allowed' : 'blocked_by_active_profile',
        ];
    }

    /** @return array<string,mixed> */
    private function inventoryForCodex(array $inventory): array
    {
        $instances = [];
        foreach ((array) ($inventory['instances'] ?? []) as $instance) {
            if (! is_array($instance)) {
                continue;
            }
            $instances[] = array_intersect_key($instance, array_flip([
                'instance_id', 'instance_type', 'active', 'routability', 'compat_status',
                'capabilities', 'supported_models', 'role_models', 'role_reasoning_efforts',
                'live_status', 'live_reason', 'live_models', 'new_models', 'deprecated_models',
                'role_model_not_in_supported_models', 'role_model_not_live',
                'role_reasoning_effort_mismatches', 'capability_role_mismatches',
            ]));
        }

        return [
            'generated_at' => $inventory['generated_at'] ?? null,
            'status' => $inventory['status'] ?? 'unknown',
            'summary' => $inventory['summary'] ?? [],
            'issues' => $inventory['issues'] ?? [],
            'instances' => $instances,
        ];
    }

    /** @return array<string,mixed> */
    private function hardwarePolicy(): array
    {
        if (! Schema::hasTable('system_configs')) {
            return [];
        }

        $policy = $this->decodeMap(DB::table('system_configs')
            ->where('section', 'llm')
            ->where('config_key', 'model_hardware_evidence_policy')
            ->value('config_value'));

        return [
            'reviewed_at' => $policy['reviewed_at'] ?? null,
            'evidence_posture' => $policy['evidence_posture'] ?? null,
            'decision_contract' => $policy['decision_contract'] ?? [],
            'hardware_classes' => $policy['hardware_classes'] ?? [],
            'sources' => $policy['sources'] ?? [],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function telemetrySummary(): array
    {
        if (! Schema::hasTable('llm_model_request_logs')) {
            return [];
        }

        try {
            $modelColumn = Schema::hasColumn('llm_model_request_logs', 'model_name') ? 'model_name' : 'model';
            $latencyColumn = Schema::hasColumn('llm_model_request_logs', 'latency_ms') ? 'latency_ms' : 'duration_ms';
            if (! Schema::hasColumns('llm_model_request_logs', [$modelColumn, $latencyColumn, 'success', 'created_at'])) {
                return [];
            }
            $successPredicate = DB::connection()->getDriverName() === 'pgsql'
                ? 'success IS TRUE'
                : 'success = 1';
            $quotedModel = DB::connection()->getQueryGrammar()->wrap($modelColumn);
            $quotedLatency = DB::connection()->getQueryGrammar()->wrap($latencyColumn);

            return array_map(static fn (object $row): array => [
                'instance_id' => (string) $row->instance_id,
                'model' => (string) $row->model,
                'requests' => (int) $row->requests,
                'successes' => (int) $row->successes,
                'avg_duration_ms' => (int) $row->avg_duration_ms,
            ], DB::table('llm_model_request_logs')
                ->select('instance_id')
                ->selectRaw("{$quotedModel} AS model")
                ->selectRaw('COUNT(*) AS requests')
                ->selectRaw("SUM(CASE WHEN {$successPredicate} THEN 1 ELSE 0 END) AS successes")
                ->selectRaw("AVG({$quotedLatency}) AS avg_duration_ms")
                ->where('created_at', '>=', now()->subDays(14))
                ->groupBy('instance_id', $modelColumn)
                ->orderByDesc('requests')
                ->limit(100)
                ->get()
                ->all());
        } catch (\Throwable) {
            return [];
        }
    }

    private function prompt(array $evidence): string
    {
        return "You are the biweekly PLOS LLM portfolio reviewer. Analyze the supplied current inventory, exact hardware policy, and content-free telemetry.\n"
            ."Actively look beyond installed catalogs for cutting-edge candidates using official Hugging Face organization/model cards, official repositories and release notes, vendor catalogs, technical reports or papers, credible independent evaluations, and sustained community feedback. Community feedback and leaderboards are discovery/ranking signals, never sole promotion or retirement evidence.\n"
            ."For every action provide an exact model/revision, license or terms, evidence grade, feedback signal, direct source URLs, and a deterministic fit record joining capability lanes to target PLOS instances, hardware, context, privacy, and failure-domain effect. Every source_urls item must be a raw absolute https:// URL: do not use Markdown links, labels, local paths, placeholders, or prose. Use official primary sources for hardware, artifact, capability, license, add, retirement, and Codex role claims. If current external research tools are unavailable or a claim cannot be sourced, omit the action rather than inventing a citation.\n"
            ."For add, promote, retire, or reconfigure actions, exact_model_revision must be an immutable revision or an official provider model ID; omit unresolved, inferred-family, or not-sourced actions.\n"
            ."First run php artisan codex:offline-capabilities --json inside the read-only workspace and use its deterministic modality_surface as the current PLOS capability inventory.\n"
            ."Cover every table-owned required capability lane: fast/standard/quality text, coding, tool use, structured output, uncensored operator text, embeddings, reranking, vision/image input, image generation output, OCR/document input, speech input/STT, speech output/TTS, video input/output, and other admitted media. Distinguish advertised model claims from an actually configured and tested PLOS route. Do not retire the last admitted resource for a required lane.\n"
            ."Also cover local Ollama models, provider retention/retirement, Jetson fit, shared host failure domains, and the current Codex external model assigned to fast/standard/quality/coding prompting.\n"
            ."Return only actionable changes or explicit retain decisions that prevent an unsafe retirement. Hardware fit creates a candidate, not a production route. Every change requires operator review and an exact PLOS validation/canary. Do not edit files, install models, alter routes, expose secrets, compress prompts, or claim that this review applied a change.\n\n"
            .json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $actions @param list<string> $requiredLanes */
    private function validateActions(array $actions, array $requiredLanes): ?string
    {
        if (count($actions) > 20) {
            return 'action_limit_exceeded';
        }

        foreach ($actions as $action) {
            $lanes = array_values(array_filter((array) ($action['capability_lanes'] ?? []), 'is_string'));
            $urls = array_values(array_filter((array) ($action['source_urls'] ?? []), 'is_string'));
            $validUrls = array_filter($urls, static function (string $url): bool {
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
            });

            if ($lanes === [] || ($requiredLanes !== [] && array_diff($lanes, $requiredLanes) !== [])) {
                return 'invalid_action_capability_lanes';
            }
            if ($urls === [] || count($validUrls) !== count($urls)) {
                return 'invalid_action_source_urls';
            }
            foreach (['resource_id', 'exact_model_revision', 'license', 'benefit'] as $field) {
                if (! is_string($action[$field] ?? null) || trim($action[$field]) === '') {
                    return 'empty_required_action_field';
                }
            }
        }

        return null;
    }

    /**
     * Keep recoverable direct citations while ensuring every accepted action still
     * passes the same public HTTP(S) evidence gate.
     *
     * @param  list<array<string,mixed>>  $actions
     * @return array{0:list<array<string,mixed>>,1:array{discarded_count:int}}
     */
    private function normalizeActionSourceUrls(array $actions): array
    {
        $discardedCount = 0;

        foreach ($actions as &$action) {
            $normalized = [];
            foreach ((array) ($action['source_urls'] ?? []) as $value) {
                if (! is_string($value)) {
                    $discardedCount++;

                    continue;
                }

                $candidate = trim($value);
                if (preg_match('/^\[[^\]]*\]\((https?:\/\/[^\s)]+)\)$/i', $candidate, $matches) === 1) {
                    $candidate = $matches[1];
                }
                $candidate = rtrim($candidate, '.,;');
                $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
                if (! in_array($scheme, ['http', 'https'], true)
                    || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
                    $discardedCount++;

                    continue;
                }

                $normalized[] = $candidate;
            }

            $action['source_urls'] = array_values(array_unique($normalized));
        }
        unset($action);

        return [$actions, ['discarded_count' => $discardedCount]];
    }

    /** @return array<string,mixed>|null */
    private function syncReview(array $analysis, array $actions, array $inventory, array $codexResult): ?array
    {
        if (! Schema::hasTable('agent_review_queue')) {
            return null;
        }

        $existing = DB::table('agent_review_queue')
            ->where('review_type', 'ai_model_update')
            ->where('agent_id', 'codex-portfolio')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first();

        if ($actions === []) {
            if ($existing !== null) {
                DB::table('agent_review_queue')->where('id', $existing->id)->update([
                    'status' => 'auto_dismissed',
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ['status' => 'none', 'id' => null];
        }

        $details = [
            'schema' => 'plos.llm_portfolio_review.v1',
            'generated_at' => now()->toIso8601String(),
            'codex' => [
                'provider' => 'codex_exec',
                'model' => $codexResult['model'] ?? null,
                'reasoning_effort' => $codexResult['reasoning_effort'] ?? null,
            ],
            'inventory_summary' => $inventory['summary'] ?? [],
            'coverage' => $analysis['coverage'] ?? [],
            'actions' => $actions,
            'validation_warnings' => $analysis['validation_warnings'] ?? [],
            'execution_contract' => [
                'approval_creates_execution_authority' => true,
                'approved_packet_executor' => 'Codex RTC using exact action validation and rollback gates',
                'automatic_install_or_route_change' => false,
                'prompt_compression' => false,
                'precompaction' => false,
            ],
        ];
        $priority = collect($actions)->contains(fn (array $action): bool => ($action['priority'] ?? null) === 'high') ? 1 : 0;
        $values = [
            'priority' => $priority,
            'title' => 'LLM portfolio: '.count($actions).' action'.(count($actions) === 1 ? '' : 's').' need operator decision',
            'summary' => mb_substr((string) ($analysis['summary'] ?? 'Review the model portfolio recommendations and approve only a bounded execution packet.'), 0, 4000),
            'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('agent_review_queue')->where('id', $existing->id)->update($values);

            return ['status' => 'updated', 'id' => (int) $existing->id];
        }

        $id = DB::table('agent_review_queue')->insertGetId(array_merge($values, [
            'review_type' => 'ai_model_update',
            'status' => 'pending',
            'agent_id' => 'codex-portfolio',
            'token' => bin2hex(random_bytes(16)),
            'created_at' => now(),
        ]));

        return ['status' => 'created', 'id' => (int) $id];
    }

    private function recordCompletion(array $codexResult, int $actionCount, ?array $review): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::table('system_configs')->updateOrInsert(
            ['section' => 'ops', 'config_key' => self::STATE_KEY],
            [
                'config_value' => json_encode([
                    'last_completed_at' => now()->toIso8601String(),
                    'codex_model' => $codexResult['model'] ?? null,
                    'action_count' => $actionCount,
                    'review_id' => $review['id'] ?? null,
                ], JSON_UNESCAPED_SLASHES),
                'data_type' => 'json',
                'description' => 'Last completed biweekly LLM portfolio review checkpoint.',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    /** @return array<string,int> */
    private function actionCounts(array $actions): array
    {
        $counts = [];
        foreach ($actions as $action) {
            $decision = (string) ($action['decision'] ?? 'unknown');
            $counts[$decision] = ($counts[$decision] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** @return array<string,mixed> */
    private function decodeMap(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string,mixed> */
    private function result(string $status, array $extra = []): array
    {
        return array_merge([
            'schema' => 'plos.llm_portfolio_review_run.v1',
            'generated_at' => now()->toIso8601String(),
            'status' => $status,
            'mode' => [
                'inventory_first' => true,
                'codex_read_only' => true,
                'writes_review_queue' => true,
                'installs_models' => false,
                'changes_routes' => false,
                'changes_codex_model_selection' => false,
                'operator_approval_required' => true,
                'compression' => false,
                'precompaction' => false,
            ],
        ], $extra);
    }
}
