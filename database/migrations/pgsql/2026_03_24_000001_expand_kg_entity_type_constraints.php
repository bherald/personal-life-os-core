<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Expand entity type constraints to include file, genealogy_person, face_cluster
        // These types are used by the multi-modal graph bridge (GR-14)
        // Combines DROP+ADD in single ALTER to minimize lock duration

        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph_entities
                DROP CONSTRAINT IF EXISTS chk_entity_type,
                ADD CONSTRAINT chk_entity_type CHECK (entity_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other',
                    'file', 'genealogy_person', 'face_cluster'
                ))
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
                DROP CONSTRAINT IF EXISTS chk_subject_type,
                ADD CONSTRAINT chk_subject_type CHECK (subject_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other',
                    'file', 'genealogy_person', 'face_cluster'
                )),
                DROP CONSTRAINT IF EXISTS chk_object_type,
                ADD CONSTRAINT chk_object_type CHECK (object_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other',
                    'file', 'genealogy_person', 'face_cluster'
                ))
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph_entities
                DROP CONSTRAINT IF EXISTS chk_entity_type,
                ADD CONSTRAINT chk_entity_type CHECK (entity_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                ))
        ");

        DB::connection($this->connection)->statement("
            ALTER TABLE knowledge_graph
                DROP CONSTRAINT IF EXISTS chk_subject_type,
                ADD CONSTRAINT chk_subject_type CHECK (subject_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                )),
                DROP CONSTRAINT IF EXISTS chk_object_type,
                ADD CONSTRAINT chk_object_type CHECK (object_type IN (
                    'person', 'organization', 'location', 'concept', 'event',
                    'document', 'date', 'product', 'technology', 'other'
                ))
        ");
    }
};
