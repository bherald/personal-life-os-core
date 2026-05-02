<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('scheduled_job_runs', 'idx_pid', 'ADD INDEX idx_pid (pid)');
        $this->addIndexIfMissing('scheduled_job_runs', 'idx_worker_id', 'ADD INDEX idx_worker_id (worker_id)');
        $this->addIndexIfMissing('node_execution_inputs', 'idx_node_execution', 'ADD INDEX idx_node_execution (node_execution_id, input_key(100))');
        $this->addIndexIfMissing('workflow_run_outputs', 'idx_run_id', 'ADD INDEX idx_run_id (run_id)');
        $this->addIndexIfMissing('genealogy_media_scan_log', 'idx_scanned_at', 'ADD INDEX idx_scanned_at (scanned_at)');
        $this->addIndexIfMissing('news_articles', 'idx_published_at', 'ADD INDEX idx_published_at (published_at)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE scheduled_job_runs DROP INDEX idx_pid');
        DB::statement('ALTER TABLE scheduled_job_runs DROP INDEX idx_worker_id');
        DB::statement('ALTER TABLE node_execution_inputs DROP INDEX idx_node_execution');
        DB::statement('ALTER TABLE workflow_run_outputs DROP INDEX idx_run_id');
        DB::statement('ALTER TABLE genealogy_media_scan_log DROP INDEX idx_scanned_at');
        DB::statement('ALTER TABLE news_articles DROP INDEX idx_published_at');
    }

    private function addIndexIfMissing(string $table, string $indexName, string $alterSql): void
    {
        $exists = DB::selectOne(
            "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $indexName]
        );

        if (($exists->cnt ?? 0) == 0) {
            DB::statement("ALTER TABLE {$table} {$alterSql}");
        }
    }
};
