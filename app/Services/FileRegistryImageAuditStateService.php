<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Inspection custody only; selector cutoffs never prove identity or restoration completion. */
class FileRegistryImageAuditStateService
{
    private const CHECKPOINTS = 'file_registry_image_audit_checkpoints';

    private const OBSERVATIONS = 'file_registry_image_audit_observations';

    private const POLICY_KEYS = ['max_binding_bytes', 'max_checkpoint_bytes', 'max_observation_bytes',
        'lease_seconds', 'retry_seconds', 'epoch_interval_seconds'];

    public function __construct(private ?Closure $clock = null) {}

    public function ensure(array $binding, array $cutoffs, array $policy): array
    {
        $policy = $this->policy($policy);
        foreach (['selector_version', 'scope', 'connector', 'root', 'audit_mode'] as $key) {
            if (! is_string($binding[$key] ?? null) || $binding[$key] === '' || str_contains($binding[$key], "\0")) {
                throw new InvalidArgumentException('Exact audit selector and physical binding are required.');
            }
        }
        if ($binding['scope'] !== 'all_trees' || $binding['audit_mode'] !== 'inspection_only' || $binding['root'][0] !== '/') {
            throw new InvalidArgumentException('Audit custody requires the all-tree inspection-only physical root binding.');
        }
        $bindingJson = $this->json($this->canonical($binding), $policy['max_binding_bytes']);
        $bindingKey = hash('sha256', $bindingJson);
        $checkpoint = $this->newEpoch($cutoffs, $policy);

        return DB::transaction(function () use ($bindingKey, $bindingJson, $checkpoint, $policy): array {
            DB::table(self::CHECKPOINTS)->insertOrIgnore([
                'binding_key' => $bindingKey, 'binding' => $bindingJson,
                'checkpoint' => $this->json($checkpoint, $policy['max_checkpoint_bytes']), 'revision' => 0,
                'created_at' => $this->timestamp(), 'updated_at' => $this->timestamp(),
            ]);
            $record = DB::table(self::CHECKPOINTS)->where('binding_key', $bindingKey)->lockForUpdate()->first();
            if (! $record || $record->binding !== $bindingJson) {
                throw new RuntimeException('Audit binding collision or alteration.');
            }

            return ['state' => 'ready', 'lease' => null, 'status' => $this->status((int) $record->id)];
        });
    }

    public function claim(int $checkpointId, ?int $expectedRevision = null): array
    {
        return DB::transaction(function () use ($checkpointId, $expectedRevision): array {
            $record = $this->record($checkpointId);
            $checkpoint = $this->checkpoint($record);
            if ($expectedRevision !== null && (int) $record->revision !== $expectedRevision) {
                return ['state' => 'stale', 'lease' => null, 'status' => $this->status($checkpointId)];
            }
            if ($record->lease_token !== null && $record->lease_expires_at !== null
                && CarbonImmutable::parse($record->lease_expires_at, 'UTC')->greaterThan($this->now())) {
                return ['state' => 'busy', 'lease' => null, 'status' => $this->status($checkpointId)];
            }
            DB::table(self::CHECKPOINTS)->where('id', $checkpointId)->update([
                'lease_token' => (string) Str::uuid(),
                'lease_expires_at' => $this->now()->addSeconds($checkpoint['policy']['lease_seconds'])->format('Y-m-d H:i:s.u'),
                'revision' => (int) $record->revision + 1, 'updated_at' => $this->timestamp(),
            ]);

            return $this->response($checkpointId, 'claimed');
        });
    }

