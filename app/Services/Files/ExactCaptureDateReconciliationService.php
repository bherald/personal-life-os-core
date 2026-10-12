<?php

namespace App\Services\Files;

use App\Services\ExifWritebackService;
use App\Services\FaceProtectedVectorRefreshService;
use App\Services\GlobalFaceWritebackApprovalService;
use App\Services\NextcloudFileApiService;
use App\Services\UnifiedCli\CliFrameworkServiceWorker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Technical completion only: never dispatches a writer or changes an identity decision. */
class ExactCaptureDateReconciliationService
{
    public const ACTION = 'exact_date_commit_reconciled';

    public function plan(string $originalReceiptId): array
    {
        try {
            $proof = $this->proof($originalReceiptId);
            $state = $this->digest($proof);
            $plan = ['original_receipt_id' => $originalReceiptId, 'asset_uuid' => $proof['registry']['asset_uuid'],
                'state_sha256' => $state, 'written_sha256' => $proof['writer']['written_sha256'],
                'transition_sha256' => $proof['transition_sha256'], 'date_tags_sha256' => $proof['date_tags_sha256']];

            return ['success' => true, 'status' => 'ready', 'dry_run' => true,
                'plan' => $plan, 'plan_sha256' => $this->digest($plan), 'metadata_file_written' => false,
                'identity_decision_changed' => false];
        } catch (Throwable) {
            return $this->held();
        }
    }

