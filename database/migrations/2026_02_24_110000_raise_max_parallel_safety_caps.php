<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Raise max_parallel from static operational targets to dynamic safety caps.
 *
 * The framework now auto-scales worker count from system state via
 * resolveMaxParallel(). max_parallel is now only a hard safety cap.
 * Actual concurrency is computed from CPU/memory/GPU/backlog/time-of-day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pipeline enrichment jobs: raise caps to match JOB_RESOURCE_PROFILES hard_cap
        DB::update("UPDATE scheduled_jobs SET max_parallel = 4 WHERE command LIKE '%files:enrich --type=faces%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 3 WHERE command LIKE '%files:enrich --type=ai%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 4 WHERE command LIKE '%file-catalog:sync --rag-sync%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 4 WHERE command LIKE '%file-catalog:sync --full%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 3 WHERE command LIKE '%files:enrich --type=phash%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 3 WHERE command LIKE '%files:thumbnails%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%files:enrich --type=video%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 3 WHERE command LIKE '%files:enrich --type=exif%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%files:enrich --type=writeback%'");
    }

    public function down(): void
    {
        // Revert to static caps
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%files:enrich%'");
        DB::update("UPDATE scheduled_jobs SET max_parallel = 2 WHERE command LIKE '%file-catalog:sync%'");
    }
};
