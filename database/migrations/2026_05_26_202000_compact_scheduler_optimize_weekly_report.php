<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'scheduler:optimize-report --window=7d --json --compact';

    private const FULL_COMMAND = 'scheduler:optimize-report --window=7d --json';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'scheduler_optimize_weekly_report')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Weekly observe-only TODO-012 evidence. Scheduled JSON output is aggregate-only and excludes job rows, job ids, job names, commands, cron expressions, raw recommendation reasons, and evidence.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'scheduler_optimize_weekly_report')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Weekly observe-only TODO-012 evidence. Stores scheduler optimization recommendations in scheduled job history without changing cron expressions, timeouts, queues, or job limits.',
                'updated_at' => now(),
            ]);
    }
};
