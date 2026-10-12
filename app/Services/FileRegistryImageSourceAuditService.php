<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** Private bounded source evidence only. Never changes source, catalog or identity. */
class FileRegistryImageSourceAuditService
{
    public function __construct(private NextcloudFileApiService $nextcloud) {}

    public function auditRegistryBatch(int $afterId, int $limit, int $maxSeconds, ?int $cutoff = null, bool $decode = false): array
    {
        return $this->batch('registry', $afterId, $limit, $maxSeconds, $cutoff, $decode);
    }

    public function auditMediaBatch(int $afterId, int $limit, int $maxSeconds, ?int $cutoff = null): array
    {
        return $this->batch('media', $afterId, $limit, $maxSeconds, $cutoff, false);
    }

    /** Admission only; ordinary review views never read or hash source files. */
    public function findingRecheckAdmission(int $findingId): array
    {
        try {
            $finding = $this->sourceFinding($findingId);
            $tool = DB::table('agent_tool_registry')->where('name', 'file_image_source_finding_recheck')->where('enabled', 1)->first();
            if (! $tool || $tool->service_class !== self::class || $tool->method !== 'recheckFinding' || $tool->risk_level !== 'read') {
                throw new InvalidArgumentException('Read-only recheck route is unavailable');
            }
            $row = $finding['row'];

            return ['status' => 'explicit_recheck_required', 'read_only' => true, 'fresh_measurement_performed' => false,
                'tool' => 'file_image_source_finding_recheck', 'arguments' => [
                    'findingId' => $findingId, 'expectedFindingDigest' => $finding['digest'],
                    'sourceKind' => $row['source_kind'], 'sourceId' => $row['source_id'],
                    ...($row['source_kind'] === 'media' ? ['treeId' => $row['tree_id']] : []),
                ], 'technical_readiness' => 'not_assessed', 'identity_decisions_performed' => false];
        } catch (Throwable) {
            return ['status' => 'held', 'reason_code' => 'finding_unavailable_or_unqualified',
                'read_only' => true, 'fresh_measurement_performed' => false, 'technical_readiness' => 'not_assessed'];
        }
    }

