<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('genealogy_proposed_relationships', 'related_person_id')) {
            DB::statement(
                "ALTER TABLE genealogy_proposed_relationships
                 ADD COLUMN related_person_id INT UNSIGNED NULL
                 COMMENT 'Existing related person when proposal links two existing people'
                 AFTER person_id"
            );
        }

        if (! $this->columnExists('genealogy_proposed_relationships', 'proposal_mode')) {
            DB::statement(
                "ALTER TABLE genealogy_proposed_relationships
                 ADD COLUMN proposal_mode VARCHAR(32) NOT NULL DEFAULT 'create_person'
                 COMMENT 'create_person or link_existing'
                 AFTER relationship_type"
            );
        }

        if (! $this->indexExists('genealogy_proposed_relationships', 'idx_related_person')) {
            DB::statement(
                'ALTER TABLE genealogy_proposed_relationships
                 ADD INDEX idx_related_person (related_person_id)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('genealogy_proposed_relationships', 'idx_related_person')) {
            DB::statement('ALTER TABLE genealogy_proposed_relationships DROP INDEX idx_related_person');
        }

        if ($this->columnExists('genealogy_proposed_relationships', 'proposal_mode')) {
            DB::statement('ALTER TABLE genealogy_proposed_relationships DROP COLUMN proposal_mode');
        }

        if ($this->columnExists('genealogy_proposed_relationships', 'related_person_id')) {
            DB::statement('ALTER TABLE genealogy_proposed_relationships DROP COLUMN related_person_id');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return (int) ($row->count ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $index]
        );

        return (int) ($row->count ?? 0) > 0;
    }
};
