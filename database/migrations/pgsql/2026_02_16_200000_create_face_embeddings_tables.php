<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Face Embeddings Tables for AI Face Detection/Clustering
 *
 * Uses pgvector for 128-dimensional face embeddings from dlib/face_recognition.
 * Supports similarity search, clustering, and genealogy person linking.
 */
return new class extends Migration
{
    protected $connection = 'pgsql_rag';

    public function up(): void
    {
        // Ensure pgvector extension exists
        DB::connection($this->connection)->statement('CREATE EXTENSION IF NOT EXISTS vector');

        // Person clusters - groups of faces identified as same person
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS person_clusters (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255),
                status VARCHAR(50) DEFAULT 'unreviewed',
                face_count INTEGER DEFAULT 0,
                genealogy_person_id INTEGER,
                merged_into_id INTEGER REFERENCES person_clusters(id),
                representative_face_id INTEGER,
                notes TEXT,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Add indexes for person_clusters
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_person_clusters_status ON person_clusters(status)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_person_clusters_genealogy ON person_clusters(genealogy_person_id)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_person_clusters_face_count ON person_clusters(face_count DESC)
        ");

        // Face embeddings - 128-dim vectors from dlib
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS face_embeddings (
                id SERIAL PRIMARY KEY,
                file_registry_id INTEGER NOT NULL,
                person_cluster_id INTEGER REFERENCES person_clusters(id),
                embedding vector(128) NOT NULL,
                region_x REAL NOT NULL,
                region_y REAL NOT NULL,
                region_w REAL NOT NULL,
                region_h REAL NOT NULL,
                crop_path VARCHAR(500),
                matched_face_id INTEGER REFERENCES face_embeddings(id),
                match_confidence REAL,
                embedding_model VARCHAR(100) DEFAULT 'dlib_face_recognition_resnet_model_v1',
                quality_score REAL,
                is_representative BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Add HNSW index for fast similarity search
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_embeddings_vector
            ON face_embeddings
            USING hnsw (embedding vector_cosine_ops)
            WITH (m = 16, ef_construction = 64)
        ");

        // Add regular indexes
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_embeddings_file ON face_embeddings(file_registry_id)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_embeddings_cluster ON face_embeddings(person_cluster_id)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_embeddings_confidence ON face_embeddings(match_confidence)
        ");

        // Face match candidates - for human review
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS face_match_candidates (
                id SERIAL PRIMARY KEY,
                face_embedding_id INTEGER REFERENCES face_embeddings(id) ON DELETE CASCADE,
                candidate_cluster_id INTEGER REFERENCES person_clusters(id) ON DELETE CASCADE,
                candidate_face_id INTEGER REFERENCES face_embeddings(id) ON DELETE SET NULL,
                confidence REAL NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                reviewed_by VARCHAR(100),
                reviewed_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");

        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_match_candidates_status ON face_match_candidates(status)
        ");
        DB::connection($this->connection)->statement("
            CREATE INDEX IF NOT EXISTS idx_face_match_candidates_confidence ON face_match_candidates(confidence DESC)
        ");

        // Cluster merge history - audit trail
        DB::connection($this->connection)->statement("
            CREATE TABLE IF NOT EXISTS cluster_merge_history (
                id SERIAL PRIMARY KEY,
                source_cluster_id INTEGER NOT NULL,
                target_cluster_id INTEGER NOT NULL,
                faces_moved INTEGER DEFAULT 0,
                merged_by VARCHAR(100),
                merged_at TIMESTAMP DEFAULT NOW()
            )
        ");

        // Add comment to tables
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE person_clusters IS 'AI-detected face clusters representing unique persons'
        ");
        DB::connection($this->connection)->statement("
            COMMENT ON TABLE face_embeddings IS '128-dim face embeddings from dlib/face_recognition for similarity search'
        ");
    }

    public function down(): void
    {
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS cluster_merge_history');
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS face_match_candidates');
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS face_embeddings');
        DB::connection($this->connection)->statement('DROP TABLE IF EXISTS person_clusters');
    }
};
