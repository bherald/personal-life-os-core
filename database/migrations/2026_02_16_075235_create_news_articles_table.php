<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("
            CREATE TABLE news_articles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                feed_url VARCHAR(500) NOT NULL,
                feed_name VARCHAR(255),
                article_url VARCHAR(1000) NOT NULL,
                article_hash VARCHAR(64) NOT NULL COMMENT 'SHA256 for dedup',
                title VARCHAR(500),
                description TEXT,
                content MEDIUMTEXT,
                author VARCHAR(255),
                published_at DATETIME,
                fetched_at DATETIME NOT NULL,
                bias_score DECIMAL(5,2),
                bias_data JSON,
                workflow_id INT,
                rag_indexed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_article_hash (article_hash),
                KEY idx_feed_url (feed_url(255)),
                KEY idx_published_at (published_at),
                KEY idx_rag_indexed (rag_indexed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS news_articles");
    }
};
