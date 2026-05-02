<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * N89 — Ancestor path computation + research coverage tracking
 *
 * Creates two tables:
 *
 * 1. genealogy_ancestor_paths — pre-computed BFS paths from root person to every
 *    reachable ancestor. Populated by GenealogyService::rebuildAncestorPaths().
 *    Enables the agent to determine bloodline tier without a recursive CTE at query time.
 *
 * 2. genealogy_person_coverage — per-person research priority score. Updated whenever
 *    a research search is logged or a hint is processed. Agent uses this instead of
 *    "count missing fields" to decide who to research next.
 *
 * Columns in genealogy_person_coverage:
 *   - bloodline_tier: 1=direct ancestor, 2=sibling/child of direct, 3=collateral, 4=married-in only
 *   - generation_distance: how many hops from tree root (root person = 0)
 *   - data_gap_score: 0.0-1.0 (1=all key fields missing)
 *   - research_exhaustion_score: 0.0-1.0 (1=fully exhausted/deferred, 0=never searched)
 *   - last_searched_at: when this person was last included in any research run
 *   - pending_hint_count: current count of pending hints
 *   - priority_score: computed = (tier_weight × 0.40) + (gap × 0.35) + (staleness × 0.25)
 *   - priority_rank: rank within tree ordered by priority_score DESC
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Table 1: ancestor paths ---
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_ancestor_paths (
                id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tree_id         INT UNSIGNED NOT NULL,
                root_person_id  INT UNSIGNED NOT NULL COMMENT 'Tree owner / starting person',
                ancestor_id     INT UNSIGNED NOT NULL COMMENT 'The ancestor at the end of this path',
                generation      SMALLINT UNSIGNED NOT NULL COMMENT '0=root, 1=parent, 2=grandparent, etc.',
                path_ids        TEXT NOT NULL COMMENT 'JSON array of person IDs from root to ancestor',
                bloodline_tier  TINYINT UNSIGNED NOT NULL DEFAULT 1
                                COMMENT '1=direct ancestor, 2=sibling/child of direct, 3=collateral',
                rebuilt_at      TIMESTAMP NULL DEFAULT NULL,
                created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tree_root_ancestor (tree_id, root_person_id, ancestor_id),
                KEY idx_tree_ancestor (tree_id, ancestor_id),
                KEY idx_tier_gen (tree_id, bloodline_tier, generation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // --- Table 2: per-person research coverage ---
        DB::statement("
            CREATE TABLE IF NOT EXISTS genealogy_person_coverage (
                id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tree_id                     INT UNSIGNED NOT NULL,
                person_id                   INT UNSIGNED NOT NULL,
                bloodline_tier              TINYINT UNSIGNED NOT NULL DEFAULT 3
                                            COMMENT '1=direct, 2=sibling of direct, 3=collateral, 4=married-in only',
                generation_distance         SMALLINT UNSIGNED NULL COMMENT 'Hops from root, NULL if not on bloodline',
                data_gap_score              DECIMAL(4,3) NOT NULL DEFAULT 0.000
                                            COMMENT '0.0=complete, 1.0=all key fields missing',
                research_exhaustion_score   DECIMAL(4,3) NOT NULL DEFAULT 0.000
                                            COMMENT '0.0=never searched, 1.0=all repos tried with no results',
                pending_hint_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                last_searched_at            TIMESTAMP NULL DEFAULT NULL,
                search_count_30d            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                negative_count_30d          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                priority_score              DECIMAL(6,4) NOT NULL DEFAULT 0.0000
                                            COMMENT 'Composite: tier×0.40 + gap×0.35 + staleness×0.25',
                priority_rank               INT UNSIGNED NULL COMMENT 'Rank within tree (1=highest priority)',
                coverage_updated_at         TIMESTAMP NULL DEFAULT NULL,
                created_at                  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tree_person (tree_id, person_id),
                KEY idx_tree_priority (tree_id, priority_score DESC),
                KEY idx_tree_tier (tree_id, bloodline_tier, priority_score DESC),
                KEY idx_last_searched (tree_id, last_searched_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_person_coverage");
        DB::statement("DROP TABLE IF EXISTS genealogy_ancestor_paths");
    }
};
