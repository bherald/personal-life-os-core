<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $updates = [
            'rag_file_bulk_index'   => 90,
            'file_enrich_ai'        => 120,
            'knowledge_graph_build' => 300,
            'file_enrich_faces'     => 90,
            'File Catalog Sync'     => 60,
        ];

        foreach ($updates as $name => $timeout) {
            DB::update(
                "UPDATE scheduled_jobs SET timeout_minutes = ? WHERE name = ?",
                [$timeout, $name]
            );
        }
    }

    public function down(): void
    {
        // Restore previous values
        $rollback = [
            'rag_file_bulk_index'   => 240,
            'file_enrich_ai'        => 180,
            'knowledge_graph_build' => 360,
            'file_enrich_faces'     => 120,
            'File Catalog Sync'     => 120,
        ];

        foreach ($rollback as $name => $timeout) {
            DB::update(
                "UPDATE scheduled_jobs SET timeout_minutes = ? WHERE name = ?",
                [$timeout, $name]
            );
        }
    }
};
