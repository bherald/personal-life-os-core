<?php

namespace App\Services\UnifiedCli;

use Illuminate\Support\Facades\Context;
use RuntimeException;
use Symfony\Component\Process\Process;

/** The existing Windows helper, bound to one worker claim; no PID or command-control API. */
final class CliWindowsActionProcess
{
    public const CONTEXT = 'plos.cli.framework_remote_action';

    public static function available(): bool
    {
        return is_array(Context::getHidden(self::CONTEXT)) && isset(Context::getHidden(self::CONTEXT)['journal']['supervisor']);
    }

    public static function run(array $command, string $cwd, int $timeoutMs): array
    {
        $context = Context::getHidden(self::CONTEXT);
        if (PHP_OS_FAMILY !== 'Windows' || ! is_array($context) || ! isset($context['journal']['supervisor'])) {
            throw new RuntimeException('An admitted Windows worker action and supervisor binding are required.');
        }
        $journal = $context['journal'];
        $identity = $context['identity'];
        self::verifyRuntime($journal);
        $state = dirname($journal['path']);
        $workspace = dirname($state).DIRECTORY_SEPARATOR.'supervised-work';
        $launch = (new CliWindowsProcessPlan($journal['supervisor']['helper'], $state, [base_path(), $cwd]))->build(
            ['command' => $command[0], 'args' => array_slice($command, 1), 'env' => []], 'offline', $workspace,
            $timeoutMs, $journal['supervisor']['memory_mb'] ?? null);
        $launch['windows_plan']['action'] = $identity;
        $bytes = json_encode($launch['windows_plan'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $environment = array_replace(array_fill_keys(array_keys(getenv()), false), $launch['env']);
        $process = new Process([$launch['command'], ...$launch['args']], $launch['cwd'], $environment,
            pack('V', strlen($bytes)).$bytes, ($timeoutMs / 1000) + 10);
        $store = new CliStateStore($journal['path']);
        $stopRequested = false;
        $keeper = static function () use ($store, $journal, $identity, &$stopRequested): void {
            try {
                $store->assertTurnNotCancelled($journal['session_id'], $journal['turn_id']);
            } catch (RuntimeException) {
                // Do not kill only the local PHP/helper process and mistake that
                // for cleanup. Ask its owned native job to stop, then await proof.
                if (! $stopRequested) {
                    self::requestCancel($journal, $identity);
                    $stopRequested = true;
                }
            }
        };
        $result = (new CliMaintainedProcess)->run($process, $keeper);

        return self::finish($store, $journal, $identity, $result, static fn () => self::terminal($journal, $identity));
    }

    /** Captured output is evidence only; the existing native terminal gate remains authoritative. */
    private static function finish(CliStateStore $store, array $journal, array $identity, array $result, \Closure $readTerminal): array
    {
        try {
            $terminal = $readTerminal();
            $phase = $result['interrupted'] ? 'helper_interrupted'
                : ($terminal === null ? 'terminal_missing' : ($terminal['exit_code'] !== $result['exit_code'] ? 'terminal_exit_mismatch' : null));
        } catch (\Throwable) {
            $phase = 'terminal_rejected';
        }
        if ($phase !== null) {
            try {
                self::retainDiagnostic($store, $journal, $identity, $result, $phase);
            } catch (\Throwable) {
                // Diagnostic persistence cannot complete or relinquish an unknown claim.
                // Never include the persistence exception or process output in the event/error.
            }
            throw new CliToolOutcomeUnknown('Windows process has no matching whole-job terminal proof; preserve its original action and do not replay.');
        }

        return $result + ['process_terminal' => $terminal];
    }

    private static function retainDiagnostic(CliStateStore $store, array $journal, array $identity, array $result, string $phase): void
    {
        self::validateIdentity($identity);
        $session = $store->session($journal['session_id']);
        $receipt = $store->actionReceipts($journal['session_id'], $journal['turn_id'])[$identity['action_id']] ?? null;
        if (($session['framework_worker']['worker_id'] ?? null) !== $journal['worker_id']
            || ($receipt['state'] ?? null) !== 'started'
            || ($receipt['document']['instance_id'] ?? null) !== $journal['worker_id']
            || ($receipt['document']['action']['arguments']['request_sha256'] ?? null) !== $identity['request_sha256']) {
            throw new RuntimeException('Diagnostic requires the exact original worker claim.');
        }
        $stderr = $result['stderr'] ?? null;
        $category = $code = $stage = null;
        $classification = is_string($stderr) && $stderr === '' ? 'empty' : 'rejected';
        // stdout/stderr share a child-facing channel. Even a perfectly matching line
        // is an untrusted observation, never proof of its origin or process termination.
        if (($result['exit_code'] ?? null) === 125 && is_string($stderr) && strlen($stderr) <= 192
            && preg_match('/\APLOS Windows sandbox operation failed: (runtime_unavailable|runner_logon_(0|[1-9][0-9]{0,9})|runner_start_(ReadSpawnRequest|SpawnChild|WriteSpawnReady)_(0|[1-9][0-9]{0,9})|io_(-?(?:0|[1-9][0-9]{0,9})))\.(?:\r?\n)?\z/D', $stderr, $match, PREG_UNMATCHED_AS_NULL)) {
            if ($match[1] === 'runtime_unavailable') {
                $category = 'runtime_unavailable';
            } elseif ($match[2] !== null && (int) $match[2] <= 4294967295) {
                $category = 'runner_logon';
                $code = (int) $match[2];
            } elseif ($match[3] !== null && (int) $match[4] <= 4294967295) {
                $category = 'runner_start';
                $stage = $match[3];
                $code = (int) $match[4];
            } elseif ($match[5] !== null && (int) $match[5] >= -2147483648 && (int) $match[5] <= 2147483647) {
                $category = 'io';
                $code = (int) $match[5];
            }
            if ($category !== null) {
                $classification = 'allowlisted_untrusted';
            }
        }
        $store->recordEvent($journal['session_id'], 'framework_native_diagnostic', [
            'schema' => 'plos.cli.windows-action-diagnostic.v1', 'turn_id' => $journal['turn_id'],
            'worker_id' => $journal['worker_id'], 'identity' => $identity, 'phase' => $phase,
            'helper_exit_code' => is_int($result['exit_code'] ?? null) ? $result['exit_code'] : null,
            'interrupted' => is_bool($result['interrupted'] ?? null) ? $result['interrupted'] : null,
            'custody_lost' => is_bool($result['custody_lost'] ?? null) ? $result['custody_lost'] : null,
            'stderr_classification' => $classification, 'category' => $category, 'code' => $code, 'stage' => $stage,
            'diagnostic_origin_verified' => false, 'process_tree_termination_verified' => false,
            'automatic_replay_allowed' => false,
        ]);
    }

    public static function terminal(array $journal, array $identity): ?array
    {
        if (! isset($journal['supervisor'])) {
            return null;
        }
        self::verifyRuntime($journal);
        $path = self::directory($journal, $identity).DIRECTORY_SEPARATOR.'terminal.json';
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        if (! is_file($path) || filesize($path) > 4096) {
            throw new RuntimeException('Native terminal record is unavailable or oversized.');
        }
        $bytes = CliLocalBindings::readPrivateFile($path);
        $record = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);

        return self::validateTerminal($record, $identity);
    }

    /** Type/identity checks only; callers must separately establish private native provenance. */
    public static function validateTerminal(mixed $record, array $identity): array
    {
        if (! is_array($record) || count($record) !== 9
            || array_diff(array_keys($record), ['schema', 'identity', 'process_tree_termination_verified', 'exit_code', 'child_exit_code', 'timed_out', 'deadline_ms', 'cancel_requested', 'automatic_replay_allowed']) !== []
            || ($record['schema'] ?? null) !== 'plos.cli.windows-action-terminal.v1' || ($record['identity'] ?? null) !== $identity
            || ($record['process_tree_termination_verified'] ?? null) !== true || ! is_int($record['exit_code'] ?? null)
            || ! is_int($record['child_exit_code'] ?? null) || $record['child_exit_code'] < -2147483648 || $record['child_exit_code'] > 2147483647
            || ! is_bool($record['timed_out'] ?? null)
            || ! is_int($record['deadline_ms'] ?? null) || $record['deadline_ms'] < 1 || $record['deadline_ms'] > 3600000
            || $record['exit_code'] !== ($record['timed_out'] ? 124 : $record['child_exit_code'])
            || ! is_bool($record['cancel_requested'] ?? null) || ($record['automatic_replay_allowed'] ?? null) !== false) {
            throw new RuntimeException('Native terminal proof does not match the original action.');
        }
        self::validateIdentity($identity);

        return $record;
    }

    public static function requestCancel(array $journal, array $identity): void
    {
        self::verifyRuntime($journal);
        $directory = self::directory($journal, $identity);
        foreach ([dirname($directory), $directory] as $path) {
            if (! is_dir($path) && ! mkdir($path, 0700) && ! is_dir($path)) {
                throw new RuntimeException('Private action-control directory unavailable.');
            }
            if (is_link($path) || realpath($path) !== $path) {
                throw new RuntimeException('Private action-control directory changed.');
            }
        }
        self::publishCancel($directory.DIRECTORY_SEPARATOR.'cancel.json', $identity);
    }

    private static function directory(array $journal, array $identity): string
    {
        self::validateIdentity($identity);

        return dirname($journal['path']).DIRECTORY_SEPARATOR.'framework-actions'.DIRECTORY_SEPARATOR.$identity['action_id'];
    }

    private static function validateIdentity(array $identity): void
    {
        if (array_keys($identity) !== ['action_id', 'request_sha256']) {
            throw new RuntimeException('Exact native action identity required.');
        }
        foreach ($identity as $value) {
            if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) {
                throw new RuntimeException('Invalid native action identity.');
            }
        }
    }

