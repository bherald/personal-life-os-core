<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // GR-15: community_detection runtime trending up (78→94min) as KG grows.
        // Sprint 1 set 120min but it will be insufficient soon. Bump to 150min.
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 150 WHERE name = 'community_detection'");

        // GR-15: Increase knowledge_graph_build limit from 500 to 750 docs/run
        // to accelerate backfill (currently 11.76% of 73K docs indexed, ~39 day ETA)
        DB::update("UPDATE scheduled_jobs SET command = 'rag:build-knowledge-graph --limit=750' WHERE name = 'knowledge_graph_build' AND command LIKE '%--limit=500%'");

        // GR-14: Expand entity_type CHECK constraints for multi-modal graph nodes
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph_entities DROP CONSTRAINT IF EXISTS chk_entity_type
        ");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph_entities ADD CONSTRAINT chk_entity_type
            CHECK (entity_type IN (
                'person', 'organization', 'location', 'concept', 'event',
                'document', 'date', 'product', 'technology', 'other',
                'file', 'genealogy_person', 'face_cluster'
            ))
        ");

        // Also expand subject_type/object_type on knowledge_graph triples
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph DROP CONSTRAINT IF EXISTS knowledge_graph_subject_type_check
        ");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph DROP CONSTRAINT IF EXISTS knowledge_graph_object_type_check
        ");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph ADD CONSTRAINT knowledge_graph_subject_type_check
            CHECK (subject_type IN (
                'person', 'organization', 'location', 'concept', 'event',
                'document', 'date', 'product', 'technology', 'other',
                'file', 'genealogy_person', 'face_cluster'
            ))
        ");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph ADD CONSTRAINT knowledge_graph_object_type_check
            CHECK (object_type IN (
                'person', 'organization', 'location', 'concept', 'event',
                'document', 'date', 'product', 'technology', 'other',
                'file', 'genealogy_person', 'face_cluster'
            ))
        ");
    }

    public function down(): void
    {
        DB::update("UPDATE scheduled_jobs SET timeout_minutes = 120 WHERE name = 'community_detection'");
        DB::update("UPDATE scheduled_jobs SET command = 'rag:build-knowledge-graph --limit=500' WHERE name = 'knowledge_graph_build' AND command LIKE '%--limit=750%'");

        // Restore original entity_type constraints (without multi-modal types)
        DB::connection('pgsql_rag')->statement("ALTER TABLE knowledge_graph_entities DROP CONSTRAINT IF EXISTS chk_entity_type");
        DB::connection('pgsql_rag')->statement("
            ALTER TABLE knowledge_graph_entities ADD CONSTRAINT chk_entity_type
            CHECK (entity_type IN ('person','organization','location','concept','event','document','date','product','technology','other'))
        ");
        DB::connection('pgsql_rag')->statement("ALTER TABLE knowledge_graph DROP CONSTRAINT IF EXISTS knowledge_graph_subject_type_check");
        DB::connection('pgsql_rag')->statement("ALTER TABLE knowledge_graph DROP CONSTRAINT IF EXISTS knowledge_graph_object_type_check");
    }
};