    /** Callbacks perform bounded rechecks and same-connection finding retention, never hashing/network work. */
    public function publishRow(array $lease, string $lane, array $row, callable $verifySourceCallback, callable $retainFindingCallback): array
    {
        $this->lane($lane);

        return $this->mutate($lease, function (object $record, array &$checkpoint) use ($lease, $lane, $row, $verifySourceCallback, $retainFindingCallback): array {
            $this->assertLaneDue($checkpoint, $lane);
            $this->row($row, $lane, $checkpoint);
            $previous = DB::table(self::OBSERVATIONS)->where('checkpoint_id', $record->id)
                ->where('source_kind', $lane)->where('source_id', $row['source_id'])->lockForUpdate()->first();
            $previousObservation = $previous === null ? null : $this->observation($previous, $checkpoint['policy']);
            $hasPreviousProof = is_string($previousObservation['observed_sha256'] ?? null)
                && preg_match('/^[a-f0-9]{64}$/D', $previousObservation['observed_sha256']);
            $assessment = ['baseline' => $hasPreviousProof ? 'previous_physical_inspection' : 'unknown',
                'observed_change' => $row['successful'] && $hasPreviousProof
                    && $previousObservation['observed_sha256'] !== $row['observed_sha256']];
            if ($verifySourceCallback($row) !== true) {
                throw new RuntimeException('Audit source snapshot or physical proof changed.');
            }
            $findingRequired = $row['finding_required'] || ! $row['successful'] || $row['retry'] || $assessment['observed_change'];
            if ($findingRequired && $retainFindingCallback($row, $previousObservation, $assessment) !== true) {
                throw new RuntimeException('Exact inspection finding was not retained.');
            }
            // The original epoch, revision, token, binding and deadline still govern callbacks.
            $this->assertLease($this->record((int) $record->id), $lease);
            if ($row['successful']) {
                $publishedAt = $this->timestamp();
                $this->json(array_intersect_key($row, array_flip(['source_kind', 'source_id', 'tree_id',
                    'source_snapshot', 'observed_sha256', 'source_fingerprint'])) + [
                        'epoch_uuid' => $checkpoint['epoch_uuid'], 'observed_at' => $publishedAt,
                    ], $checkpoint['policy']['max_observation_bytes']);
                DB::table(self::OBSERVATIONS)->updateOrInsert([
                    'checkpoint_id' => $record->id, 'source_kind' => $lane, 'source_id' => $row['source_id'],
                ], [
                    'tree_id' => $row['tree_id'], 'source_snapshot' => $this->json($row['source_snapshot'], $checkpoint['policy']['max_observation_bytes']),
                    'observed_sha256' => $row['observed_sha256'],
                    'source_fingerprint' => $this->json($this->observationEvidence($row), $checkpoint['policy']['max_observation_bytes']),
                    // Publication time; the source engine owns physical measurement timestamps.
                    'epoch_uuid' => $checkpoint['epoch_uuid'], 'observed_at' => $publishedAt,
                    'created_at' => $previous->created_at ?? $this->timestamp(), 'updated_at' => $this->timestamp(),
                ]);
            }
            $checkpoint['counters'][$lane]['attempts']++;
            $checkpoint['counters'][$lane]['inspections'] += $row['successful'] ? 1 : 0;
            $checkpoint['counters'][$lane]['findings'] += $findingRequired ? 1 : 0;
            $checkpoint['counters'][$lane]['retries'] += $row['retry'] ? 1 : 0;
            $checkpoint['retry_not_before'][$lane] = $row['retry']
                ? $this->now()->addSeconds($checkpoint['policy']['retry_seconds'])->format('Y-m-d H:i:s.u') : null;
            if (! $row['retry']) {
                $checkpoint[$lane]['after_id'] = $row['source_id'];
            }
            $checkpoint['next_lane'] = $lane === 'registry' ? 'media' : 'registry';

            return ['finding_retained' => $findingRequired, 'assessment' => $assessment,
                'cursor_advanced' => ! $row['retry']];
        });
    }

    /** EOF must be rechecked against the same selector, cursor and frozen maximum ID. */
    public function completeLane(array $lease, string $lane, callable $verifyEmptyCallback): array
    {
        $this->lane($lane);

        return $this->mutate($lease, function (object $record, array &$checkpoint) use ($lane, $verifyEmptyCallback): array {
            $this->assertLaneDue($checkpoint, $lane);
            if ($verifyEmptyCallback($lane, $checkpoint[$lane], json_decode($record->binding, true, flags: JSON_THROW_ON_ERROR)) !== true) {
                throw new RuntimeException('Audit selector has remaining or unavailable rows.');
            }
            $checkpoint[$lane]['complete'] = true;
            $checkpoint[$lane]['completed_at'] = $this->timestamp();
            $checkpoint['retry_not_before'][$lane] = null;
            $checkpoint['next_lane'] = $lane === 'registry' ? 'media' : 'registry';
            if ($checkpoint['registry']['complete'] && $checkpoint['media']['complete']) {
                $checkpoint['completed_at'] = $this->timestamp();
            }

            return [];
        });
    }

