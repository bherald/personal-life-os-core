<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Durable exclusion for participating framework callers. Unknown remote effects never expire. */
final class CliFrameworkWorkerHold
{
    public function claim(string $worker, string $address, string $binding): void
    {
        $address = self::address($address);
        if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $worker) || ! preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new RuntimeException('Exact server-owned worker and action binding required.');
        }
        $inserted = DB::table('cli_framework_worker_holds')->insertOrIgnore([
            'worker_id' => $worker, 'address' => $address, 'binding_sha256' => $binding,
            'state' => 'held', 'result_sha256' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($inserted === 1) {
            return;
        }
        $old = DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->first();
        if ($old === null || $old->address !== $address || $old->state !== 'completed' || $old->binding_sha256 === $binding) {
            throw new RuntimeException('Worker is already held, has an unknown outcome, or its stable identity changed. No reclaim or replay.');
        }
        $changed = DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('address', $address)
            ->where('state', 'completed')->where('binding_sha256', $old->binding_sha256)
            ->update(['binding_sha256' => $binding, 'state' => 'held', 'result_sha256' => null, 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new RuntimeException('Worker custody changed concurrently; no remote dispatch.');
        }
    }

    public function assertOwned(string $worker, string $binding): void
    {
        if (! DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('binding_sha256', $binding)->where('state', 'held')->exists()) {
            throw new RuntimeException('Exact framework worker custody is unavailable.');
        }
    }

    public function unknown(string $worker, string $binding): void
    {
        DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('binding_sha256', $binding)
            ->where('state', 'held')->update(['state' => 'unknown', 'updated_at' => now()]);
    }

    /** Caller supplies the hash of a verified durable result, never a local SSH exit alone. */
    public function complete(string $worker, string $binding, string $result): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $result)
            || DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('binding_sha256', $binding)
                ->where('state', 'held')->update(['state' => 'completed', 'result_sha256' => $result, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('Cannot release changed or unknown framework custody.');
        }
    }

    public function assertOriginal(string $worker, string $address, string $binding): void
    {
        if (! DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('address', self::address($address))
            ->where('binding_sha256', $binding)->whereIn('state', ['held', 'unknown', 'completed'])->exists()) {
            throw new RuntimeException('Original worker, address and immutable job binding are not held by this action.');
        }
    }

    /** Explicit reconciliation follows reopened remote/native AND local result receipts. */
    public function reconcileVerified(string $worker, string $address, string $binding, string $result, array $readback): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $result) || ($readback['state'] ?? null) !== 'result_recorded'
            || ($readback['process_tree_termination_verified'] ?? null) !== true || ($readback['automatic_replay_allowed'] ?? null) !== false) {
            throw new RuntimeException('Unknown custody requires an original durable whole-tree terminal receipt.');
        }
        $terminal = CliWindowsActionProcess::validateTerminal($readback['native_terminal'] ?? null,
            ['action_id' => $readback['action_id'] ?? '', 'request_sha256' => $readback['request_sha256'] ?? '']);
        if (($readback['native_terminal_sha256'] ?? null) !== hash('sha256', json_encode($terminal, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Native custody proof fingerprint changed.');
        }
        $this->assertOriginal($worker, $address, $binding);
        $base = DB::table('cli_framework_worker_holds')->where('worker_id', $worker)->where('address', self::address($address))->where('binding_sha256', $binding);
        if ((clone $base)->where('state', 'completed')->where('result_sha256', $result)->exists()) {
            return;
        }
        if ((clone $base)->whereIn('state', ['held', 'unknown'])->update(['state' => 'completed', 'result_sha256' => $result, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('Worker custody changed during exact reconciliation; no other action was released.');
        }
    }

    public static function address(string $address): string
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new RuntimeException('Worker exclusion requires its exact configured numeric address.');
        }

        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat(chr(0), 10).chr(255).chr(255)) {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }
}
