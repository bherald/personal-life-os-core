<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SECTION = 'ai';

    private const KEY = 'model_host_controller_automatic_selection_policy';

    private const INACTIVE_SHA256 = '2976bd9f5379a0451c96bc05da1b37c7062169ea3a8fc11edb51d0749c5069c1';

    private const ACTIVE_SHA256 = '411bccfd0f7d528e23d89f5458feb09a44a359af6d63c33199adf78bbd38ab10';

    private const INACTIVE_DESCRIPTION = 'Inactive MHC-06 automatic serial selection with exact provider-admission roles; primary live gate required before activation.';

    private const ACTIVE_DESCRIPTION = 'Enabled MHC-06 explicit opt-in automatic serial selection; normal routing remains off.';

    private const SOAK_JOB_NAME = 'model_host_controller_mhc06_soak';

    private const SOAK_COMMAND = 'model-host:mhc06-soak sample';

    private const SOAK_DESCRIPTION = 'Read-only, content-free MHC-06 controller and queue soak evidence.';

    public function up(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }
        if (! Schema::hasTable('scheduled_jobs')) {
            throw new RuntimeException('MHC-06 automatic selection requires the soak scheduler table.');
        }

        DB::transaction(function (): void {
            $this->transition(false);
            $this->installSoakSchedule();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('system_configs')) {
            return;
        }

        DB::transaction(function (): void {
            $this->transition(true);
            $this->removeOwnedSoakSchedule();
        });
    }

    private function transition(bool $rollback): void
    {
        $row = DB::table('system_configs')
            ->where('section', self::SECTION)
            ->where('config_key', self::KEY)
            ->lockForUpdate()
            ->first();
        if ($row === null) {
            throw new RuntimeException('MHC-06 automatic selection authority is missing.');
        }
        $json = (string) $row->config_value;
        $expectedHash = $rollback ? self::ACTIVE_SHA256 : self::INACTIVE_SHA256;
        $expectedDescription = $rollback ? self::ACTIVE_DESCRIPTION : self::INACTIVE_DESCRIPTION;
        if (! hash_equals($expectedHash, hash('sha256', $json))
            || (string) ($row->description ?? '') !== $expectedDescription) {
            throw new RuntimeException('MHC-06 automatic selection authority drifted.');
        }
        $policy = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $policy['revision'] = $rollback ? 'MHC-06-inactive-automatic' : 'MHC-06-automatic-v1';
        $policy['enabled'] = ! $rollback;
        $policy['automatic_selection_enabled'] = ! $rollback;
        $nextJson = json_encode($policy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $nextHash = $rollback ? self::INACTIVE_SHA256 : self::ACTIVE_SHA256;
        if (! hash_equals($nextHash, hash('sha256', $nextJson))) {
            throw new RuntimeException('MHC-06 automatic selection transition is inexact.');
        }
        $updated = DB::table('system_configs')
            ->where('id', $row->id)
            ->where('config_value', $json)
            ->update([
                'config_value' => $nextJson,
                'description' => $rollback ? self::INACTIVE_DESCRIPTION : self::ACTIVE_DESCRIPTION,
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('MHC-06 automatic selection transition lost authority.');
        }
    }

    private function installSoakSchedule(): void
    {
        if (DB::table('scheduled_jobs')->where('name', self::SOAK_JOB_NAME)->exists()) {
            throw new RuntimeException('MHC-06 soak schedule authority already exists.');
        }

        DB::table('scheduled_jobs')->insert(array_merge([
            'name' => self::SOAK_JOB_NAME,
            'created_at' => now(),
        ], $this->soakScheduleValues(), ['updated_at' => now()]));
    }

    private function removeOwnedSoakSchedule(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::SOAK_JOB_NAME)
            ->where('command', self::SOAK_COMMAND)
            ->where('description', self::SOAK_DESCRIPTION)
            ->where('notes', $this->soakNotes())
            ->delete();
    }

    /** @return array<string,mixed> */
    private function soakScheduleValues(): array
    {
        return [
            'description' => self::SOAK_DESCRIPTION,
            'job_type' => 'command',
            'command' => self::SOAK_COMMAND,
            'cron_expression' => '*/5 * * * *',
            'enabled' => 1,
            'run_in_background' => 1,
            'without_overlapping' => 1,
            'stall_exempt' => 0,
            'timeout_minutes' => 2,
            'max_parallel' => 1,
            'category' => 'Maintenance',
            'source_module' => 'Ops',
            'runtime_mode' => 'observe',
            'workload_family' => 'ops',
            'resource_profile' => 'network',
            'stall_policy' => 'strict',
            'backlog_metric' => 'model_host_controller_mhc06_soak',
            'notification_mode' => 'digest',
            'notes' => $this->soakNotes(),
        ];
    }

    private function soakNotes(): string
    {
        return json_encode([
            'schema' => 'plos.model_host_controller.mhc06_soak_schedule.v1',
            'phase' => 'MHC-06',
            'read_only' => true,
            'content_free' => true,
            'normal_routing' => false,
            'mutation_allowed' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
};
