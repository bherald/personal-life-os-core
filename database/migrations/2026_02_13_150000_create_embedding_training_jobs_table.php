<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS embedding_training_jobs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(50) NULL UNIQUE,
                status ENUM('data_ready', 'training', 'completed', 'deployed', 'error') DEFAULT 'data_ready',
                model_name VARCHAR(100) NULL,
                base_model VARCHAR(200) NULL,
                training_file VARCHAR(500) NULL,
                eval_file VARCHAR(500) NULL,
                output_dir VARCHAR(500) NULL,
                training_pairs INT UNSIGNED NULL,
                eval_pairs INT UNSIGNED NULL,
                epochs INT UNSIGNED DEFAULT 3,
                batch_size INT UNSIGNED DEFAULT 32,
                learning_rate DECIMAL(10,8) DEFAULT 0.00002000,
                evaluation_metrics JSON NULL,
                deployed_model_tag VARCHAR(100) NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME NULL,
                evaluated_at DATETIME NULL,
                deployed_at DATETIME NULL,
                INDEX idx_status (status),
                INDEX idx_job_id (job_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS embedding_training_jobs");
    }
};
