<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Durable discovery custody only. Completion never authorizes deletion. */
class FileRegistryDiscoveryStateService
{
    private const SCOPES = 'file_registry_discovery_scopes';

    private const WORK = 'file_registry_discovery_work';

    public function __construct(private SystemConfigService $systemConfig, private ?Closure $clock = null) {}

    public function ensureScope(string $path, string $connectorFingerprint, string $qualificationFingerprint, array $options = ['compute_hash' => true]): array
    {
        $this->path($path);
        if ($connectorFingerprint === '' || $qualificationFingerprint === ''
            || array_keys($options) !== ['compute_hash'] || ! is_bool($options['compute_hash'])) {
            throw new InvalidArgumentException('Exact connector, qualification and registration policy are required.');
        }
        $this->json([$connectorFingerprint, $qualificationFingerprint]);
        $key = hash('sha256', json_encode([$path, $connectorFingerprint, $options['compute_hash']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($path, $connectorFingerprint, $qualificationFingerprint, $options, $key) {
            $now = $this->timestamp();
            DB::table(self::SCOPES)->insertOrIgnore([
                'scope_key' => $key, 'scope_path' => $path, 'connector_fingerprint' => $connectorFingerprint,
                'qualification_fingerprint' => $qualificationFingerprint, 'compute_hash' => $options['compute_hash'],
                'epoch_uuid' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $scope = DB::table(self::SCOPES)->where('scope_key', $key)->lockForUpdate()->first();
            if (! $scope || $scope->scope_path !== $path || $scope->connector_fingerprint !== $connectorFingerprint
                || (bool) $scope->compute_hash !== $options['compute_hash'] || $scope->qualification_fingerprint !== $qualificationFingerprint) {
                return ['state' => 'held', 'reason' => 'scope_fingerprint_mismatch', 'lease' => null,
                    'status' => $scope ? $this->status((int) $scope->id) : null];
            }
            if (! DB::table(self::WORK)->where('scope_id', $scope->id)->exists()) {
                $this->insertWork($scope, 'directory', $path, 0, ['cookie' => '0', 'generation' => null, 'eof' => false]);
            }

            return ['state' => 'ready', 'lease' => null, 'status' => $this->status((int) $scope->id)];
        });
    }

    public function claim(int $scopeId, ?int $expectedRevision = null): array
    {
        return DB::transaction(function () use ($scopeId, $expectedRevision) {
            $scope = DB::table(self::SCOPES)->where('id', $scopeId)->lockForUpdate()->first();
            if (! $scope) {
                throw new InvalidArgumentException('Unknown discovery scope.');
            }
            if ($expectedRevision !== null && (int) $scope->revision !== $expectedRevision) {
                return ['state' => 'stale', 'reason' => 'revision_changed', 'lease' => null, 'status' => $this->status($scopeId)];
            }
            if ($scope->lease_token !== null && CarbonImmutable::parse($scope->lease_expires_at, 'UTC')->greaterThan($this->now())) {
                return ['state' => 'busy', 'reason' => 'lease_owned', 'lease' => null, 'status' => $this->status($scopeId)];
            }
            DB::table(self::SCOPES)->where('id', $scopeId)->update([
                'lease_token' => (string) Str::uuid(), 'lease_expires_at' => $this->now()->addSeconds($this->limit('lease_seconds'))->format('Y-m-d H:i:s.u'),
                'revision' => (int) $scope->revision + 1, 'updated_at' => $this->timestamp(),
            ]);

            return $this->response($scopeId, 'claimed');
        });
    }

    public function renew(array $lease): array
    {
        return $this->mutate($lease, function ($scope) {
            DB::table(self::SCOPES)->where('id', $scope->id)->update([
                'lease_expires_at' => $this->now()->addSeconds($this->limit('lease_seconds'))->format('Y-m-d H:i:s.u'),
            ]);

            return [];
        });
    }

    public function release(array $lease): array
    {
        return $this->mutate($lease, fn () => [], true);
    }

    /** Selection is read-only; successful publication/defer records fair service order. */
    public function dueWork(array $lease, string $kind, int $limit): array
    {
        $this->kind($kind);
        $limit = $this->bounded($limit, 'max_due_work');

        return DB::transaction(function () use ($lease, $kind, $limit) {
            $scope = $this->lockedScope($lease);
            $query = DB::table(self::WORK)->where('scope_id', $scope->id)->where('kind', $kind)
                ->whereIn('state', ['pending', 'deferred'])
                ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', $this->timestamp()));
            if ($kind === 'directory') {
                $query->where('epoch_uuid', $scope->epoch_uuid);
            }
            $rows = $query->orderByRaw('COALESCE(last_served_at, created_at)')->orderBy('last_served_revision')->orderBy('id')->limit($limit)->get();

            return $rows->map(fn ($row) => $this->workArray($row))->all();
        });
    }

    /** Entries: kind/path/depth/checkpoint/resume_cookie; final cookie may include skipped dot records. */
    public function publishDirectoryPage(array $lease, int $workId, array $page): array
    {
        foreach (['before_cookie', 'cookie'] as $key) {
            $this->cookie($page[$key] ?? null);
        }
        $generation = $this->generation($page['generation'] ?? null);
        if (! array_key_exists('before_generation', $page) || ! is_bool($page['complete'] ?? null)
            || ! is_int($page['records'] ?? null) || $page['records'] < 0
            || ! is_array($page['entries'] ?? null) || ! array_is_list($page['entries'])
            || count($page['entries']) > $this->limit('max_page_entries')) {
            throw new InvalidArgumentException('Malformed discovery page.');
        }
        if ($page['records'] < count($page['entries'])) {
            throw new InvalidArgumentException('Discovery records cannot undercount entries.');
        }
        $beforeGeneration = $page['before_generation'] === null ? null : $this->generation($page['before_generation']);
        foreach ($page['entries'] as $entry) {
            $this->kind($entry['kind'] ?? '');
            $this->path($entry['path'] ?? '');
            $this->cookie($entry['resume_cookie'] ?? null);
            if (! is_int($entry['depth'] ?? null) || $entry['depth'] < 0 || ! is_array($entry['checkpoint'] ?? null)) {
                throw new InvalidArgumentException('Malformed discovery entry.');
            }
            $this->json($entry['checkpoint']);
        }

        return $this->mutate($lease, function ($scope) use ($workId, $page, $generation, $beforeGeneration) {
            $work = $this->lockedWork($scope, $workId, 'directory');
            $this->assertDue($work);
            $checkpoint = json_decode($work->checkpoint, true, flags: JSON_THROW_ON_ERROR);
            if ($checkpoint['cookie'] !== $page['before_cookie'] || $checkpoint['generation'] !== $beforeGeneration
                || ($beforeGeneration !== null && $beforeGeneration !== $generation)) {
                $this->touchWork($scope, $work, ['state' => 'held', 'last_reason' => 'directory_generation_or_checkpoint_changed']);

                return ['state' => 'held', 'stop_reason' => 'restart_required', 'processed_entries' => 0];
            }
            $cookie = $checkpoint['cookie'];
            $processed = 0;
            $reason = null;
            foreach ($page['entries'] as $entry) {
                if ($entry['depth'] !== (int) $work->depth + 1 || dirname($entry['path']) !== $work->path) {
                    throw new InvalidArgumentException('Discovery entry is not an exact logical child.');
                }
                if ($entry['depth'] > $this->limit('max_depth', true)) {
                    $reason = 'depth_capacity';
                    break;
                }
                $existing = DB::table(self::WORK)->where('scope_id', $scope->id)->where('epoch_uuid', $scope->epoch_uuid)
                    ->where('kind', $entry['kind'])->where('path_hash', hash('sha256', $entry['path']))->first();
                if ($existing && $existing->path !== $entry['path']) {
                    throw new RuntimeException('Ambiguous discovery path hash.');
                }
                if (! $existing) {
                    if ($this->unresolvedCount((int) $scope->id) >= $this->limit('max_pending_work')) {
                        $reason = 'pending_capacity';
                        break;
                    }
                    $this->insertWork($scope, $entry['kind'], $entry['path'], $entry['depth'], $entry['checkpoint']);
                    if ($entry['kind'] === 'file') {
                        DB::table(self::SCOPES)->where('id', $scope->id)->increment('files_discovered');
                    }
                }
                $cookie = $entry['resume_cookie'];
                $processed++;
            }
            $allPersisted = $processed === count($page['entries']);
            if ($allPersisted) {
                $cookie = $page['cookie'];
            }
            $complete = $allPersisted && $page['complete'];
            $nextCheckpoint = ['cookie' => $cookie, 'generation' => $generation, 'eof' => $complete];
            foreach (['previous_checkpoint', 'qualification_fingerprint'] as $key) {
                if (isset($checkpoint[$key])) {
                    $nextCheckpoint[$key] = $checkpoint[$key];
                }
            }
            $this->touchWork($scope, $work, [
                'checkpoint' => $this->json($nextCheckpoint),
                'state' => $complete ? 'finished' : ($reason === 'depth_capacity' ? 'held' : 'pending'), 'last_reason' => $reason,
                'outcome' => $complete ? 'enumerated' : null,
            ]);
            DB::table(self::SCOPES)->where('id', $scope->id)->increment('directory_records', $page['records']);

            return ['state' => $reason === 'depth_capacity' ? 'held' : 'progressed', 'processed_entries' => $processed, 'stop_reason' => $reason];
        });
    }

    public function deferFile(array $lease, int $workId, string $reason, ?array $checkpoint = null): array
    {
        return $this->deferWork($lease, $workId, 'file', $reason, $checkpoint);
    }

    /** Initial physical observation schedules stability without counting a failed intake attempt. */
    public function observeFile(array $lease, int $workId, array $snapshot, string $notBefore): array
    {
        if (count($snapshot) !== 3 || ! is_int($snapshot['size'] ?? null) || $snapshot['size'] < 0
            || ! is_int($snapshot['modified_at'] ?? null) || ! is_array($snapshot['source_fingerprint'] ?? null)
            || count($snapshot['source_fingerprint']) !== 6) {
            throw new InvalidArgumentException('An exact physical file observation is required.');
        }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) {
            if (! is_int($snapshot['source_fingerprint'][$key] ?? null)) {
                throw new InvalidArgumentException('An exact physical file observation is required.');
            }
        }
        $this->json($snapshot);
        $deadline = CarbonImmutable::parse($notBefore, 'UTC')->format('Y-m-d H:i:s.u');

        return $this->mutate($lease, function ($scope) use ($workId, $snapshot, $deadline) {
            $work = $this->lockedWork($scope, $workId, 'file');
            $this->assertDue($work);
            $checkpoint = json_decode($work->checkpoint, true, flags: JSON_THROW_ON_ERROR);
            if (($checkpoint['first_snapshot'] ?? null) !== null) {
                throw new RuntimeException('Initial physical observation is already recorded.');
            }
            $checkpoint['first_snapshot'] = $snapshot;
            $checkpoint['snapshot'] = $snapshot;
            $checkpoint['stability_not_before'] = $deadline;
            $this->touchWork($scope, $work, ['checkpoint' => $this->json($checkpoint), 'state' => 'deferred', 'not_before' => $deadline]);

            return ['state' => 'waiting'];
        });
    }

    public function deferDirectory(array $lease, int $workId, string $reason): array
    {
        return $this->deferWork($lease, $workId, 'directory', $reason);
    }

    /** Explicit invalidation retains children and one bounded previous cursor for custody. */
    public function restartDirectory(array $lease, int $workId, string $reason): array
    {
        return $this->deferWork($lease, $workId, 'directory', $reason, restart: true);
    }

    private function deferWork(array $lease, int $workId, string $kind, string $reason, ?array $checkpoint = null, bool $restart = false): array
    {
        $this->reason($reason);

        return $this->mutate($lease, function ($scope) use ($workId, $kind, $reason, $checkpoint, $restart) {
            $work = $this->lockedWork($scope, $workId, $kind);
            if (! $restart) {
                $this->assertDue($work);
            }
            $attempts = (int) $work->attempts + 1;
            $held = $attempts >= $this->limit('max_attempts');
            $base = $this->limit('backoff_seconds');
            $ceiling = $this->limit('max_backoff_seconds');
            $exponent = min($attempts - 1, max(0, (int) ceil(log(max(1, $ceiling / $base), 2))));
            $changes = ['attempts' => $attempts, 'state' => $held ? 'held' : 'deferred', 'last_reason' => $reason,
                'not_before' => $held ? null : $this->now()->addSeconds((int) min($ceiling, $base * 2 ** $exponent))->format('Y-m-d H:i:s.u')];
            if ($restart && ! $held) {
                $previous = json_decode($work->checkpoint, true, flags: JSON_THROW_ON_ERROR);
                $changes['checkpoint'] = $this->json(['cookie' => '0', 'generation' => null, 'eof' => false,
                    'qualification_fingerprint' => $scope->qualification_fingerprint,
                    'previous_checkpoint' => array_intersect_key($previous, array_flip(['cookie', 'generation', 'eof']))]);
            }
            if ($checkpoint !== null) {
                $previous = json_decode($work->checkpoint, true, flags: JSON_THROW_ON_ERROR);
                if (array_key_exists('first_snapshot', $checkpoint) && $checkpoint['first_snapshot'] !== ($previous['first_snapshot'] ?? null)) {
                    throw new InvalidArgumentException('First observation custody cannot be overwritten.');
                }
                $checkpoint['first_snapshot'] = $previous['first_snapshot'] ?? null;
                if (isset($checkpoint['stability_not_before'])) {
                    $deadline = CarbonImmutable::parse($checkpoint['stability_not_before'], 'UTC')->format('Y-m-d H:i:s.u');
                    if (! $held && $deadline > $changes['not_before']) {
                        $changes['not_before'] = $deadline;
                    }
                }
                $changes['checkpoint'] = $this->json($checkpoint);
            }
            $this->touchWork($scope, $work, $changes);
            DB::table(self::SCOPES)->where('id', $scope->id)->increment('errors');
            if ($kind === 'file') {
                DB::table(self::SCOPES)->where('id', $scope->id)->increment('intake_attempts');
            }

            return ['state' => $held ? 'held' : 'waiting'];
        });
    }

    public function holdFile(array $lease, int $workId, string $reason): array
    {
        return $this->holdWork($lease, $workId, 'file', $reason);
    }

    public function holdDirectory(array $lease, int $workId, string $reason): array
    {
        return $this->holdWork($lease, $workId, 'directory', $reason);
    }

    public function finishFile(array $lease, int $workId, string $outcome): array
    {
        $this->outcome($outcome);

        return $this->mutate($lease, function ($scope) use ($workId, $outcome) {
            $work = $this->lockedWork($scope, $workId, 'file');
            $this->assertDue($work);
            $this->finish($scope, $work, $outcome);

            return [];
        });
    }

    /** Only same-connection transactional DB effects roll back; the callback must not perform external writes. */
    public function guardedIntake(array $lease, int $workId, callable $callback): array
    {
        return $this->mutate($lease, function ($scope) use ($workId, $callback, $lease) {
            $work = $this->lockedWork($scope, $workId, 'file');
            $this->assertDue($work);
            $result = $callback($this->workArray($work));
            $this->lockedScope($lease);
            if (! is_array($result) || ($result['success'] ?? false) !== true) {
                throw new RuntimeException('Intake did not succeed; its transactional writes were rolled back.');
            }
            $outcome = $result['outcome'] ?? (($result['skipped'] ?? false) ? 'unchanged' : (($result['refreshed'] ?? false) ? 'updated' : (($result['is_duplicate'] ?? false) ? 'duplicate' : 'registered')));
            $this->outcome($outcome);
            $this->finish($scope, $work, $outcome);

            return ['result' => $result];
        });
    }

    /** Revisit known files without abandoning earlier unresolved file custody. */
    public function beginEpoch(array $lease): array
    {
        return $this->mutate($lease, function ($scope) {
            if (! $this->status((int) $scope->id)['discovery_complete']) {
                return ['state' => 'held', 'stop_reason' => 'discovery_not_complete'];
            }
            if ($this->unresolvedCount((int) $scope->id) >= $this->limit('max_pending_work')) {
                return ['state' => 'held', 'stop_reason' => 'pending_capacity'];
            }
            $epoch = (string) Str::uuid();
            DB::table(self::WORK)->where('scope_id', $scope->id)->where('kind', 'file')->where('state', '!=', 'finished')->update(['epoch_uuid' => $epoch]);
            DB::table(self::SCOPES)->where('id', $scope->id)->update(['epoch_uuid' => $epoch]);
            $scope->epoch_uuid = $epoch;
            $this->insertWork($scope, 'directory', $scope->scope_path, 0, ['cookie' => '0', 'generation' => null, 'eof' => false]);

            return [];
        });
    }

    /** Caller must independently admit the new runtime qualification before invoking this explicit replacement. */
    public function replaceQualification(array $lease, string $expectedOldFingerprint, string $newFingerprint): array
    {
        if ($newFingerprint === '' || $expectedOldFingerprint === '' || $newFingerprint === $expectedOldFingerprint) {
            throw new InvalidArgumentException('A distinct admitted qualification is required.');
        }
        $this->json([$expectedOldFingerprint, $newFingerprint]);

        return $this->mutate($lease, function ($scope) use ($expectedOldFingerprint, $newFingerprint) {
            if ($scope->qualification_fingerprint !== $expectedOldFingerprint) {
                return ['state' => 'held', 'stop_reason' => 'qualification_changed'];
            }
            $files = DB::table(self::WORK)->where('scope_id', $scope->id)->where('kind', 'file')->where('state', '!=', 'finished')->count();
            if ($files >= $this->limit('max_pending_work')) {
                return ['state' => 'held', 'stop_reason' => 'pending_capacity'];
            }
            // Each directory already retains its qualification in its bounded checkpoint.
            DB::table(self::WORK)->where('scope_id', $scope->id)->where('kind', 'directory')->where('state', '!=', 'finished')
                ->update(['state' => 'finished', 'outcome' => 'invalidated', 'last_reason' => 'qualification_changed', 'updated_at' => $this->timestamp()]);
            $scope->epoch_uuid = (string) Str::uuid();
            $scope->lease_token = (string) Str::uuid();
            $scope->qualification_fingerprint = $newFingerprint;
            DB::table(self::WORK)->where('scope_id', $scope->id)->where('kind', 'file')->where('state', '!=', 'finished')
                ->update(['epoch_uuid' => $scope->epoch_uuid]);
            DB::table(self::SCOPES)->where('id', $scope->id)->update(['epoch_uuid' => $scope->epoch_uuid,
                'lease_token' => $scope->lease_token, 'qualification_fingerprint' => $newFingerprint]);
            $this->insertWork($scope, 'directory', $scope->scope_path, 0, ['cookie' => '0', 'generation' => null, 'eof' => false]);

            return [];
        });
    }

    public function purgeResolved(array $lease, int $limit): array
    {
        $limit = $this->bounded($limit, 'max_purge_rows');

        return $this->mutate($lease, function ($scope) use ($limit) {
            $ids = DB::table(self::WORK)->where('scope_id', $scope->id)->where('epoch_uuid', '!=', $scope->epoch_uuid)
                ->where('state', 'finished')->orderBy('id')->limit($limit)->pluck('id')->all();
            $count = $ids === [] ? 0 : DB::table(self::WORK)->whereIn('id', $ids)->delete();

            return ['purged' => $count];
        });
    }

    public function status(int $scopeId): array
    {
        $scope = DB::table(self::SCOPES)->where('id', $scopeId)->first();
        if (! $scope) {
            throw new InvalidArgumentException('Unknown discovery scope.');
        }
        $directories = DB::table(self::WORK)->where('scope_id', $scopeId)->where('epoch_uuid', $scope->epoch_uuid)->where('kind', 'directory');
        $discoveryComplete = (clone $directories)->exists() && ! (clone $directories)->where('state', '!=', 'finished')->exists();
        $discoveryCompletedAt = $discoveryComplete ? (clone $directories)->max('updated_at') : null;
        $pending = $this->unresolvedCount($scopeId);
        $held = DB::table(self::WORK)->where('scope_id', $scopeId)->where('state', 'held')->count();
        $hasDue = DB::table(self::WORK)->where('scope_id', $scopeId)->whereIn('state', ['pending', 'deferred'])
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', $this->timestamp()))->exists();
        $next = $hasDue ? null : DB::table(self::WORK)->where('scope_id', $scopeId)->where('state', 'deferred')->min('not_before');
        $state = $pending === 0 && $discoveryComplete ? 'complete' : ($hasDue ? 'pending' : ($next !== null ? 'waiting' : 'held'));

        return ['scope_id' => $scopeId, 'scope_path' => $scope->scope_path, 'compute_hash' => (bool) $scope->compute_hash,
            'connector_fingerprint' => $scope->connector_fingerprint, 'qualification_fingerprint' => $scope->qualification_fingerprint,
            'epoch_uuid' => $scope->epoch_uuid, 'revision' => (int) $scope->revision, 'state' => $state,
            'discovery_complete' => $discoveryComplete, 'intake_complete' => $discoveryComplete && $pending === 0,
            'discovery_completed_at' => $discoveryCompletedAt,
            'pending_work' => $pending, 'held_work' => $held, 'next_attempt_at' => $next, 'last_reason' => $scope->last_reason,
            'counters' => array_combine(['directory_records', 'files_discovered', 'intake_attempts', 'registered', 'updated', 'unchanged', 'duplicates', 'errors'],
                array_map(fn ($key) => (int) $scope->$key, ['directory_records', 'files_discovered', 'intake_attempts', 'registered', 'updated', 'unchanged', 'duplicates', 'errors']))];
    }

    private function mutate(array $lease, callable $callback, bool $release = false): array
    {
        return DB::transaction(function () use ($lease, $callback, $release) {
            $scope = $this->lockedScope($lease);
            $extra = $callback($scope);
            // Epoch is intentionally changed only by beginEpoch; all other fence fields stay exact.
            $fresh = DB::table(self::SCOPES)->where('id', $scope->id)->lockForUpdate()->first();
            $expected = array_replace($lease, ['epoch_uuid' => $scope->epoch_uuid, 'token' => $scope->lease_token]);
            $this->assertLease($fresh, $expected);
            $status = $this->status((int) $scope->id);
            $changes = ['revision' => (int) $fresh->revision + 1, 'status' => $status['state'],
                'discovery_complete' => $status['discovery_complete'], 'intake_complete' => $status['intake_complete'],
                'next_attempt_at' => $status['next_attempt_at'], 'last_reason' => $extra['stop_reason'] ?? null, 'updated_at' => $this->timestamp()];
            if ($release) {
                $changes += ['lease_token' => null, 'lease_expires_at' => null];
            }
            DB::table(self::SCOPES)->where('id', $scope->id)->update($changes);

            $response = array_replace($this->response((int) $scope->id, 'progressed'), $extra);
            $this->assertLease($fresh, $expected);

            return $response;
        });
    }

    private function lockedScope(array $lease): object
    {
        $scope = DB::table(self::SCOPES)->where('id', $lease['scope_id'] ?? null)->lockForUpdate()->first();
        $this->assertLease($scope, $lease);

        return $scope;
    }

    private function assertLease(?object $scope, array $lease): void
    {
        if (! $scope || ! is_string($lease['token'] ?? null) || $scope->lease_token !== $lease['token']
            || $scope->epoch_uuid !== ($lease['epoch_uuid'] ?? null) || (int) $scope->revision !== ($lease['revision'] ?? null)
            || $scope->lease_expires_at === null || ! CarbonImmutable::parse($scope->lease_expires_at, 'UTC')->greaterThan($this->now())) {
            throw new RuntimeException('Discovery lease is stale or expired.');
        }
    }

    private function lockedWork(object $scope, int $id, string $kind): object
    {
        $work = DB::table(self::WORK)->where('id', $id)->where('scope_id', $scope->id)->where('epoch_uuid', $scope->epoch_uuid)->where('kind', $kind)->lockForUpdate()->first();
        if (! $work || $work->state === 'finished') {
            throw new RuntimeException('Discovery work is missing, historical, or already resolved.');
        }

        return $work;
    }

    private function insertWork(object $scope, string $kind, string $path, int $depth, array $checkpoint): void
    {
        $notBefore = null;
        if ($kind === 'directory') {
            $this->cookie($checkpoint['cookie'] ?? null);
            if (! array_key_exists('generation', $checkpoint) || $checkpoint['generation'] !== null || $checkpoint['cookie'] !== '0') {
                throw new InvalidArgumentException('New directories must start with an unproven zero cookie.');
            }
            $checkpoint['qualification_fingerprint'] = $scope->qualification_fingerprint;
        } elseif (isset($checkpoint['stability_not_before'])) {
            if (! is_string($checkpoint['stability_not_before'])) {
                throw new InvalidArgumentException('Invalid file stability deadline.');
            }
            $notBefore = CarbonImmutable::parse($checkpoint['stability_not_before'], 'UTC')->format('Y-m-d H:i:s.u');
        }
        if ($kind === 'file') {
            if (array_key_exists('first_snapshot', $checkpoint)) {
                throw new InvalidArgumentException('First snapshot is supplied by discovery custody.');
            }
            $checkpoint['first_snapshot'] = $checkpoint['snapshot'] ?? null;
        }
        DB::table(self::WORK)->insert(['scope_id' => $scope->id, 'epoch_uuid' => $scope->epoch_uuid, 'first_epoch_uuid' => $scope->epoch_uuid, 'kind' => $kind,
            'path' => $path, 'path_hash' => hash('sha256', $path), 'depth' => $depth, 'checkpoint' => $this->json($checkpoint),
            'state' => $notBefore === null ? 'pending' : 'deferred', 'not_before' => $notBefore,
            // Creation order keeps later arrivals behind older due work; actual service still stamps revision + 1.
            'last_served_revision' => (int) $scope->revision,
            'created_at' => $this->timestamp(), 'updated_at' => $this->timestamp()]);
    }

    private function touchWork(object $scope, object $work, array $changes): void
    {
        DB::table(self::WORK)->where('id', $work->id)->update($changes + ['last_served_revision' => (int) $scope->revision + 1,
            'last_served_at' => $this->timestamp(), 'updated_at' => $this->timestamp()]);
    }

    private function holdWork(array $lease, int $workId, string $kind, string $reason): array
    {
        $this->reason($reason);

        return $this->mutate($lease, function ($scope) use ($workId, $kind, $reason) {
            $this->touchWork($scope, $this->lockedWork($scope, $workId, $kind), ['state' => 'held', 'last_reason' => $reason]);

            return ['state' => 'held', 'stop_reason' => $reason];
        });
    }

    private function finish(object $scope, object $work, string $outcome): void
    {
        $this->touchWork($scope, $work, ['state' => 'finished', 'attempts' => (int) $work->attempts + 1, 'outcome' => $outcome, 'not_before' => null, 'last_reason' => null]);
        DB::table(self::SCOPES)->where('id', $scope->id)->increment('intake_attempts');
        DB::table(self::SCOPES)->where('id', $scope->id)->increment($outcome === 'duplicate' ? 'duplicates' : $outcome);
        if ($outcome === 'duplicate') {
            DB::table(self::SCOPES)->where('id', $scope->id)->increment('registered');
        }
    }

    private function assertDue(object $work): void
    {
        if (! in_array($work->state, ['pending', 'deferred'], true) || ($work->not_before !== null && CarbonImmutable::parse($work->not_before, 'UTC')->greaterThan($this->now()))) {
            throw new RuntimeException('Discovery work is not due.');
        }
    }

    private function response(int $scopeId, string $state): array
    {
        $scope = DB::table(self::SCOPES)->where('id', $scopeId)->first();

        return ['state' => $state, 'status' => $this->status($scopeId), 'lease' => $scope->lease_token === null ? null : [
            'scope_id' => $scopeId, 'epoch_uuid' => $scope->epoch_uuid, 'token' => $scope->lease_token,
            'revision' => (int) $scope->revision, 'expires_at' => $scope->lease_expires_at,
        ]];
    }

    private function workArray(object $row): array
    {
        $result = (array) $row;
        $result['checkpoint'] = json_decode($row->checkpoint, true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }

    private function unresolvedCount(int $scopeId): int
    {
        return DB::table(self::WORK)->where('scope_id', $scopeId)->where('state', '!=', 'finished')->count();
    }

    private function json(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        if (strlen($json) > $this->limit('max_checkpoint_bytes')) {
            throw new InvalidArgumentException('Discovery checkpoint exceeds policy.');
        }

        return $json;
    }

    private function limit(string $key, bool $zeroAllowed = false): int
    {
        $policy = $this->systemConfig->get('files.registry_discovery_policy', config('file_registry.discovery.policy'));
        if (is_string($policy)) {
            $policy = json_decode($policy, true, flags: JSON_THROW_ON_ERROR);
        }
        $value = is_array($policy) ? ($policy[$key] ?? null) : null;
        $bound = config('file_registry.discovery.bounds.'.$key);
        if (! is_int($value) || ! is_int($bound) || $value < ($zeroAllowed ? 0 : 1) || $bound < ($zeroAllowed ? 0 : 1)) {
            throw new RuntimeException('Missing or invalid discovery policy: '.$key);
        }

        return min($value, $bound);
    }

    private function bounded(int $value, string $key): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException('A positive work bound is required.');
        }

        return min($value, $this->limit($key));
    }

    private function path(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > $this->limit('max_path_bytes') || str_contains($path, "\0")
            || str_contains($path, '\\') || str_contains($path, '//') || ($path !== '/' && str_ends_with($path, '/'))
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            throw new InvalidArgumentException('An exact logical absolute path is required.');
        }
    }

    private function cookie(mixed $cookie): void
    {
        if (! is_string($cookie) || ! preg_match('/^(0|[1-9][0-9]{0,18})$/D', $cookie)) {
            throw new InvalidArgumentException('An opaque decimal cookie is required.');
        }
    }

    private function generation(mixed $value): array
    {
        if (! is_array($value) || count($value) !== 4) {
            throw new InvalidArgumentException('Exact directory generation is required.');
        }
        $result = [];
        foreach (['dev', 'ino', 'mtime_ns', 'ctime_ns'] as $key) {
            if (! is_int($value[$key] ?? null)) {
                throw new InvalidArgumentException('Exact directory generation is required.');
            }
            $result[$key] = $value[$key];
        }

        return $result;
    }

    private function kind(string $kind): void
    {
        if (! in_array($kind, ['directory', 'file'], true)) {
            throw new InvalidArgumentException('Invalid discovery work kind.');
        }
    }

    private function reason(string $reason): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $reason)) {
            throw new InvalidArgumentException('A bounded machine reason is required.');
        }
    }

    private function outcome(string $outcome): void
    {
        if (! in_array($outcome, ['registered', 'updated', 'unchanged', 'duplicate'], true)) {
            throw new InvalidArgumentException('Invalid file intake outcome.');
        }
    }

    private function now(): CarbonImmutable
    {
        return $this->clock ? ($this->clock)() : CarbonImmutable::now('UTC');
    }

    private function timestamp(): string
    {
        return $this->now()->format('Y-m-d H:i:s.u');
    }
}
