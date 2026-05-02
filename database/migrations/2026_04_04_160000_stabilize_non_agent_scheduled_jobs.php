<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'research:run-missions --limit=1',
                timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 45),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'research_run_missions'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'email:rag-index --limit=25 --timeout=60',
                timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 60),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'email_rag_index'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 90),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'workflow_joplin_sync'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'community_detection'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'rag:hype-build --limit=50 --timeout=60',
                timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 60),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'rag_hype_build'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'file-catalog:sync --full --limit=500',
                timeout_minutes = GREATEST(COALESCE(timeout_minutes, 0), 90),
                stall_exempt = 1,
                updated_at = NOW()
            WHERE name = 'File Catalog Sync'
        ");
    }

    public function down(): void
    {
        DB::update("
            UPDATE scheduled_jobs
            SET command = 'research:run-missions --limit=2',
                timeout_minutes = 30,
                updated_at = NOW()
            WHERE name = 'research_run_missions'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'email:rag-index --limit=25',
                timeout_minutes = 60,
                updated_at = NOW()
            WHERE name = 'email_rag_index'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET timeout_minutes = 57,
                stall_exempt = 0,
                updated_at = NOW()
            WHERE name = 'workflow_joplin_sync'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET stall_exempt = 0,
                updated_at = NOW()
            WHERE name = 'community_detection'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'rag:hype-build --limit=200',
                timeout_minutes = 50,
                updated_at = NOW()
            WHERE name = 'rag_hype_build'
        ");

        DB::update("
            UPDATE scheduled_jobs
            SET command = 'file-catalog:sync --full --limit=10000',
                timeout_minutes = 30,
                stall_exempt = 0,
                updated_at = NOW()
            WHERE name = 'File Catalog Sync'
        ");
    }
};
