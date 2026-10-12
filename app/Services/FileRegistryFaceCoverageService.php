<?php

namespace App\Services;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Anonymous coverage adapter: reuses source inspection custody and the generation ledger. */
class FileRegistryFaceCoverageService extends FileRegistryImageAuditService
{
    public function policy(): array
    {
        $policy = $this->systemConfig->get('files.registry_face_coverage_policy');
        $policy = is_string($policy) ? json_decode($policy, true, flags: JSON_THROW_ON_ERROR) : $policy;
        $defaults = config('file_registry.face_coverage.policy');
        if (! is_array($policy) || ! is_array($defaults) || array_diff_key($policy, $defaults) !== [] || array_diff_key($defaults, $policy) !== []) {
            throw new RuntimeException('face_coverage_policy_invalid');
        }
        foreach ($defaults as $key => $value) {
            if (is_int($value) && (! is_int($policy[$key]) || $policy[$key] < ($key === 'historical_start_hour' ? 0 : 1) || $policy[$key] > config('file_registry.face_coverage.bounds.'.$key, $value))) {
                throw new RuntimeException('face_coverage_policy_invalid');
            }
        }
        if (! is_string($policy['timezone']) || ! in_array($policy['timezone'], timezone_identifiers_list(), true)
            || $policy['historical_start_hour'] >= $policy['historical_end_hour']
            || $policy['max_seconds'] <= config('file_registry.face_coverage.minimum_row_budget_seconds')
            || ! is_string($policy['timeout_binary']) || ! is_executable($policy['timeout_binary'])
            || $policy['max_seconds'] >= $policy['hard_timeout_seconds'] || $policy['hard_timeout_seconds'] >= $policy['outer_timeout_seconds']
            || $policy['outer_timeout_seconds'] >= $policy['lease_seconds'] || $policy['outer_timeout_seconds'] >= $policy['timeout_minutes'] * 60) {
            throw new RuntimeException('face_coverage_policy_invalid');
        }

        return $policy;
    }

    /** Run IDs are not approval: validate actual scheduler PID ancestry and its live custody. */
    public function schedulerAuthority(?int $runId = null): array
    {
        $policy = $this->policy();
        $storedPolicy = DB::table('system_configs')->where('section', 'files')->where('config_key', 'registry_face_coverage_policy')
            ->lockForUpdate()->sole();
        if ($this->encode(json_decode($storedPolicy->config_value, true, flags: JSON_THROW_ON_ERROR)) !== $this->encode($policy)) {
            throw new RuntimeException('face_coverage_live_policy_changed');
        }
        $job = DB::table('scheduled_jobs')->where('name', config('file_registry.face_coverage.schedule.name'))->lockForUpdate()->first();
        if (! $job || $job->command !== config('file_registry.face_coverage.schedule.command') || (int) $job->enabled !== 1
            || (int) $job->run_in_background !== 1 || (int) $job->without_overlapping !== 1 || (int) $job->max_parallel !== 1
            || (int) $job->timeout_minutes !== $policy['timeout_minutes'] || (int) $job->timeout_locked !== 1) {
            throw new RuntimeException('face_coverage_schedule_not_admitted');
        }
        $query = DB::table('scheduled_job_runs')->where('scheduled_job_id', $job->id)->where('status', 'running')->whereNull('completed_at')->lockForUpdate();
        $run = $runId === null ? $query->where('pid', getmypid())->sole() : $query->where('id', $runId)->sole();
        if (! $this->schedulerIsAncestor((int) $run->pid)) {
            throw new RuntimeException('face_coverage_scheduler_process_invalid');
        }
        $custody = DB::table('task_custody_records')->where('surface', TaskCustodyService::SURFACE_SCHEDULED_JOB)
            ->where('surface_ref', ScheduledJobService::custodySurfaceRef((int) $job->id, (int) $run->id))
            ->whereNull('released_at')->lockForUpdate()->sole();
        $owner = app(TaskCustodyService::class)->scheduledJobOwnerToken((int) $run->pid, (int) $run->id);
        if ($custody->owner_token !== $owner || ! CarbonImmutable::parse($custody->expires_at)->isFuture()
            || CarbonImmutable::parse($run->started_at)->addSeconds($policy['timeout_minutes'] * 60)->isPast()) {
            throw new RuntimeException('face_coverage_scheduler_custody_invalid');
        }

        return ['job_id' => (int) $job->id, 'run_id' => (int) $run->id, 'pid' => (int) $run->pid,
            'owner_token' => $owner, 'policy_sha256' => hash('sha256', $this->encode($policy))];
    }

