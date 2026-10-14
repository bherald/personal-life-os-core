<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Scheduled inspection adapter. Never writes originals, identities, catalog hashes or FT links. */
class FileRegistryImageAuditService
{
    /** Closed reporting vocabulary; exception messages and private code-shaped strings are not reasons. */
    public const AGGREGATE_REASONS = [
        'image_audit_policy_unavailable', 'image_audit_policy_invalid', 'image_audit_source_batch_held',
        'image_audit_review_retention_unavailable', 'image_audit_finding_too_large',
        'image_audit_local_root_unavailable', 'image_audit_custody_policy_changed',
        'image_audit_slice_held', 'image_audit_database_query_failed',
        'epoch_interval_pending', 'epoch_due', 'conflicting_audit_options', 'maintenance_mode',
        'invalid_audit_bounds', 'audit_scheduler_admission_invalid', 'audit_timeout_fence_invalid',
        'independent_timeout_unavailable', 'audit_admission_or_reporting_unavailable',
        'worker_hard_timeout', 'worker_nonzero_exit',
    ];

    public function __construct(
        protected FileRegistryImageAuditStateService $state,
        protected FileRegistryImageSourceAuditService $sources,
        protected NextcloudFileApiService $nextcloud,
        protected SystemConfigService $systemConfig,
    ) {}

    public function policy(): array
    {
        $policy = $this->systemConfig->get('files.registry_image_audit_policy');
        if (is_string($policy)) {
            $policy = json_decode($policy, true, flags: JSON_THROW_ON_ERROR);
        }
        if (! is_array($policy)) {
            throw new RuntimeException('image_audit_policy_unavailable');
        }
        $bounds = array_merge(config('file_registry.image_audit.state_bounds', []), config('file_registry.image_audit.maintenance_bounds', []));
        foreach ($bounds as $key => $bound) {
            if (! is_int($bound) || ! is_int($policy[$key] ?? null) || $policy[$key] < 1 || $policy[$key] > $bound) {
                throw new RuntimeException('image_audit_policy_invalid');
            }
        }
        if (! is_string($policy['timeout_binary'] ?? null) || $policy['timeout_binary'] === ''
            || $policy['max_seconds'] >= $policy['hard_timeout_seconds']
            || $policy['hard_timeout_seconds'] >= $policy['outer_timeout_seconds']
            || $policy['outer_timeout_seconds'] >= $policy['lease_seconds']
            || $policy['outer_timeout_seconds'] >= $policy['timeout_minutes'] * 60) {
            throw new RuntimeException('image_audit_policy_invalid');
        }

        return $policy;
    }

    /** Read-only and aggregate-only, including when no checkpoint exists. */
    public function status(): array
    {
        try {
            $policy = $this->policy();
            $binding = $this->binding();
            $record = DB::table('file_registry_image_audit_checkpoints')
                ->where('binding_key', hash('sha256', $this->encode($binding)))->first(['id']);

            if ($record === null) {
                return $this->emptyStatus('ready');
            }
            $status = $this->state->status((int) $record->id);
            $this->samePolicy($status, $policy);

            return $this->safeStatus($status);
        } catch (Throwable $e) {
            return $this->emptyStatus('held', $this->reason($e)) + $this->databaseError($e);
        }
    }

