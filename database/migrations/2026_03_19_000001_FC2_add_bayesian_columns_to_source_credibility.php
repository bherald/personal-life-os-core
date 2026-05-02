<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FC-2: Add Bayesian scoring columns to source_credibility.
 *
 * Beta(alpha, beta) prior: starts at (2,2) = neutral 0.5 mean.
 * Each verified result increments alpha, each refuted increments beta.
 * Posterior mean = alpha / (alpha + beta).
 */
return new class extends Migration
{
    public function up(): void
    {
        $conn = DB::connection('pgsql_rag');

        // Skip if table doesn't exist (dev environment)
        $tableExists = $conn->select("SELECT to_regclass('public.source_credibility') as exists");
        if (empty($tableExists) || $tableExists[0]->exists === null) {
            return;
        }

        // Add Bayesian columns
        $conn->statement("
            ALTER TABLE source_credibility
            ADD COLUMN IF NOT EXISTS bayesian_alpha NUMERIC(8,3) DEFAULT 2.0,
            ADD COLUMN IF NOT EXISTS bayesian_beta NUMERIC(8,3) DEFAULT 2.0,
            ADD COLUMN IF NOT EXISTS last_bayesian_update TIMESTAMP
        ");

        // Initialize Bayesian priors from existing verification data
        $conn->statement("
            UPDATE source_credibility
            SET bayesian_alpha = 2.0 + COALESCE(
                (SELECT COUNT(*) FROM source_credibility sc2
                 WHERE sc2.domain = source_credibility.domain
                   AND sc2.verification_result IN ('verified', 'partially_verified')),
                0),
                bayesian_beta = 2.0 + COALESCE(
                (SELECT COUNT(*) FROM source_credibility sc2
                 WHERE sc2.domain = source_credibility.domain
                   AND sc2.verification_result = 'refuted'),
                0),
                last_bayesian_update = CURRENT_TIMESTAMP
            WHERE verification_count > 0
        ");
    }

    public function down(): void
    {
        $conn = DB::connection('pgsql_rag');
        $conn->statement("
            ALTER TABLE source_credibility
            DROP COLUMN IF EXISTS bayesian_alpha,
            DROP COLUMN IF EXISTS bayesian_beta,
            DROP COLUMN IF EXISTS last_bayesian_update
        ");
    }
};
