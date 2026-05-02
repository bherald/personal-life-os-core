<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add uncensored/private chat support:
 * 1. is_censored flag on llm_instances (tracks which models are censored)
 * 2. is_private + model_mode on conversations (enables ephemeral uncensored chat)
 * 3. Register uncensored model role in Ollama instance configs
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add censored column to llm_instances — default true (most models are censored)
        if (!$this->columnExists('llm_instances', 'is_censored')) {
            DB::statement("ALTER TABLE llm_instances ADD COLUMN is_censored TINYINT(1) NOT NULL DEFAULT 1 AFTER capabilities");
        }

        // Add private mode + model_mode to conversations
        if (!$this->columnExists('conversations', 'is_private')) {
            DB::statement("ALTER TABLE conversations ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER system_prompt");
        }
        if (!$this->columnExists('conversations', 'model_mode')) {
            DB::statement("ALTER TABLE conversations ADD COLUMN model_mode VARCHAR(20) DEFAULT 'standard' AFTER is_private");
        }

        // Register uncensored model role in Ollama configs
        DB::update("
            UPDATE llm_instances
            SET config = JSON_SET(
                config,
                '$.models.uncensored', 'dolphin-llama3:8b'
            )
            WHERE instance_type = 'ollama' AND is_active = 1
        ");
    }

    public function down(): void
    {
        if ($this->columnExists('llm_instances', 'is_censored')) {
            DB::statement("ALTER TABLE llm_instances DROP COLUMN is_censored");
        }
        if ($this->columnExists('conversations', 'is_private')) {
            DB::statement("ALTER TABLE conversations DROP COLUMN is_private");
        }
        if ($this->columnExists('conversations', 'model_mode')) {
            DB::statement("ALTER TABLE conversations DROP COLUMN model_mode");
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $result = DB::select("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return !empty($result);
    }
};