    public function runSlice(): array
    {
        $lease = null;
        $processed = 0;
        try {
            $policy = $this->policy();
            $binding = $this->binding();
            $ensured = $this->state->ensure($binding, $this->sources->captureCutoffs(), $policy);
            $this->samePolicy($ensured['status'], $policy);
            $claim = $this->state->claim($ensured['status']['checkpoint_id'], $ensured['status']['revision']);
            $lease = $claim['lease'];
            if ($lease === null) {
                return [...$this->safeStatus($claim['status']), 'state' => 'busy', 'processed' => 0];
            }
            $current = $claim['status'];
            if ($current['registry_epoch_complete'] && $current['media_epoch_complete']) {
                $epoch = $this->state->beginEpoch($lease, $this->sources->captureCutoffs());
                $lease = $epoch['lease'];
                $current = $epoch['status'];
            }
            $deadline = hrtime(true) / 1e9 + $policy['max_seconds'];
            while ($processed < $policy['max_rows'] && hrtime(true) / 1e9 < $deadline) {
                $current = $this->state->status($lease['checkpoint_id']);
                $lane = $current['next_lane'];
                if ($lane === null) {
                    break;
                }
                $remaining = (int) floor($deadline - hrtime(true) / 1e9);
                if ($remaining < 1) {
                    break;
                }
                $cursor = $current['checkpoint'][$lane];
                $batch = $lane === 'registry'
                    ? $this->sources->auditRegistryBatch($cursor['after_id'], 1, $remaining, $cursor['cutoff'])
                    : $this->sources->auditMediaBatch($cursor['after_id'], 1, $remaining, $cursor['cutoff']);
                if (($batch['status'] ?? null) === 'blocked' || ! is_array($batch['rows'] ?? null)) {
                    throw new RuntimeException('image_audit_source_batch_held');
                }
                if ($batch['rows'] === []) {
                    if (empty($batch['complete'])) {
                        break;
                    }
                    $result = $this->state->completeLane($lease, $lane, fn ($kind, $position) => $this->binding() === $binding
                        && ! $this->sources->hasRemaining($kind, $position['after_id'], $position['cutoff']));
                    $lease = $result['lease'];

                    continue;
                }
                $raw = $batch['rows'][0];
                $row = array_intersect_key($raw, array_flip(['source_kind', 'source_id', 'tree_id', 'source_snapshot',
                    'observed_sha256', 'source_fingerprint', 'classification', 'content_format', 'format_classification',
                    'format_evidence', 'successful', 'finding_required', 'retry']));
                $row['source_fingerprint'] ??= [];
                $result = $this->state->publishRow($lease, $lane, $row,
                    fn () => $this->verifySource($raw, $binding),
                    fn ($inspection, $previous, $assessment) => $this->retainFinding($raw, $previous, $assessment, $binding, $policy));
                $lease = $result['lease'];
                $processed++;
            }
            $released = $this->state->release($lease);
            $lease = null;

            return [...$this->safeStatus($released['status']), 'processed' => $processed];
        } catch (Throwable $e) {
            if ($lease !== null) {
                try {
                    $this->state->release($lease);
                } catch (Throwable) {
                    // Expiry recovery owns unresolved custody; never force-release another owner.
                }
            }
            $databaseError = $this->databaseError($e);
            Log::warning('Image audit slice held', ['exception_class' => $e::class, 'reason' => $this->reason($e)] + $databaseError);

            $status = $this->emptyStatus('held', $this->reason($e));
            if (isset($ensured['status']['checkpoint_id'])) {
                try {
                    $status = $this->safeStatus($this->state->status($ensured['status']['checkpoint_id']));
                } catch (Throwable) {
                    // Never report invented committed progress when custody cannot be read.
                }
            }

            return [...$status, 'state' => 'held', 'reason' => $this->reason($e), 'processed' => $processed, ...$databaseError];
        }
    }

    protected function bindingMatches(array $binding): bool
    {
        return $this->binding() === $binding;
    }

