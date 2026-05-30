<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COMPACT_COMMAND = 'news:source-inventory --workflow=news_brief --days=7 --strict --json --compact';

    private const FULL_COMMAND = 'news:source-inventory --workflow=news_brief --days=7 --strict --json';

    public function up(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'news_source_inventory')
            ->update([
                'command' => self::COMPACT_COMMAND,
                'last_run_output' => null,
                'notes' => 'Weekly observe-only check after RSS self-heal and before daily report. Scheduled JSON output is aggregate-only and excludes feed rows, workflow/node ids, feed URLs, article URLs, raw health errors, and raw source samples.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('scheduled_jobs')) {
            return;
        }

        DB::table('scheduled_jobs')
            ->where('name', 'news_source_inventory')
            ->update([
                'command' => self::FULL_COMMAND,
                'last_run_output' => null,
                'notes' => 'Weekly observe-only check after RSS self-heal and before daily report. Verifies configured news_brief RSS feeds resolve through table-backed bias_ratings/bias_rating_aliases and have recent health/article telemetry.',
                'updated_at' => now(),
            ]);
    }
};
