<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        $this->updateInstanceConfig('ollama_jetson', function (array $config): array {
            $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
            $config['models']['fast'] = 'qwen2.5-coder:3b';
            $config['models']['standard'] = 'qwen2.5-coder:3b';
            $config['models']['coding'] = 'qwen2.5-coder:3b';
            unset($config['models']['quality']);

            $config['route_policy'] = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
            $config['route_policy']['require_model_role'] = true;
            $config['route_policy']['allow_generic_fallback'] = false;
            $config['route_policy']['allowed_model_roles'] = $this->rolesWithoutQuality([
                'fast',
                'embedding',
                'health',
                'coding',
                'standard',
            ], $config['route_policy']['allowed_model_roles'] ?? []);

            return $config;
        }, '2026-06-15 CONTRACT-EVAL: qwen3:4b on Jetson treats /no_think as user text and leaks reasoning; standard/coding now route to qwen2.5-coder:3b, which passed exact-output smoke. Quality remains withheld until eval promotion.');

        $this->updateInstanceConfig('ollama_primary', function (array $config): array {
            $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
            $config['models']['standard'] = 'dolphin-llama3:8b';
            $config['models']['coding'] = 'deepseek-coder:6.7b-instruct-q4_K_M';
            unset($config['models']['quality']);

            $config['route_policy'] = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
            $config['route_policy']['require_model_role'] = true;
            $config['route_policy']['allow_generic_fallback'] = false;
            $config['route_policy']['allowed_model_roles'] = $this->rolesWithoutQuality([
                'fast',
                'embedding',
                'vision',
                'coding',
                'uncensored',
                'health',
                'standard',
            ], $config['route_policy']['allowed_model_roles'] ?? []);

            return $config;
        }, '2026-06-15 CONTRACT-EVAL: qwen3:4b on primary treats /no_think as user text and leaks reasoning; standard now routes to dolphin-llama3:8b and coding to deepseek-coder, both exact-output smoke passed. Quality remains withheld until eval promotion.');

        $this->clearCaches();
    }

    public function down(): void
    {
        if (! Schema::hasTable('llm_instances')) {
            return;
        }

        foreach (['ollama_jetson', 'ollama_primary'] as $instanceId) {
            $this->updateInstanceConfig($instanceId, function (array $config): array {
                $config['models'] = is_array($config['models'] ?? null) ? $config['models'] : [];
                $config['models']['standard'] = 'qwen3:4b';
                $config['models']['quality'] = 'qwen3:4b';

                $config['route_policy'] = is_array($config['route_policy'] ?? null) ? $config['route_policy'] : [];
                $config['route_policy']['allowed_model_roles'] = array_values(array_unique(array_merge(
                    is_array($config['route_policy']['allowed_model_roles'] ?? null)
                        ? $config['route_policy']['allowed_model_roles']
                        : [],
                    ['standard', 'quality']
                )));

                return $config;
            });
        }

        $this->clearCaches();
    }

    /**
     * @param  array<int,string>  $required
     * @param  mixed  $existing
     * @return array<int,string>
     */
    private function rolesWithoutQuality(array $required, mixed $existing): array
    {
        $roles = array_values(array_unique(array_merge(
            is_array($existing) ? array_filter($existing, 'is_string') : [],
            $required
        )));

        return array_values(array_filter($roles, fn (string $role): bool => $role !== 'quality'));
    }

    /**
     * @param  callable(array<string,mixed>): array<string,mixed>  $mutator
     */
    private function updateInstanceConfig(string $instanceId, callable $mutator, ?string $note = null): void
    {
        $row = DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->first(['config', 'notes']);

        if (! $row) {
            return;
        }

        $config = json_decode((string) ($row->config ?? '{}'), true);
        if (! is_array($config)) {
            $config = [];
        }

        $payload = [
            'config' => json_encode($mutator($config), JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];

        if ($note !== null) {
            $payload['notes'] = $this->appendNoteOnce((string) ($row->notes ?? ''), $note);
        }

        DB::table('llm_instances')
            ->where('instance_id', $instanceId)
            ->update($payload);
    }

    private function appendNoteOnce(string $notes, string $note): string
    {
        if (str_contains($notes, $note)) {
            return $notes;
        }

        return trim($notes) === '' ? $note : rtrim($notes)."\n".$note;
    }

    private function clearCaches(): void
    {
        foreach ([
            'llm_instances_all',
            'llm_instances_healthy',
            'ollama_available_models',
            'ollama_vetted_models',
        ] as $key) {
            Cache::forget($key);
        }
    }
};
