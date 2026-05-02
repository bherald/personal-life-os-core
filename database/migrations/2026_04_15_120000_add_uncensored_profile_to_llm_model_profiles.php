<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $modelName = $this->resolveUncensoredModel();

        if (! is_string($modelName) || trim($modelName) === '') {
            return;
        }

        $description = 'Private uncensored local chat';
        $useCases = json_encode(['uncensored', 'private_chat', 'creative_writing'], JSON_UNESCAPED_SLASHES);
        $notes = 'DB-authoritative uncensored chat profile. Prefer local-only use and explicit user intent.';

        $existing = DB::selectOne(
            'SELECT id FROM llm_model_profiles WHERE profile_name = ? LIMIT 1',
            ['uncensored']
        );

        if ($existing) {
            DB::update(
                'UPDATE llm_model_profiles
                 SET model_name = ?, description = ?, use_cases = ?, enabled = 1, notes = ?, updated_at = NOW()
                 WHERE id = ?',
                [$modelName, $description, $useCases, $notes, $existing->id]
            );

            return;
        }

        DB::insert(
            'INSERT INTO llm_model_profiles
             (profile_name, model_name, description, use_cases, enabled, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
            ['uncensored', $modelName, $description, $useCases, $notes]
        );
    }

    public function down(): void
    {
        DB::delete('DELETE FROM llm_model_profiles WHERE profile_name = ?', ['uncensored']);
    }

    private function resolveUncensoredModel(): ?string
    {
        $profile = DB::selectOne(
            "SELECT model_name
             FROM llm_model_profiles
             WHERE enabled = 1 AND profile_name = 'creative'
             LIMIT 1"
        );

        if ($profile?->model_name) {
            return (string) $profile->model_name;
        }

        $instance = DB::selectOne(
            "SELECT config
             FROM llm_instances
             WHERE instance_type = 'ollama' AND is_active = 1
             ORDER BY priority ASC, id ASC
             LIMIT 1"
        );

        if (! $instance) {
            return null;
        }

        $config = json_decode($instance->config ?? '{}', true);
        if (! is_array($config)) {
            return null;
        }

        return $config['models']['uncensored']
            ?? $config['models']['creative']
            ?? null;
    }
};
