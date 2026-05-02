<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Remove duplicate entries first (keep the latest scan per path)
        DB::statement("DELETE s1 FROM genealogy_media_scan_log s1
            INNER JOIN genealogy_media_scan_log s2
            WHERE s1.tree_id = s2.tree_id AND s1.nextcloud_path = s2.nextcloud_path AND s1.id < s2.id");

        // Drop the non-unique index and add a unique one
        DB::statement("ALTER TABLE genealogy_media_scan_log DROP INDEX idx_tree_path");
        DB::statement("ALTER TABLE genealogy_media_scan_log ADD UNIQUE INDEX idx_tree_path_unique (tree_id, nextcloud_path(255))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE genealogy_media_scan_log DROP INDEX idx_tree_path_unique");
        DB::statement("ALTER TABLE genealogy_media_scan_log ADD INDEX idx_tree_path (tree_id, nextcloud_path(255))");
    }
};
