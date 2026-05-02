<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE IF NOT EXISTS llm_model_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                profile_name VARCHAR(50) NOT NULL UNIQUE,
                model_name VARCHAR(100) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                use_cases JSON DEFAULT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Seed with current profiles
        $profiles = [
            ['default', 'llama3.1:8b-instruct-q5_K_M', 'High quality general purpose', '["analysis","summarization","general"]'],
            ['fast', 'llama3.1:8b-instruct-q4_K_M', 'Faster, smaller quantization', '["classification","extraction","simple_qa"]'],
            ['creative', 'dolphin-llama3:8b', 'Uncensored creative writing', '["creative_writing","roleplay","uncensored"]'],
            ['coding', 'llama3.1:8b-instruct-q5_K_M', 'Code generation and review', '["code_generation","code_review","debugging"]'],
            ['vision', 'llava:7b', 'Image analysis', '["image_analysis","ocr","visual_qa"]'],
            ['embedding', 'nomic-embed-text', 'Vector embeddings', '["embedding","rag","similarity"]'],
        ];

        foreach ($profiles as [$name, $model, $desc, $useCases]) {
            DB::insert(
                "INSERT IGNORE INTO llm_model_profiles (profile_name, model_name, description, use_cases) VALUES (?, ?, ?, ?)",
                [$name, $model, $desc, $useCases]
            );
        }
    }

    public function down(): void
    {
        DB::statement("DROP TABLE IF EXISTS llm_model_profiles");
    }
};
