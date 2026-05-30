<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'ops:agent-doctor-snapshot --json --compact --since=24';

    private const FULL_COMMAND = 'ops:agent-doctor-snapshot --json --since=24';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'agent_doctor_readiness_snapshot')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Append-only observe snapshot for Agent Doctor history. Scheduled JSON output is aggregate-only; stored snapshot rows keep aggregate statuses, counts, check ids, and output-quality counts while excluding per-agent detail, raw traces, prompts, completions, command output, and filesystem paths.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'agent_doctor_readiness_snapshot')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Append-only observe snapshot for Agent Doctor history. Stores aggregate statuses, counts, check ids, and output-quality counts only; excludes per-agent detail, raw traces, prompts, completions, command output, and filesystem paths.',
                'updated_at' => now(),
            ]);
    }
};
