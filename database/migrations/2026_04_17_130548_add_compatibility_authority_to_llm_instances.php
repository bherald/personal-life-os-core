<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->columnExists('llm_instances', 'routability')) {
            DB::statement(
                "ALTER TABLE llm_instances
                 ADD COLUMN routability ENUM('allowed','bench_only','blocked') NOT NULL DEFAULT 'blocked'
                 AFTER is_active"
            );
        }

        if (! $this->columnExists('llm_instances', 'gpu_target')) {
            DB::statement(
                "ALTER TABLE llm_instances
                 ADD COLUMN gpu_target ENUM('pascal_6gb','ada_12gb','any','none') NOT NULL DEFAULT 'none'
                 AFTER routability"
            );
        }

        if (! $this->columnExists('llm_instances', 'host_affinity')) {
            DB::statement(
                'ALTER TABLE llm_instances
                 ADD COLUMN host_affinity VARCHAR(50) NULL
                 AFTER gpu_target'
            );
        }

        if (! $this->columnExists('llm_instances', 'compat_runtime_family')) {
            DB::statement(
                'ALTER TABLE llm_instances
                 ADD COLUMN compat_runtime_family VARCHAR(30) NULL
                 AFTER host_affinity'
            );
        }

        if (! $this->columnExists('llm_instances', 'compat_backend')) {
            DB::statement(
                'ALTER TABLE llm_instances
                 ADD COLUMN compat_backend VARCHAR(30) NULL
                 AFTER compat_runtime_family'
            );
        }

        if (! $this->columnExists('llm_instances', 'compat_status')) {
            DB::statement(
                "ALTER TABLE llm_instances
                 ADD COLUMN compat_status ENUM('authoritative','provisional','stale') NOT NULL DEFAULT 'provisional'
                 AFTER compat_backend"
            );
        }

        DB::statement(
            "UPDATE llm_instances
             SET routability = 'allowed',
                 gpu_target = 'pascal_6gb',
                 host_affinity = 'local-primary',
                 compat_runtime_family = 'ollama_0_17',
                 compat_backend = 'llama_cpp',
                 compat_status = 'authoritative',
                 updated_at = NOW()
             WHERE instance_id = 'ollama_primary'"
        );

        DB::statement(
            "UPDATE llm_instances
             SET routability = 'allowed',
                 gpu_target = 'ada_12gb',
                 host_affinity = 'local-secondary',
                 compat_runtime_family = 'ollama_0_18+',
                 compat_backend = 'llama_cpp',
                 compat_status = 'authoritative',
                 updated_at = NOW()
             WHERE instance_id = 'ollama_secondary'"
        );

        DB::statement(
            "UPDATE llm_instances
             SET routability = 'allowed',
                 gpu_target = 'none',
                 host_affinity = NULL,
                 compat_runtime_family = CASE
                     WHEN instance_id = 'claude_cli' THEN 'claude_cli'
                     WHEN instance_id = 'sambanova_free' THEN 'sambanova_openai'
                     WHEN instance_id = 'cerebras_free' THEN 'cerebras_openai'
                     WHEN instance_id = 'groq_free' THEN 'groq_openai'
                     WHEN instance_id = 'openrouter_free' THEN 'openrouter'
                     WHEN instance_id = 'gemini_free' THEN 'google_gemini'
                     WHEN instance_id = 'mistral_free' THEN 'mistral_openai'
                     WHEN instance_id = 'deepinfra_free' THEN 'deepinfra_openai'
                     ELSE instance_type
                 END,
                 compat_backend = CASE
                     WHEN instance_id = 'claude_cli' THEN 'claude_cli'
                     ELSE 'openai_compat'
                 END,
                 compat_status = 'authoritative',
                 updated_at = NOW()
             WHERE instance_type <> 'ollama'"
        );
    }

    public function down(): void
    {
        if ($this->columnExists('llm_instances', 'compat_status')) {
            DB::statement(
                "UPDATE llm_instances
                 SET routability = 'blocked',
                     gpu_target = 'none',
                     host_affinity = NULL,
                     compat_runtime_family = NULL,
                     compat_backend = NULL,
                     compat_status = 'provisional',
                     updated_at = NOW()
                 WHERE instance_id IN ('ollama_primary', 'ollama_secondary')
                    OR instance_type <> 'ollama'"
            );
        }

        if ($this->columnExists('llm_instances', 'compat_status')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN compat_status');
        }

        if ($this->columnExists('llm_instances', 'compat_backend')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN compat_backend');
        }

        if ($this->columnExists('llm_instances', 'compat_runtime_family')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN compat_runtime_family');
        }

        if ($this->columnExists('llm_instances', 'host_affinity')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN host_affinity');
        }

        if ($this->columnExists('llm_instances', 'gpu_target')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN gpu_target');
        }

        if ($this->columnExists('llm_instances', 'routability')) {
            DB::statement('ALTER TABLE llm_instances DROP COLUMN routability');
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) AS count
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?',
            [$table, $column]
        );

        return (int) ($row->count ?? 0) > 0;
    }
};