    /** One finding fixes the cursor; caller hashes cannot supply fresh source evidence. */
    public function recheckFinding(int $findingId, string $expectedFindingDigest, string $sourceKind, int $sourceId, ?int $treeId = null): array
    {
        try {
            $finding = $this->sourceFinding($findingId);
            $retained = $finding['row'];
            if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedFindingDigest)
                || ! hash_equals($finding['digest'], $expectedFindingDigest)
                || $sourceKind !== $retained['source_kind'] || $sourceId !== $retained['source_id']
                || $treeId !== ($sourceKind === 'media' ? $retained['tree_id'] : null)) {
                throw new InvalidArgumentException('selector_binding_held');
            }
            $seconds = config('file_registry.image_audit.finding_recheck_max_seconds');
            if (! is_int($seconds) || $seconds < 1 || $seconds > 10) {
                throw new InvalidArgumentException('recheck_budget_invalid');
            }
            $current = $this->findingSelector($retained);
            $batch = $sourceKind === 'registry'
                ? $this->auditRegistryBatch($sourceId - 1, 1, $seconds, $sourceId, false)
                : $this->auditMediaBatch($sourceId - 1, 1, $seconds, $sourceId);
            $fresh = $batch['rows'][0] ?? null;
            if (($batch['status'] ?? null) === 'blocked' || ! is_array($fresh)
                || count($batch['rows']) !== 1 || ($fresh['source_kind'] ?? null) !== $sourceKind
                || ($fresh['source_id'] ?? null) !== $sourceId || ($fresh['tree_id'] ?? null) !== $treeId
                || ($fresh['source_snapshot']['id'] ?? null) != $current->id
                || ($fresh['successful'] ?? false) !== true
                || ! is_string($fresh['observed_sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $fresh['observed_sha256'])) {
                throw new InvalidArgumentException('fresh_source_recheck_held');
            }
            $latest = $this->findingSelector($retained);
            $snapshot = $fresh['source_snapshot'];
            foreach ($snapshot as $key => $value) {
                if (! property_exists($latest, $key) || $latest->{$key} != $value) {
                    throw new InvalidArgumentException('source_selector_changed');
                }
            }
            if ($this->sourceFinding($findingId)['digest'] !== $expectedFindingDigest) {
                throw new InvalidArgumentException('finding_changed_during_recheck');
            }
            $baseline = ($retained['successful'] ?? false) === true && is_string($retained['observed_sha256'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/', $retained['observed_sha256']) ? $retained['observed_sha256'] : null;
            $relation = $baseline === null ? 'retained_physical_baseline_unknown'
                : (hash_equals($baseline, $fresh['observed_sha256']) ? 'unchanged_since_inspection' : 'changed_since_inspection');
            $historical = is_string($fresh['registry_sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $fresh['registry_sha256'])
                ? $fresh['registry_sha256'] : null;
            // This is preliminary eligibility for a READ plan, never completed qualification or write authority.
            $eligibility = $relation === 'unchanged_since_inspection'
                ? $this->sourcePlanEligibility($current, $sourceKind) : ['status' => 'held', 'reason_code' => $relation];

            return ['success' => true, 'schema' => 'plos.source_finding_recheck.v1', 'status' => 'measured', 'read_only' => true,
                'finding_id' => $findingId, 'finding_digest' => $expectedFindingDigest,
                'source_kind' => $sourceKind, 'source_id' => $sourceId, 'tree_id' => $treeId,
                'observed_at' => $batch['captured_at'], 'source_sha256' => $fresh['observed_sha256'],
                'retained_source_sha256' => $baseline, 'retained_relation' => $relation,
                'historical_sha256' => $historical, 'historical_relation' => $historical === null ? 'unknown' : $fresh['hash_relation'],
                'content_format' => $fresh['content_format'] ?? null,
                'format_classification' => $fresh['format_classification'] ?? 'unknown',
                'format_evidence' => $fresh['format_evidence'] ?? null,
                'header_status' => $fresh['header_status'], 'decode_status' => $fresh['decode_status'],
                'image_corruption_determined' => false, 'technical_eligibility' => $eligibility,
                'write_ready' => false, 'identity_decisions_performed' => false,
                'historical_hashes_backfilled' => false, 'queue_changed' => false];
        } catch (Throwable $error) {
            $codes = ['selector_binding_held', 'recheck_budget_invalid', 'fresh_source_recheck_held',
                'finding_changed_during_recheck', 'source_selector_changed'];

            return ['success' => false, 'status' => 'held', 'read_only' => true,
                'reason_code' => $error instanceof InvalidArgumentException && in_array($error->getMessage(), $codes, true)
                    ? $error->getMessage() : 'finding_unavailable_or_unqualified',
                'write_ready' => false, 'image_corruption_determined' => false,
                'identity_decisions_performed' => false, 'historical_hashes_backfilled' => false, 'queue_changed' => false];
        }
    }

    /** Inspect private metadata transition receipts for one exact retained finding. */
    public function metadataTransitionProvenance(int $findingId, string $expectedFindingDigest, string $sourceKind, int $sourceId, ?int $treeId = null): array
    {
        $held = ['success' => false, 'schema' => 'plos.metadata_transition_provenance.v1', 'status' => 'held',
            'read_only' => true, 'reason_code' => 'finding_unavailable_or_unqualified',
            'write_ready' => false, 'identity_decisions_performed' => false,
            'unknown_receipts_preserved' => true, 'queue_changed' => false];
        try {
            $finding = $this->sourceFinding($findingId);
            $retained = $finding['row'];
            $tool = DB::table('agent_tool_registry')->where('name', 'file_image_source_metadata_transition_provenance')->where('enabled', 1)->first();
            if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedFindingDigest)
                || ! hash_equals($finding['digest'], $expectedFindingDigest)
                || $sourceKind !== $retained['source_kind'] || $sourceId !== $retained['source_id']
                || $treeId !== ($sourceKind === 'media' ? $retained['tree_id'] : null)
                || ! $tool || $tool->service_class !== self::class || $tool->method !== 'metadataTransitionProvenance'
                || $tool->risk_level !== 'read') {
                throw new InvalidArgumentException('selector_binding_held');
            }
            $maxSeconds = config('file_registry.image_audit.finding_recheck_max_seconds');
            if (! is_int($maxSeconds) || $maxSeconds < 1 || $maxSeconds > 10) {
                throw new InvalidArgumentException('recheck_budget_invalid');
            }
            $current = $this->findingSelector($retained);
            $batch = $sourceKind === 'registry'
                ? $this->auditRegistryBatch($sourceId - 1, 1, $maxSeconds, $sourceId, false)
                : $this->auditMediaBatch($sourceId - 1, 1, $maxSeconds, $sourceId);
            $fresh = $batch['rows'][0] ?? null;
            if (($batch['status'] ?? null) === 'blocked' || ! is_array($fresh) || count($batch['rows']) !== 1
                || ($fresh['source_kind'] ?? null) !== $sourceKind || ($fresh['source_id'] ?? null) !== $sourceId
                || ($fresh['tree_id'] ?? null) !== $treeId || ($fresh['source_snapshot']['id'] ?? null) != $current->id
                || ($fresh['successful'] ?? false) !== true || ! is_string($fresh['observed_sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $fresh['observed_sha256'])) {
                throw new InvalidArgumentException('fresh_source_recheck_held');
            }
            foreach ($fresh['source_snapshot'] as $key => $value) {
                if (! property_exists($current, $key) || $current->{$key} != $value) {
                    throw new InvalidArgumentException('source_selector_changed');
                }
            }
            $registryId = $fresh['registry_id'] ?? null;
            $assetUuid = $fresh['asset_uuid'] ?? null;
            if (! is_int($registryId) || $registryId < 1 || ! is_string($assetUuid) || ! Str::isUuid($assetUuid)
                || (int) ($retained['registry_id'] ?? 0) !== $registryId || ($retained['asset_uuid'] ?? null) !== $assetUuid) {
                throw new InvalidArgumentException('registry_asset_binding_held');
            }
            $registry = DB::table('file_registry')->where('id', $registryId)->where('asset_uuid', $assetUuid)->where('status', 'active')->first();
            if (! $registry || $this->validPath($registry->current_path) !== ($fresh['nextcloud_path'] ?? null)) {
                throw new InvalidArgumentException('registry_asset_binding_held');
            }
            $observed = $retained['observed_sha256'] ?? null;
            $historical = $fresh['registry_sha256'] ?? null;
            $observed = is_string($observed) && preg_match('/\A[a-f0-9]{64}\z/', $observed) ? $observed : null;
            $historical = is_string($historical) && preg_match('/\A[a-f0-9]{64}\z/', $historical) ? $historical : null;
            $physical = is_string($fresh['nextcloud_path'] ?? null) ? $this->nextcloud->localPath($fresh['nextcloud_path']) : null;
            $provenance = $historical !== null && $observed !== null && is_string($physical) && realpath($physical)
                ? app(ExifWritebackService::class)->inspectVerifiedMetadataTransitionChain(
                    $registryId, $assetUuid, $historical, $observed, (string) realpath($physical))
                : ['status' => 'partial_or_unknown', 'receipt_scan_complete' => false, 'matching_transition_count' => 0,
                    'unique_path_count' => 0, 'reason_code' => 'hash_selector_unavailable', 'transitions' => []];
            if ($this->sourceFinding($findingId)['digest'] !== $expectedFindingDigest) {
                throw new InvalidArgumentException('finding_changed_during_recheck');
            }
            $latest = $this->findingSelector($retained);
            foreach ($fresh['source_snapshot'] as $key => $value) {
                if (! property_exists($latest, $key) || $latest->{$key} != $value) {
                    throw new InvalidArgumentException('source_selector_changed');
                }
            }
            $chainStatus = $provenance['status'] ?? 'partial_or_unknown';
            if ($chainStatus === 'held') {
                $chainStatus = 'partial_or_unknown';
            }
            if (($provenance['status'] ?? null) === 'verified' && $observed === $fresh['observed_sha256']) {
                $chainStatus = 'complete_unique_chain';
            } elseif (($provenance['status'] ?? null) === 'verified') {
                $chainStatus = 'partial_or_unknown';
            }

            return ['success' => true, 'schema' => 'plos.metadata_transition_provenance.v1', 'status' => $chainStatus,
                'read_only' => true, 'finding_id' => $findingId, 'finding_digest' => $expectedFindingDigest,
                'source_kind' => $sourceKind, 'source_id' => $sourceId, 'tree_id' => $treeId,
                'registry_id' => $registryId, 'asset_uuid' => $assetUuid,
                'historical_sha256' => $historical, 'retained_observed_sha256' => $observed,
                'fresh_physical_sha256' => $fresh['observed_sha256'], 'fresh_physical_relation' => $observed === null ? 'unknown'
                    : (hash_equals($observed, $fresh['observed_sha256']) ? 'matches_retained' : 'changed_since_inspection'),
                'receipt_scan_complete' => $provenance['receipt_scan_complete'] ?? false,
                'matching_transition_count' => $provenance['matching_transition_count'] ?? 0,
                'unique_path_count' => $provenance['unique_path_count'] ?? 0,
                'chain_reason_code' => $provenance['reason_code'] ?? 'provenance_unavailable',
                'chain_stage' => $provenance['stage'] ?? null,
                'chain' => $provenance['transitions'] ?? [],
                'fresh_pixel_display_proof' => ['status' => $provenance['current_image_proof_status'] ?? 'not_verified',
                    'source_sha256' => $provenance['current_source_sha256'] ?? null,
                    'image_proof_sha256' => $provenance['current_image_proof_sha256'] ?? null],
                'write_ready' => false, 'identity_decisions_performed' => false,
                'unknown_receipts_preserved' => true, 'queue_changed' => false];
        } catch (Throwable $error) {
            $held['reason_code'] = $error instanceof InvalidArgumentException && in_array($error->getMessage(), [
                'selector_binding_held', 'fresh_source_recheck_held', 'source_selector_changed', 'registry_asset_binding_held',
                'finding_changed_during_recheck', 'recheck_budget_invalid'], true)
                ? $error->getMessage() : 'finding_unavailable_or_unqualified';

            return $held;
        }
    }

    private function sourceFinding(int $findingId): array
    {
        if ($findingId < 1) {
            throw new InvalidArgumentException('Invalid finding');
        }
        $registration = DB::table('review_type_registry')->where('name', 'file_source_recovery')->where('enabled', 1)->first();
        $ui = $registration ? json_decode($registration->ui_schema, true, flags: JSON_THROW_ON_ERROR) : [];
        if (! $registration || $registration->source_table !== 'agent_review_queue' || $registration->source_connection !== 'mysql'
            || json_decode($registration->actions, true, flags: JSON_THROW_ON_ERROR) !== []
            || ($ui['inspection_only'] ?? null) !== true || $registration->service_class !== null
            || $registration->approve_method !== null || $registration->reject_method !== null) {
            throw new InvalidArgumentException('Inspection registration held');
        }
        $record = DB::table('agent_review_queue')->where('id', $findingId)->where('review_type', 'file_source_recovery')
            ->where('agent_id', config('file_registry.image_audit.review.agent_id'))->where('status', 'pending')->first();
        if (! $record || ($record->expires_at !== null && strtotime($record->expires_at) <= time())
            || strlen($record->details) > config('file_registry.image_audit.maintenance_bounds.max_finding_bytes')) {
            throw new InvalidArgumentException('Finding unavailable');
        }
        $details = json_decode($record->details, true, flags: JSON_THROW_ON_ERROR);
        $row = $details['evidence']['row'] ?? null;
        $findingProducer = is_array($row) && array_key_exists('coverage', $row)
            ? FileRegistryFaceCoverageService::class : FileRegistryImageAuditService::class;
        if (($details['schema'] ?? null) !== 'plos.image_source_inspection_finding.v1'
            || ($details['inspection_only'] ?? null) !== true || ($details['identity_decisions_performed'] ?? null) !== false
            || ($details['historical_hashes_backfilled'] ?? null) !== false || ! is_array($row)
            || ! in_array($row['source_kind'] ?? null, ['registry', 'media'], true)
            || ! is_int($row['source_id'] ?? null) || $row['source_id'] < 1
            || ($row['source_snapshot']['id'] ?? null) != $row['source_id']
            || ($row['source_kind'] === 'media' && (! is_int($row['tree_id'] ?? null) || $row['tree_id'] < 1
                || ($row['source_snapshot']['tree_id'] ?? null) != $row['tree_id']))
            || ($row['source_kind'] === 'registry' && ($row['tree_id'] ?? null) !== null)
            || ($details['finding_key'] ?? null) !== $record->token
            || ! is_string($record->token) || ! preg_match('/\A[a-f0-9]{64}\z/', $record->token)
            || app($findingProducer)->findingDigest($details['evidence']) !== $record->token) {
            throw new InvalidArgumentException('Retained source finding is unqualified');
        }

        return ['row' => $row, 'digest' => hash('sha256', json_encode((array) $record, JSON_THROW_ON_ERROR))];
    }

    private function findingSelector(array $row): object
    {
        $current = DB::table($row['source_kind'] === 'registry' ? 'file_registry' : 'genealogy_media')->where('id', $row['source_id'])->first();
        $path = $current === null ? null : ($row['source_kind'] === 'registry'
            ? $current->current_path : (($current->nextcloud_path ?? null) ?: ($current->original_path ?? null)));
        if (! $current || $this->validPath($path) !== ($row['nextcloud_path'] ?? null)
            || ($row['source_kind'] === 'registry' && ($current->asset_uuid !== ($row['asset_uuid'] ?? null) || $current->status !== 'active'))
            || ($row['source_kind'] === 'media' && (int) $current->tree_id !== $row['tree_id'])) {
            throw new InvalidArgumentException('source_selector_changed');
        }

        return $current;
    }

    private function sourcePlanEligibility(object $current, string $kind): array
    {
        if ($kind !== 'registry' || ! is_string($current->asset_uuid ?? null) || ! Str::isUuid($current->asset_uuid)
            || ($current->path_hash ?? null) !== hash('sha256', strtolower($current->current_path))
            || $current->path_hash === hash('sha256', $current->current_path)) {
            return ['status' => 'no_supported_candidate', 'reason_code' => 'no_single_source_index_candidate'];
        }
        $claimants = DB::table('file_registry')->where(function ($query) use ($current): void {
            $query->where('current_path', $current->current_path)->orWhere('path_hash', hash('sha256', $current->current_path));
            if ($current->nextcloud_fileid !== null) {
                $query->orWhere('nextcloud_fileid', $current->nextcloud_fileid);
            }
        })->limit(2)->get();
        $tool = DB::table('agent_tool_registry')->where('name', 'file_single_source_index_plan')->where('enabled', 1)->first();
        if ($claimants->count() !== 1 || (int) $claimants->first()->id !== (int) $current->id
            || ! $tool || $tool->service_class !== FileRegistryService::class || $tool->method !== 'singleSourceIndexPlan'
            || $tool->risk_level !== 'read') {
            return ['status' => 'held', 'reason_code' => 'existing_plan_route_unqualified'];
        }

        return ['status' => 'read_plan_candidate', 'tool' => 'file_single_source_index_plan',
            'arguments' => ['assetUuid' => $current->asset_uuid], 'qualification' => 'existing_plan_required', 'write_ready' => false];
    }

    /** Capture both lane maxima with the exact same eligibility used by batches. */
    public function captureCutoffs(): array
    {
        return ['registry' => (int) ($this->query('registry')->max('id') ?? 0),
            'media' => (int) ($this->query('media')->max('id') ?? 0)];
    }

    /** Sparse EOF is scoped to the captured cutoff, not the last returned row count. */
    public function hasRemaining(string $lane, int $afterId, int $cutoff): bool
    {
        if (! in_array($lane, ['registry', 'media'], true) || $afterId < 0 || $cutoff < 0) {
            throw new InvalidArgumentException('Exact audit lane and nonnegative cursor/cutoff are required.');
        }

        return $this->query($lane)->where('id', '>', $afterId)->where('id', '<=', $cutoff)->exists();
    }

    public function nextSourceId(string $lane, int $afterId, int $cutoff): ?int
    {
        if (! in_array($lane, ['registry', 'media'], true) || $afterId < 0 || $cutoff < 0) {
            throw new InvalidArgumentException('Invalid source selector.');
        }
        $id = $this->query($lane)->where('id', '>', $afterId)->where('id', '<=', $cutoff)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    private function payload(string $lane, bool $decode): array
    {
        return [
            'schema' => $lane === 'registry' ? 'plos.registry_image_source_audit.v1' : 'plos.media_image_source_audit.v1',
            'read_only' => true, 'captured_at' => now()->toIso8601String(), 'header_only' => ! $decode,
            'decode_requested' => $decode,
            'header_reader' => $decode ? 'PHP getimagesizefromstring for bounded snapshot where available' : 'PHP getimagesize; no pixel decoder invoked',
            'time_budget_scope' => 'Checked between rows and hash chunks; filesystem and decoder calls are not forcibly interrupted; decode input is resource-capped',
            'full_pixel_decode_performed' => false, 'embedded_metadata_read' => false, 'image_validation_performed' => false,
            'decode_caps' => $decode ? ['max_bytes' => max(1, (int) config('file_registry.image_audit.max_decode_bytes', 16 * 1024 * 1024)),
                'max_pixels' => max(1, (int) config('file_registry.image_audit.max_decode_pixels', 8_000_000))] : null,
            'identity_decisions_performed' => false, 'rows' => [],
        ];
    }

    private function batch(string $lane, int $afterId, int $limit, int $seconds, ?int $cutoff, bool $decode): array
    {
        $payload = $this->payload($lane, $decode);
        $configured = (string) config('services.nextcloud.data_path', '');
        $root = $configured !== '' ? realpath($configured) : false;
        if ($afterId < 0 || $limit < 1 || $seconds < 1 || $seconds > 120 || ($cutoff !== null && $cutoff < 0)
            || $root === false || ! is_dir($root)) {
            return [...$payload, 'status' => 'blocked', 'error' => 'Valid cursor, positive bounded limit/time and configured local Nextcloud root are required.'];
        }
        $query = $this->query($lane);
        $cutoff ??= (int) ((clone $query)->max('id') ?? 0);
        $limit = min($limit, max(1, (int) config('file_registry.image_audit.max_rows', 500)));
        $deadline = $this->clock() + $seconds;
        $table = $lane === 'registry' ? 'file_registry' : 'genealogy_media';
        $columns = $this->columns($table, $lane === 'registry'
            ? ['id', 'asset_uuid', 'nextcloud_fileid', 'current_path', 'path_hash', 'status', 'extension', 'mime_type', 'file_size', 'content_hash', 'content_hash_verified_at', 'nextcloud_modified_at', 'updated_at', 'lifecycle_generation_key']
            : ['id', 'tree_id', 'nextcloud_path', 'original_path', 'file_format', 'mime_type', 'file_size', 'media_type', 'file_exists', 'updated_at']);
        $registryColumns = $lane === 'registry' ? $columns : $this->columns('file_registry',
            ['id', 'asset_uuid', 'nextcloud_fileid', 'current_path', 'path_hash', 'status', 'file_size', 'content_hash', 'content_hash_verified_at', 'nextcloud_modified_at', 'updated_at', 'lifecycle_generation_key']);
        $files = (clone $query)->where('id', '>', $afterId)->where('id', '<=', $cutoff)->orderBy('id')->limit($limit)->get($columns);
        $nextId = $afterId;
        $timedOut = false;
        foreach ($files as $file) {
            if ($this->clock() >= $deadline) {
                $timedOut = true;
                break;
            }
            $row = $this->row($lane, $file, $decode, $registryColumns);
            try {
                $row = $this->inspect($row, $root, $deadline, $decode);
            } catch (Throwable) {
                $row['status'] = $this->clock() >= $deadline ? 'time_budget_exceeded' : 'source_recheck_unavailable';
                $row['hash_relation'] = 'unavailable';
                $row = $this->finishRow($row);
            }
            $payload['rows'][] = $row;
            $payload['image_validation_performed'] = $payload['image_validation_performed'] || ($row['image_validation_performed'] ?? false);
            $payload['full_pixel_decode_performed'] = $payload['full_pixel_decode_performed'] || $row['decode_performed'];
            if (! $row['cursor_advance_allowed']) {
                $timedOut = true;
                break;
            }
            $nextId = (int) $file->id;
        }
        $hasMore = $this->hasRemaining($lane, $nextId, $cutoff);

        return [...$payload, 'status' => $timedOut ? 'time_limited' : 'completed_batch',
            'after_id' => $afterId, 'next_after_id' => $nextId, 'catalog_cutoff' => $cutoff,
            'effective_limit' => $limit, 'max_seconds' => $seconds, 'has_more' => $hasMore,
            'complete' => ! $hasMore && ! $timedOut, 'checked' => count($payload['rows'])];
    }

    private function query(string $lane): Builder
    {
        $extensions = array_values(array_unique(array_map('strtolower', array_merge(
            (array) config('file_types.image', []), (array) config('file_types.image_raw', [])))));
        if ($lane === 'registry') {
            return DB::table('file_registry')->where('status', 'active')->where(function ($q) use ($extensions): void {
                $q->where('mime_type', 'like', 'image/%')->orWhereIn(DB::raw('LOWER(extension)'), $extensions);
            });
        }

        return DB::table('genealogy_media')->where(function ($q) use ($extensions): void {
            $q->where('media_type', 'photo')->orWhere('mime_type', 'like', 'image/%')
                ->orWhereIn(DB::raw('LOWER(file_format)'), $extensions);
        });
    }

    private function columns(string $table, array $wanted): array
    {
        return array_values(array_intersect($wanted, DB::getSchemaBuilder()->getColumnListing($table)));
    }

    private function row(string $lane, object $file, bool $decode, array $registryColumns): array
    {
        $snapshot = (array) $file;
        // Same precedence/path admission as FaceLinkBridgeService::reviewSourcePhoto;
        // kept local until the shared resolver can be extracted by its owner.
        $path = $lane === 'registry' ? ($file->current_path ?? null) : (($file->nextcloud_path ?? null) ?: ($file->original_path ?? null));
        $path = $this->validPath($path);
        $registry = $lane === 'registry' ? $snapshot : null;
        if ($lane === 'media' && $path !== null) {
            $registry = $this->mediaRegistrySnapshot($path, $registryColumns);
        }

        return [
            'selector_kind' => $lane, 'selector_snapshot' => $snapshot, 'registry_snapshot' => $registry,
            'registry_id' => $registry === null ? null : (int) $registry['id'], 'asset_uuid' => $registry['asset_uuid'] ?? null,
            ...($lane === 'media' ? ['media_id' => (int) $file->id, 'tree_id' => (int) $file->tree_id] : []),
            'nextcloud_path' => $path, 'extension' => $file->extension ?? $file->file_format ?? null,
            'registry_mime_type' => $file->mime_type ?? null, 'registry_size' => $registry['file_size'] ?? null,
            'registry_sha256' => $registry['content_hash'] ?? null, 'registry_hash_verified_at' => $registry['content_hash_verified_at'] ?? null,
            'status' => 'missing_or_unavailable', 'physical_exists' => false, 'actual_size' => null, 'sha256' => null,
            'source_sha256' => null, 'source_fingerprint' => null, 'hash_relation' => 'unavailable',
            'header_status' => 'not_checked', 'width' => null, 'height' => null,
            'content_format' => null, 'format_classification' => 'unknown', 'format_evidence' => null,
            'decode_status' => $decode ? 'not_checked' : 'not_requested', 'decode_error_code' => null,
            'decode_performed' => false, 'source_verified' => false,
        ];
    }

    /** Resolve only a unique active path-hash companion, including the known lowercase-hash migration candidate. */
    public function mediaRegistrySnapshot(string $path, array $columns, bool $lock = false): ?array
    {
        $exactHash = hash('sha256', $path);
        $exactQuery = DB::table('file_registry')->where('path_hash', $exactHash)->where('status', 'active');
        $exact = ($lock ? $exactQuery->lockForUpdate() : $exactQuery)->first($columns);
        if ($exact !== null) {
            if ($this->validPath($exact->current_path ?? null) !== $path) {
                return null;
            }
            $exactPathQuery = $this->whereExactCurrentPath(DB::table('file_registry')->where('status', 'active'), $path);
            $exactPathQuery = $lock ? $exactPathQuery->lockForUpdate() : $exactPathQuery;
            $exactPathRows = $exactPathQuery->limit(2)->get(['id']);

            return $exactPathRows->count() === 1 && (int) $exactPathRows[0]->id === (int) $exact->id
                ? (array) $exact : null;
        }

        $lowerHash = hash('sha256', strtolower($path));
        if ($lowerHash === $exactHash) {
            return null;
        }
        $query = DB::table('file_registry')->where('path_hash', $lowerHash)->where('status', 'active');
        $query = $this->whereExactCurrentPath($query, $path);
        $query = $lock ? $query->lockForUpdate() : $query;
        $candidates = $query->limit(2)->get($columns)->filter(fn ($row) => $this->validPath($row->current_path ?? null) === $path)->values();
        if ($candidates->count() !== 1 || ! Str::isUuid($candidates[0]->asset_uuid ?? '')) {
            return null;
        }

        // A different row may carry the exact hash while sharing this path. Keep that conflict held.
        $exactPathQuery = $this->whereExactCurrentPath(DB::table('file_registry')->where('status', 'active'), $path);
        $exactPathQuery = $lock ? $exactPathQuery->lockForUpdate() : $exactPathQuery;
        $exactPathRows = $exactPathQuery->limit(2)->get(['id']);
        if ($exactPathRows->count() !== 1 || (int) $exactPathRows[0]->id !== (int) $candidates[0]->id) {
            return null;
        }

        return (array) $candidates[0];
    }

    private function whereExactCurrentPath(Builder $query, string $path): Builder
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return $query->whereRaw('BINARY current_path = BINARY ?', [$path]);
        }

        return $query->where('current_path', $path);
    }

    private function validPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, '\\') || str_contains($path, "\0")
            || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return '/'.ltrim($path, '/');
    }

    private function inspect(array $row, string $root, float $deadline, bool $decodeRequested): array
    {
        $path = $row['nextcloud_path'];
        if ($this->clock() >= $deadline) {
            $row['status'] = 'time_budget_exceeded';

            return $this->finishRow($row);
        }
        if ($path === null || ($row['selector_kind'] === 'media' && $row['tree_id'] <= 0)) {
            $row['status'] = 'invalid_source_selector';

            return $this->finishRow($row);
        }
        $local = $this->nextcloud->localPath($path);
        $physical = $local === null ? false : realpath($local);
        if ($this->clock() >= $deadline) {
            $row['status'] = 'time_budget_exceeded';

            return $this->finishRow($row);
        }
        if ($physical && ! str_starts_with($physical, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $row['status'] = 'blocked_outside_root';
            $row['physical_exists'] = null;

            return $this->finishRow($row);
        }
        if (! $physical || ! is_file($physical) || ! is_readable($physical)) {
            return $this->finishRow($row);
        }
        $row['physical_exists'] = true;
        $proof = $this->nextcloud->computeFileHash($path, $deadline, true);
        if (! ($proof['success'] ?? false)) {
            $row['source_error_code'] = $proof['reason'] ?? 'source_proof_unavailable';
            $row['status'] = $this->clock() >= $deadline || ($proof['reason'] ?? null) === 'hash_deadline_exceeded'
                ? 'time_budget_exceeded' : (($proof['reason'] ?? null) === 'source_changed' ? 'source_changed_during_audit' : 'read_error');
            $row['hash_relation'] = $row['status'] === 'source_changed_during_audit' ? 'source_race_review' : 'unavailable';

            return $this->finishRow($row);
        }
        $before = $proof['source_fingerprint'];
        $hash = $proof['hash'];
        $row['actual_size'] = $before['size'];
        $row['sha256'] = $row['source_sha256'] = $hash;
        $row['source_fingerprint'] = $before;
        $expected = $row['registry_sha256'];
        $row['hash_relation'] = ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            ? 'not_recorded' : (hash_equals($expected, $hash) ? 'match' : 'mismatch_review');
        $imageBytes = null;
        $snapshotSourceChanged = false;
        if ($decodeRequested) {
            $maxBytes = max(1, (int) config('file_registry.image_audit.max_decode_bytes', 16 * 1024 * 1024));
            if ($before['size'] > $maxBytes) {
                $row['decode_status'] = 'resource_cap';
                $row['decode_error_code'] = 'max_decode_bytes_exceeded';
            } elseif ($before['size'] > 0) {
                $snapshot = $this->readImageSnapshot($physical, $deadline, $maxBytes);
                if ($snapshot === null) {
                    $row['status'] = $this->clock() >= $deadline ? 'time_budget_exceeded' : 'read_error';
                    $row['decode_status'] = $this->clock() >= $deadline ? 'time_budget_exceeded' : 'read_error';

                    return $this->finishRow($row);
                }
                if (! empty($snapshot['resource_cap'])) {
                    $row['decode_status'] = 'resource_cap';
                    $row['decode_error_code'] = 'max_decode_bytes_exceeded';
                } elseif (! hash_equals($hash, $snapshot['sha256'])) {
                    $row['status'] = 'source_changed_during_audit';
                    $row['hash_relation'] = 'source_race_review';
                    $row['decode_status'] = 'source_changed';
                    $snapshotSourceChanged = true;
                } else {
                    $imageBytes = $snapshot['bytes'];
                }
            } else {
                $row['decode_status'] = 'empty_source';
            }
        }
        $header = $before['size'] > 0
            ? ($decodeRequested && is_string($imageBytes) ? @getimagesizefromstring($imageBytes) : @getimagesize($physical))
            : false;
        $format = $this->contentFormat($physical, is_string($imageBytes) ? $imageBytes : null, $header);
        $row['content_format'] = $format['format'];
        $row['format_classification'] = $format['classification'];
        $row['format_evidence'] = $format['evidence'];
        $row['header_status'] = $format['classification'] === 'non_raster_graphic'
            ? 'unsupported_non_raster_format' : ($header ? 'dimensions_available' : 'unavailable_or_unsupported');
        $row['width'] = $header[0] ?? null;
        $row['height'] = $header[1] ?? null;
        $row['header_mime_type'] = $header['mime'] ?? null;
        $row['status'] = $before['size'] === 0 ? 'empty_source_hold'
            : ($format['classification'] === 'non_raster_graphic' ? 'unsupported_non_raster_format'
                : ($header ? 'header_readable' : 'header_review_required'));
        if ($decodeRequested && is_string($imageBytes) && $header) {
            $maxPixels = max(1, (int) config('file_registry.image_audit.max_decode_pixels', 8_000_000));
            $width = (int) $header[0];
            $height = (int) $header[1];
            if ($width <= 0 || $height <= 0 || $width > intdiv($maxPixels, $height)) {
                $row['decode_status'] = 'resource_cap';
                $row['decode_error_code'] = 'max_decode_pixels_exceeded';
                $row['status'] = 'decode_resource_cap_hold';
            } else {
                $inspection = app(AIService::class)->inspectImageBytes($imageBytes);
                $row['decode_status'] = $inspection['valid'] ? 'decoded' : ($inspection['error_code'] ?? 'invalid_image_data');
                $row['decode_error_code'] = $inspection['error_code'];
                $row['decode_performed'] = (bool) ($inspection['decode_performed'] ?? false);
                $row['image_validation_performed'] = true;

                if (! $inspection['valid']) {
                    $row['status'] = in_array($inspection['error_code'], ['unsupported_image_format', 'image_decoder_unavailable'], true)
                        ? 'decode_held'
                        : 'decode_review_required';
                } else {
                    $row['status'] = 'image_decoded';
                }
            }
        } elseif ($decodeRequested && is_string($imageBytes)) {
            $inspection = app(AIService::class)->inspectImageBytes($imageBytes);
            $row['decode_status'] = $inspection['valid'] ? 'decoded' : ($inspection['error_code'] ?? 'invalid_image_data');
            $row['decode_error_code'] = $inspection['error_code'];
            $row['decode_performed'] = (bool) ($inspection['decode_performed'] ?? false);
            $row['image_validation_performed'] = true;

            $row['status'] = in_array($inspection['error_code'], ['unsupported_image_format', 'image_decoder_unavailable'], true)
                ? 'decode_held'
                : 'decode_review_required';
        } elseif ($decodeRequested && $row['decode_status'] === 'not_checked') {
            $row['decode_status'] = 'header_unavailable';
            $row['status'] = 'decode_review_required';
        }
        if ($decodeRequested && $row['decode_status'] === 'resource_cap') {
            $row['status'] = 'decode_resource_cap_hold';
        }
        $after = $this->nextcloud->getFileInfo($path, true);
        $afterProof = $decodeRequested ? $this->nextcloud->computeFileHash($path, $deadline, true) : null;
        if ($this->clock() >= $deadline || ($afterProof['reason'] ?? null) === 'hash_deadline_exceeded') {
            $row['status'] = 'time_budget_exceeded';
            if ($decodeRequested) {
                $row['decode_status'] = 'time_budget_exceeded';
            }
        } elseif (($after['reason'] ?? null) === 'source_changed' || ($afterProof['reason'] ?? null) === 'source_changed') {
            $row['status'] = 'source_changed_during_audit';
            $row['hash_relation'] = 'source_race_review';
            if ($decodeRequested) {
                $row['decode_status'] = 'source_changed';
            }
        } elseif (! ($after['success'] ?? false) || ($decodeRequested && ! ($afterProof['success'] ?? false))) {
            $row['status'] = 'source_recheck_unavailable';
            $row['hash_relation'] = 'source_recheck_unavailable';
            if ($decodeRequested) {
                $row['decode_status'] = 'source_recheck_unavailable';
            }
        } elseif ($snapshotSourceChanged || ($after['source_fingerprint'] ?? null) !== $before
            || ($decodeRequested && (($afterProof['source_fingerprint'] ?? null) !== $before || ! hash_equals($hash, $afterProof['hash'])))
            || realpath($local) !== $physical || realpath((string) config('services.nextcloud.data_path')) !== $root) {
            $row['status'] = 'source_changed_during_audit';
            $row['hash_relation'] = 'source_race_review';
            if ($decodeRequested) {
                $row['decode_status'] = 'source_changed';
            }
        } else {
            $row['source_verified'] = true;
            if ($row['format_classification'] === 'non_raster_graphic') {
                $row['status'] = 'unsupported_non_raster_format';
            }
        }

        return $this->finishRow($row);
    }

    private function finishRow(array $row): array
    {
        $row['cursor_advance_allowed'] = $row['status'] !== 'time_budget_exceeded';
        $row['inspection_completed'] = $row['cursor_advance_allowed'];
        $row['retryable'] = in_array($row['status'], ['time_budget_exceeded', 'read_error', 'source_recheck_unavailable', 'source_changed_during_audit', 'missing_or_unavailable'], true);
        $row['source_kind'] = $row['selector_kind'];
        $row['source_id'] = (int) $row['selector_snapshot']['id'];
        $row['tree_id'] ??= null;
        $row['source_snapshot'] = $row['selector_snapshot'];
        $row['successful'] = $row['source_verified'];
        $row['observed_sha256'] = $row['successful'] ? $row['sha256'] : null;
        $row['classification'] = $row['hash_relation'] === 'mismatch_review' ? 'source_hash_mismatch' : $row['status'];
        $row['finding_required'] = ! $row['successful'] || $row['hash_relation'] === 'mismatch_review'
            || ! in_array($row['status'], ['header_readable', 'image_decoded'], true);
        $row['retry'] = ! $row['cursor_advance_allowed'];
        if (! $row['successful']) {
            $row['attempted_source_fingerprint'] = $row['source_fingerprint'];
            $row['source_fingerprint'] = null;
        }

        return $row;
    }

    /** Classify bytes by their content. A filename or stored MIME is never format proof. */
    private function contentFormat(string $path, ?string $snapshot, array|false $header): array
    {
        $prefixBytes = max(1, min(65536, (int) config('file_registry.image_audit.format_signature_prefix_bytes', 65536)));
        if (is_array($header) && is_string($header['mime'] ?? null) && str_starts_with(strtolower($header['mime']), 'image/')) {
            return ['format' => strtolower(substr($header['mime'], 6)), 'classification' => 'raster_image', 'evidence' => 'image_header'];
        }
        $prefix = is_string($snapshot) ? substr($snapshot, 0, $prefixBytes) : $this->readFormatPrefix($path, $prefixBytes);
        if (is_string($prefix)) {
            $vector = $this->vectorFormat($prefix);
            if ($vector !== null) {
                return ['format' => $vector, 'classification' => 'non_raster_graphic', 'evidence' => 'content_signature'];
            }
        }

        return ['format' => null, 'classification' => 'unknown', 'evidence' => null];
    }

    private function readFormatPrefix(string $path, int $maxBytes): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $bytes = fread($stream, $maxBytes);

            return is_string($bytes) ? $bytes : null;
        } finally {
            fclose($stream);
        }
    }