    protected function verifySource(array $row, array $binding): bool
    {
        if (! $this->bindingMatches($binding)) {
            return false;
        }
        $table = $row['source_kind'] === 'registry' ? 'file_registry' : 'genealogy_media';
        $query = DB::table($table)->where('id', $row['source_id']);
        if ($row['source_kind'] === 'media') {
            $query->where('tree_id', $row['tree_id']);
        }
        $actual = $query->lockForUpdate()->first(array_keys($row['source_snapshot']));
        if ($actual === null || $this->encode((array) $actual) !== $this->encode($row['source_snapshot'])) {
            return false;
        }
        if ($row['source_kind'] === 'media' && $row['nextcloud_path'] !== null) {
            $expected = $row['registry_snapshot'];
            $columns = $expected === null ? ['id', 'asset_uuid', 'current_path', 'path_hash'] : array_keys($expected);
            $companion = $this->sources->mediaRegistrySnapshot($row['nextcloud_path'], $columns, true);
            if (($expected === null && $companion !== null) || ($expected !== null
                && ($companion === null || $this->encode($companion) !== $this->encode($expected)))) {
                return false;
            }
        }
        if ($row['successful']) {
            $info = $this->nextcloud->getFileInfo($row['nextcloud_path'], true);

            return ($info['success'] ?? false) === true && ($info['source'] ?? null) === 'filesystem'
                && ($info['source_fingerprint'] ?? null) === $row['source_fingerprint'];
        }

        return true; // A retained hold publishes no current hash or identity proof.
    }

    protected function retainFinding(array $row, ?array $previous, array $assessment, array $binding, array $policy): bool
    {
        $type = config('file_registry.image_audit.review.type');
        $registration = DB::table('review_type_registry')->where('name', $type)->where('enabled', 1)->first();
        if ($registration === null || $registration->source_table !== 'agent_review_queue'
            || $registration->source_connection !== 'mysql' || json_decode($registration->actions, true) !== []
            || empty(json_decode($registration->ui_schema, true)['inspection_only'])) {
            throw new RuntimeException('image_audit_review_retention_unavailable');
        }
        $evidence = ['row' => $row, 'previous_inspection' => $previous, 'assessment' => $assessment,
            'binding_key' => hash('sha256', $this->encode($binding))];
        $findingKey = hash('sha256', $this->encode($this->findingIdentity($evidence)));
        $details = ['schema' => 'plos.image_source_inspection_finding.v1', 'inspection_only' => true,
            'finding_key' => $findingKey, 'evidence' => $evidence,
            'identity_decisions_performed' => false, 'historical_hashes_backfilled' => false];
        $encoded = $this->encode($details);
        if (strlen($encoded) > $policy['max_finding_bytes']) {
            throw new RuntimeException('image_audit_finding_too_large');
        }
        $agent = config('file_registry.image_audit.review.agent_id');
        $existing = DB::table('agent_review_queue')->where('agent_id', $agent)->where('review_type', $type)
            ->where('token', $findingKey)->orderByDesc('id')->lockForUpdate()->first();
        if ($existing !== null) {
            $retained = json_decode($existing->details, true, flags: JSON_THROW_ON_ERROR);

            return ($retained['finding_key'] ?? null) === $findingKey
                && $this->encode($this->findingIdentity($retained['evidence'] ?? [])) === $this->encode($this->findingIdentity($evidence));
        }
        DB::table('agent_review_queue')->insert([
            'agent_id' => $agent, 'review_type' => $type,
            'finding_type' => $assessment['observed_change'] ? 'changed_since_physical_inspection' : $row['classification'],
            'title' => 'Photo inspection '.$row['source_kind'].' '.$row['source_id'].' '.substr($findingKey, 0, 32),
            'summary' => 'Inspect the retained source measurement. Names, face decisions and family-tree links are unchanged.',
            'details' => $encoded, 'priority' => config('file_registry.image_audit.review.priority'),
            'status' => 'pending', 'token' => $findingKey, 'expires_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return true;
    }

    /** Read-only verification of the existing retained inspection token. */
    public function findingDigest(array $evidence): string
    {
        return hash('sha256', $this->encode($this->findingIdentity($evidence)));
    }

    protected function findingIdentity(array $evidence): array
    {
        return $evidence;
    }

    protected function binding(): array
    {
        $configured = config('services.nextcloud.data_path');
        $root = is_string($configured) && $configured !== '' ? realpath($configured) : false;
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('image_audit_local_root_unavailable');
        }
        clearstatcache(true, $root);
        $stat = @stat($root);
        if ($stat === false) {
            throw new RuntimeException('image_audit_local_root_unavailable');
        }
        $extensions = array_values(array_unique(array_map('strtolower', array_merge(
            (array) config('file_types.image', []), (array) config('file_types.image_raw', [])))));
        sort($extensions);

        return $this->canonical(['selector_version' => config('file_registry.image_audit.selector_version'),
            'scope' => 'all_trees', 'audit_mode' => 'inspection_only', 'root' => $root,
            'connector' => hash('sha256', $this->encode([$configured, $root, $stat['dev'], $stat['ino']])),
            'extensions' => $extensions]);
    }

    protected function safeStatus(array $status): array
    {
        $checkpoint = $status['checkpoint'];
        $state = $status['state'];
        $next = $status['next_attempt_at'];
        $reason = null;
        if ($state === 'complete') {
            $due = CarbonImmutable::parse($checkpoint['completed_at'], 'UTC')->addSeconds($checkpoint['policy']['epoch_interval_seconds']);
            $state = $due->greaterThan(CarbonImmutable::now('UTC')) ? 'waiting' : 'ready';
            $next = $due->format('Y-m-d H:i:s.u');
            $reason = $state === 'waiting' ? 'epoch_interval_pending' : 'epoch_due';
        }

        return ['state' => $state, 'reason' => $reason,
            'registry_epoch_complete' => $status['registry_epoch_complete'], 'media_epoch_complete' => $status['media_epoch_complete'],
            'counters' => $checkpoint['counters'], 'next_attempt_at' => $next,
            'findings' => $checkpoint['counters']['registry']['findings'] + $checkpoint['counters']['media']['findings'],
            'completion_scope' => 'captured_maximum_selector_ids'];
    }

    protected function samePolicy(array $status, array $policy): void
    {
        foreach ($status['checkpoint']['policy'] as $key => $value) {
            if (($policy[$key] ?? null) !== $value) {
                throw new RuntimeException('image_audit_custody_policy_changed');
            }
        }
    }

    protected function emptyStatus(string $state, ?string $reason = null): array
    {
        $zero = ['attempts' => 0, 'inspections' => 0, 'findings' => 0, 'retries' => 0];

        return ['state' => $state, 'reason' => $reason, 'registry_epoch_complete' => false, 'media_epoch_complete' => false,
            'counters' => ['registry' => $zero, 'media' => $zero], 'next_attempt_at' => null, 'findings' => 0,
            'completion_scope' => 'captured_maximum_selector_ids'];
    }

    protected function reason(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return 'image_audit_database_query_failed';
        }

        return in_array($e->getMessage(), self::AGGREGATE_REASONS, true) ? $e->getMessage() : 'image_audit_slice_held';
    }