    public function reconcile(string $originalReceiptId, string $expectedPlanHash, string $expectedStateHash, string $idempotencyKey, bool $dryRun = true): array
    {
        $diagnostic = (object) ['stage' => 'selector_validation'];
        try {
            if (! Str::isUuid($idempotencyKey) || strtolower($idempotencyKey) !== $idempotencyKey
                || $idempotencyKey === $originalReceiptId || ! $this->hash($expectedPlanHash) || ! $this->hash($expectedStateHash)) {
                throw new RuntimeException('Invalid selectors');
            }
            $diagnostic->stage = 'plan_validation';
            $plan = $this->plan($originalReceiptId);
            if (($plan['success'] ?? false) !== true || $plan['plan_sha256'] !== $expectedPlanHash
                || $plan['plan']['state_sha256'] !== $expectedStateHash) {
                throw new RuntimeException('Changed proof');
            }
            if ($dryRun) {
                return $plan + ['preview' => true];
            }
            $diagnostic->stage = 'trusted_caller';
            $caller = $this->caller(Context::getHidden(CliFrameworkServiceWorker::CALLER_CONTEXT));

            $diagnostic->stage = 'source_proof';
            $initial = $this->proof($originalReceiptId);
            $diagnostic->stage = 'source_lock';
            $source = fopen($initial['source_path'], 'rb');
            if (! $source || ! flock($source, LOCK_SH | LOCK_NB)) {
                if (is_resource($source)) {
                    fclose($source);
                }
                throw new RuntimeException('Source is busy');
            }
            try {
                $diagnostic->stage = 'lock_policy';

                return $this->withMysqlLockTimeout(fn (): array => DB::connection('pgsql_rag')->transaction(function () use ($originalReceiptId, $expectedStateHash, $expectedPlanHash, $idempotencyKey, $caller, $diagnostic): array {
                    $diagnostic->stage = 'pg_lock_policy';
                    $pg = DB::connection('pgsql_rag');
                    if ($pg->getDriverName() === 'pgsql') {
                        $milliseconds = $this->lockWaitSeconds() * 1000;
                        $pg->statement("SET LOCAL lock_timeout = '{$milliseconds}ms'");
                        $pg->statement("SET LOCAL statement_timeout = '{$milliseconds}ms'");
                    }

                    return DB::transaction(function () use ($originalReceiptId, $expectedStateHash, $expectedPlanHash, $idempotencyKey, $caller, $diagnostic): array {
                        // Lock original, catalog and immutable authorization before re-reading all proof surfaces.
                        $diagnostic->stage = 'registry_lock';
                        $selector = DB::table('review_desk_receipts')->where('id', $originalReceiptId)->first();
                        if (! $selector) {
                            throw new RuntimeException('Missing original');
                        }
                        DB::table('file_registry')->where('asset_uuid', $selector->reference)->lockForUpdate()->get();
                        $diagnostic->stage = 'original_lock';
                        $original = DB::table('review_desk_receipts')->where('id', $originalReceiptId)->lockForUpdate()->first();
                        if (! $original) {
                            throw new RuntimeException('Missing original');
                        }
                        $diagnostic->stage = 'decision_lock';
                        $payload = json_decode($original->payload, true, flags: JSON_THROW_ON_ERROR);
                        DB::table('file_date_review_decisions')->where('id', $payload['decision_id'] ?? 0)->lockForUpdate()->get();
                        $diagnostic->stage = 'locked_proof';
                        $proof = $this->proof($originalReceiptId, true);
                        $diagnostic->stage = 'state_validation';
                        if ($this->digest($proof) !== $expectedStateHash) {
                            throw new RuntimeException('Changed proof');
                        }
                        $result = ['success' => true, 'status' => 'completed', 'dry_run' => false,
                            'original_receipt_id' => $originalReceiptId, 'receipt_id' => $idempotencyKey,
                            'asset_uuid' => $original->reference, 'state_sha256' => $expectedStateHash,
                            'plan_sha256' => $expectedPlanHash, 'metadata_file_written' => false,
                            'identity_decision_changed' => false, 'effect_status' => 'verified_committed_writer'];
                        $completion = ['schema' => 'plos.exact_capture_date_reconciliation.v1',
                            'proof' => $proof, 'trusted_caller' => $caller, 'result' => $result];
                        $diagnostic->stage = 'receipt_lookup';
                        $prior = DB::table('review_desk_receipts')->where('id', $idempotencyKey)->lockForUpdate()->first();
                        $diagnostic->stage = 'receipt_replay';
                        if ($prior) {
                            if ($prior->action !== self::ACTION || $prior->state !== 'completed'
                                || $prior->reference !== $original->reference || $prior->request_digest !== $this->digest($completion)
                                || json_decode($prior->payload, true, flags: JSON_THROW_ON_ERROR) !== $completion) {
                                throw new RuntimeException('Key collision');
                            }

                            return $result + ['replayed' => true];
                        }
                        $diagnostic->stage = 'physical_proof';
                        if (hash_file('sha256', $proof['source_path']) !== $proof['writer']['written_sha256']
                            || hash_file('sha256', storage_path($proof['writer']['metadata_transition_receipt'])) !== $proof['transition_sha256']) {
                            throw new RuntimeException('Physical proof changed before completion');
                        }
                        $diagnostic->stage = 'receipt_insert';
                        DB::table('review_desk_receipts')->insert(['id' => $idempotencyKey,
                            'reference' => $original->reference, 'idempotency_key' => $idempotencyKey,
                            'request_digest' => $this->digest($completion), 'action' => self::ACTION, 'state' => 'completed',
                            'payload' => json_encode($completion, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                            'created_at' => now(), 'updated_at' => now()]);

                        $diagnostic->stage = 'transaction_commit';

                        return $result;
                    });
                }));
            } finally {
                flock($source, LOCK_UN);
                fclose($source);
            }
        } catch (Throwable $error) {
            return $this->held($dryRun) + [
                'failure_stage' => $diagnostic->stage, 'failure_code' => $this->failureCode($error),
            ];
        }
    }

    /** Fresh admission proof, scoped to exactly one unchanged original UNKNOWN. */
    public function isOriginalCommitted(object $original): bool
    {
        try {
            if ($original->state !== 'unknown') {
                return false;
            }
            foreach (DB::table('review_desk_receipts')->where('action', self::ACTION)
                ->where('reference', $original->reference)->where('state', 'completed')->get() as $row) {
                $completion = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
                if (($completion['result']['original_receipt_id'] ?? null) !== $original->id) {
                    continue;
                }
                if ($this->isExactCommittedReceipt($original, $row)) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** Validate this exact completion row, not another completion for the same original. */
    public function isExactCommittedReceipt(object $original, object $completionReceipt): bool
    {
        try {
            if (($original->state ?? null) !== 'unknown' || ! is_string($completionReceipt->id ?? null)) {
                return false;
            }
            $stored = DB::table('review_desk_receipts')->where('id', $completionReceipt->id)->first();
            if (! $stored || (array) $stored !== (array) $completionReceipt
                || $stored->action !== self::ACTION || $stored->state !== 'completed'
                || $stored->reference !== $original->reference || $stored->id === $original->id
                || $stored->idempotency_key !== $stored->id) {
                return false;
            }
            $completion = json_decode($stored->payload, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($completion) || ($completion['schema'] ?? null) !== 'plos.exact_capture_date_reconciliation.v1'
                || $stored->request_digest !== $this->digest($completion)
                || ($completion['result']['original_receipt_id'] ?? null) !== $original->id) {
                return false;
            }
            $proof = $this->proof($original->id, false, true);
            if ($proof['original'] !== (array) $original) {
                return false;
            }

            return is_array($completion)
                && ($completion['result']['original_receipt_id'] ?? null) === $original->id
                && $stored->request_digest === $this->digest($completion)
                && ($completion['schema'] ?? null) === 'plos.exact_capture_date_reconciliation.v1'
                && ($completion['proof'] ?? null) === $proof
                && ($completion['result']['state_sha256'] ?? null) === $this->digest($proof)
                && ($completion['result']['receipt_id'] ?? null) === $stored->id
                && ($completion['result']['effect_status'] ?? null) === 'verified_committed_writer'
                && ($completion['result']['success'] ?? null) === true
                && ($completion['result']['metadata_file_written'] ?? null) === false
                && ($completion['result']['identity_decision_changed'] ?? null) === false
                && $this->caller($completion['trusted_caller'] ?? null) === $completion['trusted_caller'];
        } catch (Throwable) {
            return false;
        }
    }

    private function proof(string $id, bool $lock = false, bool $completedRefresh = false): array
    {
        if (! Str::isUuid($id) || strtolower($id) !== $id) {
            throw new RuntimeException('Invalid original');
        }
        $original = DB::table('review_desk_receipts')->where('id', $id)->first();
        $payload = $original ? json_decode($original->payload, true, flags: JSON_THROW_ON_ERROR) : [];
        $request = $payload['request'] ?? [];
        $writer = $payload['writer_result'] ?? [];
        if (! $original || $original->action !== 'write_exact_capture_date' || $original->state !== 'unknown'
            || $original->idempotency_key !== $id || ($payload['schema'] ?? null) !== 'plos.exact_capture_date_write.v1'
            || ($payload['effect_status'] ?? null) !== 'physical_outcome_unknown'
            || $original->request_digest !== $this->digest($request) || ($request['idempotency_key'] ?? null) !== $id
            || ($request['asset_uuid'] ?? null) !== $original->reference || ($request['precision'] ?? null) !== 'exact'
            || ($writer['success'] ?? null) !== true || ! empty($writer['repair_required'])
            || ! empty($writer['unchanged']) || ! empty($writer['failure_code'])
            || ! $this->hash($writer['written_sha256'] ?? null) || ! $this->hash($writer['backup_sha256'] ?? null)
            || ($request['source_sha256'] ?? null) !== $writer['backup_sha256']
            || $writer['written_sha256'] === $writer['backup_sha256']) {
            throw new RuntimeException('Unproven committed writer');
        }
        $this->caller($payload['trusted_caller'] ?? null);
        $registry = DB::table('file_registry')->where('id', $request['file_registry_id'] ?? 0)->first();
        $decision = DB::table('file_date_review_decisions')->where('id', $payload['decision_id'] ?? 0)->first();
        if (! $registry || $registry->asset_uuid !== $original->reference || $registry->status !== 'active'
            || $registry->content_hash !== $writer['written_sha256'] || (int) $registry->exif_written !== 1
            || (int) $registry->date_needs_review !== 0 || $registry->date_taken_source !== 'user_manual'
            || $registry->date_taken_precision !== 'exact' || substr($registry->date_taken, 0, 10) !== ($request['capture_date'] ?? null)
            || (int) $registry->is_scan !== ($request['is_scan'] ?? null) || $registry->scan_date !== ($request['scan_date'] ?? null)
            || hash('sha256', $registry->current_path) !== ($request['registry_path_sha256'] ?? null)
            || ! $decision || $decision->decision !== 'approved' || $decision->decision_scope !== $original->action
            || (int) $decision->file_registry_id !== (int) $registry->id || $decision->authorization_receipt_id !== $id
            || $decision->authorized_source_sha256 !== $request['source_sha256'] || $decision->authorized_asset_uuid !== $registry->asset_uuid
            || (int) $decision->metadata_file_written !== 1 || $decision->proposed_precision !== 'exact'
            || substr($decision->proposed_date, 0, 10) !== $request['capture_date']
            || DB::table('file_date_review_decisions')->where('file_registry_id', $registry->id)->where('id', '>', $decision->id)->exists()) {
            throw new RuntimeException('Changed authorization or catalog');
        }
        $relative = $writer['metadata_transition_receipt'] ?? '';
        $root = realpath(storage_path('app/private'));
        $packet = is_string($relative) ? realpath(storage_path($relative)) : false;
        if (! $root || ! $packet || is_link(storage_path($relative)) || ! str_starts_with($packet, $root.'/')
            || ! is_file($packet) || filesize($packet) > 1048576) {
            throw new RuntimeException('Unsafe transition');
        }
        $packetHash = hash_file('sha256', $packet);
        $transition = json_decode(file_get_contents($packet), true, flags: JSON_THROW_ON_ERROR);
        $before = $transition['registry_before'] ?? [];
        if (($before['content_hash'] ?? null) !== $writer['backup_sha256']) {
            throw new RuntimeException('Transition registry preimage differs');
        }
        foreach (['id', 'asset_uuid', 'current_path', 'date_taken', 'date_taken_precision', 'date_taken_source', 'date_taken_confidence', 'date_taken_reasoning', 'date_needs_review', 'exif_written', 'is_scan', 'scan_date'] as $key) {
            if (($before[$key] ?? null) != $registry->{$key}) {
                throw new RuntimeException('Transition catalog mismatch');
            }
        }
        $date = $transition['date_metadata'] ?? [];
        $targetTags = $date['after_tags'] ?? [];
        $capture = str_replace('-', ':', $request['capture_date']);
        if (($targetTags['IPTC:DateCreated'] ?? null) !== $capture
            || ($targetTags['XMP-photoshop:DateCreated'] ?? null) !== $capture
            || ($targetTags['XMP-dc:Source'] ?? null) !== 'plos:operator-confirmed-date-only'
            || ! array_key_exists('ExifIFD:DateTimeOriginal', $targetTags) || $targetTags['ExifIFD:DateTimeOriginal'] !== null
            || ! array_key_exists('XMP-exif:DateTimeOriginal', $targetTags) || $targetTags['XMP-exif:DateTimeOriginal'] !== null
            || ! array_key_exists('IPTC:TimeCreated', $targetTags) || $targetTags['IPTC:TimeCreated'] !== null
            || (isset($request['restore_target_tags_sha256']) && $request['restore_target_tags_sha256'] !== ($date['after_tags_sha256'] ?? null))) {
            throw new RuntimeException('Approved date-only target differs');
        }

        $path = $transition['source_path'] ?? '';
        if (app(NextcloudFileApiService::class)->localPath($registry->current_path) !== $path) {
            throw new RuntimeException('Current registered source path differs');
        }
        $exif = app(ExifWritebackService::class);
        $verified = $exif->readVerifiedDateTagTransition($relative, (int) $registry->id, $registry->asset_uuid,
            $registry->current_path, $writer['backup_sha256'], $writer['written_sha256'],
            $request['date_tags_sha256'], $date['after_tags_sha256'] ?? '', $path, $writer['written_sha256']);
        $current = $exif->readExactCaptureDateEvidence($path, $writer['written_sha256']);
        if (($verified['success'] ?? false) !== true || ($current['success'] ?? false) !== true
            || ($current['date_tags'] ?? null) !== ($date['after_tags'] ?? null)
            || ($current['date_tags_sha256'] ?? null) !== ($date['after_tags_sha256'] ?? null)
            || (int) $registry->file_size !== filesize($path) || hash_file('sha256', $packet) !== $packetHash) {
            throw new RuntimeException('Changed physical proof');
        }
        $faces = DB::table('file_registry_faces')->where('file_registry_id', $registry->id)
            ->where('source_current', 1)->whereNull('superseded_at')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        $vectors = DB::connection('pgsql_rag')->table('face_embeddings')->where('file_registry_id', $registry->id)
            ->where('source_current', true)->whereNull('superseded_at')->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        $this->advancedRows($faces, $transition['faces_before'] ?? null, $writer, 'faces_advanced');
        if ($completedRefresh) {
            $beforeVectors = $transition['vectors_before'] ?? [];
            $beforeByFace = array_column($beforeVectors, null, 'file_registry_face_id');
            $beforeById = array_column($beforeVectors, null, 'id');
            foreach ($vectors as &$vector) {
                if (isset($beforeById[$vector['id']])) {
                    continue;
                }
                $prior = $beforeByFace[$vector['file_registry_face_id'] ?? null] ?? null;
                $preimage = is_array($prior)
                    ? app(FaceProtectedVectorRefreshService::class)->completedRefreshPreimage((int) $prior['id'], (int) $vector['id']) : null;
                if (! is_array($preimage) || (int) ($preimage['file_registry_id'] ?? 0) !== (int) $registry->id
                    || ($preimage['source_sha256'] ?? null) !== $writer['written_sha256']) {
                    throw new RuntimeException('Unproven completed vector replacement');
                }
                // Backups use canonical key order; the original proof hashes database column order.
                if (array_diff(array_keys($preimage), array_keys($vector)) !== []) {
                    throw new RuntimeException('Changed replacement vector schema');
                }
                $ordered = [];
                foreach (array_keys($vector) as $key) {
                    if (array_key_exists($key, $preimage)) {
                        $ordered[$key] = $preimage[$key];
                    }
                }
                $vector = $ordered;
            }
            unset($vector);
            usort($vectors, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        }
        $vectors = $this->normalizeNullableVectorSchema($vectors, $transition['vectors_before'] ?? null);
        $this->advancedRows($vectors, $transition['vectors_before'] ?? null, $writer, 'vectors_advanced');
        $approvals = [];
        foreach ($faces as $face) {
            if (! empty($face['global_identity_uuid'])) {
                if (! app(GlobalFaceWritebackApprovalService::class)->approvedFace((int) $face['id'])) {
                    throw new RuntimeException('Current approval unavailable');
                }
                $identity = DB::table('global_face_identities')->where('identity_uuid', $face['global_identity_uuid'])->first();
                if (! $identity) {
                    throw new RuntimeException('Missing current identity');
                }
                $approvals[] = ['identity' => (array) $identity,
                    'face_approval' => (array) DB::table('review_desk_receipts')->where('id', $face['global_identity_approval_receipt_id'])->first(),
                    'name_approval' => (array) DB::table('review_desk_receipts')->where('id', $identity->approval_receipt_id)->first(),
                    'source_transitions' => DB::table('review_desk_receipts')->where('reference', DB::table('review_desk_receipts')->where('id', $face['global_identity_approval_receipt_id'])->value('reference'))
                        ->where('action', GlobalFaceWritebackApprovalService::TRANSITION_ACTION)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
            }
        }
        $queues = DB::table('genealogy_face_match_queue')->whereIn('file_registry_face_id', array_column($faces, 'id'))
            ->orWhereIn('media_id', DB::table('genealogy_media')->select('id')->where('nextcloud_path', $registry->current_path)->orWhere('original_path', $registry->current_path))
            ->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->map(fn ($row) => (array) $row)->all();
        // Preserve all individual decisions and immutable historical confirmations; accept only writer-derived appended source proofs.
        $oldQueues = $transition['queue_before'] ?? null;
        if (! is_array($oldQueues) || count($oldQueues) !== count($queues)) {
            throw new RuntimeException('Changed queue coverage');
        }
        $oldById = array_column($oldQueues, null, 'id');
        foreach ($queues as $queue) {
            $old = $oldById[$queue['id']] ?? [];
            $oldDetails = json_decode($old['match_details'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $details = json_decode($queue['match_details'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $oldConfirmations = $oldDetails['operator_confirmations'] ?? [];
            $confirmations = $details['operator_confirmations'] ?? [];
            if (! is_array($confirmations) || ! array_is_list($confirmations)
                || array_slice($confirmations, 0, count($oldConfirmations)) !== $oldConfirmations) {
                throw new RuntimeException('Changed operator history');
            }
            foreach (array_slice($confirmations, count($oldConfirmations)) as $derived) {
                if (($derived['source_sha256'] ?? null) !== $writer['written_sha256']
                    || ($derived['inherited_source_sha256'] ?? null) !== $writer['backup_sha256']
                    || ($derived['metadata_transition_receipt'] ?? null) !== $relative
                    || ($derived['proof_scope'] ?? null) !== 'verified_metadata_only; original operator decision unchanged') {
                    throw new RuntimeException('Unproven derived confirmation');
                }
                $base = $derived;
                $base['source_sha256'] = $writer['backup_sha256'];
                unset($base['inherited_source_sha256'], $base['metadata_transition_receipt'], $base['proof_scope']);
                if (! in_array($base, $oldConfirmations, true)) {
                    throw new RuntimeException('New identity decision');
                }
            }
            $oldTransitions = $oldDetails['metadata_source_transitions'] ?? [];
            $newTransitions = $details['metadata_source_transitions'] ?? [];
            if ($newTransitions !== $oldTransitions && $newTransitions !== array_merge($oldTransitions, [[
                'before_sha256' => $writer['backup_sha256'], 'after_sha256' => $writer['written_sha256'],
                'receipt' => $relative, 'identity_decision_changed' => false]])) {
                throw new RuntimeException('Changed transition history');
            }
            if (($details['source_sha256'] ?? null) !== ($oldDetails['source_sha256'] ?? null)
                && ! (($oldDetails['source_sha256'] ?? null) === $writer['backup_sha256'] && ($details['source_sha256'] ?? null) === $writer['written_sha256'])) {
                throw new RuntimeException('Changed queue source');
            }
            foreach (['operator_confirmations', 'metadata_source_transitions', 'source_sha256'] as $key) {
                unset($oldDetails[$key], $details[$key]);
            }
            unset($old['match_details'], $queue['match_details']);
            if ($old != $queue || $oldDetails !== $details) {
                throw new RuntimeException('Changed queue decision');
            }
        }

        return ['original' => (array) $original, 'decision' => (array) $decision, 'registry' => array_intersect_key((array) $registry, array_flip([
            'id', 'asset_uuid', 'current_path', 'status', 'mime_type', 'content_hash', 'file_size', 'exif_written',
            'date_taken', 'date_taken_source', 'date_taken_precision', 'date_taken_confidence',
            'date_taken_reasoning', 'date_needs_review', 'is_scan', 'scan_date',
        ])), 'source_path' => $path,
            'writer' => $writer, 'transition_sha256' => $packetHash, 'date_tags_sha256' => $current['date_tags_sha256'],
            'faces_sha256' => $this->digest($faces), 'vectors_sha256' => $this->digest($vectors),
            'approvals_sha256' => $this->digest($approvals), 'queues_sha256' => $this->digest($queues)];
    }

    private function advancedRows(array $current, mixed $before, array $writer, string $countKey): void
    {
        if (! is_array($before) || count($before) !== count($current) || count($current) !== ($writer[$countKey] ?? null)) {
            throw new RuntimeException('Partial provenance');
        }
        $indexed = array_column($before, null, 'id');
        foreach ($current as $row) {
            $old = $indexed[$row['id']] ?? null;
            if (! is_array($old) || ($old['source_sha256'] ?? null) !== $writer['backup_sha256']
                || ($row['source_sha256'] ?? null) !== $writer['written_sha256']) {
                throw new RuntimeException('Changed source');
            }
            foreach (['source_sha256', 'updated_at'] as $key) {
                unset($old[$key], $row[$key]);
            }
            if ($old != $row) {
                throw new RuntimeException('Changed identity, geometry or vector');
            }
        }
    }

    private function normalizeNullableVectorSchema(array $current, mixed $before): array
    {
        if (! is_array($before)) {
            return $current;
        }

        $beforeById = array_column($before, null, 'id');
        foreach ($current as &$row) {
            $prior = $beforeById[$row['id'] ?? null] ?? null;
            if (is_array($prior) && array_key_exists('generation_provenance', $row)
                && $row['generation_provenance'] === null && ! array_key_exists('generation_provenance', $prior)) {
                unset($row['generation_provenance']);
            }
        }
        unset($row);

        return $current;
    }

    private function lockWaitSeconds(): int
    {
        $seconds = config('file_registry.profile_refresh.lock_wait_seconds');
        if (! is_int($seconds) || $seconds < 1 || $seconds > config('file_registry.profile_refresh.max_lock_wait_seconds')) {
            throw new RuntimeException('Invalid existing reconciliation lock policy');
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

    private function caller(mixed $caller): array
    {
        $keys = ['session_id', 'turn_id', 'action_id', 'arguments_sha256', 'approval_scope_sha256'];
        if (! is_array($caller) || count($caller) !== count($keys) || array_diff(array_keys($caller), $keys)) {
            throw new RuntimeException('Missing trusted caller');
        }
        foreach ($keys as $key) {
            if (! is_string($caller[$key]) || $caller[$key] === '' || strlen($caller[$key]) > 255
                || preg_match('/[\x00-\x20\x7f]/', $caller[$key]) || (str_ends_with($key, 'sha256') && ! $this->hash($caller[$key]))) {
                throw new RuntimeException('Invalid caller');
            }
        }

        return $caller;
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    /** Codes only: never expose exception messages, SQL, filesystem paths or caller material. */
    private function failureCode(Throwable $error): string
    {
        if ($error instanceof QueryException) {
            $sqlstate = $error->errorInfo[0] ?? null;
            $driver = $error->errorInfo[1] ?? null;

            return match (true) {
                $sqlstate === '22001' || (int) $driver === 1406 => 'receipt_field_width_exceeded',
                $sqlstate === '55P03' || (int) $driver === 1205 => 'database_lock_timeout',
                $sqlstate === '57014' => 'database_statement_timeout',
                $sqlstate === '40P01' || (int) $driver === 1213 => 'database_deadlock',
                default => 'database_error',
            };
        }

        return match (true) {
            $error instanceof \JsonException => 'invalid_evidence_json',
            $error instanceof RuntimeException => 'guard_held',
            default => 'technical_failure',
        };
    }

    private function held(bool $dryRun = true): array
    {
        return ['success' => false, 'status' => 'held', 'dry_run' => $dryRun,
            'error' => 'Committed writer, immutable approval or current provenance proof is unavailable or changed.',
            'metadata_file_written' => false, 'identity_decision_changed' => false];
    }
}