    public function beginEpoch(array $lease, array $cutoffs): array
    {
        return $this->mutate($lease, function (object $record, array &$checkpoint) use ($cutoffs): array {
            if (! $checkpoint['registry']['complete'] || ! $checkpoint['media']['complete']) {
                return ['state' => 'held', 'reason' => 'previous_selector_passes_incomplete'];
            }
            if (CarbonImmutable::parse($checkpoint['completed_at'], 'UTC')->addSeconds($checkpoint['policy']['epoch_interval_seconds'])->greaterThan($this->now())) {
                return ['state' => 'waiting', 'reason' => 'epoch_interval_pending'];
            }
            $checkpoint = $this->newEpoch($cutoffs, $checkpoint['policy']);

            return ['state' => 'epoch_started'];
        });
    }

    /** Coverage uses distinct binding keys; source-inspection checkpoints remain untouched. */
    public function advanceCoverageWindow(array $lease, array $cutoffs, bool $incoming): array
    {
        return $this->mutate($lease, function (object $record, array &$checkpoint) use ($cutoffs, $incoming): array {
            $binding = json_decode($record->binding, true, flags: JSON_THROW_ON_ERROR);
            if (($binding['selector_version'] ?? null) !== config('file_registry.face_coverage.selector_version')
                || ($binding['coverage_lane'] ?? null) !== ($incoming ? 'incoming' : 'historical')) {
                throw new RuntimeException('Coverage window cannot alter an inspection checkpoint.');
            }
            foreach (['registry', 'media'] as $lane) {
                if (! is_int($cutoffs[$lane] ?? null) || $cutoffs[$lane] < 0) {
                    throw new InvalidArgumentException('Invalid coverage cutoff.');
                }
            }
            if (! $incoming) {
                $checkpoint['coverage_first'] = ($checkpoint['coverage_first'] ?? 'historical') === 'historical' ? 'incoming' : 'historical';
                $first = $checkpoint['coverage_first'];
                $due = CarbonImmutable::parse($checkpoint['captured_at'], 'UTC')->addSeconds($checkpoint['policy']['epoch_interval_seconds']);
                if ($checkpoint['registry']['complete'] && $checkpoint['media']['complete'] && ! $due->greaterThan($this->now())) {
                    $checkpoint = $this->newEpoch($cutoffs, $checkpoint['policy']);
                    $checkpoint['coverage_first'] = $first;
                }
            } else {
                // First capture belongs to the historical pass. Incoming starts above that finite snapshot.
                foreach (['registry', 'media'] as $lane) {
                    if (empty($checkpoint['incoming_initialized'])) {
                        $checkpoint[$lane]['after_id'] = $checkpoint[$lane]['cutoff'];
                    }
                    $checkpoint[$lane]['cutoff'] = max($checkpoint[$lane]['cutoff'], $cutoffs[$lane]);
                    $checkpoint[$lane]['complete'] = $checkpoint[$lane]['after_id'] >= $checkpoint[$lane]['cutoff'];
                    $checkpoint[$lane]['completed_at'] = $checkpoint[$lane]['complete'] ? $this->timestamp() : null;
                }
                $checkpoint['incoming_initialized'] = true;
                $checkpoint['completed_at'] = $checkpoint['registry']['complete'] && $checkpoint['media']['complete'] ? $this->timestamp() : null;
            }

            return [];
        });
    }

    /** A generation may publish only while its exact coverage revision still owns custody. */
    public function assertCoverageLease(array $lease): array
    {
        $record = $this->record($lease['checkpoint_id'] ?? 0);
        $this->assertLease($record, $lease);
        $binding = json_decode($record->binding, true, flags: JSON_THROW_ON_ERROR);
        if (($binding['selector_version'] ?? null) !== config('file_registry.face_coverage.selector_version')
            || ! in_array($binding['coverage_lane'] ?? null, ['historical', 'incoming'], true)) {
            throw new RuntimeException('Invalid anonymous coverage lease domain.');
        }

        return $this->status((int) $record->id);
    }

