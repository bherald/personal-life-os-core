<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('genealogy_intake_runs')) {
            return;
        }

        DB::statement("
            CREATE TABLE genealogy_intake_runs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                run_key VARCHAR(64) NOT NULL,
                tree_id INT UNSIGNED NOT NULL,
                root_path VARCHAR(500) NOT NULL,
                packet_label VARCHAR(255) NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'staged',
                staged_snapshot JSON NOT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_genealogy_intake_runs_run_key (run_key),
                KEY idx_genealogy_intake_runs_tree_status (tree_id, status),
                KEY idx_genealogy_intake_runs_updated_at (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        if (Schema::hasTable('genealogy_intake_runs')) {
            DB::statement('DROP TABLE genealogy_intake_runs');
        }
    }
};
