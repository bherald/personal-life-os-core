<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const JOB_NAME = 'agent_skill_drift_audit';

    private const COMMAND = 'agents:skill-audit --format=json --compact --compare-latest --fail-on-drift --write-report';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->update([
                'command' => self::COMMAND,
                'last_run_output' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', self::JOB_NAME)
            ->update([
                'command' => 'agents:skill-audit --format=json --compare-latest --fail-on-drift --write-report',
                'last_run_output' => null,
                'updated_at' => now(),
            ]);
    }
};
