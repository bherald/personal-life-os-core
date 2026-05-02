<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up for the 2026-04-23 ollama_drift_check scheduler row.
 *
 * The original scheduling migration predated the final runtime-manifest
 * check and inserted the row without the six typed runtime metadata fields,
 * leaving ops:runtime-manifest --section=drift with one job-level offender.
 * Do not edit the deployed 2026-04-23 migration; backfill existing and future
 * environments here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            "UPDATE scheduled_jobs
             SET runtime_mode = 'ops_check',
                 workload_family = 'llm',
                 resource_profile = 'network_bound',
                 stall_policy = 'stall_exempt',
                 backlog_metric = 'ollama_drift',
                 notification_mode = 'digest',
                 updated_at = NOW()
             WHERE name = ?",
            ['ollama_drift_check']
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
            ['ollama_drift_check']
        );
    }
};
