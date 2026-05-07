<?php

namespace App\Console\Commands;

use App\Services\Genealogy\GenealogyReviewPacketApplyPreviewService;
use App\Services\Genealogy\GenealogyTypedRemediationMaterializationService;
use App\Services\Review\ReviewTargetReferenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenealogyTypedRemediationMaterializeCommand extends Command
{
    private const SUPPORTED_OPERATION_TYPES = [
        'family_duplicate_mark',
        'family_child_unlink',
        'source_duplicate_mark',
        'source_duplicate_cleanup',
        'genealogy_todo_create',
    ];

    protected $signature = 'genealogy:materialize-typed-remediation
                            {--id= : Source agent_review_queue id}
                            {--token= : Source agent_review_queue token}
                            {--target-ref= : Sanitized genealogy_finding target_ref from ops:review-backlog-report}
                            {--execute : Materialize or reuse a pending genealogy_review_packet row}
                            {--json : Emit machine-readable JSON}
                            {--compact : Emit compact sanitized output}';

    protected $description = 'Guarded operator trigger for typed genealogy remediation review-packet materialization';

    public function handle(
        GenealogyTypedRemediationMaterializationService $materializer,
        GenealogyReviewPacketApplyPreviewService $applyPreview,
        ReviewTargetReferenceService $targetReferences,
    ): int {
        $selection = $this->selection($targetReferences);
        if (! $selection['valid']) {
            return $this->emitFailure($selection['payload'], self::INVALID);
        }

        $row = $this->sourceRow($selection, $targetReferences);
        if ($row === null) {
            return $this->emitFailure($this->basePayload($selection, [
                'success' => false,
                'status' => 'failed',
                'error' => 'source_row_not_found',
                'action' => 'none',
            ]), self::FAILURE);
        }

        $details = $this->withRowFindingTypeContext($row, $this->decodeDetails($row->details ?? null));
        $preview = $applyPreview->preview($details);
        $operationTypes = $this->supportedOperationTypes($preview);
        $sourceDedupKey = $this->sourceDedupKey($row, $details);
        $source = $this->sourcePayload($row, $sourceDedupKey);
        $execute = (bool) $this->option('execute');

        if ((string) ($row->review_type ?? '') !== 'genealogy_finding'
            || (string) ($row->status ?? '') !== 'pending') {
            return $this->emitFailure($this->basePayload($selection, [
                'success' => false,
                'status' => 'failed',
                'error' => 'source_row_not_pending_genealogy_finding',
                'action' => 'none',
                'source' => $source,
                'typed_remediation_preview' => $preview,
            ]), self::FAILURE);
        }

        if ($operationTypes === []) {
            return $this->emitFailure($this->basePayload($selection, [
                'success' => false,
                'status' => 'failed',
                'error' => 'unsupported_typed_remediation',
                'action' => 'none',
                'source' => $source,
                'typed_remediation_preview' => $preview,
            ]), self::FAILURE);
        }

        if (! $execute) {
            $inspection = $materializer->inspectQueueRow($row);
            if (! ($inspection['success'] ?? false)) {
                return $this->emitFailure($this->basePayload($selection, [
                    'success' => false,
                    'status' => ($inspection['error'] ?? null) === 'packet_validation_failed' ? 'blocked' : 'failed',
                    'error' => $inspection['error'] ?? 'materialization_inspection_failed',
                    'action' => 'none',
                    'source' => $source,
                    'operation_types' => $inspection['operation_types'] ?? $operationTypes,
                    'packet' => null,
                    'packet_summary' => $inspection['packet_summary'] ?? null,
                    'validation' => $inspection['validation'] ?? null,
                    'typed_remediation_preview' => $inspection['typed_remediation_preview'] ?? $preview,
                ]), self::FAILURE);
            }

            $existing = ($inspection['materialized_existing'] ?? false) === true
                ? (object) [
                    'id' => $inspection['review_queue_id'] ?? null,
                    'token' => $inspection['token'] ?? null,
                ]
                : null;

            return $this->emitSuccess($this->basePayload($selection, [
                'success' => true,
                'status' => 'dry_run',
                'action' => $existing === null ? 'would_create_packet' : 'would_reuse_existing_packet',
                'source' => $source,
                'operation_types' => $operationTypes,
                'packet' => $existing === null ? null : [
                    'review_queue_id' => (int) $existing->id,
                    'token' => (string) $existing->token,
                    'materialized_existing' => true,
                ],
                'packet_summary' => $inspection['packet_summary'] ?? null,
                'validation' => $inspection['validation'] ?? null,
                'typed_remediation_preview' => $preview,
            ]));
        }

        $result = match ((string) ($selection['type'] ?? '')) {
            'id' => $materializer->materializeFromQueueId((int) $selection['id']),
            'token' => $materializer->materializeFromToken((string) $selection['token']),
            'target_ref' => $materializer->materializeFromQueueRow($row),
            default => [
                'success' => false,
                'error' => 'invalid_selection',
            ],
        };

        $payload = $this->basePayload($selection, [
            'success' => (bool) ($result['success'] ?? false),
            'status' => ($result['success'] ?? false) ? 'materialized' : 'failed',
            'action' => ($result['success'] ?? false)
                ? (($result['materialized_existing'] ?? false) ? 'reused_existing_packet' : 'created_packet')
                : 'none',
            'source' => $source,
            'operation_types' => $operationTypes,
            'packet' => ($result['success'] ?? false) ? [
                'review_queue_id' => (int) ($result['review_queue_id'] ?? 0),
                'token' => (string) ($result['token'] ?? ''),
                'materialized_existing' => (bool) ($result['materialized_existing'] ?? false),
            ] : null,
            'error' => $result['error'] ?? null,
            'packet_summary' => $result['packet_summary'] ?? null,
            'validation' => $result['validation'] ?? null,
            'typed_remediation_preview' => $result['typed_remediation_preview'] ?? $preview,
        ]);

        if (! ($result['success'] ?? false)) {
            return $this->emitFailure($payload, self::FAILURE);
        }

        return $this->emitSuccess($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function selection(ReviewTargetReferenceService $targetReferences): array
    {
        $rawId = $this->option('id');
        $token = trim((string) ($this->option('token') ?? ''));
        $hasId = $rawId !== null && trim((string) $rawId) !== '';
        $hasToken = $token !== '';

        $targetRef = trim((string) ($this->option('target-ref') ?? ''));
        $hasTargetRef = $targetRef !== '';
        $selectorCount = ($hasId ? 1 : 0) + ($hasToken ? 1 : 0) + ($hasTargetRef ? 1 : 0);

        if ($selectorCount !== 1) {
            return [
                'valid' => false,
                'payload' => $this->basePayload(['type' => null, 'id' => null, 'token' => null, 'target_ref' => null], [
                    'success' => false,
                    'status' => 'failed',
                    'error' => 'invalid_selection',
                    'message' => 'Provide exactly one source selector: --id, --token, or --target-ref.',
                    'action' => 'none',
                ]),
            ];
        }

        if ($hasId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if (! is_int($id) || $id < 1) {
                return [
                    'valid' => false,
                    'payload' => $this->basePayload(['type' => 'id', 'id' => $rawId, 'token' => null, 'target_ref' => null], [
                        'success' => false,
                        'status' => 'failed',
                        'error' => 'invalid_selection',
                        'message' => '--id must be a positive integer.',
                        'action' => 'none',
                    ]),
                ];
            }

            return [
                'valid' => true,
                'type' => 'id',
                'id' => $id,
                'token' => null,
                'target_ref' => null,
            ];
        }

        if ($hasTargetRef) {
            $normalized = $targetReferences->normalize($targetRef, ['genealogy_finding']);
            if ($normalized === null) {
                return [
                    'valid' => false,
                    'payload' => $this->basePayload(['type' => 'target_ref', 'id' => null, 'token' => null, 'target_ref' => $targetRef], [
                        'success' => false,
                        'status' => 'failed',
                        'error' => 'invalid_selection',
                        'message' => '--target-ref must be a genealogy_finding target ref.',
                        'action' => 'none',
                    ]),
                ];
            }

            return [
                'valid' => true,
                'type' => 'target_ref',
                'id' => null,
                'token' => null,
                'target_ref' => $normalized['target_ref'],
            ];
        }

        return [
            'valid' => true,
            'type' => 'token',
            'id' => null,
            'token' => $token,
            'target_ref' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function sourceRow(array $selection, ReviewTargetReferenceService $targetReferences): ?object
    {
        if ((string) ($selection['type'] ?? '') === 'target_ref') {
            return $targetReferences->pendingReviewRowForTargetRef(
                $selection['target_ref'] ?? null,
                ['genealogy_finding']
            );
        }

        $query = DB::table('agent_review_queue');

        return ((string) ($selection['type'] ?? '') === 'id')
            ? $query->where('id', (int) $selection['id'])->first()
            : $query->where('token', (string) $selection['token'])->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(array $selection, array $overrides = []): array
    {
        return array_merge([
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'mode' => $this->option('execute') ? 'execute' : 'dry_run',
            'execute' => (bool) $this->option('execute'),
            'dry_run' => ! (bool) $this->option('execute'),
            'no_canonical_write' => true,
            'canonical_writes_performed' => false,
            'apply_held' => true,
            'apply_performed' => false,
            'selection' => [
                'type' => $selection['type'] ?? null,
                'id' => $selection['id'] ?? null,
                'token' => $selection['token'] ?? null,
                'target_ref' => $selection['target_ref'] ?? null,
            ],
            'safety' => $this->safetyPayload(),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function sourcePayload(object $row, string $sourceDedupKey): array
    {
        return [
            'review_queue_id' => (int) $row->id,
            'token' => $this->text($row->token ?? null),
            'review_type' => (string) ($row->review_type ?? ''),
            'status' => (string) ($row->status ?? ''),
            'finding_type' => $this->text($row->finding_type ?? null),
            'source_dedup_key' => $sourceDedupKey,
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    private function safetyPayload(): array
    {
        return [
            'scope' => 'review_packet_materialization_only',
            'preview_only' => true,
            'no_canonical_write' => true,
            'canonical_write_allowed' => false,
            'canonical_writes_performed' => false,
            'apply_held' => true,
            'apply_enabled' => false,
            'apply_performed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeDetails(mixed $details): array
    {
        if (is_array($details)) {
            return $details;
        }

        if (! is_string($details) || trim($details) === '') {
            return [];
        }

        $decoded = json_decode($details, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function withRowFindingTypeContext(object $row, array $details): array
    {
        $findingType = $this->text($row->finding_type ?? null);
        if ($findingType !== null && ! isset($details['finding_type'])) {
            $details['finding_type'] = $findingType;
        }

        return $details;
    }

    /**
     * @return list<string>
     */
    private function supportedOperationTypes(array $preview): array
    {
        $types = [];
        foreach ((array) ($preview['operations'] ?? []) as $operation) {
            if (! is_array($operation)) {
                continue;
            }

            $type = $this->text($operation['operation_type'] ?? null);
            if ($type !== null && in_array($type, self::SUPPORTED_OPERATION_TYPES, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private function sourceDedupKey(object $row, array $details): string
    {
        foreach (['typed_remediation_dedup_key', 'remediation_dedup_key', 'dedup_key'] as $key) {
            $value = $this->text($details[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return 'agent_review_queue:genealogy_finding:'.(int) $row->id;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitSuccess(array $payload): int
    {
        return $this->emit($payload, self::SUCCESS);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emitFailure(array $payload, int $exitCode): int
    {
        return $this->emit($payload, $exitCode);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        $emitPayload = $this->option('compact') ? $this->compactPayload($payload) : $payload;

        if ($this->option('json')) {
            $json = json_encode($emitPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $this->error('Failed to encode typed remediation materialization JSON.');

                return self::FAILURE;
            }

            $this->line($json);

            return $exitCode;
        }

        if ($this->option('compact')) {
            $preview = is_array($emitPayload['typed_remediation_preview'] ?? null)
                ? $emitPayload['typed_remediation_preview']
                : [];
            $operationTypes = is_array($emitPayload['operation_types'] ?? null)
                ? implode(',', array_filter($emitPayload['operation_types'], 'is_string'))
                : '';
            $failedGuards = is_array($preview['failed_guard_names'] ?? null)
                ? implode(',', array_filter($preview['failed_guard_names'], 'is_string'))
                : '';
            $packetSummary = is_array($emitPayload['packet_summary'] ?? null)
                ? $emitPayload['packet_summary']
                : [];
            $validation = is_array($emitPayload['validation'] ?? null)
                ? $emitPayload['validation']
                : [];
            $blockerCodes = is_array($validation['blocker_codes'] ?? null)
                ? implode(',', array_filter($validation['blocker_codes'], 'is_string'))
                : '';
            $this->line(sprintf(
                'Genealogy typed remediation materialization compact: status=%s mode=%s action=%s success=%s operation_types=%s source_locators=%s claims=%s validation_blockers=%s blocker_codes=%s preview_only=%s operation_statuses=%s guards=%s failed_guards=%s row_touches=%s no_canonical_write=%s apply_held=%s',
                (string) ($emitPayload['status'] ?? 'unknown'),
                (string) ($emitPayload['mode'] ?? 'unknown'),
                (string) ($emitPayload['action'] ?? 'unknown'),
                ($emitPayload['success'] ?? false) ? 'yes' : 'no',
                $operationTypes !== '' ? $operationTypes : 'none',
                (string) ($packetSummary['source_locator_count'] ?? 0),
                (string) ($packetSummary['claim_count'] ?? 0),
                (string) ($validation['blocker_count'] ?? 0),
                $blockerCodes !== '' ? $blockerCodes : 'none',
                ($packetSummary['preview_only'] ?? false) ? 'yes' : 'no',
                $this->countMapText($preview['operation_status_counts'] ?? []),
                $this->countMapText($preview['guard_status_counts'] ?? []),
                $failedGuards !== '' ? $failedGuards : 'none',
                (string) ($preview['proposed_effect_row_touch_count'] ?? 0),
                ($emitPayload['safety']['no_canonical_write'] ?? false) ? 'yes' : 'no',
                ($emitPayload['safety']['apply_held'] ?? false) ? 'yes' : 'no',
            ));

            return $exitCode;
        }

        $this->line(sprintf(
            'Genealogy typed remediation materialization: status=%s mode=%s action=%s execute=%s no_canonical_write=%s apply_held=%s',
            (string) ($payload['status'] ?? 'unknown'),
            (string) ($payload['mode'] ?? 'unknown'),
            (string) ($payload['action'] ?? 'unknown'),
            ($payload['execute'] ?? false) ? 'yes' : 'no',
            ($payload['safety']['no_canonical_write'] ?? false) ? 'yes' : 'no',
            ($payload['safety']['apply_held'] ?? false) ? 'yes' : 'no',
        ));

        if (($payload['success'] ?? false) !== true) {
            $blockerSummary = $this->validationBlockerSummary($payload['validation'] ?? null);
            if (($payload['status'] ?? null) === 'blocked' && $blockerSummary['blocker_count'] > 0) {
                $this->line(sprintf(
                    'Validation blockers: count=%s codes=%s',
                    (string) $blockerSummary['blocker_count'],
                    $blockerSummary['blocker_codes'] !== [] ? implode(',', $blockerSummary['blocker_codes']) : 'none',
                ));
            }

            $this->error((string) ($payload['message'] ?? $payload['error'] ?? 'Materialization failed.'));
        } elseif (($payload['execute'] ?? false) !== true) {
            $this->warn('dry-run only; add --execute to create or reuse a pending genealogy_review_packet row.');
        }

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $packet = is_array($payload['packet'] ?? null) ? $payload['packet'] : null;

        return [
            'version' => $payload['version'] ?? 1,
            'generated_at' => $payload['generated_at'] ?? null,
            'mode' => $payload['mode'] ?? 'unknown',
            'execute' => (bool) ($payload['execute'] ?? false),
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'success' => (bool) ($payload['success'] ?? false),
            'status' => $payload['status'] ?? 'unknown',
            'error' => $payload['error'] ?? null,
            'action' => $payload['action'] ?? 'unknown',
            'selection' => [
                'type' => $payload['selection']['type'] ?? null,
                'value_present' => (($payload['selection']['id'] ?? null) !== null)
                    || (($payload['selection']['token'] ?? null) !== null)
                    || (($payload['selection']['target_ref'] ?? null) !== null),
            ],
            'source' => $source === [] ? null : [
                'present' => true,
                'review_type' => $source['review_type'] ?? null,
                'status' => $source['status'] ?? null,
                'finding_type' => $source['finding_type'] ?? null,
                'source_dedup_key_present' => $this->text($source['source_dedup_key'] ?? null) !== null,
            ],
            'operation_types' => $this->safeOperationTypes($payload['operation_types'] ?? []),
            'packet' => $packet === null ? null : [
                'present' => true,
                'materialized_existing' => (bool) ($packet['materialized_existing'] ?? false),
            ],
            'packet_summary' => $this->compactPacketSummary($payload['packet_summary'] ?? null),
            'validation' => $this->compactValidation($payload['validation'] ?? null),
            'safety' => $payload['safety'] ?? $this->safetyPayload(),
            'typed_remediation_preview' => $this->compactPreview($payload['typed_remediation_preview'] ?? null),
        ];
    }

    private function compactPacketSummary(mixed $summary): ?array
    {
        if (! is_array($summary)) {
            return null;
        }

        return [
            'target_review_type' => (string) ($summary['target_review_type'] ?? 'genealogy_review_packet'),
            'source_locator_count' => (int) ($summary['source_locator_count'] ?? 0),
            'claim_count' => (int) ($summary['claim_count'] ?? 0),
            'identity_present' => (bool) ($summary['identity_present'] ?? false),
            'privacy_present' => (bool) ($summary['privacy_present'] ?? false),
            'validation_valid' => (bool) ($summary['validation_valid'] ?? false),
            'validation_error_count' => (int) ($summary['validation_error_count'] ?? 0),
            'validation_warning_count' => (int) ($summary['validation_warning_count'] ?? 0),
            'preview_only' => (bool) ($summary['preview_only'] ?? false),
            'mutates_accepted_facts' => (bool) ($summary['mutates_accepted_facts'] ?? false),
            'dedup_key_present' => (bool) ($summary['dedup_key_present'] ?? false),
        ];
    }

    private function compactValidation(mixed $validation): ?array
    {
        if (! is_array($validation)) {
            return null;
        }

        $blockerSummary = $this->validationBlockerSummary($validation);

        return [
            'valid' => (bool) ($validation['valid'] ?? false),
            'blocker_count' => $blockerSummary['blocker_count'],
            'blocker_codes' => $blockerSummary['blocker_codes'],
        ];
    }

    /**
     * @return array{blocker_count: int, blocker_codes: list<string>}
     */
    private function validationBlockerSummary(mixed $validation): array
    {
        if (! is_array($validation)) {
            return [
                'blocker_count' => 0,
                'blocker_codes' => [],
            ];
        }

        $codes = [];
        foreach ((array) ($validation['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = $this->safeValidationCode($error['code'] ?? null);
            if ($code !== null && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return [
            'blocker_count' => count($codes),
            'blocker_codes' => $codes,
        ];
    }

    private function compactPreview(mixed $preview): ?array
    {
        if (! is_array($preview)) {
            return null;
        }

        $operations = array_values(array_filter((array) ($preview['operations'] ?? []), 'is_array'));
        $operationStatusCounts = [];
        $operationTypeCounts = [];
        $guardStatusCounts = [];
        $failedGuardNames = [];
        $proposedEffectTypes = [];
        $rowTouchCount = 0;

        foreach ($operations as $operation) {
            $status = $this->safeStatus($operation['status'] ?? null);
            $operationStatusCounts[$status] = (int) ($operationStatusCounts[$status] ?? 0) + 1;

            $type = $this->safeOperationType($operation['operation_type'] ?? null);
            $operationTypeCounts[$type] = (int) ($operationTypeCounts[$type] ?? 0) + 1;

            foreach ((array) ($operation['guards'] ?? []) as $guard) {
                if (! is_array($guard)) {
                    continue;
                }

                $guardStatus = $this->safeStatus($guard['status'] ?? null);
                $guardStatusCounts[$guardStatus] = (int) ($guardStatusCounts[$guardStatus] ?? 0) + 1;

                if ($guardStatus === 'fail') {
                    $name = $this->safeGuardName($guard['name'] ?? null);
                    if ($name !== null) {
                        $failedGuardNames[$name] = true;
                    }
                }
            }

            $effect = is_array($operation['proposed_effect'] ?? null) ? $operation['proposed_effect'] : [];
            $effectType = $this->safeEffectType($effect['type'] ?? null);
            if ($effectType !== null) {
                $proposedEffectTypes[$effectType] = (int) ($proposedEffectTypes[$effectType] ?? 0) + 1;
            }

            $touches = is_array($effect['rows_that_would_be_touched'] ?? null)
                ? $effect['rows_that_would_be_touched']
                : [];
            $rowTouchCount += count($touches);
        }

        ksort($operationStatusCounts);
        ksort($operationTypeCounts);
        ksort($guardStatusCounts);
        ksort($failedGuardNames);
        ksort($proposedEffectTypes);

        return [
            'status' => $this->safeStatus($preview['status'] ?? null),
            'mutates_accepted_facts' => (bool) ($preview['mutates_accepted_facts'] ?? false),
            'accepted_fact_mutation_count' => is_array($preview['accepted_fact_mutations'] ?? null)
                ? count($preview['accepted_fact_mutations'])
                : 0,
            'operation_count' => (int) ($preview['operation_count'] ?? count($operations)),
            'operation_type_counts' => $operationTypeCounts,
            'operation_status_counts' => $operationStatusCounts,
            'guard_status_counts' => $guardStatusCounts,
            'failed_guard_names' => array_keys($failedGuardNames),
            'proposed_effect_type_counts' => $proposedEffectTypes,
            'proposed_effect_row_touch_count' => $rowTouchCount,
            'summary' => $this->integerMap($preview['summary'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function safeOperationTypes(mixed $types): array
    {
        if (! is_array($types)) {
            return [];
        }

        $safe = [];
        foreach ($types as $type) {
            $safe[] = $this->safeOperationType($type);
        }

        return array_values(array_unique($safe));
    }

    private function safeOperationType(mixed $type): string
    {
        $type = $this->text($type);

        return $type !== null && in_array($type, self::SUPPORTED_OPERATION_TYPES, true)
            ? $type
            : 'other';
    }

    private function safeStatus(mixed $status): string
    {
        $status = $this->text($status);

        return $status !== null && in_array($status, ['blocked', 'dry_run', 'fail', 'failed', 'pass', 'pending', 'preview_only', 'resolved', 'unresolved'], true)
            ? $status
            : 'unknown';
    }

    private function safeGuardName(mixed $name): ?string
    {
        $name = $this->text($name);
        if ($name === null || ! preg_match('/^[a-z0-9_:-]{1,80}$/', $name)) {
            return null;
        }

        return $name;
    }

    private function safeValidationCode(mixed $code): ?string
    {
        $code = $this->text($code);
        if ($code === null || ! preg_match('/^[a-z0-9_:-]{1,80}$/', $code)) {
            return null;
        }

        return $code;
    }

    private function safeEffectType(mixed $type): ?string
    {
        $type = $this->text($type);
        if ($type === null || ! preg_match('/^[a-z0-9_:-]{1,80}$/', $type)) {
            return null;
        }

        return $type;
    }

    /**
     * @return array<string, int>
     */
    private function integerMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $count) {
            if (! is_string($key) || ! is_numeric($count)) {
                continue;
            }

            $map[$key] = (int) $count;
        }

        ksort($map);

        return $map;
    }

    private function countMapText(mixed $value): string
    {
        $map = $this->integerMap($value);
        if ($map === []) {
            return 'none';
        }

        $parts = [];
        foreach ($map as $key => $count) {
            $parts[] = $key.':'.$count;
        }

        return implode(',', $parts);
    }
}
