<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prod triage 2026-04-23: research_analyst_agent (job id 66) hit its
 * 60-minute wall-clock cap and got SIGALRM'd last run. Prior runs took
 * 15–18 minutes, so 60 was undersized for variable-work runs that hit a
 * slow external API. Bump to 90 to give headroom while staying under
 * the adaptive-timeout ceiling.
 *
 * Keyed on name (not id) because scheduled_jobs rows may have different
 * ids on dev vs prod.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update(
            'UPDATE scheduled_jobs
             SET timeout_minutes = 90, updated_at = NOW()
             WHERE name = ? AND timeout_minutes < 90',
            ['research_analyst_agent']
        );
    }

    public function down(): void
    {
        DB::update(
            'UPDATE scheduled_jobs
             SET timeout_minutes = 60, updated_at = NOW()
             WHERE name = ? AND timeout_minutes = 90',
            ['research_analyst_agent']
        );
    }
};
