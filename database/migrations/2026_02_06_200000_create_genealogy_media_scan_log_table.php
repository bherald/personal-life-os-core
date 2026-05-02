<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS genealogy_media_scan_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tree_id INT UNSIGNED NOT NULL,
            nextcloud_path VARCHAR(500) NOT NULL,
            scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            has_faces TINYINT(1) NOT NULL DEFAULT 0,
            face_count INT NOT NULL DEFAULT 0,
            face_names JSON NULL,
            file_size INT UNSIGNED NULL,
            scan_error TEXT NULL,
            INDEX idx_tree_path (tree_id, nextcloud_path(255)),
            INDEX idx_scanned_at (scanned_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS genealogy_media_scan_log");
    }
};
