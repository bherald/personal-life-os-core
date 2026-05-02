<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Complexity Audit: Drop tables exclusively owned by 18 removed dormant services.
 *
 * All 8 tables are empty on prod (0 rows). No active service references them.
 * Verified via ops:validate-sql and manual grep.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ContentChunkingService tables
        Schema::dropIfExists('file_registry_chunk_matches');
        Schema::dropIfExists('file_registry_chunk_index');

        // ContextEngineeringService tables
        Schema::dropIfExists('context_usage_log');
        Schema::dropIfExists('few_shot_examples');
        Schema::dropIfExists('prompt_templates');

        // WorkflowVersionService tables
        Schema::dropIfExists('workflow_version_runs');
        Schema::dropIfExists('workflow_versions');

        // YouTubeChapterService table
        Schema::dropIfExists('youtube_chapters');
    }

    public function down(): void
    {
        // Tables were empty — no data to restore.
        // Original create migrations still exist in history if schema needs recreation.
    }
};
