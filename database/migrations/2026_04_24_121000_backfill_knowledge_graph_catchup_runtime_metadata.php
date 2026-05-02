<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up for the 2026-04-20 knowledge_graph_catchup scheduler row.
 *
 * Keep this as a forward migration because the original scheduler migration may
 * already be deployed. The row is a bounded RAG catch-up lane and should carry
 * the same typed runtime metadata as the primary knowledge_graph_build lane.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET runtime_mode = 'cron',
                 workload_family = 'rag',
                 resource_profile = 'rag',
                 stall_policy = 'stall_exempt',
                 backlog_metric = 'none',
                 notification_mode = 'digest',
                 updated_at = NOW()
             WHERE name = ?",
            ['knowledge_graph_catchup']
        );
    }

    public function down(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET runtime_mode = NULL,
                 workload_family = NULL,
                 resource_profile = NULL,
                 stall_policy = NULL,
                 backlog_metric = NULL,
                 notification_mode = NULL,
                 updated_at = NOW()
             WHERE name = ?",
            ['knowledge_graph_catchup']
        );
    }
};