    private static function verifyRuntime(array $journal): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! isset($journal['supervisor'])) {
            throw new RuntimeException('Native Windows supervisor is unavailable; no alternate process path.');
        }
        CliLocalBindings::assertPrivateSqlite($journal['path']);
        foreach ($journal['supervisor']['runtime_files'] as $path => $hash) {
            if (! hash_equals($hash, hash('sha256', CliLocalBindings::readPrivateFile($path)))) {
                throw new RuntimeException('Windows supervisor source or binary changed; original action remains held.');
            }
        }
    }

    /** Complete atomic publication; the native observer never sees a partial JSON body. */
    private static function publishCancel(string $path, array $identity): void
    {
        self::validateIdentity($identity);
        $bytes = json_encode(['schema' => 'plos.cli.windows-action-cancel.v1', 'identity' => $identity], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (is_file($path)) {
            if (CliLocalBindings::readPrivateFile($path) !== $bytes) {
                throw new RuntimeException('Existing cancellation belongs to another action.');
            }

            return;
        }
        $temporary = dirname($path).DIRECTORY_SEPARATOR.'.cancel-'.bin2hex(random_bytes(16));
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot stage bounded native cancellation.');
        }
        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($temporary, 0600);
            }
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fsync($handle)) {
                throw new RuntimeException('Cancellation record was not durably written.');
            }
            fclose($handle);
            $handle = null;
            // NTFS hard-link publication is atomic/no-replace. An unsupported
            // filesystem refuses control; it never substitutes a partial write.
            if (! @link($temporary, $path) && (! is_file($path) || CliLocalBindings::readPrivateFile($path) !== $bytes)) {
                throw new RuntimeException('Cancellation publication failed; await native deadline/readback.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporary);
        }
    }
}
