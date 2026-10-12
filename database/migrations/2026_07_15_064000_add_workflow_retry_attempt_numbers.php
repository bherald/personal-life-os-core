<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE node_executions ADD COLUMN attempt_number SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER node_order');
        DB::statement('CREATE INDEX idx_node_executions_run_attempt_order ON node_executions (run_id, attempt_number, node_order)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX idx_node_executions_run_attempt_order ON node_executions');
        DB::statement('ALTER TABLE node_executions DROP COLUMN attempt_number');
    }
};
