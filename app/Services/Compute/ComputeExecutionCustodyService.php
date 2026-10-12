<?php

namespace App\Services\Compute;

use App\Exceptions\AI\InferenceAdmissionException;
use App\Services\Custody\TaskCustodyService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Exact compute slot leases in the existing TCR table; expiry is never release authority. */
class ComputeExecutionCustodyService
{
    public const SURFACE = TaskCustodyService::SURFACE_COMPUTE_EXECUTION;

    private function instancePrefix(string $instanceId): string
    {
        return hash('sha256', $instanceId).':';
    }

    private function rows(string $instanceId): Builder
    {
        return DB::table('task_custody_records')->where('surface', self::SURFACE)
            ->where('surface_ref', 'like', $this->instancePrefix($instanceId).'%')->whereNull('released_at');
    }

    public function occupied(string $instanceId, int $slot): bool
    {
        return $this->rows($instanceId)->where('surface_ref', $this->instancePrefix($instanceId).$slot)->exists();
    }

    /** Called while the exact atomic cache slot is owned. DB row lock serializes instance admission. */
    public function acquire(string $instanceId, int $slot, string $ownerToken, string $cacheOwner, int $seconds): ?object
    {
        return DB::transaction(function () use ($instanceId, $slot, $ownerToken, $cacheOwner, $seconds) {
            $instance = DB::table('compute_instances')->where('instance_id', $instanceId)->lockForUpdate()->first(['instance_id']);
            if (app()->isDownForMaintenance() || $instance === null || $this->occupied($instanceId, $slot)) {
                return null;
            }
            $id = DB::table('task_custody_records')->insertGetId([
                'surface' => self::SURFACE,
                'surface_ref' => $this->instancePrefix($instanceId).$slot,
                'owner_token' => $ownerToken,
                'acquired_at' => now(), 'expires_at' => now()->addSeconds($seconds),
                'progress_note' => 'compute_prepared',
                'result_envelope' => json_encode(['schema' => 'plos.compute_custody.v1', 'instance_id' => $instanceId,
                    'slot' => $slot, 'owner_token' => $ownerToken, 'cache_owner' => $cacheOwner], JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('task_custody_records')->where('id', $id)->first();
        });
    }

    private function owned(object $custody): Builder
    {
        return DB::table('task_custody_records')->where('id', $custody->id)->where('surface', self::SURFACE)
            ->where('owner_token', $custody->owner_token)->where('surface_ref', $custody->surface_ref)->whereNull('released_at');
    }

    public function dispatching(object $custody): void
    {
        if ($this->owned($custody)->update(['progress_note' => 'compute_dispatched', 'updated_at' => now()]) !== 1) {
            throw new InferenceAdmissionException('Compute dispatch custody publication failed; dispatch held.');
        }
    }

    public function unknown(object $custody, string $reason): void
    {
        $this->owned($custody)->update(['progress_note' => 'compute_unknown:'.$reason, 'updated_at' => now()]);
    }

    /** Publish content-free physical cleanup proof before releasing the cache handle. */
    public function terminal(object $custody, array $receipt): void
    {
        $envelope = json_decode($custody->result_envelope, true, 512, JSON_THROW_ON_ERROR);
        $envelope['terminal_receipt'] = array_intersect_key($receipt, array_flip([
            'schema', 'owner_token', 'command_sha256', 'cleanup_verified', 'dispatched', 'exit_code',
        ]));
        if ($this->owned($custody)->update(['progress_note' => 'compute_terminal_verified',
            'result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR), 'updated_at' => now()]) !== 1) {
            throw new InferenceAdmissionException('Compute terminal receipt publication failed; hold retained.');
        }
    }

    /** Only the caller's proved-before-runner branch may publish this owned no-dispatch terminal. */
    public function noDispatch(object $custody): void
    {
        $envelope = json_decode($custody->result_envelope, true, 512, JSON_THROW_ON_ERROR);
        $envelope['terminal_receipt'] = ['schema' => 'plos.compute_no_dispatch.v1', 'owner_token' => $custody->owner_token,
            'dispatched' => false, 'cleanup_verified' => true, 'reason' => 'maintenance_before_runner'];
        if ($this->owned($custody)->whereIn('progress_note', ['compute_prepared', 'compute_dispatched'])->update([
            'progress_note' => 'compute_terminal_verified', 'result_envelope' => json_encode($envelope, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]) !== 1) {
            throw new InferenceAdmissionException('Compute no-dispatch terminal publication failed; hold retained.');
        }
    }

    public function release(object $custody, bool $success): void
    {
        if ($this->owned($custody)->where('progress_note', 'compute_terminal_verified')->update([
            'released_at' => now(), 'outcome' => $success ? 'success' : 'failure',
            'notification_state' => 'suppressed', 'updated_at' => now(),
        ]) !== 1) {
            throw new InferenceAdmissionException('Compute terminal custody release failed; hold retained.');
        }
    }
}