    private function vectorFormat(string $bytes): ?string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $bytes) ?? $bytes;
        $text = ltrim($text);
        for ($i = 0; $i < 16; $i++) {
            if (preg_match('/\A<\?xml\b[^?]*(?:\?(?:>|\z))\s*/i', $text, $match) === 1
                || preg_match('/\A<!--.*?-->\s*/s', $text, $match) === 1
                || preg_match('/\A<!DOCTYPE\b[^>]*>\s*/is', $text, $match) === 1) {
                $text = substr($text, strlen($match[0]));

                continue;
            }
            break;
        }
        if (preg_match('/\A<(?:[A-Za-z_][\w.-]*:)?svg(?:\s|>)/i', $text) === 1) {
            return 'svg';
        }
        if (str_starts_with($bytes, "\xD7\xCD\xC6\x9A")) {
            return 'wmf';
        }
        if (strlen($bytes) >= 18) {
            $header = substr($bytes, 0, 18);
            $type = unpack('v', substr($header, 0, 2))[1];
            $headerWords = unpack('v', substr($header, 2, 2))[1];
            $version = unpack('v', substr($header, 4, 2))[1];
            $fileWords = unpack('V', substr($header, 6, 4))[1];
            $maxRecordWords = unpack('V', substr($header, 12, 4))[1];
            if (in_array($type, [1, 2], true) && $headerWords === 9 && in_array($version, [0x0100, 0x0300], true)
                && $fileWords >= 9 && $maxRecordWords > 0) {
                return 'wmf';
            }
        }
        if (strlen($bytes) >= 44 && substr($bytes, 40, 4) === ' EMF') {
            return 'emf';
        }

        return null;
    }

    private function clock(): float
    {
        return hrtime(true) / 1e9;
    }

    /** @return array{sha256?:string,bytes?:string,resource_cap?:bool}|null */
    private function readImageSnapshot(string $path, float $deadline, int $maxBytes): ?array
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }
        try {
            $hash = hash_init('sha256');
            $bytes = '';
            while (! feof($stream)) {
                if ($this->clock() >= $deadline) {
                    return null;
                }
                $chunk = fread($stream, min(1024 * 1024, $maxBytes + 1 - strlen($bytes)));
                if ($chunk === false) {
                    return null;
                }
                if ($chunk === '' && ! feof($stream)) {
                    return null;
                }
                hash_update($hash, $chunk);
                $bytes .= $chunk;
                if (strlen($bytes) > $maxBytes) {
                    return null;
                }
            }

            return ['sha256' => hash_final($hash), 'bytes' => $bytes];
        } finally {
            fclose($stream);
        }
    }
}
