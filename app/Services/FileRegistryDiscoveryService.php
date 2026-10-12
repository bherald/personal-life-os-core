<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/** One fair discovery/intake slice. Source discovery never authorizes deletion or identity decisions. */
class FileRegistryDiscoveryService
{
    public function __construct(
        private FileRegistryDiscoveryStateService $state,
        private FileRegistryDirectoryPageService $pages,
        private NextcloudFileApiService $nextcloud,
        private FileIntakeStabilityService $stability,
    ) {}

    public function scan(string $scope, callable $register, int $limit = 500, array $options = []): array
    {
        $status = null;
        $lease = null;
        $before = [];
        $errors = [];
        $stop = null;
        $bounds = [];
        $used = ['directory_records' => 0, 'directory_pages' => 0, 'metadata_probes' => 0,
            'callback_probe_reservations' => 0, 'intake_attempts' => 0, 'file_visits' => 0, 'purged_state_rows' => 0, 'initial_stability_waits' => 0, 'unstable_retries' => 0];
        $qualificationStarted = $this->monotonic();
        try {
            $policy = $this->pages->policy();
            $computeHash = $options['compute_hash'] ?? true;
            if (! is_bool($computeHash) || $limit < 0) {
                throw new RuntimeException('invalid_intake_policy');
            }
            $bounds = ['directory_records' => $policy['max_directory_records'],
                'directory_pages' => $policy['max_directory_pages'] ?? $policy['max_due_work'],
                'metadata_probes' => $policy['max_stat_calls'],
                'callback_probe_reservation' => $policy['max_callback_stat_calls'] ?? 4,
                'intake_attempts' => min($limit > 0 ? $limit : $policy['max_intake_attempts'], $policy['max_intake_attempts']),
                'file_visits' => min($limit > 0 ? $limit : $policy['max_intake_attempts'], $policy['max_intake_attempts']),
                'slice_seconds' => $policy['max_slice_seconds'], 'pending_work' => $policy['max_pending_work']];
            $callerDeadline = $options['deadline'] ?? null;
            if ($callerDeadline !== null && (! is_numeric($callerDeadline) || ! is_finite((float) $callerDeadline))) {
                throw new RuntimeException('invalid_deadline');
            }
            if ($callerDeadline !== null && (float) $callerDeadline <= $this->monotonic()) {
                throw new RuntimeException('caller_deadline_expired');
            }
            $observation = $this->pages->observe($scope);
            if ($callerDeadline !== null && (float) $callerDeadline <= $this->monotonic()) {
                throw new RuntimeException('caller_deadline_expired');
            }
            $qualification = $this->pages->qualification($observation);
            if ($qualification === null) {
                if (($observation['reason'] ?? null) !== 'qualification_required_or_mismatched') {
                    throw new RuntimeException('runtime_observation_unavailable');
                }
                if ($callerDeadline !== null && (float) $callerDeadline - $this->monotonic()
                    < (float) config('file_registry.discovery.qualification_timeout_seconds')) {
                    throw new RuntimeException('qualification_deadline_unavailable');
                }
                $qualified = $this->pages->qualify($scope);
                $qualification = ($qualified['state'] ?? null) === 'qualified' ? ($qualified['qualification'] ?? null) : null;
                if (! is_array($qualification)) {
                    throw new RuntimeException('runtime_qualification_unavailable');
                }
            }
            $fingerprint = $observation['qualification']['runtime_fingerprint'] ?? null;
            if (! is_string($fingerprint) || ($qualification['state'] ?? null) !== 'qualified'
                || ($qualification['runtime_fingerprint'] ?? null) !== $fingerprint
                || ($qualification['helper_sha256'] ?? null) !== ($observation['qualification']['helper_sha256'] ?? null)
                || ($qualification['filesystem'] ?? null) !== ($observation['qualification']['filesystem'] ?? null)) {
                throw new RuntimeException('runtime_qualification_mismatch');
            }
            $physical = $this->pages->physicalScope($scope, $scope);
            $scopeResult = $this->state->ensureScope($scope, $physical['connector_fingerprint'], $fingerprint, ['compute_hash' => $computeHash]);
            $status = $scopeResult['status'] ?? null;
            if ($status === null) {
                throw new RuntimeException('scope_unavailable');
            }
            if ($status['scope_path'] !== $scope || $status['connector_fingerprint'] !== $physical['connector_fingerprint']
                || $status['compute_hash'] !== $computeHash) {
                throw new RuntimeException('scope_identity_mismatch');
            }
            $before = $status['counters'];
            $claimed = $this->state->claim($status['scope_id'], $status['revision']);
            $status = $claimed['status'];
            $lease = $claimed['lease'];
            if ($lease === null) {
                return $this->result($scope, $status, $before, $used, $bounds, [], $claimed['reason'] ?? $claimed['state'], $claimed['state']);
            }
            if ($status['qualification_fingerprint'] !== $fingerprint) {
                $changed = $this->state->replaceQualification($lease, $status['qualification_fingerprint'], $fingerprint);
                [$lease, $status] = $this->advance($changed);
                if ($status['qualification_fingerprint'] !== $fingerprint) {
                    throw new RuntimeException('qualification_replacement_held');
                }
            }
            $qualificationSeconds = $this->monotonic() - $qualificationStarted;
            $deadline = min($this->monotonic() + $policy['max_slice_seconds'], $callerDeadline === null ? INF : (float) $callerDeadline);
            $finishedAt = $status['discovery_completed_at'] ?? null;
            if ($status['discovery_complete'] && is_string($finishedAt)
                && CarbonImmutable::parse($finishedAt, 'UTC')->addSeconds($policy['epoch_interval_seconds'])->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                [$lease, $status] = $this->advance($this->state->beginEpoch($lease));
            }
            if ($this->monotonic() < $deadline) {
                $purged = $this->state->purgeResolved($lease, $policy['max_purge_rows']);
                [$lease, $status] = $this->advance($purged);
                $used['purged_state_rows'] = $purged['purged'];
            }
            $stability = $this->stability->policy();
            $delay = $stability['enabled'] ? $stability['stability_delay_seconds'] : 0.0;
            $directoryBudgetBlocked = false;
            while ($this->monotonic() < $deadline) {
                if (is_callable($options['cancelled'] ?? null) && $options['cancelled']()) {
                    $stop = 'cancelled';
                    break;
                }
                $served = false;
                if (! $directoryBudgetBlocked && $used['directory_pages'] < $bounds['directory_pages'] && $used['directory_records'] < $bounds['directory_records']) {
                    $directories = $this->state->dueWork($lease, 'directory', 1);
                    if ($directories !== []) {
                        $served = true;
                        $work = $directories[0];
                        $used['directory_pages']++;
                        $page = $this->pages->page($scope, $work['path'], $work['checkpoint'], $qualification, [
                            'max_records' => $bounds['directory_records'] - $used['directory_records'],
                            'max_entries' => $policy['max_page_entries'],
                            'max_seconds' => max(0.001, min($policy['max_page_seconds'], $deadline - $this->monotonic())),
                        ]);
                        if (($page['state'] ?? null) !== 'available') {
                            $reason = $page['reason'] ?? 'directory_page_unavailable';
                            $changed = in_array($reason, ['directory_generation_changed', 'root_identity_changed'], true);
                            $transient = in_array($reason, ['elapsed_time_limit', 'directory_page_unavailable',
                                'native_directory_read_failed', 'source_or_runtime_unavailable'], true);
                            $response = $changed ? $this->state->restartDirectory($lease, $work['id'], 'directory_generation_changed')
                                : ($transient ? $this->state->deferDirectory($lease, $work['id'], 'directory_page_unavailable')
                                    : $this->state->holdDirectory($lease, $work['id'], 'directory_source_held'));
                            [$lease, $status] = $this->advance($response);
                            $errors[] = ['path' => $work['path'], 'reason' => $changed ? 'directory_generation_changed'
                                : ($transient ? 'directory_page_unavailable' : 'directory_source_held')];
                        } else {
                            if (! is_int($page['records'] ?? null) || $page['records'] < 0
                                || $page['records'] > $bounds['directory_records'] - $used['directory_records']) {
                                throw new RuntimeException('invalid_native_record_count');
                            }
                            $used['directory_records'] += $page['records'];
                            $persisted = $this->state->publishDirectoryPage($lease, $work['id'], $this->storagePage($work, $page));
                            [$lease, $status] = $this->advance($persisted);
                            if (($persisted['stop_reason'] ?? null) !== null) {
                                $stop = $persisted['stop_reason'];
                            } elseif (($page['stop_reason'] ?? null) === 'output_bytes' && $page['cookie'] === $work['checkpoint']['cookie']) {
                                [$lease, $status] = $this->advance($this->state->holdDirectory($lease, $work['id'], 'directory_output_budget_too_small'));
                                $stop = 'directory_output_budget_too_small';
                            } elseif (($page['stop_reason'] ?? null) === 'record_buffer' && $page['cookie'] === $work['checkpoint']['cookie']) {
                                $directoryBudgetBlocked = true;
                                if ($used['directory_records'] === 0) {
                                    [$lease, $status] = $this->advance($this->state->holdDirectory($lease, $work['id'], 'directory_record_budget_too_small'));
                                    $stop = 'directory_record_budget_too_small';
                                } else {
                                    $stop = 'directory_record_budget';
                                }
                            }
                        }
                    }
                }
                if ($this->monotonic() >= $deadline) {
                    $stop = 'time_budget';
                    break;
                }
                if ($used['file_visits'] < $bounds['file_visits'] && $used['metadata_probes'] < $bounds['metadata_probes']) {
                    $files = $this->state->dueWork($lease, 'file', 1);
                    if ($files !== []) {
                        $served = true;
                        $work = $files[0];
                        $used['file_visits']++;
                        if (($work['checkpoint']['native_kind'] ?? 'regular') !== 'regular') {
                            [$lease, $status] = $this->advance($this->state->holdFile($lease, $work['id'], 'unsupported_source_entry'));
                            $errors[] = ['path' => $work['path'], 'reason' => 'unsupported_source_entry'];

                            continue;
                        }
                        $used['metadata_probes']++;
                        try {
                            $info = $this->nextcloud->getFileInfo($work['path'], true);
                        } catch (Throwable $e) {
                            $info = ['success' => false];
                        }
                        $snapshot = $this->snapshot($info, $work['path']);
                        if ($snapshot === null) {
                            $response = ($info['review_required'] ?? false) === true
                                ? $this->state->holdFile($lease, $work['id'], 'current_source_held')
                                : $this->state->deferFile($lease, $work['id'], 'current_source_unavailable');
                            [$lease, $status] = $this->advance($response);
                            $errors[] = ['path' => $work['path'], 'reason' => 'current_source_unavailable'];

                            continue;
                        }
                        $saved = $work['checkpoint']['snapshot'] ?? null;
                        if ($saved !== null && $saved !== $snapshot) {
                            $checkpoint = array_replace($work['checkpoint'], ['snapshot' => $snapshot,
                                'stability_not_before' => CarbonImmutable::now('UTC')->addMicroseconds((int) ceil($delay * 1000000))->format('Y-m-d H:i:s.u')]);
                            [$lease, $status] = $this->advance($this->state->deferFile($lease, $work['id'], 'changed_during_stability', $checkpoint));
                            $used['unstable_retries']++;

                            continue;
                        }
                        if ($saved === null) {
                            $notBefore = CarbonImmutable::now('UTC')->addMicroseconds((int) ceil($delay * 1000000))->format('Y-m-d H:i:s.u');
                            [$lease, $status] = $this->advance($this->state->observeFile($lease, $work['id'], $snapshot, $notBefore));
                            if ($delay > 0) {
                                $used['initial_stability_waits']++;

                                continue;
                            }
                        }
                        if ($bounds['metadata_probes'] < $used['metadata_probes'] + $bounds['callback_probe_reservation']) {
                            $stop = 'metadata_probe_budget';
                            break;
                        }
                        if ($this->monotonic() >= $deadline) {
                            $stop = 'time_budget';
                            break;
                        }
                        if (is_callable($options['cancelled'] ?? null) && $options['cancelled']()) {
                            $stop = 'cancelled';
                            break;
                        }
                        $used['metadata_probes'] += $bounds['callback_probe_reservation'];
                        $used['callback_probe_reservations'] += $bounds['callback_probe_reservation'];
                        $used['intake_attempts']++;
                        $intakeResult = null;
                        try {
                            $response = $this->state->guardedIntake($lease, $work['id'], function () use ($register, $work, $computeHash, $deadline, $snapshot, &$intakeResult) {
                                return $intakeResult = $register($work['path'], ['compute_hash' => $computeHash,
                                    'require_local_source' => true, 'deadline' => $deadline,
                                    'expected_source_fingerprint' => $snapshot['source_fingerprint']]);
                            });
                            [$lease, $status] = $this->advance($response);
                        } catch (Throwable $e) {
                            $hold = is_array($intakeResult) && ($intakeResult['review_required'] ?? false) === true;
                            $response = $hold ? $this->state->holdFile($lease, $work['id'], 'registration_review_required')
                                : $this->state->deferFile($lease, $work['id'], 'registration_failed');
                            [$lease, $status] = $this->advance($response);
                            $errors[] = ['path' => $work['path'], 'reason' => $hold ? 'registration_review_required' : 'registration_failed'];
                        }
                    }
                }
                if (! $served) {
                    $stop ??= $used['metadata_probes'] >= $bounds['metadata_probes'] ? 'metadata_probe_budget'
                        : ($used['file_visits'] >= $bounds['file_visits'] ? 'intake_budget'
                            : ($used['directory_records'] >= $bounds['directory_records'] ? 'directory_record_budget'
                                : ($used['directory_pages'] >= $bounds['directory_pages'] ? 'directory_page_budget' : null)));
                    break;
                }
            }
            if ($this->monotonic() >= $deadline) {
                $stop = 'time_budget';
            }
            [$lease, $status] = $this->advance($this->state->release($lease));
            $result = $this->result($scope, $status, $before, $used, $bounds, $errors, $stop);
            $result['qualification_seconds'] = $qualificationSeconds;

            return $result;
        } catch (Throwable $e) {
            if ($lease !== null) {
                try {
                    [$lease, $status] = $this->advance($this->state->release($lease));
                } catch (Throwable $ignored) {
                    // Custody is retained for lease expiry/recovery; no stale worker mutates it.
                }
            }

            return $this->result($scope, $status, $before, $used, $bounds, $errors,
                preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $e->getMessage()) ? $e->getMessage() : 'discovery_slice_held', 'held');
        }
    }

    private function storagePage(array $work, array $page): array
    {
        $entries = [];
        foreach ($page['entries'] as $entry) {
            $name = $entry['name'] ?? null;
            if (! is_string($name) || $name === '' || in_array($name, ['.', '..'], true) || str_contains($name, '/') || str_contains($name, "\0")) {
                throw new RuntimeException('invalid_native_entry');
            }
            $directory = ($entry['kind'] ?? null) === 'directory';
            $entries[] = ['kind' => $directory ? 'directory' : 'file', 'path' => rtrim($work['path'], '/').'/'.$name,
                'depth' => (int) $work['depth'] + 1, 'resume_cookie' => $entry['cookie'],
                'checkpoint' => $directory ? ['cookie' => '0', 'generation' => null, 'eof' => false]
                    : ['snapshot' => null, 'stability_not_before' => null, 'native_kind' => $entry['kind'] ?? 'unknown']];
        }

        return ['before_cookie' => $work['checkpoint']['cookie'], 'before_generation' => $work['checkpoint']['generation'],
            'cookie' => $page['cookie'], 'generation' => $page['generation'], 'complete' => $page['complete'],
            'records' => $page['records'], 'entries' => $entries];
    }

    private function snapshot(array $info, string $path): ?array
    {
        $fingerprint = $info['source_fingerprint'] ?? null;
        if (($info['success'] ?? false) !== true || ($info['source'] ?? null) !== 'filesystem'
            || ($info['path'] ?? null) !== $path || ($info['is_directory'] ?? false) || ! is_array($fingerprint)
            || count($fingerprint) !== 6 || array_diff(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'], array_keys($fingerprint)) !== []) {
            return null;
        }
        foreach (['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'] as $key) {
            if (! is_int($fingerprint[$key])) {
                return null;
            }
        }
        if (($fingerprint['mode'] & 0170000) !== 0100000 || $fingerprint['size'] < 0
            || ($info['size'] ?? null) !== $fingerprint['size'] || ! is_string($info['last_modified'] ?? null)
            || strtotime($info['last_modified']) !== $fingerprint['mtime']) {
            return null;
        }

        return ['size' => $fingerprint['size'], 'modified_at' => strtotime($info['last_modified']), 'source_fingerprint' => $fingerprint];
    }

    private function advance(array $response): array
    {
        return [$response['lease'], $response['status']];
    }

    private function result(string $scope, ?array $status, array $before, array $used, array $bounds, array $errors, ?string $stop, ?string $state = null): array
    {
        $after = $status['counters'] ?? $before;
        $delta = fn ($key) => max(0, ($after[$key] ?? 0) - ($before[$key] ?? 0));

        return ['scanned' => $delta('files_discovered'), 'registered' => $delta('registered'),
            'already_registered' => $delta('updated') + $delta('unchanged'), 'skipped_unchanged' => $delta('unchanged'),
            'duplicates' => $delta('duplicates'), 'errors' => count($errors), 'error_details' => $errors,
            'deferred' => $status['pending_work'] ?? 0,
            'deferred_unstable' => $used['initial_stability_waits'] + $used['unstable_retries'],
            'deferred_reasons' => array_filter(['stability_delay_pending' => $used['initial_stability_waits'],
                'changed_during_delay' => $used['unstable_retries']]),
            'stability_wait_seconds' => 0.0, 'stability_policy_source' => FileIntakeStabilityService::CONFIG_KEY,
            'state' => $state ?? $status['state'] ?? 'held', 'scope' => $scope, 'scope_id' => $status['scope_id'] ?? null,
            'epoch' => $status['epoch_uuid'] ?? null, 'revision' => $status['revision'] ?? null,
            'discovery_complete' => $status['discovery_complete'] ?? false, 'intake_complete' => $status['intake_complete'] ?? false,
            'next_attempt_at' => $status['next_attempt_at'] ?? null, 'stop_reason' => $stop,
            'pending_work' => $status['pending_work'] ?? 0, 'held_work' => $status['held_work'] ?? 0,
            'bounds' => $bounds, 'slice' => $used, 'counters_before' => $before, 'counters_after' => $after];
    }

    private function monotonic(): float
    {
        return hrtime(true) / 1000000000;
    }
}
