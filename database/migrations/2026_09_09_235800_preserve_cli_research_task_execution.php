<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('genealogy_research_tasks')) {
            throw new RuntimeException('Load the PLOS skeletal schema before enabling scoped research tasks.');
        }
        if (! Schema::hasColumn('genealogy_research_tasks', 'cli_execution_scope')) {
            Schema::table('genealogy_research_tasks', function (Blueprint $table): void {
                $table->json('cli_execution_scope')->nullable();
            });
        }
        Schema::table('genealogy_research_tasks', function (Blueprint $table): void {
            foreach (['research_question', 'selection_reason', 'scope_reason', 'evidence_summary',
                'conflicts_found', 'outcome_reason', 'error_message'] as $column) {
                $table->longText($column)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Code rollback must not discard saved settings or shorten operator input.
        // Retain the nullable column and wider text storage.
    }
};
