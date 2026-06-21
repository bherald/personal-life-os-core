<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyReviewPacketMaterializationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class OpsFacePersonSourceRepairPlanCommand extends Command
{
    private const REQUIRED_CONFIG_KEYS = [
        'default_limit',
        'max_limit',
        'max_offset',
        'review_type',
        'finding_type',
        'agent_id',
        'priority',
        'confidence',
        'expires_days',
        'sprint_boundary_label',
        'packet_identity_label',
        'packet_identity_scope',
        'actionable_status_order',
        'status_actions',
    ];

    protected $signature = 'ops:face-person-source-repair-plan
        {--tree=4 : Genealogy tree id}
        {--person-id= : Optional person id filter}
        {--source-id= : Optional source id filter}
        {--status= : Optional exact review status bucket filter}
        {--limit= : Maximum repair-plan rows, falling back to system_configs.faces.face_person_source_repair_plan}
        {--offset=0 : Sample row offset passed through to provenance report calls}
        {--write : Create or reuse an agent_review_queue review packet}
        {--confirm : Required with --write}
        {--json : Emit machine-readable JSON}
        {--compact : Omit row selectors from JSON output}
        {--dry-run : Validate command shape without querying row data or writing packets}';

    protected $description = 'Build a review-only face/photo provenance repair plan from the all-person source audit';

    public function handle(): int
    {
        $config = $this->repairPlanConfig();
        if (($config['__error'] ?? null) !== null) {
            return $this->failPayload((string) $config['__error']);
        }

        $treeId = max(1, (int) $this->option('tree'));
        $personId = $this->positiveNullableInt($this->option('person-id'));
        $sourceId = $this->positiveNullableInt($this->option('source-id'));
        $status = $this->cleanOptionString($this->option('status'));
        $limit = $this->positiveIntOption(
            'limit',
            $this->configInt($config, 'default_limit', 25),
            1,
            $this->configInt($config, 'max_limit', 250)
        );
        $offset = max(0, min(
            $this->configInt($config, 'max_offset', 100000),
            (int) $this->option('offset')
        ));
        $write = (bool) $this->option('write');
        $dryRun = (bool) $this->option('dry-run');

        if ($write && ! (bool) $this->option('confirm') && ! $dryRun) {
            return $this->failPayload('write_requires_confirm');
        }

        $payload = $this->basePayload($treeId, $personId, $sourceId, $status, $limit, $offset, $config);

        if ($dryRun) {
            $payload['mode'] = 'dry_run';
            $payload['dry_run'] = true;
            $payload['summary']['query_would_run'] = false;
            $this->renderPayload($payload);

            return self::SUCCESS;
        }

        $collected = $this->collectPlans($treeId, $personId, $sourceId, $status, $limit, $offset, $config);
        if (($collected['error'] ?? null) !== null) {
            return $this->failPayload((string) $collected['error']);
        }

        $payload = array_replace_recursive($payload, [
            'source_report_summary' => $collected['source_report_summary'],
            'source_status_counts' => $collected['source_status_counts'],
            'source_reports' => $collected['source_reports'],
            'plans' => $collected['plans'],
            'summary' => array_merge($payload['summary'], $collected['summary']),
            'next_action' => $collected['plans'] === []
                ? 'no_repair_plan_rows_in_selected_slice'
                : 'operator_review_repair_packet_before_any_identity_writeback',
        ]);

        if ($write && $payload['plans'] !== []) {
            $writeResult = $this->writeReviewPacket($payload, $config);
            $payload['review_packet'] = $writeResult;
            $payload['summary']['review_packets_created'] = $writeResult['created'] ? 1 : 0;
            $payload['summary']['review_packets_reused'] = $writeResult['reused'] ? 1 : 0;
            if (isset($writeResult['error'])) {
                $this->renderPayload($payload);

                return self::FAILURE;
            }
        }

        if ((bool) $this->option('compact')) {
            $payload = $this->compactPayload($payload);
        }

        $this->renderPayload($payload);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function collectPlans(int $treeId, ?int $personId, ?int $sourceId, ?string $status, int $limit, int $offset, array $config): array
    {
        $overall = $this->callReport($treeId, $personId, $sourceId, null, 1, 0, true);
        if (($overall['error'] ?? null) !== null) {
            return ['error' => $overall['error']];
        }

        $statusBuckets = $status !== null ? [$status] : $this->actionableStatuses($config);
        $plans = [];
        $sourceReports = [];
        $remaining = $limit;

        foreach ($statusBuckets as $bucket) {
            if ($remaining <= 0) {
                break;
            }

            $report = $this->callReport($treeId, $personId, $sourceId, $bucket, $remaining, $offset, false);
            if (($report['error'] ?? null) !== null) {
                return ['error' => $report['error']];
            }

            $samples = is_array($report['samples'] ?? null) ? $report['samples'] : [];
            $sourceReports[] = [
                'status' => $bucket,
                'summary' => $report['summary'] ?? [],
                'sample_count' => count($samples),
            ];

            foreach ($samples as $sample) {
                if (! is_array($sample)) {
                    continue;
                }

                $plan = $this->planForSample($sample, $config, count($plans) + 1);
                if ($plan === null) {
                    continue;
                }

                $plans[] = $plan;
                $remaining--;
                if ($remaining <= 0) {
                    break;
                }
            }
        }

        return [
            'source_report_summary' => $overall['summary'] ?? [],
            'source_status_counts' => $overall['status_counts'] ?? [],
            'source_reports' => $sourceReports,
            'plans' => $plans,
            'summary' => [
                'query_would_run' => true,
                'source_report_calls' => count($sourceReports) + 1,
                'candidate_rows_sampled' => array_sum(array_map(
                    fn (array $report): int => (int) ($report['sample_count'] ?? 0),
                    $sourceReports
                )),
                'plans_created' => count($plans),
                'review_packets_created' => 0,
                'review_packets_reused' => 0,
                'identity_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'metadata_files_changed' => 0,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function callReport(int $treeId, ?int $personId, ?int $sourceId, ?string $status, int $limit, int $offset, bool $compact): array
    {
        $params = [
            '--tree' => $treeId,
            '--limit' => $limit,
            '--offset' => $offset,
            '--json' => true,
        ];

        if ($personId !== null) {
            $params['--person-id'] = $personId;
        }
        if ($sourceId !== null) {
            $params['--source-id'] = $sourceId;
        }
        if ($status !== null) {
            $params['--status'] = $status;
        }
        if ($compact) {
            $params['--compact'] = true;
        }

        $output = new BufferedOutput;
        $exit = Artisan::call('ops:face-person-source-provenance-report', $params, $output);
        if ($exit !== self::SUCCESS) {
            return ['error' => 'source_report_failed'];
        }

        try {
            $decoded = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['error' => 'source_report_invalid_json'];
        }

        return is_array($decoded) ? $decoded : ['error' => 'source_report_invalid_payload'];
    }

    /**
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>|null
     */
    private function planForSample(array $sample, array $config, int $index): ?array
    {
        $status = (string) ($sample['status'] ?? '');
        $actions = is_array($config['status_actions'] ?? null) ? $config['status_actions'] : [];
        $action = is_array($actions[$status] ?? null) ? $actions[$status] : null;
        if ($action === null) {
            return null;
        }

        $kind = (string) ($sample['kind'] ?? 'unknown');
        $selector = $this->selectorForSample($sample);
        $planKey = $kind.':'.($selector['primary_id'] ?? $index).':'.$status;

        return [
            'plan_key' => $planKey,
            'sequence' => $index,
            'kind' => $kind,
            'status' => $status,
            'suggested_action' => (string) ($action['action'] ?? 'operator_review'),
            'manual_next_step' => (string) ($action['manual_next_step'] ?? 'Operator review required before any write.'),
            'operator_decision_required' => true,
            'safe_to_apply_automatically' => false,
            'selector' => $selector,
            'evidence' => $this->evidenceForSample($sample),
            'blocked_automatic_operations' => [
                'auto_link_face_to_person',
                'auto_split_or_merge_face_clusters',
                'auto_create_genealogy_person',
                'metadata_writeback',
                'person_media_identity_write',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>
     */
    private function selectorForSample(array $sample): array
    {
        if (($sample['kind'] ?? '') === 'person_media_link') {
            return [
                'primary_id' => $sample['person_media_id'] ?? null,
                'person_media_id' => $sample['person_media_id'] ?? null,
                'person_id' => $sample['person_id'] ?? null,
                'media_id' => $sample['media_id'] ?? null,
                'source_ids' => $sample['source_ids'] ?? [],
                'citation_ids' => $sample['citation_ids'] ?? [],
            ];
        }

        if (($sample['kind'] ?? '') === 'unlinked_photo_media') {
            return [
                'primary_id' => $sample['media_id'] ?? null,
                'media_id' => $sample['media_id'] ?? null,
                'source_ids' => $sample['source_ids'] ?? [],
                'citation_ids' => $sample['citation_ids'] ?? [],
            ];
        }

        return [
            'primary_id' => $sample['face_id'] ?? null,
            'face_id' => $sample['face_id'] ?? null,
            'file_registry_id' => $sample['file_registry_id'] ?? null,
            'linked_person_id' => $sample['linked_person_id'] ?? null,
            'provenance_person_id' => $sample['provenance_person_id'] ?? null,
            'media_ids' => $sample['media_ids'] ?? [],
            'source_ids' => $sample['source_ids'] ?? [],
            'citation_ids' => $sample['citation_ids'] ?? [],
        ];
    }

    /**
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>
     */
    private function evidenceForSample(array $sample): array
    {
        return [
            'filename' => $sample['filename'] ?? null,
            'person_name' => $sample['person_name'] ?? null,
            'linked_canonical_name' => $sample['linked_canonical_name'] ?? null,
            'matched_export_name' => $sample['matched_export_name'] ?? null,
            'matched_variant_name' => $sample['matched_variant_name'] ?? null,
            'provenance_status' => $sample['provenance_status'] ?? null,
            'media_title' => $sample['media_title'] ?? null,
            'media_type' => $sample['media_type'] ?? null,
            'detector_source_label' => $sample['detector_source_label'] ?? null,
            'file_status' => $sample['file_status'] ?? null,
            'source_folder' => $sample['source_folder'] ?? null,
            'analysis_status' => $sample['analysis_status'] ?? null,
            'has_faces' => $sample['has_faces'] ?? null,
            'face_confirmed' => $sample['face_confirmed'] ?? null,
            'has_face_region' => $sample['has_face_region'] ?? null,
            'review_required' => $sample['review_required'] ?? true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function writeReviewPacket(array $payload, array $config): array
    {
        $packet = $this->reviewPacket($payload, $config);
        $result = app(GenealogyReviewPacketMaterializationService::class)->materialize($packet, [
            'agent_id' => (string) ($config['agent_id'] ?? 'genealogy-review-packet'),
            'finding_type' => (string) ($config['finding_type'] ?? 'face_person_source_repair_plan'),
            'confidence' => $this->configFloat($config, 'confidence', 0.82),
            'priority' => $this->configInt($config, 'priority', 2),
            'expires_at' => now()->addDays($this->configInt($config, 'expires_days', 30)),
        ]);

        if (! ($result['success'] ?? false)) {
            return [
                'created' => false,
                'reused' => false,
                'error' => (string) ($result['error'] ?? 'review_packet_materialization_failed'),
                'validation' => $result['validation'] ?? null,
            ];
        }

        return [
            'created' => ! (bool) ($result['materialized_existing'] ?? false),
            'reused' => (bool) ($result['materialized_existing'] ?? false),
            'review_queue_id' => (int) ($result['review_queue_id'] ?? 0),
            'token' => (string) ($result['token'] ?? ''),
            'dedup_key' => (string) ($result['payload']['details']['dedup_key'] ?? ''),
            'packet_key' => (string) ($packet['packet_key'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function dedupKey(array $payload): string
    {
        $planKeys = array_map(
            fn (array $plan): string => (string) ($plan['plan_key'] ?? ''),
            is_array($payload['plans'] ?? null) ? $payload['plans'] : []
        );

        return 'face-person-source-repair-plan:'.sha1(json_encode([
            'filters' => $payload['filters'] ?? [],
            'plans' => $planKeys,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function reviewPacket(array $payload, array $config): array
    {
        $packetKey = $this->dedupKey($payload);
        $title = $this->packetTitle($payload);
        $locators = $this->sourceLocatorsForPacket($payload);
        $claims = $this->claimsForPacket($payload, $locators);
        $status = (string) ($payload['filters']['status'] ?? 'all_actionable_statuses');
        $operations = array_map(
            fn (array $claim, int $index): array => [
                'operation_type' => 'genealogy_todo_create',
                'status' => 'preview_only',
                'apply_enabled' => false,
                'mutates_accepted_facts' => false,
                'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
                'current_state' => [
                    'target_context' => [
                        'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
                    ],
                    'research_question' => (string) ($claim['research_question'] ?? $claim['claim'] ?? $title),
                ],
                'proposed_effect' => [
                    'type' => 'create_genealogy_research_task_preview_only',
                    'description' => 'Would create a genealogy research task for operator-reviewed face/photo provenance cleanup; no identity or metadata rows are changed.',
                    'research_question' => (string) ($claim['research_question'] ?? $claim['claim'] ?? $title),
                    'rows_that_would_be_touched' => [],
                ],
            ],
            $claims,
            array_keys($claims)
        );

        return [
            'packet_key' => $packetKey,
            'packet_label' => $title,
            'title' => $title,
            'summary' => sprintf(
                'Review %d face/photo provenance repair candidate(s). Packet approval remains preview-only and may create research-task follow-up only.',
                count($payload['plans'] ?? [])
            ),
            'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
            'target_tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
            'identity' => [
                'label' => $this->configString($config, 'packet_identity_label'),
                'scope' => $this->configString($config, 'packet_identity_scope'),
                'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
                'status_bucket' => $status,
            ],
            'sprint' => [
                'boundary_label' => $this->configString($config, 'sprint_boundary_label'),
            ],
            'source_locator' => $locators[0] ?? 'face-person-source-repair-plan',
            'source_locators' => $locators,
            'sources' => array_map(
                fn (string $locator): array => [
                    'source_locator' => $locator,
                    'kind' => 'face_photo_provenance_audit',
                ],
                $locators
            ),
            'claims' => $claims,
            'privacy' => [
                'status' => 'cleared',
                'cleared' => true,
                'scope' => 'internal_face_photo_provenance_repair_packet',
            ],
            'materialization' => [
                'source' => 'ops_face_person_source_repair_plan',
                'source_review_type' => 'genealogy_finding',
                'target_review_type' => (string) ($config['review_type'] ?? 'genealogy_review_packet'),
                'operation_types' => ['genealogy_todo_create'],
                'apply_enabled' => false,
                'writeback' => false,
            ],
            'typed_remediation_preview' => [
                'status' => 'preview_only',
                'mutates_accepted_facts' => false,
                'accepted_fact_mutations' => [],
                'operations' => $operations,
            ],
            'metadata' => [
                'origin' => 'ops:face-person-source-repair-plan',
                'finding_type' => (string) ($config['finding_type'] ?? 'face_person_source_repair_plan'),
                'sprint_boundary_label' => $this->configString($config, 'sprint_boundary_label'),
                'filters' => $payload['filters'],
                'source_report_summary' => $payload['source_report_summary'],
                'source_status_counts' => $payload['source_status_counts'],
                'plans' => $payload['plans'],
                'safety' => $payload['posture'],
            ],
            'confidence' => $this->configFloat($config, 'confidence', 0.82),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int,string>
     */
    private function sourceLocatorsForPacket(array $payload): array
    {
        $locators = ['face_person_source_repair_plan:tree:'.(int) ($payload['filters']['tree_id'] ?? 4)];
        foreach (is_array($payload['plans'] ?? null) ? $payload['plans'] : [] as $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $selector = is_array($plan['selector'] ?? null) ? $plan['selector'] : [];
            foreach ([
                'face_id' => 'file_registry_face',
                'file_registry_id' => 'file_registry',
                'person_media_id' => 'genealogy_person_media',
                'media_id' => 'genealogy_media',
                'person_id' => 'genealogy_person',
            ] as $key => $prefix) {
                $value = $selector[$key] ?? null;
                if (is_numeric($value) && (int) $value > 0) {
                    $locators[] = $prefix.':'.(int) $value;
                }
            }
        }

        return array_values(array_slice(array_unique($locators), 0, 50));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<int,string>  $locators
     * @return array<int,array<string,mixed>>
     */
    private function claimsForPacket(array $payload, array $locators): array
    {
        $claims = [];
        foreach (is_array($payload['plans'] ?? null) ? $payload['plans'] : [] as $index => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $status = (string) ($plan['status'] ?? 'review_required');
            $locator = $locators[$index + 1] ?? $locators[0] ?? 'face_person_source_repair_plan';
            $question = sprintf(
                'Review %s %s face/photo provenance or coverage candidate %s and decide the operator-approved cleanup path before any identity, cluster, person-media, or metadata write.',
                (string) ($plan['kind'] ?? 'unknown'),
                $status,
                (string) ($plan['plan_key'] ?? $index + 1)
            );

            $claims[] = [
                'claim' => $question,
                'claim_text' => $question,
                'field_name' => 'face_photo_provenance',
                'change_type' => 'genealogy_data_quality',
                'operation_type' => 'genealogy_todo_create',
                'task_type' => 'verify_facts',
                'research_question' => $question,
                'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
                'source_locator' => $locator,
                'status' => $status,
                'plan_key' => (string) ($plan['plan_key'] ?? ''),
                'suggested_action' => (string) ($plan['suggested_action'] ?? 'operator_review'),
                'manual_next_step' => (string) ($plan['manual_next_step'] ?? ''),
            ];
        }

        return $claims !== []
            ? $claims
            : [[
                'claim' => 'Review face/photo provenance repair candidates before any identity writeback.',
                'claim_text' => 'Review face/photo provenance repair candidates before any identity writeback.',
                'field_name' => 'face_photo_provenance',
                'change_type' => 'genealogy_data_quality',
                'operation_type' => 'genealogy_todo_create',
                'task_type' => 'verify_facts',
                'research_question' => 'Review face/photo provenance repair candidates before any identity writeback.',
                'tree_id' => (int) ($payload['filters']['tree_id'] ?? 4),
                'source_locator' => $locators[0] ?? 'face_person_source_repair_plan',
            ]];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function packetTitle(array $payload): string
    {
        $status = $payload['filters']['status'] ?? null;
        $label = $status !== null ? (string) $status : 'review-required';

        return sprintf(
            'Face/photo repair plan %s: %s (%d rows)',
            substr($this->dedupKey($payload), -10),
            $label,
            count($payload['plans'] ?? [])
        );
    }

    /**
     * @return array<int,string>
     */
    private function actionableStatuses(array $config): array
    {
        $statuses = is_array($config['actionable_status_order'] ?? null) ? $config['actionable_status_order'] : [];

        return array_values(array_filter(array_map('strval', $statuses)));
    }

    /**
     * @return array<string,mixed>
     */
    private function repairPlanConfig(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT config_value FROM system_configs WHERE section = ? AND config_key = ? LIMIT 1',
                ['faces', 'face_person_source_repair_plan']
            );
        } catch (Throwable) {
            return ['__error' => 'face_person_source_repair_plan_config_unavailable'];
        }

        if ($row === null) {
            return ['__error' => 'face_person_source_repair_plan_config_missing'];
        }

        $decoded = json_decode((string) $row->config_value, true);
        if (! is_array($decoded)) {
            return ['__error' => 'face_person_source_repair_plan_config_invalid_json'];
        }

        $missing = [];
        foreach (self::REQUIRED_CONFIG_KEYS as $key) {
            if (! array_key_exists($key, $decoded)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            return ['__error' => 'face_person_source_repair_plan_config_missing_keys'];
        }

        foreach (['default_limit', 'max_limit', 'max_offset', 'priority', 'confidence', 'expires_days'] as $key) {
            if (! is_numeric($decoded[$key])) {
                return ['__error' => 'face_person_source_repair_plan_config_invalid_numeric_value'];
            }
        }

        foreach (['sprint_boundary_label', 'packet_identity_label', 'packet_identity_scope'] as $key) {
            if (! is_string($decoded[$key]) || trim($decoded[$key]) === '') {
                return ['__error' => 'face_person_source_repair_plan_config_invalid_text_value'];
            }
        }

        if (! is_array($decoded['actionable_status_order']) || $decoded['actionable_status_order'] === []
            || ! is_array($decoded['status_actions']) || $decoded['status_actions'] === []) {
            return ['__error' => 'face_person_source_repair_plan_config_invalid_action_policy'];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactPayload(array $payload): array
    {
        unset($payload['plans']);
        $payload['compact'] = true;
        $payload['posture']['row_identifiers_included'] = false;
        $payload['posture']['plans_included'] = false;

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $payload['filters'] = [
            'tree' => $filters['tree_id'] ?? null,
            'person_filter_applied' => ($filters['person_id'] ?? null) !== null,
            'source_filter_applied' => ($filters['source_id'] ?? null) !== null,
            'status' => $filters['status'] ?? null,
            'limit' => $filters['limit'] ?? null,
            'offset' => $filters['offset'] ?? null,
        ];

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function basePayload(int $treeId, ?int $personId, ?int $sourceId, ?string $status, int $limit, int $offset, array $config): array
    {
        return [
            'command' => 'ops:face-person-source-repair-plan',
            'mode' => 'plan',
            'dry_run' => false,
            'read_only' => true,
            'mutation_allowed' => false,
            'review_packet_write_allowed' => (bool) $this->option('write'),
            'captured_at' => now()->toIso8601String(),
            'filters' => [
                'tree_id' => $treeId,
                'person_id' => $personId,
                'source_id' => $sourceId,
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ],
            'posture' => [
                'schema' => 'face_person_source_repair_plan.v1',
                'config_source' => 'system_configs.faces.face_person_source_repair_plan',
                'review_type' => (string) ($config['review_type'] ?? 'genealogy_review_packet'),
                'finding_type' => (string) ($config['finding_type'] ?? 'face_person_source_repair_plan'),
                'operator_review_required' => true,
                'automatic_link_allowed' => false,
                'cluster_merge_allowed' => false,
                'create_person_allowed' => false,
                'metadata_writeback_allowed' => false,
                'face_table_mutation_allowed' => false,
                'person_media_mutation_allowed' => false,
                'row_identifiers_included' => true,
                'plans_included' => true,
            ],
            'summary' => [
                'query_would_run' => true,
                'source_report_calls' => 0,
                'candidate_rows_sampled' => 0,
                'plans_created' => 0,
                'review_packets_created' => 0,
                'review_packets_reused' => 0,
                'identity_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'metadata_files_changed' => 0,
            ],
            'source_report_summary' => [],
            'source_status_counts' => [],
            'source_reports' => [],
            'plans' => [],
            'next_action' => 'run_plan',
        ];
    }

    private function positiveNullableInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function positiveIntOption(string $option, int $default, int $min, int $max): int
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            return max($min, min($max, $default));
        }

        return max($min, min($max, (int) $raw));
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function configInt(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function configFloat(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function configString(array $config, string $key): string
    {
        return trim((string) ($config[$key] ?? ''));
    }

    private function cleanOptionString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function failPayload(string $error): int
    {
        $this->renderPayload([
            'command' => 'ops:face-person-source-repair-plan',
            'mode' => 'error',
            'error' => $error,
            'read_only' => true,
            'mutation_allowed' => false,
            'review_packet_write_allowed' => false,
        ]);

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderPayload(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line(sprintf(
            'face-person-source-repair-plan: mode=%s plans=%d sampled=%d packet_created=%d packet_reused=%d',
            $payload['mode'] ?? 'unknown',
            (int) ($payload['summary']['plans_created'] ?? 0),
            (int) ($payload['summary']['candidate_rows_sampled'] ?? 0),
            (int) ($payload['summary']['review_packets_created'] ?? 0),
            (int) ($payload['summary']['review_packets_reused'] ?? 0)
        ));
        $this->line((string) ($payload['next_action'] ?? ''));
    }
}