    /** Select retained technical holds; never reuse the forward cursor publication contract. */
    public function coverageRevisitCandidates(array $lease, int $limit): array
    {
        $status = $this->assertCoverageLease($lease);
        if ($status['binding']['coverage_lane'] !== 'incoming' || ! $status['registry_epoch_complete'] || ! $status['media_epoch_complete']
            || $limit < 1 || $limit > config('file_registry.face_coverage.bounds.max_rows')) {
            throw new RuntimeException('face_coverage_revisit_selector_held');
        }
        $rows = DB::table(self::OBSERVATIONS)->where('checkpoint_id', $lease['checkpoint_id'])
            ->where('source_fingerprint->coverage->outcome', 'held')
            ->where('source_fingerprint->coverage->reason', 'generation_source_unavailable')
            ->where(function ($query): void {
                $query->whereNull('source_fingerprint->coverage_revisit->state')
                    ->orWhere('source_fingerprint->coverage_revisit->state', 'retryable_hold');
            })->where(function ($query): void {
                $query->whereNull('source_fingerprint->coverage_revisit->not_before')
                    ->orWhere('source_fingerprint->coverage_revisit->not_before', '<=', $this->timestamp());
            })->orderBy('updated_at')->orderBy('id')->limit($limit)->get();

        return $rows->map(function ($row) use ($status): array {
            $observation = $this->observation($row, $status['checkpoint']['policy']);
            if ($observation['source_id'] > $status['checkpoint'][$observation['source_kind']]['after_id']) {
                throw new RuntimeException('face_coverage_revisit_cursor_held');
            }

            return ['id' => (int) $row->id, 'preimage_sha256' => $this->observationDigest($row), 'observation' => $observation];
        })->all();
    }

