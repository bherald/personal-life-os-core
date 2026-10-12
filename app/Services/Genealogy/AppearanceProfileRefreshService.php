<?php

namespace App\Services\Genealogy;

use App\Services\UnifiedCli\CliArtifactStore;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use App\Services\UnifiedCli\CliLocalBindings;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SQLite3;
use Throwable;

/** Technical projection refresh; never an identity approval. */
class AppearanceProfileRefreshService
{
    public const ACTION = 'refresh_face_profiles';

    public const RECONCILE_ACTION = 'reconcile_face_profiles';

    public function __construct(private PersonFaceAppearanceProfileService $profiles) {}

    public function plan(array $selectors): array
    {
        $selectors = $this->selectors($selectors);
        $state = $this->capture($selectors);

        return ['success' => true, 'plan_sha256' => $this->digest($state['plan']),
            'state_sha256' => $this->digest($state), 'identities' => count($state['plan']['identity_keys']),
            'faces' => count($state['sources']['faces']), 'old_profiles' => count($state['preimage']['profiles']),
            'old_links' => count($state['preimage']['links']),
            'sources_sha256' => $this->digest($state['sources']), 'preimage_sha256' => $this->digest($state['preimage'])];
    }

    public function refresh(array $selectors, string $expectedPlanSha256, string $expectedStateSha256, string $idempotencyKey): array
    {
        $selectors = $this->selectors($selectors);
        foreach ([$expectedPlanSha256, $expectedStateSha256] as $sha) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                throw new RuntimeException('profile_refresh_digest_invalid');
            }
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new RuntimeException('profile_refresh_idempotency_invalid');
        }
        $caller = $this->caller();
        $request = compact('selectors', 'expectedPlanSha256', 'expectedStateSha256', 'idempotencyKey');
        $digest = $this->digest($request);

        return $this->withMysqlLockTimeout(fn () => $this->execute($selectors, $expectedPlanSha256, $expectedStateSha256, $idempotencyKey, $request, $digest, $caller));
    }

    private function execute(array $selectors, string $expectedPlanSha256, string $expectedStateSha256, string $idempotencyKey, array $request, string $digest, array $caller): array
    {
        $claim = DB::transaction(function () use ($idempotencyKey, $digest, $request, $caller): array {
            $old = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
            if ($old) {
                if ($old->action !== self::ACTION || $old->request_digest !== $digest) {
                    throw new RuntimeException('profile_refresh_receipt_conflict');
                }
                $payload = json_decode($old->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($old->state !== 'completed' || ($payload['success'] ?? false) !== true) {
                    throw new RuntimeException('profile_refresh_outcome_held');
                }

                return ['replay' => array_replace($payload['result'], ['replayed' => true])];
            }
            $pending = DB::table('review_desk_receipts')->where('action', self::ACTION)->where('state', 'processing')
                ->orderBy('id')->lockForUpdate()->limit(config('file_registry.profile_refresh.max_pending_receipts') + 1)->get();
            if ($pending->count() > config('file_registry.profile_refresh.max_pending_receipts')) {
                throw new RuntimeException('profile_refresh_pending_limit');
            }
            $keys = $this->scopeKeys($request['selectors']);
            foreach ($pending as $receipt) {
                if ($this->reconciled($receipt)) {
                    continue;
                }
                $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
                $prior = $payload['request']['selectors'] ?? null;
                if (! is_array($prior) || array_intersect($keys, $this->scopeKeys($prior)) !== []) {
                    throw new RuntimeException('profile_refresh_scope_outcome_held');
                }
            }
            DB::table('review_desk_receipts')->insert([
                'id' => $idempotencyKey, 'reference' => $idempotencyKey, 'idempotency_key' => $idempotencyKey,
                'request_digest' => $digest, 'action' => self::ACTION, 'state' => 'processing',
                'payload' => json_encode(['schema' => 'plos.profile-refresh.v1', 'identity_approval' => false,
                    'request' => $request, 'caller' => $caller, 'effect_status' => 'not_started'], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [];
        });
        if (isset($claim['replay'])) {
            return $claim['replay'];
        }

        // A processing receipt is never retried automatically. Data and final receipt
        // commit together; an interrupted/unknown commit requires read-only reconciliation.
        // Beginning a PG transaction acquires no vector locks. MySQL commits
        // while matching vector row locks remain held by the outer PG transaction.
        $backupStarted = false;
        try {
            return DB::connection('pgsql_rag')->transaction(function () use ($selectors, $expectedPlanSha256, $expectedStateSha256, $idempotencyKey, $digest, &$backupStarted): array {
                $pg = DB::connection('pgsql_rag');
                if ($pg->getDriverName() === 'pgsql') {
                    $milliseconds = $this->lockWaitSeconds() * 1000;
                    $pg->statement("SET LOCAL lock_timeout = '{$milliseconds}ms'");
                    $pg->statement("SET LOCAL statement_timeout = '{$milliseconds}ms'");
                }

                return DB::transaction(function () use ($selectors, $expectedPlanSha256, $expectedStateSha256, $idempotencyKey, $digest, &$backupStarted): array {
                    // Established order: MySQL registry/face/projection first, then
                    // bounded matching PG vector rows. No global vector table freeze.
                    $this->capture($selectors, true, false);
                    $receipt = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
                    if (! $receipt || $receipt->state !== 'processing' || $receipt->request_digest !== $digest) {
                        throw new RuntimeException('profile_refresh_receipt_changed');
                    }
                    $state = $this->capture($selectors, true);
                    if ($this->digest($state['plan']) !== $expectedPlanSha256 || $this->digest($state) !== $expectedStateSha256) {
                        throw new RuntimeException('profile_refresh_state_changed');
                    }
                    $backupStarted = true;
                    $backup = $this->backup($idempotencyKey, $state);
                    // The file must remain readable and exact immediately before mutation.
                    if (! hash_equals($backup['sha256'], (string) hash_file('sha256', $backup['path']))) {
                        throw new RuntimeException('profile_refresh_backup_changed');
                    }
                    if ($this->digest($this->capture($selectors, true)) !== $expectedStateSha256) {
                        throw new RuntimeException('profile_refresh_state_changed');
                    }
                    $result = $this->profiles->storeProfileWritePlan($state['plan']) + [
                        'success' => true, 'identity_approval' => false, 'receipt_id' => $idempotencyKey,
                        'plan_sha256' => $expectedPlanSha256, 'state_sha256' => $expectedStateSha256,
                        'backup_sha256' => $backup['sha256'], 'backup_path' => $backup['path'], 'replayed' => false,
                    ];
                    // Existing row locks do not fence inserts for a missing vector.
                    // Recheck immediately after publication; this is a derived cache,
                    // not an atomic cross-store identity/calibration attestation.
                    $after = $this->capture($selectors, true);
                    if ($this->digest($after['sources']) !== $this->digest($state['sources'])
                        || $this->digest($after['plan']) !== $expectedPlanSha256) {
                        throw new RuntimeException('profile_refresh_state_changed');
                    }
                    $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
                    $payload += ['success' => true, 'effect_status' => 'completed', 'result' => $result];
                    $payload['effect_status'] = 'completed';
                    DB::table('review_desk_receipts')->where('id', $idempotencyKey)->update([
                        'state' => 'completed', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);

                    return $result;
                });
            });
        } catch (Throwable $error) {
            // Only known failures before even attempting a backup prove no mutation.
            $known = ['profile_refresh_state_changed', 'profile_refresh_scope_changed',
                'profile_refresh_scope_too_large', 'profile_refresh_owner_conflict'];
            if (! $backupStarted && $error instanceof RuntimeException && in_array($error->getMessage(), $known, true)) {
                DB::transaction(function () use ($idempotencyKey, $digest, $error): void {
                    $row = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
                    if (! $row || $row->state !== 'processing' || $row->request_digest !== $digest) {
                        throw new RuntimeException('profile_refresh_receipt_changed');
                    }
                    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                    $payload['failure_code'] = $error->getMessage();
                    $payload['effect_status'] = 'not_started';
                    DB::table('review_desk_receipts')->where('id', $idempotencyKey)->update([
                        'state' => 'failed', 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                    ]);
                });

                return ['success' => false, 'status' => 'held', 'receipt_id' => $idempotencyKey,
                    'failure_code' => $error->getMessage(), 'effect_status' => 'not_started', 'identity_approval' => false];
            }
            throw $error;
        }
    }

    public function recoveryPlan(string $receiptId, string $workerArtifactSha256): array
    {
        $row = $this->recoveryReceipt($receiptId);
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        $proof = $this->workerProof($row, $workerArtifactSha256);
        $this->assertBackupAbsent($receiptId);
        $state = $this->capture($payload['request']['selectors']);

        return ['success' => true, 'receipt_id' => $receiptId, 'worker_proof_sha256' => $this->digest($proof),
            'state_sha256' => $this->digest($state), 'sources_sha256' => $this->digest($state['sources']),
            'preimage_sha256' => $this->digest($state['preimage']), 'original_outcome' => 'unknown',
            'projection_mutation_not_started' => true, 'original_receipt_sha256' => $this->receiptDigest($row)];
    }

    public function reconcileNoEffect(string $receiptId, string $workerArtifactSha256, string $expectedStateSha256,
        string $expectedSourcesSha256, string $expectedPreimageSha256, string $idempotencyKey): array
    {
        $request = compact('receiptId', 'workerArtifactSha256', 'expectedStateSha256', 'expectedSourcesSha256', 'expectedPreimageSha256', 'idempotencyKey');
        foreach ([$workerArtifactSha256, $expectedStateSha256, $expectedSourcesSha256, $expectedPreimageSha256] as $sha) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $sha)) {
                throw new RuntimeException('profile_recovery_digest_invalid');
            }
        }
        if (! Str::isUuid($receiptId) || ! Str::isUuid($idempotencyKey) || $receiptId === $idempotencyKey) {
            throw new RuntimeException('profile_recovery_idempotency_invalid');
        }
        $caller = $this->caller();
        if (! hash_equals($caller['arguments_sha256'], $this->digest($request))) {
            throw new RuntimeException('profile_recovery_caller_mismatch');
        }

        return $this->withMysqlLockTimeout(fn () => DB::connection('pgsql_rag')->transaction(function () use ($request, $caller): array {
            $pg = DB::connection('pgsql_rag');
            if ($pg->getDriverName() === 'pgsql') {
                $milliseconds = $this->lockWaitSeconds() * 1000;
                $pg->statement("SET LOCAL lock_timeout = '{$milliseconds}ms'");
                $pg->statement("SET LOCAL statement_timeout = '{$milliseconds}ms'");
            }

            return DB::transaction(function () use ($request, $caller): array {
                $original = $this->recoveryReceipt($request['receiptId']);
                $payload = json_decode($original->payload, true, flags: JSON_THROW_ON_ERROR);
                if ($caller['session_id'] !== ($payload['caller']['session_id'] ?? null)
                    || $caller['action_id'] === ($payload['caller']['action_id'] ?? null)) {
                    throw new RuntimeException('profile_recovery_foreign_caller');
                }
                // Same order as refresh: projection/source rows, original claim, matching PG rows.
                $this->capture($payload['request']['selectors'], true, false);
                $original = $this->recoveryReceipt($request['receiptId'], true);
                $state = $this->capture($payload['request']['selectors'], true);
                if ($this->digest($state) !== $request['expectedStateSha256']
                    || $this->digest($state['sources']) !== $request['expectedSourcesSha256']
                    || $this->digest($state['preimage']) !== $request['expectedPreimageSha256']) {
                    throw new RuntimeException('profile_recovery_state_changed');
                }
                $proof = $this->workerProof($original, $request['workerArtifactSha256']);
                $this->assertBackupAbsent($request['receiptId']);
                $digest = $this->digest($request);
                $old = DB::table('review_desk_receipts')->where('id', $request['idempotencyKey'])->lockForUpdate()->first();
                if ($old) {
                    if ($old->action !== self::RECONCILE_ACTION || $old->request_digest !== $digest || $old->state !== 'completed') {
                        throw new RuntimeException('profile_recovery_receipt_conflict');
                    }
                    $saved = json_decode($old->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (($saved['original_receipt_sha256'] ?? null) !== $this->receiptDigest($original)) {
                        throw new RuntimeException('profile_recovery_receipt_changed');
                    }

                    return $saved['result'] + ['replayed' => true];
                }
                $result = ['success' => true, 'receipt_id' => $request['idempotencyKey'], 'original_receipt_id' => $original->id,
                    'original_outcome' => 'unknown', 'projection_mutation_not_started' => true,
                    'identity_approval' => false, 'fresh_action_required' => true];
                DB::table('review_desk_receipts')->insert([
                    'id' => $request['idempotencyKey'], 'reference' => $original->id, 'idempotency_key' => $request['idempotencyKey'],
                    'request_digest' => $digest, 'action' => self::RECONCILE_ACTION, 'state' => 'completed',
                    'payload' => json_encode(['schema' => 'plos.profile-refresh-reconciliation.v1', 'request' => $request,
                        'caller' => $caller, 'success' => true, 'original_receipt_sha256' => $this->receiptDigest($original),
                        'worker_proof' => $proof, 'result' => $result], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $result;
            });
        }));
    }

    private function recoveryReceipt(string $id, bool $lock = false): object
    {
        if (! Str::isUuid($id)) {
            throw new RuntimeException('profile_recovery_receipt_invalid');
        }
        $query = DB::table('review_desk_receipts')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        $payload = $row ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! $row || $row->action !== self::ACTION || $row->state !== 'processing'
            || ($payload['schema'] ?? null) !== 'plos.profile-refresh.v1'
            || ($payload['effect_status'] ?? null) !== 'not_started'
            || ! is_array($payload['request'] ?? null) || ! is_array($payload['caller'] ?? null)
            || ($payload['request']['idempotencyKey'] ?? null) !== $id
            || $this->digest($payload['request']) !== $row->request_digest) {
            throw new RuntimeException('profile_recovery_original_held');
        }

        return $row;
    }

    private function receiptDigest(object $row): string
    {
        return $this->digest((array) $row);
    }

    private function reconciled(object $row): bool
    {
        $receipts = DB::table('review_desk_receipts')->where('action', self::RECONCILE_ACTION)
            ->where('reference', $row->id)->where('state', 'completed')->get();
        foreach ($receipts as $receipt) {
            $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
            if (($payload['schema'] ?? null) === 'plos.profile-refresh-reconciliation.v1'
                && ($payload['success'] ?? false) === true
                && ($payload['result']['projection_mutation_not_started'] ?? false) === true
                && ($payload['request']['receiptId'] ?? null) === $row->id
                && ($payload['original_receipt_sha256'] ?? null) === $this->receiptDigest($row)
                && $receipt->request_digest === $this->digest($payload['request'])) {
                return true;
            }
        }

        return false;
    }

    private function assertBackupAbsent(string $id): void
    {
        $root = realpath(storage_path());
        $relative = config('file_registry.profile_refresh.private_directory');
        if ($root === false || ! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('profile_recovery_backup_unknown');
        }
        $path = $root;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
                throw new RuntimeException('profile_recovery_backup_unknown');
            }
        }
        clearstatcache(true, $path.'/'.$id.'.json');
        if (file_exists($path.'/'.$id.'.json') || is_link($path.'/'.$id.'.json')) {
            throw new RuntimeException('profile_recovery_backup_present');
        }
    }

    private function workerProof(object $receipt, string $artifactSha256): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $artifactSha256)) {
            throw new RuntimeException('profile_recovery_digest_invalid');
        }
        $payload = json_decode($receipt->payload, true, flags: JSON_THROW_ON_ERROR);
        $caller = $payload['caller'];
        $directory = config('file_registry.profile_refresh.cli_state_directory');
        if (! is_string($directory) || $directory === '' || str_contains($directory, '\\') || in_array('..', explode('/', $directory), true)) {
            throw new RuntimeException('profile_recovery_custody_unavailable');
        }
        $directory = str_starts_with($directory, '/') ? $directory : storage_path($directory);
        $path = $directory.'/state.sqlite';
        CliLocalBindings::assertPrivateSqlite($path);
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $db->enableExceptions(true);
        $db->exec('BEGIN');
        try {
            $query = $db->prepare('SELECT document FROM cli_sessions WHERE id=:session');
            $query->bindValue(':session', $caller['session_id']);
            $session = json_decode($query->execute()->fetchArray(SQLITE3_ASSOC)['document'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $query = $db->prepare('SELECT state,document FROM cli_action_receipts WHERE session_id=:session AND turn_id=:turn AND action_id=:action');
            foreach (['session' => 'session_id', 'turn' => 'turn_id', 'action' => 'action_id'] as $key => $value) {
                $query->bindValue(':'.$key, $caller[$value]);
            }
            $actionRow = $query->execute()->fetchArray(SQLITE3_ASSOC);
            $action = json_decode($actionRow['document'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $closed = [$caller['turn_id']];
            if (isset($action['continuation_turn_id'])) {
                $closed[] = $action['continuation_turn_id'];
            }
            foreach ($closed as $turn) {
                $matches = array_values(array_filter($session['turns'] ?? [], fn ($t) => ($t['id'] ?? null) === $turn));
                if (count($matches) !== 1 || ! in_array($matches[0]['status'] ?? null, ['completed', 'failed', 'cancelled', 'interrupted'], true)) {
                    throw new RuntimeException('profile_recovery_worker_active_or_unknown');
                }
            }
            if (($actionRow['state'] ?? null) !== 'started' || ($action['outcome'] ?? null) !== 'unknown'
                || ($action['action']['id'] ?? null) !== $caller['action_id']
                || ($action['action']['name'] ?? null) !== 'file_face_profiles_refresh'
                || ($action['action']['arguments'] ?? null) !== $payload['request']
                || $this->digest($action['action']['arguments']) !== ($caller['arguments_sha256'] ?? null)
                || ! is_int($action['started_at'] ?? null)) {
                throw new RuntimeException('profile_recovery_action_mismatch');
            }
            $query = $db->prepare('SELECT id,document FROM cli_events WHERE session_id=:session AND event_type=:type ORDER BY id');
            $query->bindValue(':session', $caller['session_id']);
            $query->bindValue(':type', 'framework_service_io');
            $events = $query->execute();
            $matches = [];
            while ($row = $events->fetchArray(SQLITE3_ASSOC)) {
                $event = json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR);
                if (($event['event']['tool'] ?? null) === 'file_face_profiles_refresh'
                    && ($event['recorded_at'] ?? 0) >= $action['started_at']) {
                    $matches[] = $row + ['decoded' => $event];
                }
            }
            // Historic IO lacked action_id. Require one candidate and no other same-tool
            // action in its interval; ambiguous custody remains held, never inferred.
            $matches = array_values(array_filter($matches, fn ($e) => ($e['decoded']['event']['stdout']['sha256'] ?? null) === $artifactSha256));
            if (count($matches) !== 1) {
                throw new RuntimeException('profile_recovery_artifact_unbound');
            }
            $event = $matches[0]['decoded'];
            if (($event['event']['target'] ?? null) !== 'local'
                || (isset($event['event']['action_id']) && $event['event']['action_id'] !== $caller['action_id'])) {
                throw new RuntimeException('profile_recovery_artifact_unbound');
            }
            $query = $db->prepare('SELECT document FROM cli_action_receipts WHERE session_id=:session AND action_id<>:action');
            $query->bindValue(':session', $caller['session_id']);
            $query->bindValue(':action', $caller['action_id']);
            $others = $query->execute();
            while ($row = $others->fetchArray(SQLITE3_ASSOC)) {
                $other = json_decode($row['document'], true, flags: JSON_THROW_ON_ERROR);
                if (($other['action']['name'] ?? null) === 'file_face_profiles_refresh'
                    && ($other['started_at'] ?? 0) <= $event['recorded_at']
                    && (! isset($other['completed_at']) || $other['completed_at'] >= $action['started_at'])) {
                    throw new RuntimeException('profile_recovery_artifact_ambiguous');
                }
            }
            if (! is_dir($directory.'/artifacts')) {
                throw new RuntimeException('profile_recovery_artifact_unbound');
            }
            $packet = json_decode((new CliArtifactStore($directory.'/artifacts'))->read($artifactSha256), true, flags: JSON_THROW_ON_ERROR);
            if (($packet['schema'] ?? null) !== 'plos.cli.internal-operation.v1' || ($packet['started'] ?? null) !== true
                || ($packet['cancelled'] ?? null) !== false || ($packet['completed'] ?? null) !== false
                || ($packet['exit']['code'] ?? null) !== 1 || (! array_key_exists('signal', $packet['exit'] ?? []) || $packet['exit']['signal'] !== null)) {
                throw new RuntimeException('profile_recovery_worker_exit_unknown');
            }
            $stdout = base64_decode($packet['stdout_base64'] ?? '', true);
            $service = $stdout === false ? null : json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            if (($service['schema'] ?? null) !== 'plos.cli.framework-result.v1' || array_key_exists('result', $service)) {
                throw new RuntimeException('profile_recovery_worker_exit_unknown');
            }

            return ['session_id' => $caller['session_id'], 'turn_id' => $caller['turn_id'], 'action_id' => $caller['action_id'],
                'action_receipt_sha256' => $this->digest($actionRow), 'io_event_id' => $matches[0]['id'],
                'io_event_sha256' => $this->digest($event), 'artifact_sha256' => $artifactSha256, 'ordinary_exit_code' => 1];
        } finally {
            $db->exec('ROLLBACK');
            $db->close();
        }
    }

    /** Read-only action-owned scope check; no refresh or provenance promotion. */
    public function receiptTouchesRegistry(array $payload, int $registryId, bool $lock = false): bool
    {
        $selectors = $this->selectors($payload['request']['selectors'] ?? []);
        $people = array_column(array_filter($selectors, fn ($s) => $s['type'] === 'person'), 'value');
        $names = array_column(array_filter($selectors, fn ($s) => $s['type'] === 'name'), 'value');

        return DB::table('file_registry_faces')->where('file_registry_id', $registryId)
            ->where(fn ($q) => $q->whereIn('genealogy_person_id', $people)->orWhere(fn ($q) => $q->whereNull('genealogy_person_id')->whereIn('person_name', $names)))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first(['id']) !== null;
    }

    private function selectors(array $selectors): array
    {
        if (! array_is_list($selectors) || $selectors === [] || count($selectors) > config('file_registry.profile_refresh.max_selectors')) {
            throw new RuntimeException('profile_refresh_scope_invalid');
        }
        $seen = [];
        foreach ($selectors as $selector) {
            if (! is_array($selector) || count($selector) !== 2 || ! isset($selector['type'], $selector['value'])
                || ! in_array($selector['type'], ['person', 'name'], true)
                || ($selector['type'] === 'person' && (! is_int($selector['value']) || $selector['value'] < 1))
                || ($selector['type'] === 'name' && (! is_string($selector['value']) || trim($selector['value']) === ''
                    || strlen($selector['value']) > config('file_registry.profile_refresh.max_name_bytes')
                    || $selector['value'] !== $this->normalizeName($selector['value'])
                    || preg_match('/[\x00-\x1f\x7f]/', $selector['value'])))) {
                throw new RuntimeException('profile_refresh_scope_invalid');
            }
            $key = $this->scopeKeys([$selector])[0];
            if (isset($seen[$key])) {
                throw new RuntimeException('profile_refresh_duplicate_scope');
            }
            $seen[$key] = true;
        }

        return $selectors;
    }

    private function normalizeName(string $name): string
    {
        // Match PersonFaceAppearanceProfileService's key contract exactly.
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function lockWaitSeconds(): int
    {
        $seconds = config('file_registry.profile_refresh.lock_wait_seconds');
        if (! is_int($seconds) || $seconds < 1 || $seconds > config('file_registry.profile_refresh.max_lock_wait_seconds')) {
            throw new RuntimeException('profile_refresh_lock_policy_invalid');
        }

        return $seconds;
    }

    private function withMysqlLockTimeout(\Closure $operation): array
    {
        $seconds = $this->lockWaitSeconds();
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $operation();
        }
        $original = (int) DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        DB::statement('SET SESSION innodb_lock_wait_timeout = '.$seconds);
        try {
            return $operation();
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$original);
        }
    }

    private function scopeKeys(array $selectors): array
    {
        return array_map(fn ($selector) => $selector['type'] === 'person' ? 'person:'.$selector['value']
            : 'name:'.strtolower($this->normalizeName($selector['value'])), $selectors);
    }

    private function capture(array $selectors, bool $lock = false, bool $lockVectors = true): array
    {
        $personIds = array_column(array_filter($selectors, fn ($s) => $s['type'] === 'person'), 'value');
        $names = array_column(array_filter($selectors, fn ($s) => $s['type'] === 'name'), 'value');
        $facesQuery = DB::table('file_registry_faces')->where(function ($q) use ($personIds, $names): void {
            $q->whereIn('genealogy_person_id', $personIds)->orWhere(function ($q) use ($names): void {
                $q->whereNull('genealogy_person_id')->whereIn('person_name', $names);
            });
        })->orderBy('id');
        $registryIds = [];
        if ($lock) {
            $registryIds = (clone $facesQuery)->limit(config('file_registry.profile_refresh.max_source_faces') + 1)
                ->get(['file_registry_id'])->pluck('file_registry_id')->unique()->sort()->values()->all();
            DB::table('file_registry')->whereIn('id', $registryIds)->orderBy('id')->lockForUpdate()->get();
            $facesQuery->lockForUpdate();
        }
        $faces = $facesQuery->limit(config('file_registry.profile_refresh.max_source_faces') + 1)->get();
        if ($faces->count() > config('file_registry.profile_refresh.max_source_faces')) {
            throw new RuntimeException('profile_refresh_scope_too_large');
        }
        if ($lock && array_diff($faces->pluck('file_registry_id')->all(), $registryIds) !== []) {
            throw new RuntimeException('profile_refresh_scope_changed');
        }
        $sources = ['faces' => $faces->toArray()];
        foreach (['registry' => ['file_registry', array_unique($faces->pluck('file_registry_id')->all())],
            'people' => ['genealogy_persons', $personIds]] as $key => [$table, $ids]) {
            $q = DB::table($table)->whereIn('id', $ids)->orderBy('id');
            if ($lock) {
                $q->lockForUpdate();
            }
            $sources[$key] = $q->get()->toArray();
        }
        if ($lock) {
            DB::table('person_face_profile_faces')->whereIn('file_registry_face_id', $faces->pluck('id')->all())
                ->orderBy('file_registry_face_id')->lockForUpdate()->get();
        }
        $plan = ['profiles' => [], 'identity_keys' => [], 'skipped' => 0];
        foreach ($selectors as $selector) {
            $part = $selector['type'] === 'person' ? $this->profiles->planForPerson($selector['value']) : $this->profiles->planForName($selector['value']);
            $plan['profiles'] = array_merge($plan['profiles'], $part['profiles']);
            $plan['identity_keys'] = array_values(array_unique(array_merge($plan['identity_keys'], $part['identity_keys'])));
            $plan['skipped'] += $part['skipped'];
        }
        if ($this->profiles->conflictingProfileOwners($plan) !== []) {
            throw new RuntimeException('profile_refresh_owner_conflict');
        }
        $q = DB::table('person_face_appearance_profiles')->whereIn('identity_key', $plan['identity_keys'])->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }
        $oldProfiles = $q->get();
        $q = DB::table('person_face_profile_faces')->whereIn('profile_id', $oldProfiles->pluck('id')->all())->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }
        $links = $q->limit(config('file_registry.profile_refresh.max_old_links') + 1)->get();
        if ($links->count() > config('file_registry.profile_refresh.max_old_links')) {
            throw new RuntimeException('profile_refresh_scope_too_large');
        }

        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')
            ->whereIn('file_registry_face_id', $faces->pluck('id')->all())->orderBy('id')
            ->limit(config('file_registry.profile_refresh.max_source_faces') + 1);
        if ($lock && $lockVectors) {
            $vectors->lockForUpdate();
        }
        $sources['embeddings'] = $vectors->get()->toArray();
        if (count($sources['embeddings']) > config('file_registry.profile_refresh.max_source_faces')) {
            throw new RuntimeException('profile_refresh_scope_too_large');
        }

        return ['selectors' => $selectors, 'plan' => $plan, 'sources' => $sources,
            'preimage' => ['profiles' => $oldProfiles->toArray(), 'links' => $links->toArray()]];
    }

    protected function backup(string $id, array $state): array
    {
        $root = realpath(storage_path());
        $relative = config('file_registry.profile_refresh.private_directory');
        if ($root === false || ! is_string($relative) || $relative === '' || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)) {
            throw new RuntimeException('profile_refresh_backup_unavailable');
        }
        $directory = $root;
        foreach (explode('/', $relative) as $part) {
            $directory .= '/'.$part;
            if (is_link($directory)) {
                throw new RuntimeException('profile_refresh_backup_unavailable');
            }
        }
        if ((! is_dir($directory) && ! mkdir($directory, 0700, true)) || is_link($directory) || ! chmod($directory, 0700)) {
            throw new RuntimeException('profile_refresh_backup_unavailable');
        }
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > config('file_registry.profile_refresh.max_backup_bytes')) {
            throw new RuntimeException('profile_refresh_backup_too_large');
        }
        $path = $directory.'/'.$id.'.json';
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('profile_refresh_backup_unavailable');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('profile_refresh_backup_unavailable');
            }
        } finally {
            fclose($handle);
        }
        $sha = hash('sha256', $bytes);
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== 0600 || hash_file('sha256', $path) !== $sha) {
            throw new RuntimeException('profile_refresh_backup_unavailable');
        }

        return ['path' => $path, 'sha256' => $sha];
    }

    private function caller(): array
    {
        $caller = Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT);
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys)) {
            throw new RuntimeException('trusted_framework_caller_required');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$key])
                || (str_ends_with($key, 'sha256') && ! preg_match('/^[a-f0-9]{64}$/D', $caller[$key]))) {
                throw new RuntimeException('trusted_framework_caller_required');
            }
        }

        return $caller;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