    /** Never retain the errorInfo message, query, bindings, connection name or source identifiers. */
    private function databaseError(Throwable $error): array
    {
        if (! $error instanceof QueryException) {
            return [];
        }
        $info = $error->errorInfo ?? null;
        $safe = self::sanitizeDatabaseError(is_array($info)
            ? ['sqlstate' => $info[0] ?? null, 'driver_code' => $info[1] ?? null] : null);

        return $safe === [] ? [] : ['database_error' => $safe];
    }

    /** Revalidate the same primitive-only contract at the child/parent process boundary. */
    public static function sanitizeDatabaseError(mixed $error): array
    {
        if (! is_array($error)) {
            return [];
        }
        $safe = [];
        if (is_string($error['sqlstate'] ?? null) && preg_match('/^[A-Z0-9]{5}$/D', $error['sqlstate']) === 1) {
            $safe['sqlstate'] = $error['sqlstate'];
        }
        $code = $error['driver_code'] ?? null;
        if ((is_int($code) || is_string($code) && preg_match('/^[0-9]{1,5}$/D', $code) === 1)
            && (int) $code >= 0 && (int) $code <= 65535) {
            $safe['driver_code'] = (int) $code;
        }

        return $safe;
    }

    protected function encode(array $value): string
    {
        return json_encode($this->canonical($value), JSON_THROW_ON_ERROR);
    }

    protected function canonical(array $value): array
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
}