    protected function schedulerIsAncestor(int $pid): bool
    {
        $current = getmypid();
        for ($depth = 0; $depth < 16 && $current > 1; $depth++) {
            if ($current === $pid) {
                return true;
            }
            $stat = @file_get_contents('/proc/'.$current.'/stat');
            if (! is_string($stat) || preg_match('/^\d+ \(.*\) \S+ (\d+) /s', $stat, $matches) !== 1) {
                return false;
            }
            $current = (int) $matches[1];
        }

        return false;
    }

    public function assertAuthority(array $authority): void
    {
        $lease = $authority['lease'] ?? null;
        if (! is_array($lease)) {
            throw new RuntimeException('face_coverage_lease_required');
        }
        $actual = $this->schedulerAuthority($authority['run_id'] ?? 0);
        if ($actual !== array_diff_key($authority, ['lease' => true, 'generation' => true])) {
            throw new RuntimeException('face_coverage_authority_changed');
        }
        $status = $this->state->assertCoverageLease($lease);
        $expected = $this->coverageBinding($status['binding']['coverage_lane']);
        if ($status['binding'] !== $expected) {
            throw new RuntimeException('face_coverage_source_binding_changed');
        }
        $this->samePolicy($status, $this->policy());
        if ($status['binding']['coverage_lane'] === 'historical' && ! $this->historicalWindowOpen()) {
            throw new RuntimeException('face_coverage_overnight_window_closed');
        }
        if (isset($authority['generation'])) {
            $generation = $authority['generation'];
            $selector = $status['next_lane'];
            $cursor = $status['checkpoint'][$selector] ?? null;
            if (! is_array($generation) || ! is_array($cursor) || ($generation['selector'] ?? null) !== $selector
                || ($generation['source_id'] ?? null) !== $this->sources->nextSourceId($selector, $cursor['after_id'], $cursor['cutoff'])) {
                throw new RuntimeException('face_coverage_generation_selector_changed');
            }
            $source = $generation['source'] ?? null;
            $registry = $selector === 'registry' ? ($source['source_snapshot'] ?? null) : ($source['registry_snapshot'] ?? null);
            if (! is_array($source) || ! is_array($registry)
                || ($source['source_kind'] ?? null) !== $selector || ($source['source_id'] ?? null) !== $generation['source_id']
                || ($source['observed_sha256'] ?? null) !== ($generation['source_sha256'] ?? null)
                || ($registry['asset_uuid'] ?? null) !== ($generation['asset_uuid'] ?? null)
                || (int) ($registry['id'] ?? 0) !== ($generation['file_registry_id'] ?? null)
                || ($registry['current_path'] ?? null) !== ($source['nextcloud_path'] ?? null)
                || ! $this->verifySource($source, $expected)) {
                throw new RuntimeException('face_coverage_selected_asset_changed');
            }
        }
    }

    protected function bindingMatches(array $binding): bool
    {
        return in_array($binding['coverage_lane'] ?? null, ['incoming', 'historical'], true)
            && $binding === $this->coverageBinding($binding['coverage_lane']);
    }

    protected function coverageBinding(string $lane): array
    {
        return $this->canonical(array_replace($this->binding(), ['selector_version' => config('file_registry.face_coverage.selector_version'),
            'coverage_lane' => $lane, 'policy_sha256' => hash('sha256', $this->encode($this->policy()))]));
    }

    protected function historicalWindowOpen(): bool
    {
        $policy = $this->policy();
        $hour = CarbonImmutable::now($policy['timezone'])->hour;

        return $hour >= $policy['historical_start_hour'] && $hour < $policy['historical_end_hour'];
    }

