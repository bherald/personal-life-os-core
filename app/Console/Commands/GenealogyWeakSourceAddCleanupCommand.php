<?php

namespace App\Console\Commands;

use App\Services\ReviewTypeRegistryService;
use App\Services\SystemConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyWeakSourceAddCleanupCommand extends Command
{
    private const CONFIG_KEY = 'genealogy.weak_source_add_cleanup_policy';

    /** @var string[] */
    private const REQUIRED_POLICY_KEYS = [
        'enabled',
        'default_limit',
        'max_limit',
        'status',
        'review_type',
        'gate_reason',
        'writeback_allowed',
        'decision',
        'reason',
        'require_token',
        'require_tree_scope',
    ];

    protected $signature = 'genealogy:weak-source-add-cleanup
        {--tree=4 : Genealogy tree id}
        {--limit=0 : Candidate limit; 0 uses table policy default}
        {--execute : Ignore matching review rows}
        {--confirm : Required with --execute unless --dry-run is used}
        {--dry-run : Force dry-run mode}
        {--json : Emit machine-readable JSON}
        {--compact : Omit per-row candidates and results from JSON output}';

    protected $description = 'Table-driven cleanup for below-actionability Genea review noise';

    public function __construct(
        private SystemConfigService $config,
        private ReviewTypeRegistryService $reviewRegistry
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $policy = $this->cleanupPolicy();
        if (($policy['__error'] ?? null) !== null) {
            return $this->failPayload((string) $policy['__error']);
        }

        $treeId = max(1, (int) $this->option('tree'));
        $execute = (bool) $this->option('execute');
        $dryRun = (bool) $this->option('dry-run') || ! $execute;
        $limit = $this->effectiveLimit($policy);

        if ($execute && ! $dryRun && ! (bool) $this->option('confirm')) {
            return $this->failPayload('execute_requires_confirm');
        }

        $payload = [
            'command' => 'genealogy:weak-source-add-cleanup',
            'mode' => $dryRun ? 'dry_run' : 'execute',
            'dry_run' => $dryRun,
            'read_only' => $dryRun,
            'review_queue_mutation_allowed' => $execute && ! $dryRun,
            'captured_at' => now()->toIso8601String(),
            'posture' => [
                'config_source' => 'system_configs.'.self::CONFIG_KEY,
                'canonical_writes' => false,
                'canonical_source_link_writeback' => false,
                'relationship_changes' => false,
                'face_identity_automation' => false,
                'tree_scoped' => true,
                'operator_actionable_rows_preserved' => true,
            ],
            'policy' => [
                'enabled' => (bool) $policy['enabled'],
                'effective_limit' => $limit,
                'max_limit' => (int) $policy['max_limit'],
                'status' => (string) $policy['status'],
                'review_type' => (string) $policy['review_type'],
                'proposal_change_type' => (string) ($policy['proposal_change_type'] ?? ''),
                'proposal_change_types' => $policy['proposal_change_types'],
                'gate_reason' => (string) $policy['gate_reason'],
                'writeback_allowed' => (bool) $policy['writeback_allowed'],
                'decision' => (string) $policy['decision'],
            ],
            'summary' => [
                'candidates' => 0,
                'attempted' => 0,
                'ignored' => 0,
                'failed' => 0,
                'skipped_missing_token' => 0,
                'canonical_rows_changed' => 0,
                'person_media_rows_changed' => 0,
                'face_rows_changed' => 0,
            ],
            'candidates' => [],
            'results' => [],
            'next_action' => 'cleanup_policy_disabled',
        ];

        if (! (bool) $policy['enabled']) {
            $this->renderPayload($this->compactIfRequested($payload));

            return self::SUCCESS;
        }

        $candidates = $this->candidateRows($treeId, $policy, $limit);
        $payload['summary']['candidates'] = count($candidates);
        $payload['candidates'] = array_map(fn (object $row): array => $this->candidatePayload($row), $candidates);
        $payload['next_action'] = count($candidates) > 0
            ? ($dryRun ? 'rerun_with_execute_confirm_to_ignore_candidates' : 'cleanup_attempted')
            : 'weak_source_add_backlog_clear';

        if (! $dryRun) {
            foreach ($candidates as $row) {
                $result = $this->ignoreCandidate($row, $policy);
                $payload['results'][] = $result;
                $payload['summary']['attempted']++;
                if (($result['success'] ?? false) === true) {
                    $payload['summary']['ignored']++;
                } elseif (($result['error'] ?? null) === 'missing_token') {
                    $payload['summary']['skipped_missing_token']++;
                } else {
                    $payload['summary']['failed']++;
                }
            }

            $payload['next_action'] = ((int) $payload['summary']['failed']) > 0
                ? 'investigate_failed_review_ignores'
                : 'rerun_supervisor_to_refresh_worker_throttle';
        }

        $this->renderPayload($this->compactIfRequested($payload));

        return ((int) $payload['summary']['failed']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function cleanupPolicy(): array
    {
        $policy = $this->config->get(self::CONFIG_KEY);
        if (! is_array($policy)) {
            return ['__error' => 'weak_source_add_cleanup_policy_missing'];
        }

        foreach (self::REQUIRED_POLICY_KEYS as $key) {
            if (! array_key_exists($key, $policy)) {
                return ['__error' => 'weak_source_add_cleanup_policy_missing_keys'];
            }
        }

        if (! is_bool($policy['enabled'])
            || ! is_bool($policy['writeback_allowed'])
            || ! is_bool($policy['require_token'])
            || ! is_bool($policy['require_tree_scope'])) {
            return ['__error' => 'weak_source_add_cleanup_policy_invalid_boolean_value'];
        }

        foreach (['default_limit', 'max_limit'] as $key) {
            if (! is_numeric($policy[$key]) || (int) $policy[$key] < 1) {
                return ['__error' => 'weak_source_add_cleanup_policy_invalid_limit'];
            }
        }

        if ((int) $policy['default_limit'] > (int) $policy['max_limit']) {
            return ['__error' => 'weak_source_add_cleanup_policy_invalid_limit_order'];
        }

        foreach (['status', 'review_type', 'gate_reason', 'decision', 'reason'] as $key) {
            if (! is_string($policy[$key]) || trim($policy[$key]) === '') {
                return ['__error' => 'weak_source_add_cleanup_policy_invalid_string_value'];
            }
            $policy[$key] = trim($policy[$key]);
        }

        $proposalChangeTypes = $this->proposalChangeTypes($policy);
        if ($proposalChangeTypes === []) {
            return ['__error' => 'weak_source_add_cleanup_policy_invalid_change_types'];
        }

        $policy['proposal_change_types'] = $proposalChangeTypes;
        $policy['proposal_change_type'] = $proposalChangeTypes[0];

        if ($policy['decision'] !== 'ignore') {
            return ['__error' => 'weak_source_add_cleanup_policy_unsupported_decision'];
        }

        if ((bool) $policy['require_tree_scope'] !== true) {
            return ['__error' => 'weak_source_add_cleanup_policy_requires_tree_scope'];
        }

        return $policy;
    }

    private function effectiveLimit(array $policy): int
    {
        $requested = max(0, (int) $this->option('limit'));
        $default = (int) $policy['default_limit'];
        $max = (int) $policy['max_limit'];

        return min($requested > 0 ? $requested : $default, $max);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return string[]
     */
    private function proposalChangeTypes(array $policy): array
    {
        $rawTypes = $policy['proposal_change_types'] ?? null;
        if (! is_array($rawTypes)) {
            $rawTypes = [$policy['proposal_change_type'] ?? null];
        }

        $types = [];
        foreach ($rawTypes as $type) {
            if (! is_string($type)) {
                continue;
            }

            $type = trim($type);
            if ($type === '' || preg_match('/^[a-z0-9_:-]+$/', $type) !== 1) {
                continue;
            }

            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return object[]
     */
    private function candidateRows(int $treeId, array $policy, int $limit): array
    {
        $proposalChangeTypes = $policy['proposal_change_types'];
        $changeTypePredicates = implode(
            ' OR ',
            array_fill(0, count($proposalChangeTypes), "JSON_CONTAINS(JSON_EXTRACT(arq.details, '$.proposals[*].change_type'), JSON_QUOTE(?))")
        );
        $personIdExpression = "CAST(COALESCE(
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.person_id')), 'null'),
                    NULLIF(JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.proposals[0].person_id')), 'null')
                ) AS UNSIGNED)";

        return DB::select(
            "SELECT
                    arq.id,
                    arq.token,
                    arq.title,
                    arq.confidence,
                    arq.created_at,
                    {$personIdExpression} AS person_id,
                    JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.proposals[0].change_type')) AS proposal_change_type,
                    JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.proposals[0].proposed_value')) AS proposed_value,
                    JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.proposals[0].evidence_summary')) AS evidence_summary
               FROM agent_review_queue arq
               INNER JOIN genealogy_persons gp
                       ON gp.id = {$personIdExpression}
              WHERE arq.status = ?
                AND arq.review_type = ?
                AND gp.tree_id = ?
                AND ({$changeTypePredicates})
                AND JSON_CONTAINS(JSON_EXTRACT(arq.details, '$.actionability_gate.reasons'), JSON_QUOTE(?))
                AND JSON_UNQUOTE(JSON_EXTRACT(arq.details, '$.ds_governance.writeback_allowed')) = ?
                AND (arq.expires_at IS NULL OR arq.expires_at > NOW())
              ORDER BY arq.created_at ASC, arq.id ASC
              LIMIT ?",
            [
                (string) $policy['status'],
                (string) $policy['review_type'],
                $treeId,
                ...$proposalChangeTypes,
                (string) $policy['gate_reason'],
                (bool) $policy['writeback_allowed'] ? 'true' : 'false',
                $limit,
            ]
        );
    }

    private function ignoreCandidate(object $row, array $policy): array
    {
        $token = trim((string) ($row->token ?? ''));
        if ((bool) $policy['require_token'] && $token === '') {
            return [
                'id' => (int) ($row->id ?? 0),
                'success' => false,
                'error' => 'missing_token',
            ];
        }

        $unifiedId = (string) $policy['review_type'].':'.($token !== '' ? $token : (string) ($row->id ?? ''));
        $result = $this->reviewRegistry->ignoreItem($unifiedId);

        if (($result['success'] ?? false) === true) {
            DB::update(
                'UPDATE agent_review_queue SET reviewer_notes = ?, updated_at = NOW() WHERE id = ? AND review_type = ?',
                [
                    (string) $policy['reason'],
                    (int) ($row->id ?? 0),
                    (string) $policy['review_type'],
                ]
            );
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'unified_id' => $unifiedId,
            'success' => (bool) ($result['success'] ?? false),
            'message' => $result['message'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    private function candidatePayload(object $row): array
    {
        return [
            'id' => (int) ($row->id ?? 0),
            'title' => (string) ($row->title ?? ''),
            'person_id' => (int) ($row->person_id ?? 0),
            'confidence' => $row->confidence !== null ? (float) $row->confidence : null,
            'created_at' => (string) ($row->created_at ?? ''),
            'proposal_change_type' => (string) ($row->proposal_change_type ?? ''),
            'proposed_value' => $row->proposed_value ?? null,
        ];
    }

    private function failPayload(string $error): int
    {
        $payload = [
            'command' => 'genealogy:weak-source-add-cleanup',
            'status' => 'error',
            'error' => $error,
            'read_only' => true,
            'review_queue_mutation_allowed' => false,
            'posture' => [
                'config_source' => 'system_configs.'.self::CONFIG_KEY,
                'canonical_writes' => false,
                'face_identity_automation' => false,
            ],
        ];

        $this->renderPayload($payload);

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function compactIfRequested(array $payload): array
    {
        if (! (bool) $this->option('compact')) {
            return $payload;
        }

        $payload['compact'] = true;
        unset($payload['candidates'], $payload['results']);

        return $payload;
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

        if (($payload['status'] ?? null) === 'error') {
            $this->error((string) ($payload['error'] ?? 'error'));

            return;
        }

        $summary = $payload['summary'] ?? [];
        $this->info(sprintf(
            'Genealogy low-actionability cleanup: candidates %d, ignored %d, failed %d.',
            (int) ($summary['candidates'] ?? 0),
            (int) ($summary['ignored'] ?? 0),
            (int) ($summary['failed'] ?? 0)
        ));
    }
}