    /** Store a bounded advisory supplement while byte-preserving all original inspection/coverage fields. */
    public function publishCoverageRevisit(array $lease, array $candidate, array $result, callable $verifyPins): array
    {
        $status = $this->assertCoverageLease($lease);
        if ($status['binding']['coverage_lane'] !== 'incoming' || ! $status['registry_epoch_complete'] || ! $status['media_epoch_complete']) {
            throw new RuntimeException('face_coverage_revisit_selector_held');
        }

        return $this->mutate($lease, function ($record, &$checkpoint) use ($candidate, $result, $verifyPins): array {
            $row = DB::table(self::OBSERVATIONS)->where('id', $candidate['id'] ?? 0)->where('checkpoint_id', $record->id)->lockForUpdate()->first();
            if (! $row || ! hash_equals($candidate['preimage_sha256'] ?? '', $this->observationDigest($row))) {
                throw new RuntimeException('face_coverage_revisit_observation_changed');
            }
            $original = $this->observation($row, $checkpoint['policy']);
            if ($original['source_id'] > $checkpoint[$original['source_kind']]['after_id']) {
                throw new RuntimeException('face_coverage_revisit_cursor_held');
            }
            $fingerprint = $original['source_fingerprint'];
            if (($fingerprint['coverage']['outcome'] ?? null) !== 'held'
                || ($fingerprint['coverage']['reason'] ?? null) !== 'generation_source_unavailable'
                || ! in_array($fingerprint['coverage_revisit']['state'] ?? null, [null, 'retryable_hold'], true)
                || (isset($fingerprint['coverage_revisit']['not_before'])
                    && CarbonImmutable::parse($fingerprint['coverage_revisit']['not_before'], 'UTC')->greaterThan($this->now()))
                || ! in_array($result['state'] ?? null, ['ready_for_read_plan', 'retryable_hold', 'read_plan_hold'], true)
                || ! is_string($result['reason'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $result['reason'])
                || ! is_array($result['pins'] ?? null) || $verifyPins() !== true) {
                throw new RuntimeException('face_coverage_revisit_proof_held');
            }
            unset($original['source_fingerprint']['coverage_revisit']);
            $fingerprint['coverage_revisit'] = ['schema' => 'plos.face_coverage_readiness.v1',
                'original_observation_sha256' => hash('sha256', $this->json($this->canonical($original), $checkpoint['policy']['max_observation_bytes'])),
                'checked_at' => $this->timestamp(),
                'not_before' => $this->now()->addSeconds($checkpoint['policy']['retry_seconds'])->format('Y-m-d H:i:s.u'),
                'state' => $result['state'], 'reason' => $result['reason'], 'pins' => $result['pins'],
                'write_ready' => false, 'automatic_replay_allowed' => false];
            $fingerprint['coverage_revisit']['evidence_sha256'] = hash('sha256', $this->json(
                $this->canonical($fingerprint['coverage_revisit']), $checkpoint['policy']['max_observation_bytes']));
            $encoded = $this->json($fingerprint, $checkpoint['policy']['max_observation_bytes']);
            $this->json(array_replace($original, ['source_fingerprint' => $fingerprint]), $checkpoint['policy']['max_observation_bytes']);
            DB::table(self::OBSERVATIONS)->where('id', $row->id)->update(['source_fingerprint' => $encoded, 'updated_at' => $this->timestamp()]);

            return ['readiness_state' => $result['state']];
        });
    }

    private function observationDigest(object $row): string
    {
        return hash('sha256', json_encode($this->canonical((array) $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function release(array $lease): array
    {
        return $this->mutate($lease, fn () => [], true);
    }

    public function status(int $checkpointId): array
    {
        $record = $this->record($checkpointId);
        $checkpoint = $this->checkpoint($record);
        $nextLane = $this->nextLane($checkpoint);
        $complete = $checkpoint['registry']['complete'] && $checkpoint['media']['complete'];
        $retryDates = array_filter($checkpoint['retry_not_before'], fn ($date, $lane) => ! $checkpoint[$lane]['complete'] && $date !== null, ARRAY_FILTER_USE_BOTH);

        return ['checkpoint_id' => $checkpointId, 'binding_key' => $record->binding_key,
            'binding' => json_decode($record->binding, true, flags: JSON_THROW_ON_ERROR),
            'revision' => (int) $record->revision, 'epoch_uuid' => $checkpoint['epoch_uuid'],
            'state' => $complete ? 'complete' : ($nextLane === null ? 'waiting' : 'pending'),
            'next_lane' => $nextLane, 'next_attempt_at' => $nextLane === null && $retryDates !== [] ? min($retryDates) : null,
            'registry_epoch_complete' => $checkpoint['registry']['complete'], 'media_epoch_complete' => $checkpoint['media']['complete'],
            'captured_selector_limits' => ['registry' => $checkpoint['registry']['cutoff'], 'media' => $checkpoint['media']['cutoff']],
            'completion_scope' => 'captured_maximum_selector_ids', 'checkpoint' => $checkpoint];
    }

    private function mutate(array $lease, callable $callback, bool $release = false): array
    {
        return DB::transaction(function () use ($lease, $callback, $release): array {
            $record = $this->record($lease['checkpoint_id'] ?? 0);
            $this->assertLease($record, $lease);
            $checkpoint = $this->checkpoint($record);
            $extra = $callback($record, $checkpoint);
            $fresh = $this->record((int) $record->id);
            $this->assertLease($fresh, $lease);
            $changes = ['checkpoint' => $this->json($checkpoint, $checkpoint['policy']['max_checkpoint_bytes']),
                'revision' => (int) $fresh->revision + 1, 'updated_at' => $this->timestamp()];
            if ($release) {
                $changes += ['lease_token' => null, 'lease_expires_at' => null];
            }
            DB::table(self::CHECKPOINTS)->where('id', $record->id)->update($changes);
            $response = array_replace($this->response((int) $record->id, 'progressed'), $extra);
            $this->assertLease($fresh, $lease);

            return $response;
        });
    }

    private function record(int $id): object
    {
        $record = DB::table(self::CHECKPOINTS)->where('id', $id)->lockForUpdate()->first();
        $bound = config('file_registry.image_audit.state_bounds.max_binding_bytes');
        if (! $record || ! is_int($bound) || strlen($record->binding) > $bound
            || ! hash_equals($record->binding_key, hash('sha256', $record->binding))) {
            throw new RuntimeException('Unknown or altered audit binding.');
        }

        return $record;
    }

    private function assertLease(object $record, array $lease): void
    {
        $checkpoint = $this->checkpoint($record);
        if (($lease['checkpoint_id'] ?? null) !== (int) $record->id || ($lease['binding_key'] ?? null) !== $record->binding_key
            || ! is_string($lease['token'] ?? null) || ! Str::isUuid($lease['token']) || $lease['token'] !== $record->lease_token
            || ($lease['epoch_uuid'] ?? null) !== $checkpoint['epoch_uuid'] || ($lease['revision'] ?? null) !== (int) $record->revision
            || $record->lease_expires_at === null || ! CarbonImmutable::parse($record->lease_expires_at, 'UTC')->greaterThan($this->now())) {
            throw new RuntimeException('Image audit lease is stale or expired.');
        }
    }

    private function response(int $id, string $state): array
    {
        $record = DB::table(self::CHECKPOINTS)->where('id', $id)->first();
        $status = $this->status($id);

        return ['state' => $state, 'status' => $status, 'lease' => $record->lease_token === null ? null : [
            'checkpoint_id' => $id, 'binding_key' => $record->binding_key, 'epoch_uuid' => $status['epoch_uuid'],
            'token' => $record->lease_token, 'revision' => (int) $record->revision, 'expires_at' => $record->lease_expires_at,
        ]];
    }

    private function row(array $row, string $lane, array $checkpoint): void
    {
        if (($row['source_kind'] ?? null) !== $lane || ! is_int($row['source_id'] ?? null)
            || $row['source_id'] <= $checkpoint[$lane]['after_id'] || $row['source_id'] > $checkpoint[$lane]['cutoff']
            || ! array_key_exists('tree_id', $row) || ($lane === 'registry' && $row['tree_id'] !== null)
            || ($lane === 'media' && (! is_int($row['tree_id']) || $row['tree_id'] < 1))
            || ! is_array($row['source_snapshot'] ?? null) || ! is_array($row['source_fingerprint'] ?? null)
            || (string) ($row['source_snapshot']['id'] ?? '') !== (string) $row['source_id']
            || ($lane === 'media' && (string) ($row['source_snapshot']['tree_id'] ?? '') !== (string) $row['tree_id'])
            || ! is_string($row['classification'] ?? null) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $row['classification'])
            || (array_key_exists('content_format', $row) && $row['content_format'] !== null
                && (! is_string($row['content_format']) || ! preg_match('/\A[a-z0-9][a-z0-9.+_-]{0,127}\z/D', $row['content_format'])))
            || (array_key_exists('format_classification', $row)
                && ! in_array($row['format_classification'], ['unknown', 'raster_image', 'non_raster_graphic'], true))
            || (array_key_exists('format_evidence', $row)
                && ! in_array($row['format_evidence'], [null, 'image_header', 'content_signature'], true))
            || ! is_bool($row['successful'] ?? null) || ! is_bool($row['finding_required'] ?? null) || ! is_bool($row['retry'] ?? null)
            || ! array_key_exists('observed_sha256', $row)
            || ($row['successful'] && ($row['source_fingerprint'] === [] || ! is_string($row['observed_sha256']) || ! preg_match('/^[a-f0-9]{64}$/D', $row['observed_sha256'])))
            || (! $row['successful'] && $row['observed_sha256'] !== null)) {
            throw new InvalidArgumentException('Exact bounded audit row and newly measured proof are required.');
        }
        $this->json($row, $checkpoint['policy']['max_observation_bytes']);
    }

    /** Keep bounded format and coverage receipts beside the physical fingerprint in the existing observation blob. */
    private function observationEvidence(array $row): array
    {
        $evidence = $row['source_fingerprint'];
        if (array_key_exists('format_classification', $row)) {
            $evidence['format'] = array_intersect_key($row, array_flip([
                'content_format', 'format_classification', 'format_evidence',
            ]));
        }
        if (isset($row['coverage'])) {
            $evidence['coverage'] = $row['coverage'];
        }

        return $evidence;
    }

    private function observation(object $row, array $policy): array
    {
        if (strlen($row->source_snapshot) > $policy['max_observation_bytes']
            || strlen($row->source_fingerprint) > $policy['max_observation_bytes']) {
            throw new RuntimeException('Stored audit observation exceeds its configured bound.');
        }
        $observation = ['source_kind' => $row->source_kind, 'source_id' => (int) $row->source_id,
            'tree_id' => $row->tree_id === null ? null : (int) $row->tree_id,
            'source_snapshot' => json_decode($row->source_snapshot, true, flags: JSON_THROW_ON_ERROR),
            'observed_sha256' => $row->observed_sha256,
            'source_fingerprint' => json_decode($row->source_fingerprint, true, flags: JSON_THROW_ON_ERROR),
            'epoch_uuid' => $row->epoch_uuid, 'observed_at' => $row->observed_at];
        $this->json($observation, $policy['max_observation_bytes']);

        return $observation;
    }

    private function newEpoch(array $cutoffs, array $policy): array
    {
        foreach (['registry', 'media'] as $lane) {
            if (! is_int($cutoffs[$lane] ?? null) || $cutoffs[$lane] < 0) {
                throw new InvalidArgumentException('Captured maximum selector IDs must be nonnegative integers.');
            }
        }
        $timestamp = $this->timestamp();
        $checkpoint = ['schema' => 'file_registry_image_audit_checkpoint.v1', 'epoch_uuid' => (string) Str::uuid(),
            'policy' => $policy, 'captured_at' => $timestamp, 'completed_at' => $cutoffs['registry'] === 0 && $cutoffs['media'] === 0 ? $timestamp : null,
            'next_lane' => 'registry', 'retry_not_before' => ['registry' => null, 'media' => null], 'counters' => []];
        foreach (['registry', 'media'] as $lane) {
            $checkpoint[$lane] = ['cutoff' => $cutoffs[$lane], 'after_id' => 0, 'complete' => $cutoffs[$lane] === 0,
                'completed_at' => $cutoffs[$lane] === 0 ? $timestamp : null];
            $checkpoint['counters'][$lane] = ['attempts' => 0, 'inspections' => 0, 'findings' => 0, 'retries' => 0];
        }

        return $checkpoint;
    }

    private function checkpoint(object $record): array
    {
        $bound = config('file_registry.image_audit.state_bounds.max_checkpoint_bytes');
        if (! is_int($bound) || strlen($record->checkpoint) > $bound) {
            throw new RuntimeException('Audit checkpoint exceeds its configured bound.');
        }
        $checkpoint = json_decode($record->checkpoint, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($checkpoint) || ($checkpoint['schema'] ?? null) !== 'file_registry_image_audit_checkpoint.v1'
            || ! Str::isUuid($checkpoint['epoch_uuid'] ?? '') || ! is_array($checkpoint['policy'] ?? null)
            || ! in_array($checkpoint['next_lane'] ?? null, ['registry', 'media'], true)) {
            throw new RuntimeException('Invalid audit checkpoint.');
        }
        foreach (['registry', 'media'] as $lane) {
            if (! is_int($checkpoint[$lane]['cutoff'] ?? null) || $checkpoint[$lane]['cutoff'] < 0
                || ! is_int($checkpoint[$lane]['after_id'] ?? null) || $checkpoint[$lane]['after_id'] < 0
                || $checkpoint[$lane]['after_id'] > $checkpoint[$lane]['cutoff'] || ! is_bool($checkpoint[$lane]['complete'] ?? null)
                || ! array_key_exists($lane, $checkpoint['retry_not_before'] ?? [])) {
                throw new RuntimeException('Invalid audit selector checkpoint.');
            }
            foreach (['attempts', 'inspections', 'findings', 'retries'] as $counter) {
                if (! is_int($checkpoint['counters'][$lane][$counter] ?? null) || $checkpoint['counters'][$lane][$counter] < 0) {
                    throw new RuntimeException('Invalid audit counters.');
                }
            }
        }
        $checkpoint['policy'] = $this->policy($checkpoint['policy']);
        $this->json($checkpoint, $checkpoint['policy']['max_checkpoint_bytes']);

        return $checkpoint;
    }

    private function policy(array $policy): array
    {
        $result = [];
        foreach (self::POLICY_KEYS as $key) {
            $bound = config('file_registry.image_audit.state_bounds.'.$key);
            if (! is_int($policy[$key] ?? null) || $policy[$key] < 1 || ! is_int($bound) || $bound < 1) {
                throw new RuntimeException('Missing or invalid image audit custody policy: '.$key);
            }
            $result[$key] = min($policy[$key], $bound);
        }

        return $result;
    }

    private function assertLaneDue(array $checkpoint, string $lane): void
    {
        if ($this->nextLane($checkpoint) !== $lane) {
            throw new RuntimeException('Audit lane is complete, deferred or out of fair order.');
        }
    }

    private function nextLane(array $checkpoint): ?string
    {
        $preferred = $checkpoint['next_lane'];
        foreach ([$preferred, $preferred === 'registry' ? 'media' : 'registry'] as $lane) {
            $retry = $checkpoint['retry_not_before'][$lane];
            if (! $checkpoint[$lane]['complete'] && ($retry === null || ! CarbonImmutable::parse($retry, 'UTC')->greaterThan($this->now()))) {
                return $lane;
            }
        }

        return null;
    }

    private function lane(string $lane): void
    {
        if (! in_array($lane, ['registry', 'media'], true)) {
            throw new InvalidArgumentException('Unknown audit selector lane.');
        }
    }

    private function json(array $value, int $limit): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > $limit) {
            throw new InvalidArgumentException('Image audit evidence exceeds its bounded JSON policy.');
        }

        return $json;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }

    private function now(): CarbonImmutable
    {
        return ($this->clock ? ($this->clock)() : CarbonImmutable::now('UTC'))->setTimezone('UTC');
    }

    private function timestamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
