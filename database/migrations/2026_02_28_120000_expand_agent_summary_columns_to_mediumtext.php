<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand summary columns from TEXT (64KB) to MEDIUMTEXT (16MB)
        // Agent runs can produce multi-phase reports that exceed 64KB
        DB::statement("ALTER TABLE agent_episodes MODIFY summary MEDIUMTEXT NOT NULL");
        DB::statement("ALTER TABLE agent_review_queue MODIFY summary MEDIUMTEXT NOT NULL");
        DB::statement("ALTER TABLE agent_review_queue MODIFY reviewer_notes MEDIUMTEXT NULL");
        DB::statement("ALTER TABLE agent_messages MODIFY body MEDIUMTEXT NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE agent_episodes MODIFY summary TEXT NOT NULL");
        DB::statement("ALTER TABLE agent_review_queue MODIFY summary TEXT NOT NULL");
        DB::statement("ALTER TABLE agent_review_queue MODIFY reviewer_notes TEXT NULL");
        DB::statement("ALTER TABLE agent_messages MODIFY body TEXT NOT NULL");
    }
};
