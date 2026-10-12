<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECOVERY_JOB = 'compute_health_reconcile';

    private const SPLADE_JOB = 'rag_splade_index';

    private const OLD_SPLADE_COMMAND = 'rag:splade-index --limit=100';

    private const NEW_SPLADE_COMMAND = 'rag:splade-index --limit=500';

    private const CAPABILITY_MARKER = 'migration_20260717_splade_capability';

    public function up(): void
    {
        $this->promoteExistingNlpWorkers();
        $this->addRecoveryJob();
        $this->hardenSpladeJob();
    }

    public function down(): void
    {
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')
                ->where('name', self::RECOVERY_JOB)
                ->where('command', 'ops:compute-health-reconcile --json --compact')
                ->delete();

            $row = DB::table('scheduled_jobs')->where('name', self::SPLADE_JOB)->first();
            if ($row !== null) {
                $notes = $this->decodedObject($row->notes ?? null);
                $runtime = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
                foreach ([
                    'required_compute_capability' => 'splade',
                    'unavailable_outcome' => 'deferred',
                    'automatic_recovery' => true,
                    'retry_cadence_owner' => 'scheduled_jobs.rag_splade_index',
                ] as $key => $value) {
                    if (($runtime[$key] ?? null) === $value) {
                        unset($runtime[$key]);
                    }
                }
                if ($runtime === []) {
                    unset($notes['runtime']);
                } else {
                    $notes['runtime'] = $runtime;
                }

                DB::table('scheduled_jobs')->where('id', $row->id)->update([
                    'command' => (string) $row->command === self::NEW_SPLADE_COMMAND
                        ? self::OLD_SPLADE_COMMAND
                        : $row->command,
                    'notes' => $notes === [] ? null : json_encode($notes, JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->removePromotedCapabilities();
    }

    private function promoteExistingNlpWorkers(): void
    {
        if (! Schema::hasTable('compute_instances')) {
            return;
        }

        foreach (DB::table('compute_instances')->get(['id', 'capabilities', 'config']) as $row) {
            $capabilities = $this->decodedList($row->capabilities ?? null);
            if (! in_array('nlp', $capabilities, true) || in_array('splade', $capabilities, true)) {
                continue;
            }

            $config = $this->decodedObject($row->config ?? null);
            $capabilities[] = 'splade';
            $config[self::CAPABILITY_MARKER] = true;

            DB::table('compute_instances')->where('id', $row->id)->update([
                'capabilities' => json_encode(array_values(array_unique($capabilities)), JSON_UNESCAPED_SLASHES),
                'config' => json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    private function removePromotedCapabilities(): void
    {
        if (! Schema::hasTable('compute_instances')) {
            return;
        }

        foreach (DB::table('compute_instances')->get(['id', 'capabilities', 'config']) as $row) {
            $config = $this->decodedObject($row->config ?? null);
            if (($config[self::CAPABILITY_MARKER] ?? null) !== true) {
                continue;
            }

            unset($config[self::CAPABILITY_MARKER]);
            $capabilities = array_values(array_filter(
                $this->decodedList($row->capabilities ?? null),
                static fn (string $capability): bool => $capability !== 'splade'
            ));

            DB::table('compute_instances')->where('id', $row->id)->update([
                'capabilities' => json_encode($capabilities, JSON_UNESCAPED_SLASHES),
                'config' => $config === [] ? null : json_encode($config, JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    private function addRecoveryJob(): void
    {
        if (! Schema::hasTable('scheduled_jobs')
            || DB::table('scheduled_jobs')->where('name', self::RECOVERY_JOB)->exists()) {
            return;
        }

        $now = now();
        DB::table('scheduled_jobs')->insert([
            'name' => self::RECOVERY_JOB,
            'description' => 'Reconcile active compute worker health and close recovered circuits.',
            'job_type' => 'command',
            'command' => 'ops:compute-health-reconcile --json --compact',
            'cron_expression' => '*/15 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 5,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'maintenance',
            'workload_family' => 'ops',
            'resource_profile' => 'default',
            'stall_policy' => 'strict',
            'backlog_metric' => 'compute_health_stale',
            'notification_mode' => 'digest',
            'notes' => json_encode([
                'schema' => 'plos.compute_health_reconcile_scheduler.v1',
                'runtime' => [
                    'probe_scope' => 'active_compute_instances',
                    'health_metadata_only' => true,
                    'external_llm_invoked' => false,
                    'private_data_shared' => false,
                ],
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function hardenSpladeJob(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        $row = DB::table('scheduled_jobs')->where('name', self::SPLADE_JOB)->first();
        if ($row === null) {
            return;
        }

        $notes = $this->decodedObject($row->notes ?? null);
        $runtime = is_array($notes['runtime'] ?? null) ? $notes['runtime'] : [];
        $runtime += [
            'required_compute_capability' => 'splade',
            'unavailable_outcome' => 'deferred',
            'automatic_recovery' => true,
            'retry_cadence_owner' => 'scheduled_jobs.rag_splade_index',
        ];
        $notes['runtime'] = $runtime;

        DB::table('scheduled_jobs')->where('id', $row->id)->update([
            'command' => (string) $row->command === self::OLD_SPLADE_COMMAND
                ? self::NEW_SPLADE_COMMAND
                : $row->command,
            'notes' => json_encode($notes, JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function decodedList(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded)
            ? array_values(array_filter(array_map('strval', $decoded), static fn (string $item): bool => $item !== ''))
            : [];
    }

    /** @return array<string,mixed> */
    private function decodedObject(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
};