    public function status(): array
    {
        $report = ['state' => 'ready', 'processed' => 0, 'findings' => 0, 'identity_decisions_performed' => false,
            'completion_scope' => 'captured_maximum_selector_ids', 'lanes' => []];
        foreach (['historical', 'incoming'] as $lane) {
            $binding = $this->coverageBinding($lane);
            $record = DB::table('file_registry_image_audit_checkpoints')->where('binding_key', hash('sha256', $this->encode($binding)))->first(['id']);
            $status = $record ? $this->state->status((int) $record->id) : null;
            $report['lanes'][$lane] = $status ? ['state' => $status['state'], 'captured_selector_limits' => $status['captured_selector_limits'],
                'registry_epoch_complete' => $status['registry_epoch_complete'], 'media_epoch_complete' => $status['media_epoch_complete'],
                'counters' => $status['checkpoint']['counters']] : ['state' => 'ready'];
            $report['lanes'][$lane]['observed_coverage'] = ['generated' => 0, 'unchanged' => 0, 'held' => 0, 'previewed' => 0];
            $report['lanes'][$lane]['held_reasons'] = [];
            if ($record) {
                $counts = DB::table('file_registry_image_audit_observations')->where('checkpoint_id', $record->id)
                    ->selectRaw('JSON_EXTRACT(source_fingerprint, ?) AS coverage_outcome', ['$.coverage.outcome'])
                    ->selectRaw('JSON_EXTRACT(source_fingerprint, ?) AS coverage_reason', ['$.coverage.reason'])
                    ->selectRaw('COUNT(*) AS total')->groupBy('coverage_outcome', 'coverage_reason')->get();
                foreach ($counts as $count) {
                    $outcome = is_string($count->coverage_outcome) ? (json_decode($count->coverage_outcome, true) ?? $count->coverage_outcome) : null;
                    if (array_key_exists($outcome ?? '', $report['lanes'][$lane]['observed_coverage'])) {
                        $report['lanes'][$lane]['observed_coverage'][$outcome] += (int) $count->total;
                    }
                    if ($outcome === 'held') {
                        $reason = is_string($count->coverage_reason) ? (json_decode($count->coverage_reason, true) ?? $count->coverage_reason) : null;
                        $reason = is_string($reason) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $reason) === 1 ? $reason : 'unclassified_hold';
                        $report['lanes'][$lane]['held_reasons'][$reason] = ($report['lanes'][$lane]['held_reasons'][$reason] ?? 0) + (int) $count->total;
                    }
                }
                $report['lanes'][$lane]['readiness_assessments'] = DB::table('file_registry_image_audit_observations')
                    ->where('checkpoint_id', $record->id)->whereNotNull('source_fingerprint->coverage_revisit->state')->count();
                $report['lanes'][$lane]['ready_for_read_plan'] = DB::table('file_registry_image_audit_observations')
                    ->where('checkpoint_id', $record->id)->where('source_fingerprint->coverage_revisit->state', 'ready_for_read_plan')->count();
                $report['lanes'][$lane]['observed_coverage']['previewed'] = DB::table('file_registry_image_audit_observations')
                    ->where('checkpoint_id', $record->id)
                    ->whereRaw("JSON_EXTRACT(source_fingerprint, '$.coverage.preview_available') = true")->count();
            }
            $report['findings'] += $status ? array_sum(array_column($status['checkpoint']['counters'], 'findings')) : 0;
        }

        return $report;
    }

    public function runScheduledSlice(int $runId): array
    {
        $authority = $this->schedulerAuthority($runId);
        $policy = $this->policy();
        $leases = [];
        $processed = 0;
        $outcomes = ['generated' => 0, 'unchanged' => 0, 'held' => 0, 'previewed' => 0];
        $revisits = ['assessed' => 0, 'ready_for_read_plan' => 0, 'held' => 0];
        try {
            // Seed both selectors from one exact initial snapshot; incoming remains independent of the historical backlog.
            $cutoffs = $this->sources->captureCutoffs();
            foreach (['historical', 'incoming'] as $lane) {
                $binding = $this->coverageBinding($lane);
                $initialCutoffs = $lane === 'incoming'
                    ? $this->state->status($leases['historical']['checkpoint_id'])['captured_selector_limits'] : $cutoffs;
                $ensured = $this->state->ensure($binding, $initialCutoffs, $policy);
                $claim = $this->state->claim($ensured['status']['checkpoint_id'], $ensured['status']['revision']);
                if ($claim['lease'] === null) {
                    return ['state' => 'busy', 'slice_processed' => 0, 'outcomes' => $outcomes, 'identity_decisions_performed' => false];
                }
                $leases[$lane] = $claim['lease'];
                $advanced = $this->state->advanceCoverageWindow($leases[$lane], $cutoffs, $lane === 'incoming');
                $leases[$lane] = $advanced['lease'];
            }
            $deadline = hrtime(true) / 1e9 + $policy['max_seconds'];
            $first = $this->state->status($leases['historical']['checkpoint_id'])['checkpoint']['coverage_first'];
            $historicalWindowOpen = $this->historicalWindowOpen();
            for ($turn = 0; $processed < $policy['max_rows'] && hrtime(true) / 1e9 < $deadline; $turn++) {
                $pass = $turn % 2 === 0 ? $first : ($first === 'incoming' ? 'historical' : 'incoming');
                if ($pass === 'historical' && ! $historicalWindowOpen) {
                    if ($this->state->status($leases['incoming']['checkpoint_id'])['next_lane'] === null) {
                        break;
                    }

                    continue;
                }
                $lease = $leases[$pass];
                $status = $this->state->status($lease['checkpoint_id']);
                $selector = $status['next_lane'];
                if ($selector === null) {
                    $other = $this->state->status($leases[$pass === 'incoming' ? 'historical' : 'incoming']['checkpoint_id']);
                    if ($other['next_lane'] === null || ! $historicalWindowOpen) {
                        break;
                    }

                    continue;
                }
                $this->assertAuthority($authority + ['lease' => $lease]);
                $cursor = $status['checkpoint'][$selector];
                $remaining = (int) floor($deadline - hrtime(true) / 1e9);
                if ($remaining < config('file_registry.face_coverage.minimum_row_budget_seconds')) {
                    break;
                }
                $batch = $selector === 'registry' ? $this->sources->auditRegistryBatch($cursor['after_id'], 1, min(120, $remaining), $cursor['cutoff'])
                    : $this->sources->auditMediaBatch($cursor['after_id'], 1, min(120, $remaining), $cursor['cutoff']);
                if (($batch['status'] ?? null) === 'blocked' || ! is_array($batch['rows'] ?? null)) {
                    throw new RuntimeException('face_coverage_source_batch_held');
                }
                if ($batch['rows'] === []) {
                    if (empty($batch['complete'])) {
                        break;
                    }
                    $done = $this->state->completeLane($lease, $selector, fn ($kind, $position) => ! $this->sources->hasRemaining($kind, $position['after_id'], $position['cutoff']));
                    $leases[$pass] = $done['lease'];

                    continue;
                }
                $raw = $batch['rows'][0];
                $binding = $this->coverageBinding($pass);
                if (! $this->verifySource($raw, $binding)) {
                    throw new RuntimeException('face_coverage_source_changed');
                }
                $coverage = $this->cover($raw, $authority + ['lease' => $lease]);
                $raw['coverage'] = $coverage;
                // Coverage admission holds remain in this observation; only genuine source findings enter source recovery review.
                $row = array_intersect_key($raw, array_flip(['source_kind', 'source_id', 'tree_id', 'source_snapshot',
                    'observed_sha256', 'source_fingerprint', 'classification', 'content_format', 'format_classification',
                    'format_evidence', 'successful', 'finding_required', 'retry', 'coverage']));
                $row['source_fingerprint'] ??= [];
                $published = $this->state->publishRow($lease, $selector, $row,
                    fn () => $this->verifySource($raw, $binding),
                    fn ($inspection, $previous, $assessment) => $this->retainFinding($raw, $previous, $assessment, $binding, $policy));
                $leases[$pass] = $published['lease'];
                $outcomes[$coverage['outcome']]++;
                if (! empty($coverage['preview_available'])) {
                    $outcomes['previewed']++;
                }
                $processed++;
            }

            $incoming = $this->state->status($leases['incoming']['checkpoint_id']);
            $historical = $this->state->status($leases['historical']['checkpoint_id']);
            $frontierExhausted = $incoming['registry_epoch_complete'] && $incoming['media_epoch_complete']
                && (! $historicalWindowOpen || ($historical['registry_epoch_complete'] && $historical['media_epoch_complete']));
            if ($frontierExhausted && $processed < $policy['max_rows']
                && $this->remainingSliceSeconds($deadline) >= config('file_registry.face_coverage.minimum_row_budget_seconds')) {
                $candidates = $this->state->coverageRevisitCandidates($leases['incoming'], $policy['max_rows'] - $processed);
                foreach ($candidates as $candidate) {
                    if ($processed + $revisits['assessed'] >= $policy['max_rows']
                        || $this->remainingSliceSeconds($deadline) < config('file_registry.face_coverage.minimum_row_budget_seconds')) {
                        break;
                    }
                    $lease = $leases['incoming'];
                    $historicalLease = $leases['historical'];
                    $this->assertAuthority($authority + ['lease' => $lease]);
                    [$result, $verify] = $this->assessHeldCoverage($candidate, $deadline);
                    $published = $this->state->publishCoverageRevisit($lease, $candidate, $result, function () use ($verify, $authority, $lease, $historicalLease, $deadline): bool {
                        $this->assertAuthority($authority + ['lease' => $lease]);
                        if ($this->historicalWindowOpen()) {
                            $this->assertAuthority($authority + ['lease' => $historicalLease]);
                            $historicalNow = $this->state->status($historicalLease['checkpoint_id']);
                            if (! $historicalNow['registry_epoch_complete'] || ! $historicalNow['media_epoch_complete']) {
                                return false;
                            }
                        }

                        return hrtime(true) / 1e9 < $deadline && $verify();
                    });
                    $leases['incoming'] = $published['lease'];
                    $revisits['assessed']++;
                    $revisits[$published['readiness_state'] === 'ready_for_read_plan' ? 'ready_for_read_plan' : 'held']++;
                }
            }

            return $this->status() + ['outcomes' => $outcomes, 'slice_processed' => $processed, 'revisits' => $revisits];
        } catch (Throwable $error) {
            if (InferenceAdmissionException::isAdmissionFailure($error)) {
                throw $error;
            }

            return ['state' => 'held', 'reason' => 'face_coverage_slice_held', 'slice_processed' => $processed,
                'outcomes' => $outcomes, 'revisits' => $revisits, 'identity_decisions_performed' => false];
        } finally {
            foreach ($leases as $lease) {
                try {
                    $this->state->release($lease);
                } catch (Throwable) {
                    // Successor/expiry recovery owns stale leases.
                }
            }
        }
    }

    protected function remainingSliceSeconds(float $deadline): int
    {
        return (int) floor($deadline - hrtime(true) / 1e9);
    }

    /** Private agent discovery only. Retained measurements never authorize a native action. */
    public function listIncomingCoverageReadiness(int $afterObservationId = 0, int $limit = 1): array
    {
        if ($afterObservationId < 0 || $limit < 1 || $limit > $this->policy()['max_rows']) {
            throw new RuntimeException('face_coverage_readiness_page_invalid');
        }
        $binding = $this->coverageBinding('incoming');
        $checkpoint = DB::table('file_registry_image_audit_checkpoints')->where('binding_key', hash('sha256', $this->encode($binding)))->first();
        $rows = $checkpoint ? DB::table('file_registry_image_audit_observations')->where('checkpoint_id', $checkpoint->id)
            ->where('id', '>', $afterObservationId)->whereNotNull('source_fingerprint->coverage_revisit->state')
            ->orderBy('id')->limit($limit)->get() : collect();
        $items = [];
        foreach ($rows as $row) {
            if (strlen($row->source_fingerprint) > $this->policy()['max_observation_bytes']
                || strlen($row->source_snapshot) > $this->policy()['max_observation_bytes']) {
                throw new RuntimeException('face_coverage_readiness_evidence_held');
            }
            $fingerprint = json_decode($row->source_fingerprint, true, flags: JSON_THROW_ON_ERROR);
            $revisit = $fingerprint['coverage_revisit'];
            unset($fingerprint['coverage_revisit']);
            $original = ['source_kind' => $row->source_kind, 'source_id' => (int) $row->source_id,
                'tree_id' => $row->tree_id === null ? null : (int) $row->tree_id,
                'source_snapshot' => json_decode($row->source_snapshot, true, flags: JSON_THROW_ON_ERROR),
                'observed_sha256' => $row->observed_sha256, 'source_fingerprint' => $fingerprint,
                'epoch_uuid' => $row->epoch_uuid, 'observed_at' => $row->observed_at];
            $originalJson = $this->encode($original);
            if (strlen($originalJson) > $this->policy()['max_observation_bytes']
                || ! is_string($revisit['original_observation_sha256'] ?? null)
                || ! hash_equals($revisit['original_observation_sha256'], hash('sha256', $originalJson))
                || ($revisit['schema'] ?? null) !== 'plos.face_coverage_readiness.v1'
                || ($revisit['write_ready'] ?? null) !== false || ($revisit['automatic_replay_allowed'] ?? null) !== false) {
                throw new RuntimeException('face_coverage_readiness_evidence_held');
            }
            $unsigned = $revisit;
            unset($unsigned['evidence_sha256']);
            if (! is_string($revisit['evidence_sha256'] ?? null)
                || ! hash_equals($revisit['evidence_sha256'], hash('sha256', $this->encode($this->canonical($unsigned))))) {
                throw new RuntimeException('face_coverage_readiness_evidence_held');
            }
            $asset = $revisit['pins']['asset_uuid'] ?? null;
            $items[] = ['observation_id' => (int) $row->id, 'source_kind' => $row->source_kind,
                'source_id' => (int) $row->source_id, 'tree_id' => $row->tree_id === null ? null : (int) $row->tree_id,
                'retained_readiness' => $revisit, 'fresh_measurement_performed' => false, 'write_ready' => false,
                'next_read_tool' => $revisit['state'] === 'ready_for_read_plan' && Str::isUuid($asset ?? '')
                    ? ['tool' => 'file_face_coverage_preview_plan', 'arguments' => ['asset_uuid' => $asset]] : null];
        }

        return ['schema' => 'plos.face_coverage_readiness_list.v1', 'items' => $items,
            'next_after_observation_id' => $items === [] ? null : end($items)['observation_id'],
            'read_only' => true, 'write_ready' => false, 'automatic_replay_allowed' => false];
    }

    /** Exact-ID source remeasurement plus existing read planners, never coverage execution. */
    protected function assessHeldCoverage(array $candidate, float $deadline): array
    {
        $old = $candidate['observation'];
        $seconds = config('file_registry.image_audit.finding_recheck_max_seconds');
        if (! is_int($seconds) || $seconds < 1 || $seconds > 10) {
            throw new RuntimeException('face_coverage_revisit_budget_invalid');
        }
        $batch = $old['source_kind'] === 'registry'
            ? $this->sources->auditRegistryBatch($old['source_id'] - 1, 1, $seconds, $old['source_id'], false)
            : $this->sources->auditMediaBatch($old['source_id'] - 1, 1, $seconds, $old['source_id']);
        $fresh = $batch['rows'][0] ?? null;
        if (! is_array($fresh) || count($batch['rows']) !== 1 || ($batch['status'] ?? null) === 'blocked'
            || $fresh['source_kind'] !== $old['source_kind'] || $fresh['source_id'] !== $old['source_id']
            || $fresh['tree_id'] !== $old['tree_id']) {
            throw new RuntimeException('face_coverage_revisit_source_held');
        }
        $binding = $this->coverageBinding('incoming');
        if (! $this->verifySource($fresh, $binding)) {
            throw new RuntimeException('face_coverage_revisit_source_changed');
        }
        $pins = ['source_snapshot' => $fresh['source_snapshot'], 'source_sha256' => $fresh['observed_sha256'],
            'physical_fingerprint' => $fresh['source_fingerprint'], 'asset_uuid' => $fresh['asset_uuid'] ?? null,
            'registry_snapshot' => $fresh['registry_snapshot'] ?? null, 'generation_plan' => null, 'native_read_plan' => null, 'ledgers' => null];
        $reason = 'source_changed_requires_fresh_plan';
        $state = 'read_plan_hold';
        if (empty($fresh['successful'])) {
            $reason = $fresh['classification'];
        } elseif ($this->encode($fresh['source_snapshot']) === $this->encode($old['source_snapshot'])
            && $fresh['observed_sha256'] === $old['observed_sha256'] && Str::isUuid($pins['asset_uuid'] ?? '')) {
            $pins['ledgers'] = $this->coverageLedgerPins($pins['asset_uuid']);
            if (! $pins['ledgers']['terminal']) {
                $reason = 'generation_ledger_incomplete_no_replay';
            } else {
                $faces = app(FaceEmbeddingService::class);
                $plan = $faces->planAnonymousFaceGeneration($pins['asset_uuid']);
                $pins['generation_plan'] = $plan;
                $reason = $plan['error'] ?? $plan['hold_reason'] ?? 'generation_plan_unqualified';
                if (($plan['eligible'] ?? false) === true && ($plan['success'] ?? false) === true
                    && ($plan['source_sha256'] ?? null) === $fresh['observed_sha256']) {
                    $native = $faces->planAnonymousCoveragePreview($pins['asset_uuid']);
                    $pins['native_read_plan'] = $native;
                    $reason = $native['error'] ?? 'native_read_plan_unqualified';
                    if (($native['success'] ?? false) === true && ($native['eligible'] ?? false) === true
                        && ($native['source_sha256'] ?? null) === $plan['source_sha256']
                        && ($native['binding_sha256'] ?? null) === ($plan['binding_sha256'] ?? null)
                        && ($native['generation_key'] ?? null) === ($plan['generation_key'] ?? null)
                        && ($native['detector_version'] ?? null) === ($plan['detector_version'] ?? null)
                        && ($native['engine_provenance'] ?? null) === ($plan['engine_provenance'] ?? null)
                        && hrtime(true) / 1e9 < $deadline) {
                        $final = $faces->planAnonymousCoveragePreview($pins['asset_uuid']);
                        $pins['final_native_read_plan'] = $final;
                        if ($final === $native) {
                            $state = 'ready_for_read_plan';
                            $reason = 'fresh_native_read_plan_available';
                        } else {
                            $reason = $final['error'] ?? 'native_read_plan_changed';
                        }
                    }
                }
            }
        }
        // Only actual availability failures receive a cooldown retry; proof/resource/protection failures stop automatic polling.
        if ($state !== 'ready_for_read_plan' && in_array($reason, ['current_source_unavailable', 'local_source_unavailable',
            'detector_unavailable', 'detector_version_unavailable', 'read_error', 'missing_or_unavailable'], true)) {
            $state = 'retryable_hold';
        }
        $verify = fn (): bool => $this->verifySource($fresh, $binding)
            && ($pins['ledgers'] === null || $this->coverageLedgerPins($pins['asset_uuid']) === $pins['ledgers'])
            && ($state !== 'ready_for_read_plan' || app(FaceEmbeddingService::class)
                ->scheduledCoveragePreviewHoldReason((int) $fresh['registry_id'], $pins['generation_plan']['generation_key']) === null);

        return [['state' => $state, 'reason' => $reason, 'pins' => $pins], $verify];
    }

    private function coverageLedgerPins(string $assetUuid): array
    {
        $pins = ['terminal' => true, 'rows' => []];
        $bound = config('file_registry.face_coverage.bounds.max_rows');
        foreach (['file_registry_face_generation_runs', 'file_registry_face_reconciliation_runs'] as $table) {
            $rows = DB::table($table)->where('asset_uuid', $assetUuid)->orderBy('id')->limit($bound + 1)->get();
            if ($rows->count() > $bound) {
                throw new RuntimeException('face_coverage_revisit_ledger_bound');
            }
            foreach ($rows as $row) {
                $pins['rows'][] = ['table' => $table, 'id' => (int) $row->id, 'run_uuid' => $row->run_uuid,
                    'stage' => $row->stage, 'preimage_sha256' => hash('sha256', $this->encode((array) $row))];
                $pins['terminal'] = $pins['terminal'] && $row->stage === 'complete';
            }
        }

        return $pins;
    }

    protected function findingIdentity(array $evidence): array
    {
        $row = $evidence['row'] ?? [];

        return $this->canonical(['schema' => 'anonymous_face_coverage_finding.v1',
            'source_kind' => $row['source_kind'] ?? null, 'source_id' => $row['source_id'] ?? null,
            'tree_id' => $row['tree_id'] ?? null, 'path_sha256' => hash('sha256', $row['nextcloud_path'] ?? ''),
            'observed_sha256' => $row['observed_sha256'] ?? null, 'classification' => $row['classification'] ?? null,
            'physical_fingerprint' => $row['source_fingerprint'] ?? null, 'coverage' => $row['coverage'] ?? null,
            'previous_sha256' => ! empty($evidence['assessment']['observed_change'])
                ? ($evidence['previous_inspection']['observed_sha256'] ?? null) : null]);
    }

    protected function cover(array $row, array $authority): array
    {
        if (($row['format_classification'] ?? null) === 'non_raster_graphic') {
            return ['outcome' => 'held', 'reason' => 'unsupported_non_raster_format'];
        }
        if (empty($row['successful']) || ! Str::isUuid($row['asset_uuid'] ?? '')) {
            return ['outcome' => 'held', 'reason' => empty($row['asset_uuid']) ? 'registry_asset_missing' : 'source_proof_unavailable'];
        }
        $registry = $row['registry_snapshot'] ?? null;
        if ($row['source_kind'] === 'media' && is_array($registry)
            && ($registry['current_path'] ?? null) === ($row['nextcloud_path'] ?? null)
            && ($registry['path_hash'] ?? null) === hash('sha256', strtolower($row['nextcloud_path']))
            && $registry['path_hash'] !== hash('sha256', $row['nextcloud_path'])) {
            return ['outcome' => 'held', 'reason' => 'source_index_correction_required'];
        }
        $faces = app(FaceEmbeddingService::class);
        $unknown = DB::table('file_registry_face_generation_runs')->where('asset_uuid', $row['asset_uuid'])
            ->where(fn ($query) => $query->where('stage', 'detector_running')->orWhere('failure_code', 'generation_terminal_unknown'))
            ->exists();
        if ($unknown) {
            throw new InferenceAdmissionException('Generation detector terminal state is UNKNOWN; coverage and preview dispatch held.');
        }
        $plan = $faces->planAnonymousFaceGeneration($row['asset_uuid']);
        if (empty($plan['source_sha256']) || $plan['source_sha256'] !== $row['observed_sha256']) {
            return ['outcome' => 'held', 'reason' => 'generation_source_unavailable'];
        }
        $run = DB::table('file_registry_face_generation_runs')->where('asset_uuid', $row['asset_uuid'])
            ->where('generation_key', $plan['generation_key'])->first();
        if ($run && ($run->source_sha256 !== $plan['source_sha256'] || $run->detector_version !== $plan['detector_version'])) {
            return ['outcome' => 'held', 'reason' => 'generation_receipt_proof_conflict'];
        }
        if ($run && ($run->stage === 'detector_running' || ($run->failure_code ?? null) === 'generation_terminal_unknown')) {
            throw new InferenceAdmissionException('Generation detector terminal state is UNKNOWN; coverage and preview dispatch held.');
        }
        // A completed zero-face receipt is as authoritative as one containing faces; no binding is rewritten.
        if ($run && $run->stage === 'complete') {
            return ['outcome' => 'unchanged', 'run_uuid' => $run->run_uuid, 'face_count' => (int) $run->face_count];
        }
        $protectionReason = $plan['hold_reason'] ?? app(FaceEmbeddingService::class)
            ->scheduledCoveragePreviewHoldReason((int) $row['registry_id'], $plan['generation_key']);
        if (is_string($protectionReason) && $protectionReason !== '') {
            if (! in_array($protectionReason, ['protected_named_face_present', 'protected_reviewed_face_present',
                'legacy_current_generation_unknown', 'prior_current_generation_reconciliation_required',
                'anonymous_generation_reconciliation_pending'], true)) {
                $protectionReason = '';
            }
            $detectorVersion = $plan['coverage_preview_detector_version'] ?? null;
            if ($protectionReason !== '' && is_string($detectorVersion) && preg_match('/^[a-f0-9]{64}$/D', $detectorVersion)) {
                $existingPreview = $faces->findScheduledCoveragePreview($row['asset_uuid'], $plan['source_sha256'], $detectorVersion);
                if ($existingPreview !== null) {
                    return ['outcome' => 'held', 'reason' => $protectionReason, 'preview_available' => true,
                        'preview_index_key' => $existingPreview['binding_key'], 'preview_face_count' => $existingPreview['face_count']];
                }
            }
            if ($protectionReason !== '' && is_string($detectorVersion) && isset($plan['engine_provenance'])) {
                $authority['generation'] = ['selector' => $row['source_kind'], 'source_id' => $row['source_id'],
                    'asset_uuid' => $row['asset_uuid'], 'file_registry_id' => $row['registry_id'],
                    'source_sha256' => $plan['source_sha256'], 'binding_sha256' => $plan['binding_sha256'],
                    'source' => array_intersect_key($row, array_flip(['source_kind', 'source_id', 'tree_id', 'source_snapshot',
                        'registry_snapshot', 'nextcloud_path', 'observed_sha256', 'source_fingerprint', 'successful']))];
                $preview = $faces->previewScheduledCoverage(['asset_uuid' => strtolower($row['asset_uuid'])], $plan, $authority);

                return ['outcome' => 'held', 'reason' => $protectionReason,
                    'preview_available' => ! empty($preview['success']) && ! empty($preview['suggestions_published']),
                    'preview_index_key' => $preview['preview_index_key'] ?? null, 'preview_face_count' => $preview['face_count'] ?? null];
            }
        }
        $unknownCurrent = DB::table('file_registry_faces')->where('file_registry_id', $row['registry_id'])
            ->where('source', 'ai_detection')->where('source_current', 1)->whereNull('superseded_at')->whereNull('source_generation_key')->exists();
        if ($unknownCurrent) {
            return ['outcome' => 'held', 'reason' => 'legacy_current_generation_unknown'];
        }
        if (empty($plan['eligible']) || ($run && ($run->stage === 'held' || $run->binding_sha256 !== $plan['binding_sha256']))) {
            return ['outcome' => 'held', 'reason' => $plan['hold_reason'] ?? 'original_generation_receipt_held', 'run_uuid' => $run->run_uuid ?? null];
        }
        $authority['generation'] = ['selector' => $row['source_kind'], 'source_id' => $row['source_id'],
            'asset_uuid' => $row['asset_uuid'], 'file_registry_id' => $row['registry_id'],
            'source_sha256' => $plan['source_sha256'], 'binding_sha256' => $plan['binding_sha256'],
            'source' => array_intersect_key($row, array_flip(['source_kind', 'source_id', 'tree_id', 'source_snapshot',
                'registry_snapshot', 'nextcloud_path', 'observed_sha256', 'source_fingerprint', 'successful']))];
        $result = $faces->restoreScheduledAnonymousFaceGeneration($row['asset_uuid'], $plan['source_sha256'],
            $plan['binding_sha256'], $run->idempotency_key ?? (string) Str::uuid(), $authority);

        return ['outcome' => ! empty($result['success']) ? 'generated' : 'held', 'reason' => $result['error'] ?? null,
            'run_uuid' => $result['run_uuid'] ?? null, 'face_count' => $result['face_count'] ?? null];
    }
}
